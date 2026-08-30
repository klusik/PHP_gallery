# Patch notes

## Version 0.94.1

Version 0.94.1 hardens request handling and release activation after the Version 0.94 runtime and routing audit. Internal application trees are consistently protected in subdirectory deployments, public original-media URLs carry stable cache identities, unexpected PHP failures return bounded server-error responses, and updater activation fails new requests closed until the active file set is coherent.

  ### Highlights

  #### Safer hosting and routing boundaries

  - Fixed Apache protection for top-level internal directories when PHP Gallery is installed below a path such as `/Galerie/`, while preserving normal public routes whose later slug components contain words such as `app`.
  - Added matching defense-in-depth protection to `public/.htaccess` and retained the explicit `.well-known` exception.
  - Added an early runtime boundary that returns safe `500` responses for uncaught or catchable fatal PHP failures when response headers remain controllable.

  #### Coherent updater activation

  - Added a durable activation marker before active application files are replaced.
  - Returned private, non-cacheable `503 Service Unavailable` responses to new ordinary requests during activation, while keeping authenticated updater recovery/status access available.
  - Made corrupt or incomplete activation state fail closed and delayed marker removal until activation completion was durably recorded.

  #### Stable immutable media

  - Added canonical stable version identities to public full/original media URLs emitted by gallery pages, Smart Galleries, lightbox data, thumbnail bundles, and public media manifests.
  - Preserved identical media bytes across canonical and legacy query routes while retaining `ETag` and conditional `304 Not Modified` behavior.

  ### Technical Details

  #### Backend

  - Added dependency-free early failure and activation handling in `app/early_runtime.php`, loaded from `public/index.php` and `install.php` before the normal bootstrap.
  - Updated `app/services/updates_jobs.php` to create, validate, recover, and clear `cache/updates/activation.json` around the non-yielding activation stage.
  - Updated `.htaccess` and `public/.htaccess` to apply protected-tree rules in Apache per-directory rewrite context instead of relying on an origin-anchored redirect expression.
  - Updated public media URL callers in `app/controllers/gallery_lightbox.php`, `app/controllers/public_gallery_lightbox.php`, `app/controllers/public_gallery_page.php`, `app/controllers/smart_galleries.php`, `app/services/public_gallery_media_manifest.php`, and `app/services/thumbnail_bundles.php`.
  - Updated `scripts/deploy.ps1` and `scripts/deploy.sh` to exclude disposable LaTeX build intermediates from release packages.
  - Required production hosts to keep `display_errors` disabled so PHP cannot print runtime details before the bounded emergency handler responds.

  #### Database

  - Added no migration and made no schema or stored-data change in Version 0.94.1.

  #### Frontend

  - Added no JavaScript or CSS dependency and preserved existing gallery, lightbox, and Admin interactions.

  #### Tests

  - Added `tests/version_094_audit_hardening.php` with focused contracts for rewrite protection, early `500` semantics, JSON failures, streaming safety, and activation-gate recovery.
  - Added harmless runtime fixtures under `tests/fixtures/` for uncaught exceptions, PDO-style failures, missing requirements, fatal shutdown, JSON errors, conditional files, and already-committed streaming responses.
  - Added `tests/public_media_version_routing_test.php` for stable media revision identities, canonical/legacy payload equivalence, private-cache policy, and revision changes when media identity changes.
  - Extended `tests/deploy_app_packaging_test.php` to enforce the LaTeX-intermediate exclusion policy in both deployment helpers.

  ### User Impact

  #### For visitors

  - Reduced the chance of seeing a partially activated release and made public original-image caching safe across media replacements.
  - Preserved existing public URLs, gallery navigation, thumbnails, originals, and lightbox behavior.

  #### For administrators

  - Improved update recovery semantics without changing the normal update workflow.
  - Required no database migration or content rewrite; production hosting should be verified with `display_errors=0` before deployment.

## Version 0.94

Version 0.94 improves gallery descriptions with safe, recognizable external links. Administrators can use Markdown or compact link tags, visitors see bundled brand symbols for well-known services and locally cached favicons for other public sites, and the complete retrieval path remains bounded and isolated from public-page rendering.

  ### Highlights

  #### Gallery-description links

  - Added `[link=URL]label[/link]`, `[url=URL]label[/url]`, `[link]URL[/link]`, and `[url]URL[/url]` alongside existing Markdown links.
  - Restricted rendered targets to normalized HTTP or HTTPS addresses, including convenient `www.` input, while leaving unsupported or executable schemes as inert text.
  - Opened external targets in a new tab with `noopener` and `noreferrer` protection.
  - Added bundled local brand symbols for YouTube, Facebook, X/Twitter, Instagram, Wikipedia, LinkedIn, GitHub, Reddit, TikTok, Discord, Twitch, and Vimeo, with exact-domain or subdomain matching.
  - Added locally cached favicons for other public websites; links remain fully usable without an icon when discovery, storage, or schema support is unavailable.

  #### Bounded favicon discovery

  - Triggered favicon refresh only after an administrator saves a gallery, never during anonymous public rendering.
  - Deduplicated discovery by hostname across source and translated gallery descriptions and limited new network work per save.
  - Blocked loopback, private, reserved, and otherwise non-public IPv4 destinations before connecting, while preserving host and TLS verification for approved public targets.
  - Bounded redirects, response headers, HTML bytes, image bytes, image dimensions, request duration, and total save-time network work.
  - Validated downloaded PNG, JPEG, GIF, WebP, and ICO content by file signature and image structure instead of trusting remote content-type claims; SVG and active content are not cached.
  - Added retry windows for successful, missing, failed, and blocked results and retained a previous known-good icon through temporary refresh failures.

  ### Technical Details

  #### Backend and public delivery

  - Added `app/services/link_favicons.php` for URL normalization, brand matching, description-link extraction, bounded fetching, cache persistence, and validated public asset resolution.
  - Added the `link_favicon_asset` route in `app/bootstrap/routing.php`, `app/bootstrap/dispatch.php`, and `app/bootstrap/request.php` for installations whose public document root cannot serve the gallery cache directly.
  - Added cacheable, type-specific favicon responses in `app/controllers/theme_assets.php` and registered the route with `app/services/seo_request_guard.php`.
  - Updated `app/controllers/admin_galleries_edit_actions.php` so a successful gallery save performs best-effort favicon refresh without allowing cosmetic failures to fail the gallery mutation.

  #### Database and storage

  - Added migration `database/migrations/202608300001_link_favicon_cache.php` with the hostname-keyed `link_favicon_cache` table.
  - Stored bounded status, local filename, MIME type, source URL, content hash, fetch time, last-attempt time, retry time, and update time; no remote response body, credential, cookie, or request header is stored in the database.
  - Stored validated icon files under the internal gallery cache and exposed only filenames matching the generated hash-based format.
  - Kept favicon behavior optional when the migration has not yet been applied: gallery links still render, no outbound refresh is attempted, and no persistent write is authorized from an unavailable cache table.

  #### Frontend and assets

  - Updated `app/views/gallery_descriptions.php` to render the supported link syntaxes through one escaped URL and anchor pipeline.
  - Added the local symbol sprite in `public/assets/link-icons/brands.svg`, its license and usage documentation, and framework-free icon styling in `public/assets/styles/utilities.css`.

  #### Schema policy, documentation, and tests

  - Added the named `presentation.link_favicon_cache` three-state capability so cache reads and writes require verified schema, confirmed missing storage follows the no-icon compatibility path, and unknown inspection state omits the cosmetic operation with bounded diagnostics rather than acting as proof of absence.
  - Added `tests/link_favicon_model_test.php` for URL-scheme rejection, exact-domain brand matching, supported markup extraction, relative favicon resolution, image validation, and the structured schema-policy boundary.
  - Updated runtime/version metadata, README, database documentation, release metadata, and the permanent administrator manual; rebuilt the indexed PDF for Version 0.94.

  ### Upgrade and User Impact

  #### For visitors

  - Gallery-description links are easier to recognize and continue to work when icons are unavailable.
  - Public page requests use only local database and filesystem state for icons and do not contact linked third-party sites.

  #### For administrators

  - Run the normal updater or `php scripts/migrate.php` so `202608300001_link_favicon_cache.php` can create the optional favicon metadata table.
  - Saving a gallery can briefly contact previously uncached public link hosts within the bounded fetch budget; known services use bundled icons and require no remote lookup.
  - No source photographs, existing gallery descriptions, thumbnails, access rules, or viewer data are migrated or rewritten by Version 0.94.

## Version 0.93.2

Version 0.93.2 is a focused media-renaming and Windows traffic-diagnostics refinement on top of Version 0.93.1. It makes automatic media names follow the current gallery-title context while preserving explicit physical-path naming, and adds a durable grouped HTML anomaly report that makes monitor findings easier to review without changing the monitor's safe read-only behavior.

  ### Highlights

  #### Gallery-aware automatic media naming

  - Updated the default media-renamer pattern to use the normalized `{gallery_context}` value, so automatically generated filenames reflect the current gallery title when it is available.
  - Kept parent folder segments in the automatic context while replacing only the current gallery leaf with its display title, making later gallery-title changes visible to availability checks and dry runs.
  - Added the separate `{gallery_path}` placeholder for the normalized physical folder hierarchy, preserving explicit patterns that need the actual on-disk path rather than the display context.
  - Kept existing `{gallery_title}`, `{photo_title}`, sequence, original-name, and image-ID placeholders compatible; the new context is opt-in for custom patterns and the default pattern only.
  - Preserved collision checks, safe slugification, extension handling, dry-run behavior, and source-file safety during renaming.

  #### Windows monitor anomaly reporting

  - Added a standalone `anomalies/report.html` report to each monitor run, containing only primary anomalies instead of cluttering the report with ordinary successful requests or diagnostic probes.
  - Grouped forced-address probes and immediate anomaly rechecks beneath their originating request, including compact outcome, delay, HTTP, TLS, timeout, reset, and slow-request context.
  - Added readable classification for transport failures, generic Apache 404 responses, static-asset failures, slow TTFB, and slow total request time while retaining the underlying JSONL, CSV, and event-log records.
  - Preserved bounded, deployment-safe diagnostics: the monitor remains GET-only and read-only, keeps Host/SNI behavior for address probes, and continues to avoid credentials, tokens, request bodies, and unbounded report content.

  ### Technical Details

  #### Backend and media renaming

  - Updated `app/services/media_renamer.php` with separate gallery-context and physical-path normalization helpers and the `{gallery_context}` replacement.
  - Kept `{gallery_path}` mapped to the normalized physical gallery hierarchy so existing explicit custom patterns retain their documented meaning.

  #### Windows tooling

  - Updated `winapp/gallery_http_monitor.py` to version `1.2.1` and added grouped anomaly-report generation through `RunLogger.write_anomaly_report()`.
  - Kept report values bounded and human-readable without exposing raw private paths, credentials, cookies, or tokens.

  #### Documentation, metadata, and tests

  - Updated `app/bootstrap.php`, `README.md`, `release-metadata.json`, and the administrator manual to Version 0.93.2.
  - Rebuilt the indexed `docs/PHP_Gallery_Manual.pdf` and regenerated `app/core-manifest.json` after the final release edits.
  - Retained the focused media-renamer and monitor regression coverage and verified the complete PHP suite, syntax checks, manifest freshness, and documentation build.

  ### Upgrade and User Impact

  #### For administrators

  - Automatic media renaming now follows a renamed gallery's display title in the default naming context; custom patterns using `{gallery_path}` continue to use the physical hierarchy.
  - Windows monitor runs now provide a compact HTML anomaly summary for quick review alongside the existing detailed machine-readable records.
  - No database migration, source-photo migration, or generated-thumbnail rebuild is required for Version 0.93.2.

  #### Compatibility and safety

  - Existing media-renamer collision, authorization, mutation, and source-file safety rules remain unchanged.
  - The monitor remains read-only and deployment-safe, and normal updater/migration workflows remain the supported upgrade path.

## Version 0.93.1

Version 0.93.1 is a focused progressive-thumbnail and Windows traffic-diagnostics patch on top of Version 0.93. It preserves the complete Version 0.93 performance, updater, uploader, viewer, Smart Gallery, zoom, protected-media, and deployment foundation.

  ### Highlights

  #### Progressive thumbnail correction

  - Corrected progressive thumbnail dimension detection and candidate-size computation across the server rendering model and browser upgrade path.
  - Kept progressive and responsive renderer identifiers, cache-busting, diagnostics, access checks, semantic markup, and no-JavaScript behavior unchanged.
  - Updated gallery lifecycle integrations so detected and promoted dimensions remain consistent during progressive loading.

  #### Windows HTTP traffic monitor

  - Added configurable short, medium, and long idle schedules for repeatable traffic diagnosis.
  - Added sentinel/discovered-page cold and warm comparisons, per-address validation with Host/SNI preservation, richer 404/SEO-guard classification, and protocol-aware curl snapshots for available HTTP versions.
  - Added bounded result retention, incremental durable reports, and consistent live ZIP creation without retaining full request bodies in memory.
  - Kept the monitor read-only: it generates only safe GET requests, does not import credentials, and redacts cookie/token values in diagnostics.

  #### Deployment safety

  - Updated PowerShell and shell deployment helpers to exclude tests, monitor logs, Python caches, `.pyc` files, and runtime/user data while preserving required protection files.
  - Retained explicit manifest generation and verification as a release requirement.

  ### Upgrade and release details

  - No database migration is required for Version 0.93.1.
  - No source photographs, generated derivatives, viewer preferences, or account data are changed by this patch.
  - Updated runtime/version metadata, README, architecture/database/testing documentation, administrator manual and PDF, patch notes, and the core integrity manifest.
  - Verified progressive renderer contracts, the complete PHP regression suite, focused benchmark/runtime checks, syntax validation, manifest generation/checking, and `git diff --check`.

## Version 0.93

Version 0.93 is an operational performance, reliability, diagnostics, and Windows uploader release following Version 0.92.3. It reduces avoidable request work while preserving the existing security, access-control, mutation, viewer, Smart Gallery, thumbnail, and updater contracts.

  ### Highlights

  #### TTFB, caching, and concurrency

  - Added bounded request-trigger scheduling and clearer runtime diagnostics so background maintenance does not consume an unbounded portion of a normal request.
  - Added request-local database query caching and improved cache invalidation around public media deletion and thumbnail/source lookups.
  - Hardened public media concurrency/session release behavior and protected clean public-home URL routing.
  - Improved upload inventory confirmation, automatic media-renamer selection, and maintenance/archive operations.

  #### Updater and operational diagnostics

  - Strengthened autoupdate request-latency handling, resumable updater state, filesystem activation safety, and migration/schema-cache boundaries.
  - Added Admin test-run workflows, analysis, diagnostics, and browser controls for repeatable operational verification.
  - Added focused regression coverage for request budgets, database caching, public-media concurrency, clean URLs, archive maintenance, upload automation, and updater behavior.

  #### Windows uploader redesign

  - Redesigned the Windows companion uploader around clearer import, watch-folder, activity, settings, and optional AI workflows.
  - Added modular configuration, discovery, media-capability, diagnostics, state-store, and job-model services while retaining the existing `.pyw` launcher and installation path.
  - Improved ZIP/file discovery, progress and recovery behavior, HEIC/HEIF/DNG capability reporting, local thumbnail fallback, tray lifecycle, and worker isolation.
  - Preserved gallery-scoped API-key behavior, server-authoritative validation, duplicate confirmation, SimConnect metadata, optional AI processing, and source-file safety.

  ### Upgrade and release details

  - No source photographs, generated derivatives, or viewer personal data are migrated by this release.
  - Existing updater and migration workflows remain the supported upgrade path; release files retain their current schema-inspection and mutation-safety policies.
  - Updated runtime/version metadata, README, architecture/database/testing documentation, administrator manual and PDF, translations, Admin diagnostics, and the core integrity manifest.
  - Removed the temporary Windows watcher design brief before release preparation; temporary feature briefs must not ship in release packages.
  - Verified the complete PHP regression suite, focused runtime/upload/media tests, Windows uploader tests, syntax checks, manifest generation/checking, and `git diff --check`.

## Version 0.92.3

Version 0.92.3 makes progressive thumbnail sharpening the default public photo-card renderer. It is a compatibility-aware presentation release on top of Version 0.92.2: the responsive renderer remains permanently supported, explicit existing selections are preserved, and the complete viewer-account, collection-sharing, benchmark, lightbox, Smart Gallery, and protected-media behavior remains intact.

  ### Highlights

  #### Progressive renderer default

  - Changed the normalized public thumbnail rendering default from `responsive` to `progressive`.
  - Kept `responsive` and `progressive` as the only permanent machine/architecture values; no temporary or replacement renderer identifier was introduced.
  - Preserved the progressive pipeline’s server-rendered small-image fallback, bounded near-viewport activation, responsive layout, useful alt text, access checks, and no-JavaScript behavior.
  - Kept the responsive renderer available as the compatibility/legacy option for installations that prefer complete server-rendered candidate sets.

  #### Settings, migration, and diagnostics

  - Added idempotent migration `202608200002_public_thumbnail_progressive_default.php` to establish progressive as the default without overwriting explicit stored choices.
  - Updated Admin Theme and Settings labels, help text, normalization, Smart Gallery presentation contracts, diagnostics, and tests so the default/legacy wording is consistent everywhere.
  - Updated cache-busted progressive-renderer and diagnostics modules and refreshed the core integrity manifest.
  - Preserved both supported renderers’ gallery/password/NSFW/media authorization, semantic markup, and browser lifecycle contracts.

  ### Upgrade and release details

  - Existing installations should run the normal updater so the default-setting migration is applied. Existing explicit renderer settings remain unchanged.
  - No source photographs or generated thumbnails are moved or rebuilt solely because the default changes.
  - Updated `AGENTS.md`, README, architecture, database, testing, Admin settings inventory, and the administrator manual; rebuilt the indexed manual PDF for Version 0.92.3.
  - Regenerated and verified `app/core-manifest.json` after all final source and documentation edits.
  - Verified renderer normalization, migration consistency, Smart Gallery presentation, Admin settings, the complete PHP suite, syntax checks, and `git diff --check`.

## Version 0.92.2

Version 0.92.2 is a focused lightbox reliability patch on top of Version 0.92.1. It addresses the final resource-ownership edges found during rapid navigation and repeated close/reopen cycles while preserving the complete Version 0.92 viewer-account, collection-sharing, benchmark, zoom, Smart Gallery, and protected-media foundation.

  ### Highlights

  #### Detached image and cache lifecycle

  - Hardened ownership tracking for detached image loads so a request that no longer belongs to the active photograph is aborted or ignored safely.
  - Hardened decoded-image cache insertion and eviction so late decodes cannot repopulate a newer photo’s cache state after navigation or teardown.
  - Invalidated stale preload generations consistently during rapid previous/next movement, slideshow transitions, close/reopen cycles, and lightbox destruction.
  - Ensured preload queues, abort controllers, event callbacks, timers, and lifecycle registries are released together instead of leaving detached work behind.
  - Preserved active-photo identity, metadata, favourite state, zoom/pan state, fullscreen/map state, and progressive-quality promotion while asynchronous work completes.

  #### Compatibility and safety

  - Kept the existing protected preview/full-media authorization pipeline, private/no-store behavior, Smart Gallery access intersection, and public-media session-release guarantees unchanged.
  - Kept normal gallery, Smart Gallery, slideshow, fullscreen, map, touch, keyboard, zoom, and no-JavaScript behavior compatible.
  - Added focused metadata and resource lifecycle regression coverage for stale work, detached loads, cache cleanup, preload invalidation, and teardown/reopen scenarios.
  - No database migration or filesystem change is required; Version 0.92.1 installations can use the normal updater.

  ### Release artifacts

  - Updated runtime version, README, architecture/database/testing references, administrator manual, and release metadata to Version 0.92.2.
  - Rebuilt the indexed manual PDF and regenerated `app/core-manifest.json` after the final source and documentation edits.
  - Verified the complete PHP suite, lightbox lifecycle contracts, benchmark runtime scope, syntax checks, manifest freshness, and `git diff --check`.

## Version 0.92.1

Version 0.92.1 is a focused observability and performance refinement on top of the complete Version 0.92 viewer-account and lightbox release. It adds a bounded, repeatable public-gallery benchmark and extends protected-media diagnostics while preserving viewer privacy, gallery authorization, and shared-hosting safety.

  ### Highlights

  #### Public gallery benchmark

  - Added an administrator-only benchmark workflow for measuring a public gallery through isolated anonymous previews rather than an authenticated Admin page.
  - Added bounded browser-driven runs with explicit start, progress, completion, cancellation, expiry, and failure states so a benchmark cannot become an unbounded background job.
  - Added server render counters, request timing, browser navigation timing, cache status, response/transfer measurements, resource timing, decode timing, and lightbox lifecycle observations where the browser exposes them.
  - Kept benchmark state session-scoped and automatically expiring; it is not visitor telemetry, an account preference, or a permanent report file.
  - Added a static same-origin probe and cache-aware controls so repeated comparisons can distinguish server rendering, browser cache, protected media, and public derivative behavior.
  - Added clear Admin result summaries and JSON export suitable for before/after comparisons on ordinary shared hosting.

  #### Protected media and lightbox diagnostics

  - Extended authorized protected-media diagnostics for benchmark requests without exposing private image paths, raw credentials, or unauthorized media URLs.
  - Preserved gallery visibility, password/share, NSFW, conditional-request, and media authorization checks before any benchmark response or session-lock release.
  - Retained bounded lightbox preview caching, detached-request cancellation, stale-generation rejection, failed-entry cleanup, and controlled slideshow preloading from Version 0.92.
  - Kept the active photo, favourite state, metadata lifecycle, fullscreen/map state, and navigation controls stable while asynchronous benchmark or lightbox work completes.

  ### Technical and release details

  - Updated gallery benchmark services/controllers, public-media diagnostics, lightbox lifecycle code, and cache-busting asset revisions.
  - Added focused PHP and browser/static benchmark contracts, media-diagnostics coverage, metadata/resource lifecycle tests, and Smart Gallery public-contract regression updates.
  - Removed stale generated benchmark snapshots from tracked runtime data; new benchmark results remain runtime data rather than release source.
  - Updated the README, architecture, database, testing guide, administrator manual, and rebuilt the indexed manual PDF for Version 0.92.1.
  - Regenerated and verified `app/core-manifest.json` after all source and documentation edits.
  - No database migration is required for this release.

## Version 0.92

Version 0.92 introduces the invite-only multi-user viewer system and a substantial resource-lifecycle hardening pass for the lightbox and protected media. Administrators can manage viewer accounts and invitations while viewers receive a separate, privacy-preserving account boundary for favourites, private collections, and controlled unlisted sharing. The viewer feature is disabled by default and does not grant access to protected galleries.

  ### Highlights

  #### Viewer account administration

  - Added a disabled-by-default **Viewer accounts** master feature switch under Admin > Features.
  - Added administrator-created viewer accounts with generated or administrator-supplied temporary passwords and forced first-login password replacement.
  - Added administrator invitation creation, listing, resend, revoke, and expiry workflows without pre-creating the recipient account.
  - Added viewer suspension, restoration, and sign-out-everywhere controls that rotate viewer security authority without deleting favourites or private collections.
  - Kept viewer identity separate from administrator identity and prevented viewer authentication from granting protected-gallery access.

  #### Verified viewer authentication and lifecycle

  - Added invite-only email verification and activation with scanner-safe responses, one-time verification tokens, expiry handling, resend cooldowns, and a minimum 15-character viewer password.
  - Added dedicated viewer login/logout and rotating viewer remember-me credentials separate from administrator login persistence.
  - Added generic password recovery through the configured bounded mail transport, with token expiry, one-time use, and persistent-session revocation.
  - Added authenticated viewer password changes, staged email changes with verification, account deletion, and recent-password reauthentication for sensitive operations.
  - Added private no-store account responses and viewer-only lifecycle boundaries suitable for shared-hosting session behavior.

  #### Favourites, collections, and sharing

  - Added authenticated viewer favourites on authorized gallery cards and lightbox images, plus a private favourites page.
  - Added private viewer collections with create, rename, delete, ordering, and browse workflows.
  - Added one revocable 30-day unlisted read-only collection share per owned collection.
  - Removed the displayed share secret after exchange into a narrow session grant and revalidated source-gallery/media authorization for every rendered item.
  - Kept favourites, collection references, and share grants from bypassing gallery passwords, visibility, NSFW rules, or administrator authorization boundaries.

  #### Anti-automation and abuse controls

  - Added adaptive registration/login/recovery rate limits, trusted-client handling, challenge escalation, and bounded mail-abuse protection.
  - Added browser challenge support and localized security messages without exposing account existence through public responses.
  - Added security-event and maintenance foundations for viewer lifecycle cleanup and authority revocation.

  #### Lightbox caching and resource lifecycle

  - Reduced the decoded lightbox preview neighbourhood from 48 images to 12 so long galleries do not retain an unnecessarily large set of decoded bitmaps in browser memory.
  - Added ownership tracking for detached image requests and aborts unfinished preview, nearby-image, and slideshow loads when the lightbox closes, navigates, or is torn down.
  - Invalidated stale preload generations, removed failed or aborted cache entries, and prevented late decodes from repopulating a newer photo's state.
  - Kept slideshow preloading bounded to the next authorized photograph, rejected duplicate and stale preload work, and preserved the active timer, transition, navigation, fullscreen state, and controls.
  - Preserved the active viewer favourite state when asynchronous lightbox metadata updates complete, preventing a late response from overwriting the current card state.
  - Cleaned up public-media image-load handlers and registries when work is cancelled so obsolete browser requests and callbacks can be released promptly.

  #### Protected media session and cache behavior

  - Added a protected-media session-lock release after authorization and cache-policy decisions for thumbnail, media, cover, and branding responses, allowing concurrent requests to proceed on slower or session-locked hosting.
  - Kept gallery, password, visibility, NSFW, conditional-request, and media authorization checks ahead of session release; releasing the lock does not weaken access control.
  - Preserved private/no-store cache behavior for access-sensitive responses and immutable public caching only for safe public derivatives.

  ### Technical Details

  #### Backend and database

  - Added viewer account, authentication, lifecycle, token, rate-limit, mail, security-event, collection, favourite, and sharing services under `app/services/`.
  - Added viewer account, invitation, authentication, lifecycle, and verification-token migrations under `database/migrations/`.
  - Added route and dispatch integration for viewer registration, verification, login, recovery, account lifecycle, favourites, collections, and collection sharing.
  - Preserved schema-aware fail-closed behavior for required viewer writes and kept the master feature wrapper dormant when disabled.

  #### Frontend and localization

  - Added viewer account and collection UI integration to the public layout and lightbox/gallery surfaces.
  - Added browser anti-automation challenge behavior and cache-busted public viewer assets.
  - Added maintained English, Czech, German, and Swedish strings for viewer authentication, registration, recovery, account security, favourites, collections, invitations, and sharing.
  - Updated `README.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `TESTING.md`, and `docs/VIEWER_SECURITY_FOUNDATIONS.md` with the multi-user security contracts.

  #### Tests and release artifacts

  - Added focused viewer foundation, authentication, lifecycle, invitation, HTTP, verification-resend, anti-automation, security-operation, collection, favourite, sharing, and MySQL concurrency regression tests.
  - Updated `docs/PHP_Gallery_Manual.tex` and rebuilt the indexed `docs/PHP_Gallery_Manual.pdf` for Version 0.92, including the lightbox cache and protected-media session lifecycle contracts.
  - Updated `app/controllers/public_media.php` and `public/assets/gallery-modules/lightbox.js` for bounded caching, cancellation, stale-generation protection, and session-lock release.
  - Regenerated and verified `app/core-manifest.json` after the final runtime, asset, migration, and documentation changes.

  ### User Impact

  #### For visitors

  - Invitees can activate a verified viewer account, sign in separately from administrators, recover access safely, save favourites, and maintain private image collections.
  - Collection owners can share one revocable unlisted read-only link for up to 30 days without exposing a public profile or collection directory.
  - Shared and saved image references continue to obey the current visitor's gallery, media, password, visibility, and NSFW authorization.

  #### For administrators

  - Viewer accounts remain off until explicitly enabled, and the enabled mode is invite-only.
  - Administrators can provision, invite, suspend, restore, revoke, and force-sign-out viewer identities from the dedicated account controls.
  - No existing gallery access rules, administrator accounts, source files, or public media permissions are changed by enabling the viewer subsystem.

  #### For gallery performance

  - Long galleries retain fewer decoded previews, and obsolete image work is cancelled during rapid navigation or closing.
  - Slideshow and fullscreen transitions no longer depend on stale preload callbacks, while protected media requests do not hold the PHP session lock after their security decisions are complete.

## Version 0.91.3

Version 0.91.3 is a focused lightbox reliability release. It improves slideshow preloading and fullscreen transitions while preserving the existing zoom, navigation, access-control, responsive, and no-JavaScript behavior.

  ### Highlights

  #### Stable slideshow preloading

  - Updated slideshow playback to preload the next authorized photograph without interrupting the active image or its transition timing.
  - Prevented duplicate preload requests and rejected stale preload completions after navigation, closing, reopening, or changing slideshow ownership.
  - Kept the active image, slideshow timer, navigation position, loading indicators, and viewer controls synchronized while the next photograph is prepared.

  #### Fullscreen transition reliability

  - Preserved slideshow state and active-photo identity when entering or leaving fullscreen.
  - Prevented fullscreen changes from exposing a stale preloaded image or resetting the current slideshow transition unexpectedly.
  - Retained existing zoom, pan, map, voting, keyboard, responsive, protected-media, and no-JavaScript behavior.

  ### Technical Details

  #### Frontend and runtime metadata

  - Updated `public/assets/gallery-modules/lightbox.js` with bounded slideshow-preload ownership, stale lifecycle protection, and fullscreen-safe transition handling.
  - Updated `public/assets/styles/lightbox.css` for the corrected slideshow/fullscreen presentation state.
  - Updated `app/bootstrap.php` to runtime version `0.91.3`.
  - Updated `release-metadata.json` with the `v_0.91.3` release entry.
  - Regenerated `app/core-manifest.json` after the final runtime and asset changes.

  #### Tests

  - Added `tests/lightbox_slideshow_preload_test.php` for preload ownership, duplicate/stale work rejection, slideshow timing, and fullscreen transition contracts.
  - Ran the complete PHP regression suite, focused lightbox tests, syntax checks, manifest checks, and `git diff --check`.

  ### User Impact

  #### For visitors

  - Slideshow transitions remain smooth while the next photograph is prepared in the background.
  - Entering or leaving fullscreen no longer disrupts the active slideshow state or displays stale preloaded content.

  #### For administrators

  - No database migration or configuration change is required.
  - The normal updater can install the release, and existing galleries, media, access rules, and generated files remain compatible.

## Version 0.91.2

Version 0.91.2 is the complete consolidated release of the Version 0.91 and 0.91.1 viewer and Smart Gallery work. It is intentionally documented as a full release handoff so the public zoom, progressive image quality, Smart Gallery visibility, presentation, placement, cycle safety, and navigation changes are all represented together.

  ### Highlights

  #### Smart Galleries for public viewers

  - Published and enabled Smart Galleries can appear to anonymous viewers when attached to an accessible public gallery.
  - Public Smart Gallery routes, navigation entries, direct links, downloads, SEO guards, and no-JavaScript fallbacks use the same centralized access and visibility policies as normal galleries.
  - Matched results are intersected with the current viewer’s permissions, including private galleries, password/share protection, hidden media, NSFW protection, and image-level authorization. Protected images and counts are not leaked.
  - Smart Galleries reuse normal cards, thumbnails, metadata, pagination, responsive rendering, and lightbox infrastructure.

  #### Configurable Smart Gallery presentation

  - Added per-Smart-Gallery presentation configuration for supported gallery layout, rows/page size, thumbnail size and quality, renderer selection, spacing, metadata visibility, and related display controls.
  - Added canonical normalization and safe backward-compatible defaults for missing or malformed values.
  - Added Admin and side-panel controls, localized labels, effective rendering support, documentation, and migrations without copying images or moving source files.

  #### Placement and ordering

  - Multiple Smart Galleries can be attached to the same parent gallery.
  - Each attachment can render above the normal gallery content or below it, with bottom placement preserved as the default.
  - Top and bottom attachments have independent deterministic ordering with stable tie-breaking.
  - Admin controls support attachment management, placement, ordering, duplicate prevention, and safe in-place side-panel updates.

  #### Cycle safety

  - Direct self-attachments and indirect Smart Gallery/gallery cycles are rejected server-side.
  - Runtime visited-node tracking, recursion depth limits, expanded-node/result bounds, and deduplication protect public requests from legacy or malformed loops.
  - Invalid existing relationships terminate safely and remain diagnosable and repairable in Admin.

  #### Viewer zoom, quality, and navigation

  - Retained the Version 0.91 accessible 100%–400% zoom system with toolbar, keyboard, wheel/trackpad, pointer, touch pinch, bounded pan, fullscreen, map, slideshow, and reduced-motion behavior.
  - Retained demand-driven promotion from authorized previews to sharper browser-displayable sources, active-photo-only loading, translated progress feedback, immediate compositor repaint, stale lifecycle rejection, and no raw-file URL exposure.
  - Ordinary Left/Right arrows move one photograph; Shift+Left/Right moves ten photographs in the current ordered result set.
  - Corrected backward ten-photo modular navigation at the beginning of a gallery and updated translated keyboard-help text in all maintained catalogs.

  ### Upgrade and verification

  - Existing installations must run the normal updater to apply the Smart Gallery presentation and attachment-order migrations. No source files are moved or duplicated.
  - Updated runtime version, release metadata, README, architecture/database/testing references, Smart Gallery documentation, and the administrator manual.
  - Rebuilt the indexed 0.91.2 manual PDF and regenerated the 388-file `app/core-manifest.json` after final edits.
  - Verified the complete PHP regression suite, focused Smart Gallery and lightbox/browser tests, syntax validation, translation consistency, migration checks, manifest freshness, and `git diff --check`.

## Version 0.91.1

Version 0.91.1 is a Smart Gallery hardening and presentation release built on the Version 0.91 zoom and progressive image-quality foundation. It makes published Smart Galleries behave like genuine public gallery destinations, adds configurable presentation and placement controls, hardens recursive relationships, and improves rapid lightbox navigation.

  ### Highlights

  #### Public Smart Gallery behavior

  - Published and enabled Smart Galleries can now appear to anonymous viewers when attached to an accessible public gallery, instead of being visible only to administrators.
  - Public rendering applies the same centralized visibility, publication, parent-gallery, share/password, NSFW, and image-level access rules as normal galleries. Protected images and protected result counts are not leaked through a public Smart Gallery.
  - Smart Gallery public routes, navigation entries, attachment locations, download behavior, SEO guards, and no-JavaScript links now use the public access contract consistently.
  - Smart Gallery result rendering reuses the normal gallery card, thumbnail, metadata, lightbox, responsive, and pagination infrastructure rather than creating a parallel gallery pipeline.

  #### Configurable Smart Gallery presentation

  - Added per-Smart-Gallery presentation settings for layout columns, rows/page size, thumbnail sizing and quality, renderer selection, spacing, metadata visibility, and related gallery display options supported by the existing architecture.
  - Added canonical normalization and safe defaults so missing or malformed presentation values remain compatible with existing Smart Galleries.
  - Added Admin editor controls, side-panel integration, localized labels, live/effective rendering support, and documentation for Smart Gallery presentation configuration.
  - Added migration support for presentation data without duplicating image records or changing the filesystem. Existing Smart Galleries retain their prior appearance and behavior by default.

  #### Safe attachment placement and ordering

  - A parent gallery can now contain multiple Smart Galleries in deterministic order.
  - Each attachment can be placed above the normal gallery content or below it; bottom placement remains the default for existing attachments.
  - Ordering is stored per parent attachment and is independent for top and bottom groups, with stable tie-breaking for equal order values.
  - Added Admin controls and translated validation for placement, ordering, duplicate attachments, and attachment management.

  #### Cycle prevention and bounded evaluation

  - Added server-side rejection of direct self-attachments and indirect Smart Gallery/gallery cycles.
  - Added runtime visited-node tracking, recursion depth limits, expanded-node/result bounds, and deduplication so legacy or malformed relationships cannot render indefinitely or exhaust the request.
  - Added safe diagnostics and repair-oriented Admin behavior for invalid existing relationships without changing unrelated normal galleries.

  #### Faster lightbox navigation

  - Added `Shift+Left` and `Shift+Right` shortcuts to move backward or forward by ten photographs in the current lightbox result set.
  - Kept ordinary `Left` and `Right` arrows as single-photo navigation, so existing workflows remain unchanged.
  - Updated the translated lightbox keyboard-help text in English, Czech, German, and Swedish so the shortcut is discoverable.
  - Corrected modular index handling for backward ten-photo movement, including wraparound at the beginning of a result set.
  - Preserved Smart Gallery ordering, pagination, access filtering, zoom state, progressive quality lifecycle, fullscreen/map behavior, slideshow behavior, and stale-navigation protections while adding the shortcut.

  ### Compatibility and release verification

  - Added idempotent migrations for Smart Gallery presentation settings and per-parent attachment placement/order. Existing installations must run the normal migration updater; no image files are moved or duplicated.
  - Updated `CMS_VERSION`, release metadata, README, architecture/database/testing references, Smart Gallery documentation, and the administrator manual to Version 0.91.1.
  - Rebuilt the indexed manual PDF and regenerated `app/core-manifest.json` after the final source and documentation edits.
  - Verified the full PHP regression suite, focused lightbox/browser tests, syntax validation, translation consistency, manifest freshness, and `git diff --check`.

## Version 0.91

Version 0.91 introduces a complete public lightbox zoom and progressive image-quality release. Visitors can inspect photographs with accessible controls, keyboard shortcuts, wheel and trackpad input, pointer dragging, and touch pinch gestures while the existing gallery, fullscreen, map, voting, access-control, responsive, and no-JavaScript behavior remains intact. The active photograph can transparently upgrade from a protected preview to a sharper browser-displayable source when the viewport and zoom level require more pixels.

  ### Highlights

  #### Accessible lightbox zoom and navigation

  - Added bounded 100%–400% zoom with 25% steps, visible percentage/reset state, disabled limits, keyboard shortcuts (`+`, `=`, `-`, `_`, and `0`), and synchronized normal/fullscreen controls.
  - Added pointer-aware wheel and trackpad zoom, anchor-preserving transforms, bounded panning, two-pointer touch pinch, post-zoom one-finger panning, and preserved one-finger mobile photo swiping at 100%.
  - Preserved the stage as the single semantic viewer surface, including keyboard focus, visible focus behavior, browser Ctrl/Command page zoom, map controls, voting controls, metadata, slideshow, picture strip, 3D carousel, and responsive safe-area layouts.
  - Reset zoom state predictably when navigating, starting a slideshow, closing, or reopening; fullscreen and map-pane changes preserve the current scale while reclamping translation to the new viewport.

  #### Progressive image quality

  - Added demand-driven quality selection from the existing protected preview and browser-displayable full-media routes. The browser considers contained CSS width, zoom scale, bounded device density, and conservative rendering headroom.
  - Upgraded only the active photograph, without eagerly downloading full sources for adjacent images or constructing raw-file URLs. Existing gallery, Smart Gallery, private-gallery, share, NSFW, and media authorization checks remain authoritative.
  - Added translated loading feedback with a compact activity ring, `aria-busy`, polite announcements, pointer-transparent presentation, and reduced-motion behavior while a sharper source transfers and decodes.
  - Preserved scale, pan, alt text, focus, URL/history, fullscreen state, and active-photo identity during promotion. Repeated previous/next navigation and close/reopen cycles receive independent quality lifecycles; stale callbacks and late decodes cannot overwrite another photograph.
  - Rebuilt the decoded image compositor surface after promotion so sharper detail appears in the current lightbox immediately without requiring a fullscreen toggle.

  ### Technical Details

  #### Backend and presentation metadata

  - Added `public_render` quality-candidate metadata through `app/services/thumbnail_bundles.php`, including validated source URLs and bounded effective dimensions.
  - Updated `app/controllers/public_gallery_lightbox.php`, `app/controllers/gallery_lightbox.php`, `app/controllers/public_gallery_page.php`, and Smart Gallery rendering to expose the same authorized candidates for server-rendered and lazy lightbox cards.
  - Preserved existing media URL generation and access preflight; no database migration or filesystem movement is required for zoom quality promotion.

  #### Frontend and styling

  - Added `public/assets/gallery-modules/lightbox-zoom-model.js` for pure zoom bounds, anchor math, panning, candidate normalization, demand calculation, and no-downgrade source selection.
  - Extended `public/assets/gallery-modules/lightbox.js` with delegated zoom interactions, fullscreen/map remeasurement, quality scheduling, stale-request cancellation, decoded-image installation, loading feedback, and repeated-photo ownership checks.
  - Updated `public/assets/styles/lightbox.css` and `public/assets/styles/mobile-gallery.css` for clipped transforms, responsive controls, loading feedback, mobile gestures, visible focus, and reduced-motion behavior.
  - Updated public asset cache-busting revisions in `public/assets/gallery.js` and `public/assets/public-gallery.js` so deployed browsers load the release behavior.

  #### Compatibility and translations

  - Kept the existing server-rendered image links and no-JavaScript navigation as the fallback path.
  - Kept zoom state presentation-only: it is not stored in a cookie, account setting, URL, database row, telemetry event, or server-side preference.
  - Added and verified the loading and zoom strings in the maintained English, Czech, German, and Swedish catalogs with safe English fallback.

  #### Tests and release artifacts

  - Added focused contracts covering zoom model math, controls and lifecycle, gesture/event boundaries, translations, quality candidates, rendering metadata, loading indicators, stale-request cancellation, repeated-photo ownership, and access ordering.
  - Verified the complete PHP regression suite, focused Node tests, PHP syntax, JavaScript syntax, function documentation, translation consistency, migration consistency, deployment packaging, updater safety, and `git diff --check`.
  - Updated `README.md`, `ARCHITECTURE.md`, `DATABASE.md`, `TESTING.md`, `TEMP_ZOOM_CONTROLS_FEATURE.MD`, and `docs/PHP_Gallery_Manual.tex`; rebuilt the indexed Version 0.91 PDF manual.
  - Updated runtime version and release metadata to 0.91 and regenerated `app/core-manifest.json` after the final source and documentation edits.

  ### User Impact

  #### For visitors

  - Photographs can be inspected more naturally on desktop and touch devices, with zoom controls that remain usable in normal and fullscreen viewing.
  - Large photographs become sharper in the current viewer as the required detail increases, with visible progress during slower transfers and no forced navigation or fullscreen toggle.
  - Existing public access restrictions, private galleries, shared links, NSFW protections, maps, votes, downloads, pagination, responsive layouts, and no-JavaScript behavior remain unchanged.

  #### For administrators and maintainers

  - No migration is required for the zoom feature; the existing protected preview/media routes and metadata dimensions are reused.
  - Large full-source decodes can consume substantial bandwidth and memory, especially for high-resolution originals; only the active photograph is promoted.
  - Release verification now includes repeated multi-photo promotion, close/reopen cycles, stale decode rejection, and immediate repaint checks.

## Version 0.90

Version 0.90 is a gallery organization, upload convenience, and multilingual-content release. It introduces dynamic Smart Galleries built from secure nested rules, lets browser-assisted uploads consume ordinary photo ZIP exports without server-side archive extraction, and adds optional translated gallery and photo titles/descriptions for every maintained viewer language. Existing physical galleries, source files, access rules, source metadata, and public voting remain authoritative and compatible.

  ### Highlights

  #### Dynamic Smart Galleries

  - Added saved virtual galleries whose membership is evaluated from current image and gallery metadata without copying image records or moving files.
  - Added a non-technical nested rule builder with bounded `AND`, `OR`, and `NOT` groups, validation diagnostics, human-readable summaries, result counts, preview controls, duplication, enable/disable, publication, and deletion workflows.
  - Added filters for physical gallery membership and descendants, direct and inherited tags, capture dates, EXIF and GPS data, titles/descriptions/filenames/searchable text, AI metadata, duplicate state, file characteristics, and private editorial ratings.
  - Added private administrator 0–5-star editorial ratings as a Smart Gallery criterion without changing or exposing public visitor voting.
  - Added deterministic database pagination and sorting, stable query-string and clean URLs, existing public gallery cards and lightbox behavior, and automatic membership changes when source metadata or ratings change.
  - Added unlisted, homepage-root, and physical-subgallery placement modes. One Smart Gallery can appear beneath multiple physical galleries; administrators can manage placements from either side and hide one placement without affecting the others.
  - Added **Save search as Smart Gallery** for compatible public-search state while keeping the structured rule format independent from raw SQL.

  #### Secure public virtual collections

  - Intersected every public Smart Gallery result and count with the existing physical-gallery access policy so private, locked, unpublished, share-only, NSFW-restricted, or otherwise inaccessible source images cannot leak through a published virtual collection.
  - Compiled only server-allowlisted fields and operators into parameterized SQL; submitted values never become SQL identifiers, operators, or fragments.
  - Limited rule depth and condition counts, used stable IDs for gallery/tag references, and made deleted references, malformed JSON, unsupported rule versions, and disabled/private definitions fail safely.
  - Reused existing thumbnail, metadata, voting, lightbox, responsive-layout, clean/query-string routing, and no-JavaScript infrastructure instead of creating a second gallery renderer.

  #### Browser-local ZIP photo import

  - Extended browser-assisted gallery upload inputs to accept user ZIP archives such as iCloud Photos exports.
  - Added local extraction for classic single-disk ZIPs using stored and Deflate compression. Supported JPEG, PNG, WebP, and GIF entries join the ordinary browser preparation and bounded upload-batch pipeline.
  - Kept the selected archive in the browser: the ZIP itself is never posted to PHP, and classic PHP upload remains unchanged.
  - Skipped folders, hidden macOS metadata, unsupported media, encrypted entries, unsupported compression, corrupt payloads, and unsafe paths while validating signatures and CRC values for accepted images.
  - Rejected traversal, malformed boundaries, multi-disk/ZIP64 archives, excessive entry counts, oversized expansion, and suspicious compression ratios before server upload.
  - Added translated extraction progress and actionable failure messages to all maintained Admin catalogs.

  #### Multilingual gallery and photo content

  - Added optional source-language classification and translated title/description variants for galleries and photographs in English, Czech, German, and Swedish.
  - Kept base title and description fields as the compatibility/source representation. Existing content is not reclassified or rewritten during migration.
  - Added compact **Other languages** controls to the existing gallery and photo editors, including dynamically rendered Admin side panels and their in-place AJAX save behavior.
  - Applied the viewer's browser-local language choice to public galleries, subgallery cards, photo cards, direct-photo pages, lazy lightbox payloads, SEO metadata, structured data, accessible alternative text, and public search results.
  - Used independent title/description fallback for galleries while treating a saved translated photo caption as one variant, preventing accidental mixed-language photo captions.
  - Preserved translations and source-language metadata through gallery sidecars and gallery migration packages.
  - Added optional OpenAI translation drafts that populate reviewable editor fields but never publish or save automatically.
  - Kept language selection separate from access control, slugs, filesystem paths, filenames, ordering, visibility, passwords, NSFW policy, and media authorization.

  ### Technical Details

  #### Backend and rule engine

  - Added `app/services/smart_galleries.php` as the centralized versioned rule validator, field/operator registry, parameterized SQL compiler, query/count service, CRUD owner, placement service, readable-summary generator, and search conversion boundary.
  - Added `app/controllers/smart_galleries.php` for Admin management, JSON/AJAX preview and placement actions, public routing, and safe unavailable-state handling.
  - Updated public routing, cards, home/gallery pagination, lightbox loading, search, and Admin gallery editing to reuse Smart Gallery presentation and reverse-placement controls.
  - Added bounded Admin log context for Smart Gallery success, validation failure, and placement operations without logging raw SQL, credentials, tokens, or private paths.
  - Extended mutation-schema policy so Smart Gallery and rating writes require conclusively available storage before any persistent mutation; confirmed missing or unknown schema refuses the write with migration guidance.

  #### Multilingual service and rendering

  - Added `app/services/content_localization.php` for maintained-language normalization, three-state schema readiness, batched loading, request-local caching, fallback resolution, validation, and canonical persistence.
  - Updated gallery/photo Admin save paths, public renderers, SEO, lazy lightbox metadata, search, sidecars, gallery migration, and OpenAI text assistance to use the centralized localization model.
  - Preserved access checks before localized content loading and avoided public N+1 translation queries through batched presentation overlays.

  #### Database

  - Added `database/migrations/202608140001_smart_galleries.php` with `smart_galleries`, versioned rule storage, visibility/sorting indexes, and nullable indexed `images.editorial_rating`.
  - Added `database/migrations/202608140002_smart_gallery_placement.php` with root/gallery/unlisted placement state and legacy single-parent linkage.
  - Added `database/migrations/202608140003_smart_gallery_multiple_placements.php` with the `smart_gallery_placements` junction table and an idempotent copy of existing single-gallery placements.
  - Added `database/migrations/202608150001_multilingual_content.php` with nullable source-language columns plus unique, indexed, cascading `gallery_translations` and `image_translations` tables.
  - Kept upgrade work metadata-only: no image movement, Smart Gallery membership synchronization, translation backfill, or image metadata rebuild is required.

  #### Frontend and localization

  - Added `public/assets/gallery-modules/admin-smart-galleries.js` and supporting CSS for delegated nested-rule editing, dynamic Admin fragments, previews, and in-place placement controls.
  - Extended the existing browser upload worker with validated ZIP central-directory parsing and extraction, then reused the established image worker pool and prepared-batch endpoint.
  - Updated browser-module cache-busting imports so deployed clients load the Smart Gallery, multilingual editor, and ZIP-import behavior immediately.
  - Added every new Admin/public string to the synchronized English, Czech, German, and Swedish catalogs with English fallback preserved.

  #### Tests and release artifacts

  - Added focused Smart Gallery rule, Boolean logic, SQL-injection, access-intersection, pagination, CRUD, placement, missing-reference, malformed-version, and rendering contracts in `tests/smart_gallery_rules_test.php`.
  - Added `tests/browser_upload_zip_worker_test.mjs` with generated stored/Deflate fixtures and unsafe, unsupported, encrypted, and corrupt entries; extended browser-upload static contracts to prohibit PHP fallback for ZIP selections.
  - Added `tests/content_localization_model_test.php`, `tests/admin_content_localization_test.php`, and `tests/public_content_localization_test.php`; extended OpenAI, migration, search, rendering, and language-preference coverage.
  - Verified all 62 registered PHP regression scripts, focused Node browser fixtures, PHP/JavaScript syntax, translation alignment, migration compatibility, function documentation, updater safety, and deployment packaging contracts for the release candidate.
  - Updated `README.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `TESTING.md`, `docs/LATEX_BUILD.md`, and `docs/PHP_Gallery_Manual.tex`; rebuilt the indexed PDF manual for Version 0.90.
  - Expanded the documented release workflow with migration ordering, cache-busting, complete Node/PHP checks, archive inventory, cross-artifact version agreement, annotated tagging, and previous-version updater smoke testing.
  - Regenerated and verified `app/core-manifest.json` after the final Version 0.90 source and documentation edits.

  ### User Impact

  #### For visitors

  - Published Smart Galleries behave like normal paginated collections while showing only photographs the current visitor may access.
  - A viewer's existing browser-local language choice can now select matching gallery and photograph text without becoming an account or site-wide preference.
  - Normal galleries, URLs, lightbox navigation, downloads, maps, voting, responsive thumbnails, and no-JavaScript behavior remain available under their existing policies.

  #### For administrators

  - Administrators can build and publish reusable dynamic collections, place them in multiple navigation locations, save compatible searches, and curate results with private ratings.
  - iCloud-style ZIP exports can be selected directly in browser-assisted upload; supported photographs are extracted locally and unsupported entries are reported and skipped.
  - Gallery and photo translations can be reviewed and saved in the existing editors, with optional AI drafts and predictable fallback behavior.
  - Upgrading from Version 0.89.1 requires the normal migration run. No manual SQL, file movement, membership rebuild, or translation backfill is required.

## Version 0.89.1

Version 0.89.1 is a focused automatic-update reliability patch for installations that should receive newly published stable releases without waiting several hours. It shortens the normal stable metadata-check interval to one hour while preserving GitHub rate-limit handling, resumable update jobs, safe package validation, and all 0.89 public-language and schema-safety behavior.

  ### Highlights

  #### Faster stable release discovery

  - Updated request-triggered automatic checks to run at most once per hour per installation instead of once every five hours.
  - Updated the unattended CLI worker to use the same one-hour throttle, so hosting cron and normal page requests cannot drift apart.
  - Kept GitHub `Retry-After`, rate-limit reset, and local backoff handling authoritative; the shorter interval does not bypass provider protection.
  - Preserved the distinction between metadata discovery and resumable package processing, including bounded request-time worker slices and cron continuation.

  #### Maintained updater safety and release integrity

  - Kept stable, beta, rollback, cancellation, manifest, migration, and schema-safety boundaries unchanged.
  - Corrected the resumable-updater redaction audit so its intentional internal exception-message extraction boundary is excluded precisely without weakening the runtime redaction behavior.
  - Refreshed the release documentation, synchronized language fallback strings, and regenerated `app/core-manifest.json` for version 0.89.1.

  ### Technical Details

  #### Backend

  - Updated automatic-update TTL defaults and minimums in `app/services/updates_install.php`, `app/services/updates_status.php`, `app/controllers/updates.php`, and `scripts/application_update.php` to 3,600 seconds.
  - Kept automatic updates opt-in/opt-out through the existing Admin setting and left beta installations intentionally passive.

  #### Frontend and localization

  - Updated the Admin fallback copy and maintained English, Czech, German, and Swedish catalogs to describe the one-hour interval accurately.
  - Updated `README.md`, `ARCHITECTURE.md`, `DATABASE.md`, `TESTING.md`, and `docs/PHP_Gallery_Manual.tex` with the current release behavior.

  #### Tests and release artifacts

  - Verified the complete 58-test PHP regression suite, focused updater and translation tests, PHP syntax checks, and `git diff --check`.
  - Rebuilt `docs/PHP_Gallery_Manual.pdf` for the 0.89.1 edition.
  - Regenerated and checked `app/core-manifest.json`; all 377 managed files remain covered.

  ### User Impact

  #### For administrators

  - Newly published stable releases are normally discovered within one hour when the site receives eligible requests or the CLI worker is scheduled.
  - GitHub API protection remains respected, and the updater still refuses unsafe, stale, malformed, or unverifiable packages before activation.

  #### For visitors

  - No public rendering, language preference, gallery access, or no-JavaScript behavior changes in this patch release.

## Version 0.89

Version 0.89 is a release-readiness, updater reliability, schema-safety, and localization design release. It completes the repository-wide three-state schema inspection conversion, introduces resumable authenticated update jobs, and adds a fully configurable public viewer language selector with safe live preview and reset workflows. The release keeps existing public entrypoints, no-JavaScript fallbacks, protected data, and the viewer's browser-local language preference intact.

  ### Highlights

  #### Added configurable public viewer language designs

  - Added five stable selector presets, including the unchanged Classic appearance as the default plus Solid pills, Outline, Soft cards, and Minimal designs.
  - Added safe controls for preset selection, flag visibility, language codes and names, density, alignment, active-state emphasis, spacing, padding, margins, borders, radii, flag dimensions, typography, theme colors, custom colors, and transparent color fields.
  - Added a reusable language-settings panel shared by Theme > Language and the central Settings page, with basic Settings controls linking administrators to the detailed Theme editor.
  - Added an in-place preview using the production selector structure, bundled SVG flags, real language names, and the same normalized values used by public rendering.
  - Added individual-field reset controls, current-preset reset, and reset-all behavior. Resets remain unsaved until the containing settings form is submitted and never modify enabled languages, selector availability, site language, or a viewer's browser cookie.
  - Preserved semantic links, `hreflang`, `lang`, `aria-label`, `aria-current`, keyboard focus, narrow-screen behavior, and meaningful language text when flags are disabled.

  #### Completed schema reliability and updater hardening

  - Completed the eleven-phase conversion to explicit `available`, confirmed `missing`, and `unknown` schema states across security, authentication, mutation, ingestion, and optional presentation/reporting boundaries.
  - Preserved fail-closed behavior for NSFW-sensitive requests, authentication storage uncertainty, destructive operations, upload/migration writes, thumbnail metadata changes, voting, Picture Game, telemetry, navigation persistence, AI queues, and other state-changing workflows.
  - Added bounded System Health and Runtime Diagnostics models and redacted diagnostics for degraded schema inspection; raw SQL, database exceptions, credentials, tokens, DSNs, and private paths are not exposed.
  - Added resumable, checkpointed updater jobs for stable, beta, reinstall, rollback, and background work, including bounded download/extraction, manifest validation, locking, cancellation, rollback snapshots, and in-place Admin side-panel continuation.
  - Kept deployment helpers focused on producing local folders or ZIP archives. Release integrity is refreshed explicitly with `scripts/generate_manifest.php` before packaging or handoff.

  #### Improved maintainability and language coverage

  - Unified missing PHP and JavaScript function documentation across the changed runtime and browser modules.
  - Synchronized English, Czech, German, and Swedish catalogs, including all new selector design, preview, reset, validation, and fallback strings.
  - Bundled local SVG flags under `public/assets/flags/` with the upstream license notice so public rendering does not depend on an external flag service.

  ### Technical Details

  #### Backend

  - Added canonical selector defaults, preset definitions, normalization, persistence, and safe CSS-variable projection to `app/services/translations.php`.
  - Extended `app/services/admin_settings_registry.php`, `app/controllers/admin_theme_language.php`, `app/controllers/admin_theme_actions.php`, and `app/views/admin_language_settings.php` without duplicating the language panel.
  - Added defensive fallback handling for missing or malformed structured settings, including transparent-color flags and legacy partial preset values.
  - Added focused updater and schema-policy services while retaining compatibility coordinators and established routes.

  #### Database

  - Added no new database migration for Version 0.89.
  - Stored selector design values through the existing canonical application-settings service; viewer language selection remains a browser-local preference.
  - Kept schema capability observation request-local and separated from security, mutation, and optional-presentation policy decisions.

  #### Frontend

  - Added `public/assets/gallery-modules/admin-language-selector-design.js` with delegated handlers that survive dynamic Admin side-panel rendering.
  - Updated `public/assets/styles/admin.css`, `public/assets/styles/public.css`, `app/views/layout.php`, and the compatibility renderer for compact controls, live preview, preset classes, validated custom properties, transparent colors, and clean flag removal.
  - Updated cache-busting imports for changed browser modules and preserved the primary AJAX/in-place side-panel interaction contract.

  #### Tests and release integrity

  - Added and extended service, rendering, catalog, updater, schema-policy, documentation, and side-panel contract tests.
  - The release baseline requires `php tests/run.php`, focused translation/language/settings/updater/schema tests, `php -l` for every changed PHP file, `node --check` for changed JavaScript, `git diff --check`, and a current manifest verified with both generator modes.
  - Rebuilt `docs/PHP_Gallery_Manual.pdf` from `docs/PHP_Gallery_Manual.tex` after updating the edition metadata and release workflow instructions.

  ### User Impact

  #### For visitors

  - The public language selector keeps its existing default appearance while allowing administrators to select a more suitable visual treatment.
  - Flags can be hidden without losing native language labels or accessible codes, and each visitor's selected language continues to be remembered only in that visitor's browser.
  - Existing gallery access, password, NSFW, media authorization, semantic markup, and no-JavaScript behavior remain unchanged.

  #### For administrators

  - Theme > Language is the detailed owner for selector design; central Settings exposes only the basic selector controls and links to the detailed editor.
  - Live preview and reset controls make experimentation reversible before saving, while server-side normalization remains authoritative for every submitted value.
  - Updates can resume after ordinary request or hosting interruptions, and deterministic manifest/version/hash mismatches are rejected before activation.
  - System Health and Runtime Diagnostics distinguish unavailable schema from a confirmed pre-feature installation and provide bounded next steps.

## Version 0.88

Version 0.88 is a maintainability, deployment-safety, localization, and Admin usability release. It breaks the largest runtime coordinators into focused modules without changing the public entrypoints, hardens complete-package deployment and update cleanup for shared-hosting extractors, completes the supported English, Czech, German, and Swedish language surface, adds configurable public tag presentation, and turns the centralized Settings hub into a searchable index of global configuration and specialist tools.

  ### Highlights

  #### Modularized the application runtime

  - Split bootstrap configuration, request preparation, session startup, route interpretation, scheduled-maintenance hooks, and controller dispatch into focused modules under `app/bootstrap/`.
  - Kept `app/bootstrap.php` as the stable thin coordinator so existing public entrypoints and hosting configurations continue working.
  - Split the largest Admin gallery editor, Theme editor, public gallery renderer, shared helper, and updater implementations into feature-focused controller, helper, and service modules.
  - Preserved the original coordinator files as compatibility entrypoints while reducing mixed responsibilities and regression risk.

  #### Hardened deployment and updater cleanup

  - Updated both deployment helpers so the complete `app/` tree, including the new bootstrap modules, is included in release archives.
  - Made ZIP entry names use portable forward slashes so limited web-hosting extractors create real directories instead of root files whose names contain backslashes.
  - Added guarded cleanup for flattened or misplaced managed application files created by previous archive extraction, while preserving unrelated root files such as verification and analytics files.
  - Added a dedicated Advanced maintenance action for running only the misplaced-file cleanup without reinstalling the application.
  - Extended updater validation, backup, rollback, logging, and safety coverage for modular runtime files and obsolete managed paths.

  #### Added centralized and searchable Settings

  - Added the central Admin Settings page with stable General, Public appearance, Content, Media and browsing, Uploads and automation, Privacy and diagnostics, and Advanced sections.
  - Preserved each existing service or specialist page as the canonical owner for normalization, validation, persistence, secrets, file uploads, and destructive actions.
  - Added safe central editing for the narrow set of settings that already have canonical shared setters, with current/default/inherited status and specialist deep links for everything else.
  - Added a Spotlight-style contextual search beneath the Settings title with live token filtering, accent-insensitive matching, relevance ordering, keyboard navigation, ARIA combobox/listbox behavior, clearing, section activation, and exact-control highlighting.
  - Indexed every global specialist control through discovery-only registry entries, including Theme, uploads, telemetry, thumbnails, maintenance, navigation data, feature flags, database tools, account mail, Google, and OpenAI settings, without exposing secret values or duplicating hundreds of normal section cards.

  #### Improved Theme and public tag presentation

  - Added configurable gallery hero-tag ordering, initial visible limits, display-all behavior, and optional row-bounded scrolling.
  - Added dedicated public tag-page gallery columns, rows, and gallery-card layout settings with safe fallback to the existing global Theme values.
  - Added contextual navigation between tag management and the relevant Theme subsection.
  - Fixed tag-result pagination, Admin tag usage links, local rewritten thumbnail URLs, and full-width public hero/tag presentation.
  - Limited the selectable built-in language set to the complete English, Czech, German, and Swedish catalogs.

  #### Improved Admin dashboard maintenance

  - Removed maintenance-only schema, database usage, navigation-data, and system work from the ordinary dashboard request.
  - Added an authenticated deferred maintenance endpoint that loads the panel only when Maintenance is opened.
  - Added grouped Content and display, Media and cache, Maps and navdata, and System health subtabs, including correct initialization after deferred HTML insertion.
  - Linked the dashboard thumbnail-gap warning directly to the exact Media and cache maintenance group.
  - Added server and browser warning logs with diagnostic IDs, exception details, HTTP status, content type, response snippets, route context, and requested maintenance tab when deferred loading fails.

  ### Technical Details

  #### Backend

  - Added `app/bootstrap/configuration.php`, `app/bootstrap/request.php`, `app/bootstrap/session.php`, `app/bootstrap/routing.php`, `app/bootstrap/maintenance.php`, and `app/bootstrap/dispatch.php`.
  - Added focused Admin gallery editor modules in `app/controllers/admin_galleries_edit_actions.php`, `app/controllers/admin_galleries_edit_metadata.php`, `app/controllers/admin_galleries_edit_page.php`, and `app/controllers/admin_galleries_edit_views.php`.
  - Added focused Theme modules in `app/controllers/admin_theme_actions.php`, `app/controllers/admin_theme_appearance.php`, `app/controllers/admin_theme_layout.php`, `app/controllers/admin_theme_media.php`, `app/controllers/admin_theme_language.php`, `app/controllers/admin_theme_custom_css.php`, and `app/controllers/admin_theme_page.php`.
  - Added focused public gallery modules for cards, controls, home rendering, lightbox rendering, and page orchestration.
  - Split shared helpers into Admin rendering, files, page rendering, public URLs, requests, and runtime modules while retaining `app/helpers.php` as the loader.
  - Split updater behavior into filesystem, install, patch-note, remote, and status services while retaining `app/services/updates.php` as the coordinator.
  - Added `app/controllers/admin_settings.php`, `app/services/admin_settings_registry.php`, and `app/views/admin_settings.php` for centralized ownership, safe editing, redaction, discovery, and specialist navigation.

  #### Database

  - Added no new database migration for Version 0.88.
  - Stored new Theme and central Settings preferences through existing canonical settings tables and services.
  - Kept telemetry, account integrations, secrets, per-gallery overrides, and destructive maintenance state in their existing owners without shadow copies.

  #### Frontend

  - Added `public/assets/gallery-modules/admin-settings-search.js` for local Settings discovery and accessible keyboard interaction.
  - Added `public/assets/gallery-modules/hero-tags.js` for in-place hero-tag disclosure and row-aware scrolling.
  - Updated Admin tabs and side-panel lifecycle code to initialize dynamically inserted nested controls and preserve URL state.
  - Updated Theme, tag, public gallery, maintenance, and Settings styling for responsive layouts and consistent Admin panel geometry.
  - Updated browser cache-busting dependencies for changed Admin and public modules.

  #### Localization and documentation

  - Completed and synchronized the supported English, Czech, German, and Swedish JSON/PHP language catalogs.
  - Added `docs/ADMIN_SETTINGS_INVENTORY.md` as the canonical ownership, fallback, sensitivity, migration, and discovery reference.
  - Updated `README.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `TESTING.md`, and `docs/PHP_Gallery_Manual.tex` for the modular runtime, tag presentation, deferred maintenance, centralized Settings, and Settings search.
  - Updated `AGENTS.md` to require PHP syntax validation for changed PHP files and release/deployment surfaces.

  #### Tests

  - Added runtime-boundary tests for thin coordinators, module loading, compatibility entrypoints, and manifest inclusion.
  - Added deployment packaging tests for the complete `app/` tree and portable ZIP paths.
  - Added updater safety tests for flattened-file cleanup, root-file preservation, backups, rollback, and Advanced cleanup routing.
  - Added Settings registry, normalization, rendering, navigation, accessibility, search filtering, specialist discovery, and sensitive-value tests.
  - Added hero-tag, tag-page Theme, public media URL rewrite, translation-catalog consistency, deferred maintenance, and MySQL compatibility coverage.

  ### User Impact

  #### For visitors

  - Public tag pages can use a dedicated grid and card presentation while preserving global defaults when no override is saved.
  - Gallery hero tags can remain compact, expand in place, or use bounded scrolling according to the selected Theme policy.
  - Rewritten local thumbnails, tag pagination, gallery links, and full-width hero presentation behave consistently.
  - Existing gallery access, password, NSFW, media authorization, semantic markup, and no-JavaScript behavior remain unchanged.

  #### For administrators

  - Global configuration can be found quickly from one searchable Settings surface without learning which specialist page owns each control.
  - Dashboard opening remains faster because expensive maintenance data is loaded only when requested.
  - Thumbnail warnings open the exact maintenance group, and deferred-panel failures now leave actionable Admin log diagnostics.
  - Shared-hosting deployments include the complete runtime tree, extract into correct directories, and can clean old flattened application files without deleting unrelated root content.
  - The smaller runtime modules make future maintenance and review safer while preserving familiar routes and workflows.

## Version 0.87.1

Version 0.87.1 is a focused Safari compatibility patch for the public Leaflet maps shown in the lightbox. It restores visible map pins in Safari while preserving the existing Chrome behavior, map access rules, and fullscreen lightbox workflows.

  ### Highlights

  #### Restored Safari map pins

  - Fixed public lightbox map markers that were missing in Safari even though the map tiles and location were displayed correctly.
  - Hardened the CSS-only Leaflet marker rendering against gallery image styles, fullscreen rules, inherited filters, opacity, sizing, and visibility overrides.
  - Kept separate visual roles for photo, active-photo, route, route-start, route-end, and route-via markers.

  ### Technical Details

  #### Frontend

  - Updated `public/assets/gallery-modules/lightbox.js` to keep Leaflet marker setup and lightbox asset revisions synchronized.
  - Updated `public/assets/styles/public-shared.css` with Safari-safe marker sizing, visibility, stacking, and rendering rules.
  - Updated `app/helpers.php` and `app/views/layout.php` so changes to the lightbox module invalidate deployed browser caches.

  #### Tests

  - Added `tests/leaflet_marker_rendering_model_test.php` for marker creation, role handling, CSS contracts, and asset-version coverage.
  - Added `tests/SAFARI_LEAFLET_MARKER_MANUAL_TEST.md` for Safari and Chrome verification of normal and fullscreen lightbox maps.

  ### User Impact

  #### For visitors

  - Safari users can see photo and route pins on public lightbox maps again.
  - Existing GPS authorization, privacy, gallery access, map navigation, popups, and no-JavaScript behavior remain unchanged.

## Version 0.87

Version 0.87 is an operational scalability and public-rendering release. It introduces a durable Admin log lifecycle for installations whose audit history has grown large, adds grouped browsing and bounded exports, moves eligible historical days into protected filesystem archives before removing their live database rows, and preserves resumable recovery when maintenance is interrupted. It also adds the permanent progressive public thumbnail renderer, improves the explanation of the application request pipeline for gallery owners, and refreshes the release documentation and integrity metadata.

  ### Highlights

  #### Scaled Admin logs for growing installations

  - Added grouped Admin log browsing so repeated operational events can be reviewed as useful summaries instead of overwhelming the screen with identical rows.
  - Added bounded keyset pagination for large log lists, group members, and exports so work remains controlled as `admin_logs` grows.
  - Added complete CSV, JSON, and ZIP export paths that stream large histories through bounded database and temporary-file batches.
  - Added configurable live-log retention with a `Forever` option and explicit archive-first behavior for historical records.

  #### Added protected Admin log archives

  - Added day-based archives under `data/admin-log-archives/`, protected by `data/admin-log-archives/.htaccess` and kept outside ordinary public browsing.
  - Added self-describing JSON and static HTML archive files so archived history remains inspectable without a live database query or JavaScript.
  - Added archive manifests containing the application version, archive date, row count, format, and source identity information.
  - Added row-count verification before live rows are removed, atomic temporary-file promotion, lock/state files, resumable maintenance, and recovery for interrupted archive creation or cleanup.
  - Added Admin controls and status reporting for archive inspection, retention choices, pending work, failures, and safe continuation.

  #### Improved public thumbnail rendering

  - Added the permanent `progressive` selected-gallery renderer as a small-first, bounded near-viewport sharpening pipeline.
  - Preserved `responsive` as the safe default with complete server-rendered candidate markup and no-JavaScript behavior.
  - Kept larger progressive candidates inert until the browser scheduler activates them and retained gallery, password, NSFW, media authorization, semantic markup, and useful-alt-text checks.

  ### Technical Details

  #### Backend

  - Added `app/services/admin_log_archives.php` for archive paths, retention normalization, day snapshots, bounded row streaming, manifests, static archive output, locks, resumable state, recovery, and cleanup.
  - Extended `app/services/logs.php` with grouped summaries, group-member browsing, bounded keyset exports, archive-first retention boundaries, and reusable normalized export payloads.
  - Updated `app/controllers/admin_logs.php` and `app/controllers/site_maintenance.php` for archive settings, archive maintenance, grouped views, exports, status updates, and recovery-aware Admin responses.
  - Added migration `202608100001_admin_log_scaling.php` with `(created_at, id)` and grouping indexes for age-bounded and grouped `admin_logs` access.
  - Kept generic database cleanup from deleting live audit history or archive files; log retention remains an explicit, separately authorized policy.

  #### Frontend and public rendering

  - Updated Admin log controls and styles for grouped rows, archive state, retention actions, bounded progress, errors, and in-place refresh behavior.
  - Preserved the Admin right-side panel as the primary JavaScript interaction surface, with direct POST/redirect behavior remaining a compatibility fallback.
  - Updated the public thumbnail renderer and browser lifecycle documentation to distinguish permanent `responsive` and `progressive` architecture terms from the Admin-facing Beta label.

  #### Documentation and release metadata

  - Updated `README.md`, `ARCHITECTURE.md`, `DATABASE.md`, `CODEMAP.md`, and `TESTING.md` for Version 0.87 and the new Admin log lifecycle.
  - Expanded `docs/PHP_Gallery_Manual.tex` with an owner-friendly explanation of request preparation, routing, dispatch, controllers, services, views, browser modules, and the selected-gallery render pipeline.
  - Regenerated `docs/PHP_Gallery_Manual.pdf` and `app/core-manifest.json` for the Version 0.87 release surface.

  #### Tests

  - Added `tests/admin_log_scaling_test.php` for migration, grouping, bounded export, retention, and archive-first contracts.
  - Added `tests/admin_log_archive_maintenance_test.php` for protected archive paths, generated archive formats, row-count safety, resumable state, interrupted work, and cleanup behavior.
  - Extended public thumbnail, Admin panel, and maintenance verification guidance for both permanent thumbnail renderers and the new log archive workflow.

  ### User Impact

  #### For administrators

  - Large audit histories remain easier to browse, group, export, and maintain without treating the entire `admin_logs` table as one unbounded operation.
  - Older records can be moved out of the live database while remaining available as protected, readable day archives.
  - Interrupted maintenance can be inspected and continued instead of silently deleting incomplete history.
  - The manual now explains the application's request and rendering process in everyday language, making the relationship between the website, database, services, and browser clearer.

  #### For visitors

  - Public gallery photo cards retain the responsive renderer as the default and can use progressive small-first sharpening when selected by the owner.
  - Access checks, protected media behavior, direct links, semantic server-rendered markup, and no-JavaScript navigation remain intact.

## Version 0.86.1

Version 0.86.1 is a patch-level consistency, documentation, versioning, and repository-hygiene release. It aligns the documented Duplicate Photo Detector behavior with the implementation already shipped in 0.86, corrects current-version metadata, completes the ledger schema documentation, adds the repository's referenced MIT license, and regenerates integrity metadata without changing the detector's runtime workflow.

  ### Highlights

  #### Corrected Duplicate Photo Detector documentation

  - Corrected stale descriptions that characterized the complete detector workflow as report-only, read-only, or non-destructive.
  - Clarified that metadata scanning itself only reads indexed image metadata, while review-ledger controls persist administrator decisions and **Delete this** permanently removes one explicitly selected result.
  - Documented the implementation already present in 0.86: clickable gallery/photo context, canonical pair ignores, exact-gallery ignores, per-administrator ledger ownership, parent/child gallery independence, **Clear ledger**, and in-place result deletion.
  - Clarified that the existing Admin right-side panel and AJAX fragment-refresh path are primary, while normal POST/redirect handling remains the non-JavaScript or direct-request fallback.

  #### Corrected release and repository metadata

  - Updated the runtime and current documentation version from `0.86` to `0.86.1`.
  - Corrected the historical 0.86 release notes to describe the detector functionality that was already present in that release rather than presenting it as report-only.
  - Added the standard MIT `LICENSE` file referenced by existing source headers, using the locally documented author and project year.
  - Regenerated `app/core-manifest.json` from the final local release tree with version `0.86.1`.

  ### Technical Details

  #### Documentation and source descriptions

  - Updated `ARCHITECTURE.md` with the current version, three-job session limit, immutable server-owned scope, Admin/CSRF validation, pair/image/gallery scope checks, AJAX-first mutation flow, and existing deletion delegation.
  - Updated `DATABASE.md` with version `0.86.1`, the actual duplicate-ledger indexes, canonical per-administrator keys, exact-gallery semantics, and cascade behavior defined by `202608080001_duplicate_photo_ledger.php`.
  - Verified `README.md` and `CODEMAP.md` against the local controller, detector service, ledger service, view, JavaScript, CSS, migration, normal image-deletion service, and focused tests.
  - Updated stale file-level purpose/responsibility text without removing comments, docstrings, or PHPDoc blocks.

  #### Tests

  - Updated detector test descriptions to distinguish pure metadata matching from the controller's explicit ledger and deletion mutations.
  - Updated `TESTING.md` to describe selected-branch/global scope, bounded pair expansion, per-administrator ledger behavior, exact-gallery independence, deletion/job pruning, AJAX-first panel behavior, and POST deletion fallback accurately.
  - Re-ran the focused duplicate detector and ledger tests, migration and updater/version-related tests, manifest checks, PHP syntax checks, and the complete standalone PHP regression suite.

  ### User Impact

  #### For administrators

  - The documented workflow now matches the controls administrators actually see in the Duplicate Photo Detector.
  - Release, database, testing, and architecture references consistently describe version `0.86.1` and the existing 0.86 detector behavior.
  - No detector interaction or deletion behavior changed as part of this patch.

  #### For visitors

  - There is no public gallery behavior change in this patch release.

## Version 0.86

Version 0.86 added the Admin Duplicate Photo Detector for bounded review and cleanup of exact and metadata-supported duplicate candidates. It introduced selected-gallery-branch and explicit all-gallery scanning, deterministic left/right findings, clickable public context, persistent per-administrator review rules, and explicit deletion through the existing gallery image-deletion service inside the established Admin right-side panel.

  ### Highlights

  #### Added duplicate photo detection

  - Added an Admin duplicate-photo detector to the gallery workflow.
  - Searches the selected gallery and its descendants by default and provides an explicit, unchecked **Search all galleries** option for a broader scan.
  - Displays deterministic left/right findings with thumbnails, clickable gallery/photo context, filenames, file sizes, dimensions, MIME types, capture dates, camera/lens metadata, and matching evidence.
  - Distinguishes exact checksum matches from strong and possible metadata candidates instead of treating file size alone as proof.
  - Keeps incomplete or missing EXIF values from creating false exact matches.
  - Added per-administrator pair and exact-gallery review rules, ledger clearing, and explicit deletion of a selected result through the normal gallery image-deletion service.

  ### Technical Details

  #### Backend

  - Added `app/controllers/admin_duplicate_photos.php` for authenticated, CSRF-protected scanning, ledger mutations, validated deletion, JSON responses, and POST/redirect fallback handling.
  - Added `app/services/duplicate_photo_detector.php` for immutable server-side scope, bounded session jobs, metadata normalization, deterministic matching, canonical pair expansion, ledger filtering, and paginated result preparation.
  - Added `app/services/duplicate_photo_ledger.php` for canonical pair rules and exact-gallery rules owned independently by each administrator.
  - Reused the existing `images.checksum_sha256`, `file_size`, dimensions, MIME, and stored EXIF fields populated by `app/services/image_scanning.php`.
  - Added migration `202608080001_duplicate_photo_ledger.php` with per-administrator pair/gallery primary keys, lookup indexes, and cascading user/image/gallery foreign keys.

  #### Frontend

  - Added `app/views/admin_duplicate_photos.php` for scope controls, bounded progress, pair findings, public context links, ledger actions, deletion controls, and POST fallbacks.
  - Added `public/assets/gallery-modules/admin-duplicate-photo-detector.js` for capture-phase delegated handling, automatic bounded continuation, and in-place scan/ledger/delete fragment refresh.
  - Added `public/assets/styles/admin-duplicate-photo-detector.css` for responsive duplicate groups, evidence labels, thumbnails, and panel states.
  - Integrated the feature with the existing `admin-side-panel.js` workflow so normal JavaScript operation keeps the panel open and does not navigate or reload the Admin page.

  #### Tests

  - Added `tests/duplicate_photo_detector_test.php` for checksum and EXIF matching, missing metadata, scope validation, deterministic bounded pairs, ledger filtering, clickable context, deletion validation, job pruning, and AJAX side-panel contracts.
  - Added `tests/duplicate_photo_ledger_test.php` for canonical pair keys, exact-gallery semantics, parent/child independence, per-administrator schema keys, cascades, parameterized persistence, and protected maintenance policy.
  - Updated `TESTING.md` with focused automated and manual verification steps for the duplicate detector and right-side panel.

  ### User Impact

  #### For administrators

  - Administrators can review likely duplicate photos directly from the selected gallery’s right-side panel.
  - Global searching is opt-in, making the default workflow safer and faster for large installations.
  - Each result explains why photos were grouped and provides persistent review controls plus explicit deletion of a confirmed duplicate.
  - AJAX is the primary interaction path; POST/redirect remains available as fallback when JavaScript is unavailable or the route is used directly.

  #### For visitors

  - Duplicate scanning and ledger review are Admin-only; public behavior changes only when an administrator explicitly deletes a selected image.

## Version 0.85

Version 0.85 is a database maintenance and storage reliability release. It adds a complete read-only audit of the active schema, bounded and explainable cleanup for only high-confidence records, conditional repair of legacy thumbnail metadata structures, and separately confirmed database statistics and physical optimization actions. The release is designed for shared hosting, where inspection must remain explicit, resumable, auditable, and safe.

  ### Database maintenance extension

  #### Added complete read-only database inspection

  - Added an explicit Admin maintenance tab that inventories every table dynamically through `information_schema`.
  - Reports table engines, collations, estimated rows, storage, columns, defaults, ENUM/SET definitions, keys, foreign keys, migration references, broad code references, and separately scoped production/test SQL evidence.
  - Writes the latest structured report to `cache/admin-database-maintenance-report.json` only after the administrator starts an inspection.
  - Keeps the ordinary dashboard fast by loading only the cached report during normal rendering.

  #### Added bounded and explainable logical cleanup

  - Added high-confidence rules for proven orphan rows, deterministic duplicate metadata rows, and records with explicit application expiry semantics.
  - Added dry-run output, persisted resumable state, bounded batch sizes, before/after counters, failure state, CSRF protection, Admin authentication, explicit confirmation, and structured logging.
  - Added `database_maintenance_audit_log`; each committed batch records the exact removed row identities and reason inside the same transaction, so an audit-write failure rolls back deletion.
  - Protected galleries, images, tags, users, settings, audit logs, telemetry, migration history, imported navigation data, and unknown tables from generic automatic deletion.
  - Kept all filesystem media, thumbnail files, and ZIP files outside the database cleanup workflow.

  #### Added conditional legacy schema repair

  - Added migration `202607250001_database_maintenance_schema_repair.php`.
  - Creates the transactional cleanup audit table when absent and repairs partial thumbnail metadata compaction by checking every table, column, index, and foreign key before alteration.
  - Preserves source geometry and orientation in `images` before removing proven duplicated legacy thumbnail columns.
  - Supports already compact databases, partially applied historical migrations, MySQL/MariaDB DDL auto-commit behavior, and the former SQL-only migration runner.
  - Added a non-mutating repair dry-run that reports the pending migration and exact legacy objects before any DDL is applied.

  #### Separated statistics refresh from physical optimization

  - Added selected-table `ANALYZE TABLE` and separately confirmed `OPTIMIZE TABLE` actions, including a selected-table dry-run plan before physical optimization.
  - Displays allocated size and `data_free` before execution and warns about shared-hosting locks and rebuild cost.
  - Never runs `OPTIMIZE TABLE` from inspection, logical cleanup, page load, or normal migrations.
  - Reports successful execution without claiming that the storage engine necessarily reduced physical filesystem usage.

  #### Expanded regression coverage

  - Added `tests/database_maintenance_test.php` for inventory normalization, scoped SQL-reference extraction, table policy protection, legacy object detection, cleanup classification, deterministic duplicate survival, thumbnail distribution reporting, dry-run contracts, and Admin security requirements.
  - Added `tests/database_maintenance_schema_repair_test.php` for absent, partial, compact, retry, geometry-preservation, and no-row-deletion repair behavior.
  - Extended migration consistency and former-runner compatibility tests for the new conditional repair migration.

## Version 0.84.2

Version 0.84.2 is an updater safety patch focused on preventing incomplete deployments and accidental cleanup of valid application files. It strengthens release snapshot validation, stages replacements before activating them, narrows misplaced-project cleanup, and adds regression coverage for updater failure paths and current application layouts.

  ### Highlights

  #### Safer update deployment

  - Required critical application, public entry-point, service, view, language, and migration files before an update can modify the installation.
  - Rejected incomplete or unreadable release snapshots with specific diagnostics.
  - Staged incoming files before replacing active files so failures during copying leave the installation less exposed to partial updates.
  - Applied replacements in dependency-aware order and atomically renamed staged files into place.
  - Delayed obsolete-file cleanup until the complete replacement snapshot was active.

  #### More precise cleanup behavior

  - Stopped treating unknown top-level `app/` entries as misplaced project copies.
  - Preserved valid modules such as `app/views.php`, `app/views/`, `app/lang/`, `app/migration_definitions.php`, and `app/migration_repairs.php`.
  - Kept cleanup limited to known nested project artifacts such as `app/app`, `app/public`, and `app/index.php`.
  - Improved backup and rollback preparation for overwritten and removed managed files.

  ### Technical Details

  #### Backend

  - Updated `app/services/updates.php` with complete source-root validation and staged file replacement.
  - Added size verification for staged update files before activation.
  - Preserved OPcache invalidation after each successful replacement.
  - Improved updater error messages for missing files, staging failures, directory/file conflicts, and atomic replacement failures.

  #### Tests

  - Added `tests/updater_safety_model_test.php`.
  - Covered required release files and rejection of incomplete update snapshots.
  - Covered protection of valid top-level application modules from cleanup.
  - Covered recognition of known nested project artifacts.
  - Updated `TESTING.md` with the updater safety regression test.

  ### User Impact

  #### For administrators

  - Updates fail earlier when a downloaded archive is incomplete instead of touching the active installation.
  - Valid application modules are no longer at risk of being removed as presumed misplaced project copies.
  - Update diagnostics identify the missing or unreadable release component that needs attention.

  #### For visitors

  - A failed update is less likely to leave the public site with a mixed or incomplete code version.
  - Successful updates preserve the existing public gallery behavior while replacing files safely.

## Version 0.84.1

Version 0.84.1 is a migration reliability patch for installations upgrading through the 0.84 public-path changes. It makes migration definitions safe for both the current definition-aware runner and older SQL-only runners, adds deterministic repair and verification behavior, and improves regression coverage for partially applied or legacy migration states.

  ### Highlights

  #### Migration runner compatibility

  - Preserved compatibility with the former SQL-only migration runner used by older installations.
  - Prevented PHP migration definitions and repair callbacks from being interpreted as SQL statements.
  - Validated all pending migration definitions before applying the first database change.
  - Recorded migration versions only after all SQL statements and repair callbacks completed successfully.

  #### Public path repair and verification

  - Added deterministic repair handling for legacy and partially applied gallery public-path migrations.
  - Re-ran hierarchical public-path repairs after the migration runner upgrade.
  - Verified that nested filesystem galleries retain complete nested public URL paths.
  - Kept repair operations transactional when the migration runner does not already own a transaction.

  ### Technical Details

  #### Backend

  - Added `app/migration_repairs.php` for reusable transactional migration repair callbacks.
  - Updated `app/migration_definitions.php` to support current and legacy migration loading contracts.
  - Updated `app/migrations.php` to validate the complete pending migration set before execution.
  - Updated `install.php` to keep installation-time migration behavior aligned with the normal updater.

  #### Database

  - Updated `202607120002_harden_gallery_public_paths.php` and `202607120003_restore_hierarchical_gallery_public_paths.php` for legacy-runner compatibility.
  - Added migration `202607120004_verify_gallery_public_paths_after_runner_upgrade.php`.
  - Ensured migration repair callbacks run before their migration versions are recorded.

  #### Tests

  - Added `tests/migration_legacy_runner_compatibility_test.php`.
  - Added coverage for direct-require migration execution under the former SQL-only runner.
  - Added assertions for repair callbacks, transaction commits, rollback behavior, and hierarchical path verification.
  - Updated `tests/migration_consistency_test.php` and `TESTING.md` for the expanded migration checks.

  ### User Impact

  #### For administrators

  - Upgrades from older 0.84 migration-runner states complete more safely.
  - Partially applied public-path migrations can be repaired deterministically during upgrade.
  - Migration failures are less likely to leave a version marked as applied prematurely.

  #### For visitors

  - Nested gallery URLs remain hierarchical after an upgrade.
  - Existing public gallery links are preserved while path repairs are applied.

## Version 0.84

Version 0.84 is a reliability and maintainability release focused on canonical gallery URLs, hierarchical public paths, a cleaner upload subsystem, and more predictable lightbox navigation. It also strengthens migration auditing and consolidates the standalone test suite so the application is easier to upgrade, verify, and operate across shared-hosting environments.

  ### Highlights

  #### Safer and more capable public gallery paths

  - Hardened public gallery path parsing, normalization, validation, and URL generation.
  - Restored hierarchical public paths so nested galleries retain their complete public location.
  - Preserved canonical path behavior across gallery creation, editing, moving, and sidecar operations.
  - Improved route handling for public galleries and public media links.

  #### Improved lightbox and map navigation

  - Added an accessible keyboard-shortcut help panel to the lightbox toolbar.
  - Documented keyboard controls for photo navigation, fullscreen, maps, slideshows, and closing the lightbox.
  - Improved previous/next navigation behavior and pointer-event handling in map split view.
  - Kept lightbox navigation controls visible when map split mode requires them.
  - Improved responsive styling, safe-area positioning, focus behavior, and HUD visibility.

  #### Removed obsolete experimental upload infrastructure

  - Removed the obsolete experimental upload and thumbnail-rebuild services.
  - Removed the associated experimental browser workers, admin assets, migrations, and test coverage.
  - Retained the supported browser upload and thumbnail-rebuild implementation and its production migrations.

  ### Technical Details

  #### Backend

  - Updated `app/services/public_paths.php` with centralized canonical path handling and hierarchical path restoration.
  - Updated `app/helpers.php`, `app/services/gallery_mutations.php`, and `app/services/gallery_sidecars.php` to use the hardened path behavior.
  - Added `app/migration_definitions.php` for centralized migration metadata and consistency checks.
  - Updated `app/migrations.php` and `install.php` to keep migration discovery and installation aligned.
  - Updated EXIF and public gallery services to use the corrected public URL behavior.

  #### Database

  - Added migration `202607120001_browser_upload_legacy_settings_cleanup.php` to remove obsolete browser-upload settings.
  - Added migration `202607120002_harden_gallery_public_paths.php` for public-path normalization and compatibility.
  - Added migration `202607120003_restore_hierarchical_gallery_public_paths.php` to restore nested gallery URL structure.
  - Removed the obsolete experimental upload and thumbnail-rebuild migrations.

  #### Frontend

  - Updated `public/assets/gallery-modules/lightbox.js` and `public/assets/styles/lightbox.css` for shortcut help and map split navigation.
  - Updated `public/assets/gallery-modules/lightbox-deferred.js`, `public/assets/gallery.js`, and `public/assets/public-gallery.js` for the revised lightbox integration.
  - Updated English and Czech translations for shortcut help, navigation labels, and map controls.
  - Removed obsolete experimental upload and thumbnail-rebuild browser modules.

  #### Tests

  - Added `tests/run.php` as a consolidated runner for the standalone PHP test scripts.
  - Added shared DNG, thumbnail-compatibility, and fixed-clock test shims under `tests/support/`.
  - Added focused coverage for public gallery paths and migration consistency.
  - Expanded regression coverage for administration, lightbox behavior, thumbnails, uploads, URLs, and public asset loading.

  ### User Impact

  #### For visitors

  - Nested galleries now keep stable, readable hierarchical public URLs.
  - Public gallery and media links behave more consistently across rewrite configurations.
  - Lightbox photo navigation is easier to discover and more reliable in map split view.
  - Keyboard and assistive-technology users receive clearer control labels and shortcut guidance.

  #### For administrators

  - Gallery editing and moving workflows use safer canonical public paths.
  - Database upgrades remove obsolete experimental settings and preserve hierarchical URL behavior.
  - The supported upload workflow is easier to maintain after removal of unused experimental components.
  - The consolidated test runner makes release and deployment verification faster and more repeatable.

## Version 0.83

Version 0.83 is a comprehensive performance optimization and profiling release focused on measuring, analyzing, and improving gallery rendering efficiency. It introduces sophisticated benchmarking tools for administrators, optimizes critical rendering paths, implements lightbox preloading, and adds detailed performance profiling across the entire platform. The release includes four-phase optimization initiatives targeting thumbnail lookup performance, internationalization handling, manifest generation, and browser rendering speed.

  ### Highlights

  #### Comprehensive gallery benchmarking system

  - New admin benchmarking interface for measuring gallery performance.
  - Real-time performance metrics collection and visualization.
  - Detailed rendering time analysis and bottleneck identification.
  - Database query profiling and optimization metrics.
  - Performance trend tracking and historical analysis.
  - Comparative performance metrics and reporting.

  #### Performance optimization across four phases

  - **Phase 1**: Thumbnail fallback lookup optimization for faster resolution.
  - **Phase 2**: Internationalization optimization by moving translations out of initial page load.
  - **Phase 3A-3D**: Manifest JSON generation, JSON-LD support, profiling improvements.
  - **Phase 4**: Lightbox preloading for improved browser rendering performance.

  #### Enhanced profiling and metrics

  - Detailed rendering performance profiling.
  - Phase-based performance tracking.
  - Resource usage analysis and reporting.
  - Optimization impact measurement.
  - Performance baseline establishment.

  #### Lightbox preloading

  - Intelligent image preloading for lightbox views.
  - Optimized loading strategy for better UX.
  - Reduced initial render time.
  - Better caching of lightbox resources.

  ### Technical Details

  #### Backend

  - Created `app/services/gallery_benchmark.php` (512 lines):
    * Comprehensive benchmarking engine
    * Performance metrics collection and aggregation
    * Rendering time analysis and profiling
    * Database query performance tracking
    * Cache effectiveness measurement
    * Trend analysis and comparison metrics
    * Detailed performance reporting

  - Created `app/services/public_gallery_media_manifest.php` (452 lines):
    * Media manifest generation for galleries
    * Structured data preparation
    * SEO optimization with JSON-LD support
    * Efficient metadata aggregation
    * Performance-optimized caching
    * Manifest versioning and validation

  - Enhanced `app/services/public_render_profiler.php`:
    * Better profiling metrics collection
    * Phase-based performance tracking
    * Detailed timing information capture
    * Resource usage analysis
    * Bottleneck identification

  - Enhanced `app/services/thumbnail_sources.php`:
    * Optimized thumbnail source resolution
    * Improved caching strategies
    * Reduced database query overhead
    * Faster fallback lookups

  - Enhanced `app/services/thumbnail_metadata.php`:
    * Improved metadata handling
    * Better caching mechanisms

  - Enhanced `app/services/seo_request_guard.php`:
    * Better SEO integration
    * Structured data handling

  - Enhanced `app/helpers.php`:
    * Performance-critical optimization
    * Better caching and memoization

  - Created `app/controllers/admin_gallery_benchmark.php` (303 lines):
    * Admin benchmark management interface
    * Benchmark execution and result tracking
    * Performance visualization endpoints
    * Trend analysis and reporting

  - Enhanced `app/controllers/public_gallery.php`:
    * Better manifest integration
    * Optimized rendering pipeline

  - Enhanced `app/controllers/theme_assets.php`:
    * Optimized asset delivery

  - Updated `app/bootstrap.php`:
    * Register benchmark service
    * Initialize profiler

  - Updated `app/services.php`:
    * Register new services

  #### Database

  - No database migrations required.
  - Uses existing gallery structure.

  #### Frontend

  - Created `public/assets/gallery-modules/admin-gallery-benchmark.js` (460 lines):
    * Interactive benchmark UI with real-time metrics
    * Chart and graph visualization
    * Performance trend analysis interface
    * Comparative metrics display
    * Export and reporting functionality
    * Historical data visualization

  - Enhanced `public/assets/gallery-modules/lightbox.js` (215 lines):
    * Lightbox preloading implementation
    * Optimized image loading strategy
    * Improved performance metrics tracking
    * Better cache utilization

  - Enhanced `public/assets/gallery-modules/lightbox-deferred.js`:
    * Optimized deferred loading
    * Better resource management

  - Enhanced `app/views/layout.php` (233 lines):
    * Template optimization
    * Reduced rendering time
    * Optimized asset loading order
    * Better compression support
    * Manifest integration

  - Enhanced `app/views/seo.php`:
    * JSON-LD structured data support
    * Better SEO metadata

  - Updated `public/assets/gallery.js`:
    * Load and initialize benchmark module
    * Better module integration

  - Updated `public/assets/public-gallery.js`:
    * Integration with optimizations

  #### Tests

  - Comprehensive benchmarking validation.
  - Performance regression detection.
  - Optimization impact measurement.

  ### User Impact

  #### For visitors

  - Faster lightbox loading with preloading strategy.
  - Better page performance from optimization phases.
  - Reduced initial page load time from i18n optimization.
  - Improved SEO through JSON-LD structured data.

  #### For administrators

  - New benchmarking interface for measuring performance.
  - Real-time performance metrics and visualization.
  - Historical trend analysis for optimization tracking.
  - Bottleneck identification and reporting.
  - Performance baseline establishment.
  - Optimization impact measurement.
  - Detailed profiling data for diagnosis.

## Version 0.82

Version 0.82 delivers comprehensive gallery metadata organization capabilities, enabling administrators to intelligently structure galleries into hierarchical subgallery systems based on image metadata. This major feature release includes an interactive organization workflow with real-time preview, secure AJAX batch processing, and multiple organization strategies for transforming flat galleries into organized hierarchies.

  ### Highlights

  #### Gallery metadata organization system

  - Create hierarchical subgallery structures from image metadata automatically.
  - Multiple organization strategies (date, location, camera, custom fields).
  - Interactive workflow with real-time preview before applying changes.
  - Safe batch processing with error recovery and validation.
  - CSRF protection and admin-only access controls.

  #### Interactive organization workflow

  - Step-by-step wizard interface for organization setup.
  - Real-time preview of proposed gallery hierarchy.
  - Strategy customization and configuration.
  - Undo capability with confirmation dialogs.
  - Progress tracking for batch operations.

  #### Secure AJAX batch processing

  - Reliable batch operation handling with error recovery.
  - CSRF token rotation support for long-running tasks.
  - Form state management across multiple requests.
  - Comprehensive error reporting and logging.
  - Safe operation rollback on failure.

  ### Technical Details

  #### Backend

  - Created `app/services/gallery_metadata_organizer.php` (944 lines):
    * Gallery organization engine with multiple strategies
    * Date-based, location-based, camera-based hierarchies
    * Intelligent subgallery naming and conflict resolution
    * Batch processing with dry-run preview
    * Validation and safety checks

  - Enhanced `app/services/gallery_mutations.php` (276 lines):
    * Gallery deletion and subtree operations
    * Safe database row management
    * Foreign key handling and cleanup
    * SQL injection prevention

  - Enhanced `app/controllers/admin_galleries_edit.php`:
    * Metadata organization endpoints
    * AJAX handlers for preview and execution
    * Security validation and CSRF checks
    * Progress tracking and error handling

  #### Frontend

  - Created `public/assets/gallery-modules/admin-metadata-organizer.js` (945 lines):
    * Interactive organization UI
    * AJAX batch request handling
    * Form state management
    * Error handling and recovery
    * Progress visualization

  - Enhanced admin sidebar and dashboard
  - Responsive styling for all screen sizes
  - Visual feedback and status indicators

  #### Database

  - No new migrations required.
  - Uses existing gallery structure.

  #### Internationalization

  - Complete English and Czech translations
  - UI labels, help text, error messages
  - Strategy descriptions and options

  #### Testing

  - Unit tests for organization logic
  - Validation of strategies and edge cases

  ### User Impact

  #### For visitors

  - More organized gallery navigation when galleries are structured by metadata.
  - Improved content discovery through hierarchical organization.

  #### For administrators

  - Powerful one-click gallery organization by metadata.
  - Multiple organization strategies to choose from.
  - Interactive preview before making changes.
  - Safe operations with error recovery.
  - Detailed progress tracking during organization.

## Version 0.81.1

Version 0.81.1 fixes compatibility issues in the gallery reporting service. It adds proper namespace qualification for built-in PHP functions and function existence validation for disk space operations, ensuring the report system works correctly in restricted hosting environments and strict namespace contexts.

  ### Highlights

  #### Fixed namespace compatibility

  - Corrected namespace references in gallery report service.
  - Added function existence checks for disk space functions.
  - Better support for restricted hosting environments.
  - Improved PHP namespace compliance.

  ### Technical Details

  #### Backend

  - Fixed `admin_gallery_report_disk_free_bytes()`:
    * Use fully qualified `\disk_free_space()` function reference
    * Added `function_exists()` check before calling
    * Returns 0 gracefully if function unavailable
    * Handles restricted hosting environments

  - Fixed `admin_gallery_report_disk_total_bytes()`:
    * Use fully qualified `\disk_total_space()` function reference
    * Added `function_exists()` check before calling
    * Returns 0 gracefully if function unavailable
    * Supports hosting restrictions on disk functions

  - Updated `app/core-manifest.json`:
    * Regenerated with updated file hashes

  #### Database

  - No database changes required.

  #### Frontend

  - No frontend changes required.

  ### User Impact

  #### For visitors

  - No changes to public gallery functionality.

  #### For administrators

  - Gallery reports now work correctly in all hosting environments.
  - Better error handling when disk space functions are restricted.
  - No impact on storage reporting functionality when functions unavailable.

## Version 0.81

Version 0.81 is a major feature release delivering a comprehensive gallery reporting and analytics dashboard. It builds on the 0.80 release series (which introduced browser-based uploads, intelligent batch management, subgallery sorting, and upload validation) by adding powerful admin tools for understanding gallery content, storage usage, metadata coverage, and geographic distribution of images.

  ### Highlights

  #### Comprehensive gallery reporting and analytics

  - New interactive analytics dashboard with detailed gallery insights.
  - Real-time reporting on images, storage, metadata, and GPS locations.
  - Export gallery reports to HTML for offline review and sharing.
  - Identify metadata gaps and optimization opportunities.
  - Understand geographic distribution of images through GPS clustering.

  #### Detailed storage and metadata analysis

  - Storage breakdown by gallery with trend analysis.
  - Image type distribution and format analysis.
  - Metadata coverage reporting (EXIF, GPS, captions, tags).
  - Missing metadata identification and suggestions.
  - Database table statistics and exact row counts.

  #### GPS clustering with geographic intelligence

  - Intelligent geographic clustering of GPS-tagged images.
  - City-scale grouping using Haversine distance calculations.
  - Place matching against comprehensive offline database.
  - Fallback to nearest known location for out-of-radius clusters.
  - Visual display of geographic patterns in image collection.

  #### Telemetry and usage insights

  - Session statistics and usage trends.
  - Route popularity and gallery access patterns.
  - Multi-window analysis (7, 30, 90, 365-day views).
  - Detailed breakdown of visitor interactions.
  - Performance and optimization recommendations.

  ### Previous 0.80 Release Series Summary

  The 0.80 release series introduced significant improvements to the upload system and gallery management:

  - **0.80.0**: Complete browser-based upload system with client-side EXIF extraction, integrated thumbnail rebuild, batch idempotency recovery, real-time progress tracking, and deterministic image ordering.
  - **0.80.1**: Extracted gallery sorting logic into dedicated service layer for improved maintainability.
  - **0.80.2**: Added admin-only subgallery date sorting feature for temporary reordering by start date.
  - **0.80.3**: Improved upload validation with automatic detection and correction of mismatched file formats.
  - **0.80.4**: Removed query parameter versioning from public URLs for better hosting compatibility while maintaining asset cache management.
  - **0.80.5**: Fixed URL handling and improved cache management through HTTP headers.

  ### Technical Details

  #### Backend

  - Created `app/services/admin_gallery_report.php` (2245 lines):
    * Comprehensive reporting engine with analytics
    * Image statistics and aggregation
    * Storage usage tracking and breakdown
    * Metadata coverage analysis
    * GPS cluster identification and place matching
    * Database analysis with exact row counts
    * Telemetry integration and trending
    * Report generation and caching

  - Created `app/services/gallery_sorting.php` (125 lines):
    * Reusable gallery sorting utilities
    * Subgallery date sorting logic
    * Support for multiple sort strategies

  - Created `app/services/browser_uploads.php` (1459 lines):
    * Complete client-side upload workflow orchestration
    * Session and batch management
    * Image validation and format detection

  - Created `app/services/browser_thumbnail_rebuild.php` (786 lines):
    * Integrated thumbnail rebuild during uploads
    * On-demand rebuild operations
    * Async processing with fallback

  - Enhanced `app/controllers/admin_gallery_report.php`:
    * Report request handling and coordination
    * Data transformation for visualization

  - Enhanced `app/controllers/admin_galleries_reorder.php`:
    * Gallery reordering with sorting integration

  - Updated upload and image scanning services:
    * Better format detection and validation
    * Improved metadata handling

  #### Database

  - Added migration `202606100001_browser_client_upload_settings.php`:
    * Settings for client-side upload configuration

  - Added migration `202606100002_browser_upload_batch_safety.php`:
    * Safety markers for upload recovery

  - Added migration `202606100003_browser_thumbnail_rebuild_settings.php`:
    * Thumbnail rebuild configuration

  #### Frontend

  - Created `public/assets/gallery-modules/admin-gallery-report.js` (297 lines):
    * Interactive report UI and visualization
    * Real-time filtering and drill-down

  - Created `public/assets/gallery-modules/admin-browser-upload.js` (1172 lines):
    * Complete browser upload interface

  - Created `public/assets/gallery-modules/browser-image-worker.js` (986 lines):
    * Client-side image processing pipeline

  - Created `public/assets/gallery-modules/admin-browser-thumbnail-rebuild.js` (844 lines):
    * Thumbnail rebuild UI and progress tracking

  - Enhanced CSS:
    * Admin dashboard styling for reports
    * Upload and rebuild UI styling

  #### Internationalization

  - Added English translations for reporting UI
  - Added Czech translations for reporting UI
  - Complete language support for all new features

  ### User Impact

  #### For visitors

  - Faster uploads with client-side processing.
  - Better gallery organization with subgallery sorting by date.
  - Improved media URL handling and caching.

  #### For administrators

  - **Gallery insights**: Comprehensive reports on gallery content and organization.
  - **Storage management**: Detailed breakdown of storage usage by gallery.
  - **Metadata coverage**: Identify missing EXIF, GPS, captions, and tags.
  - **Geographic patterns**: Understand where images were taken through GPS clustering.
  - **Optimization**: Recommendations for missing metadata and optimization opportunities.
  - **Upload management**: Browser-based uploads with real-time progress and recovery.
  - **Gallery tools**: Subgallery date sorting, improved reordering, better filtering.
  - **Diagnostics**: Database analysis and telemetry for system health monitoring.

## Version 0.80.5

Version 0.80.5 fixes URL handling for public media and thumbnail assets. It removes query parameter-based cache versioning that was causing compatibility issues with shared-hosting rewrite paths and lightbox consumers. Public URLs now remain clean and parameter-free while maintaining proper cache management through HTTP headers and file system timestamps.

  ### Highlights

  #### Fixed public URL compatibility

  - Removed query parameter versioning from media and thumbnail URLs.
  - Public URLs now remain clean and parameter-free.
  - Improved compatibility with all hosting configurations and URL consumers.
  - Better support for shared-hosting rewrite paths.
  - Fixed issues with lightbox consumers and social media crawlers.

  #### Restored cache management via HTTP headers

  - Cache invalidation now relies on HTTP headers and file timestamps.
  - Cleaner URLs for better SEO and sharing.
  - Simplified URL handling throughout the system.

  ### Technical Details

  #### Backend

  - Modified `image_public_asset_url_with_version()`:
    * Now returns unmodified URLs without query parameters
    * Maintained for backward compatibility
    * Cache invalidation handled by HTTP layer

  - Modified `image_public_asset_version()`:
    * Now returns empty string (no version generated)
    * Previous hash-based versioning removed
    * Maintained for backward compatibility with callers

  - Modified `social_preview_cache_busted_url()`:
    * Returns clean URLs without query parameters
    * Removed version marker appending logic
    * Maintains compatibility with social crawlers

  - Updated `social_preview_image_from_thumbnail()`:
    * Uses clean preview URLs without parameters
    * Simplified URL handling
    * Better support for metadata consumers

  - Updated `app/core-manifest.json`:
    * Regenerated with current file hashes

  #### Database

  - No database changes required.

  #### Frontend

  - No frontend changes required.

  ### User Impact

  #### For visitors

  - Media and thumbnail URLs are now cleaner and simpler.
  - Better compatibility with all browsers and URL handlers.
  - Improved performance with shared-hosting configurations.
  - Social media previews work reliably without parameter issues.

  #### For administrators

  - Cache management simplified through HTTP headers.
  - URLs remain consistent and predictable.
  - Better compatibility with all URL rewrite systems.

## Version 0.80.4

Version 0.80.4 is a major feature release delivering a complete, production-ready browser-based upload system with integrated thumbnail rebuild, intelligent batch management, and smart asset versioning. This release moves image processing from the server to the browser, enabling faster uploads with real-time progress feedback, automatic recovery from failures, and seamless thumbnail generation.

  ### Highlights

  #### Complete browser-based upload system

  - Images are processed entirely on the client side before upload to server.
  - Drag-and-drop file selection with intuitive batch management UI.
  - Real-time progress tracking throughout the upload pipeline.
  - Automatic recovery from network interruptions and mid-flight failures.
  - Intelligent batch handling with configurable size and timeout settings.
  - Full EXIF metadata extraction in the browser before sending.

  #### Integrated thumbnail rebuild during upload

  - Thumbnails are automatically generated as images are uploaded.
  - On-demand rebuild for specific images or entire galleries.
  - Configurable rebuild behavior and settings in admin interface.
  - Progress tracking shows thumbnail generation status in real time.
  - Efficient cache invalidation prevents stale thumbnail delivery.

  #### Smart cache versioning for media assets

  - Media and thumbnail URLs now include automatic version tokens.
  - Browser cache remains valid during normal use but auto-invalidates when files change.
  - Files updated during uploads, renames, or rebuilds are fetched fresh.
  - No manual cache busting required from users.
  - Transparent versioning that doesn't break URL patterns.

  #### Enhanced admin controls

  - New browser upload settings panel for configuration.
  - Visual upload progress with real-time event display.
  - Detailed error messages and recovery options.
  - Batch thumbnail rebuild interface with progress visualization.
  - Network status awareness with reconnection support.

  ### Technical Details

  #### Backend

  - Created `app/services/browser_uploads.php` (1459 lines):
    * Complete upload workflow orchestration
    * Session and batch tracking with safety mechanisms
    * Image validation and format detection
    * Progress event management and reporting
    * Retry recovery and idempotency handling
    * Integration with experimental upload system

  - Created `app/services/browser_thumbnail_rebuild.php` (786 lines):
    * Integrated thumbnail rebuild during upload processing
    * On-demand rebuild for images and galleries
    * Settings management for rebuild behavior
    * Progress tracking for rebuild operations
    * Cache invalidation and maintenance
    * Async rebuild with fallback support

  - Enhanced `app/helpers.php`:
    * `image_public_asset_url_with_version()` - Append version to URLs
    * `image_public_asset_version()` - Generate stable cache versions
    * Updated `image_public_media_url()` for versioning
    * Updated `image_public_thumbnail_url()` for versioning

  - Updated controllers:
    * `app/controllers/admin_uploads.php` - New browser upload endpoints
    * `app/controllers/admin_thumbnails.php` - Rebuild administration

  - Updated services:
    * `app/services/thumbnail_bundles.php` - Derivative version handling
    * `app/services/thumbnail_sources.php` - Build coordination
    * Integrated thumbnail maintenance and metadata services

  #### Database

  - Added migration `202606100001_browser_client_upload_settings.php`:
    * Settings table for client-side upload configuration
    * Batch size, timeout, and retry parameters
    * Per-gallery upload behavior customization

  - Added migration `202606100002_browser_upload_batch_safety.php`:
    * Safety markers and checkpoints for batch recovery
    * Session tracking and idempotency markers
    * Recovery tracking for failed uploads

  - Added migration `202606100003_browser_thumbnail_rebuild_settings.php`:
    * Configuration for automatic thumbnail rebuild
    * Rebuild scheduling and priority settings
    * Per-gallery rebuild preferences

  #### Frontend

  - Created `public/assets/gallery-modules/admin-browser-upload.js` (1172 lines):
    * Complete upload UI with progress tracking
    * Drag-and-drop file selection
    * Batch management and queue visualization
    * Real-time event display and error handling
    * Settings panel for upload configuration
    * Network status awareness

  - Created `public/assets/gallery-modules/admin-browser-thumbnail-rebuild.js` (844 lines):
    * UI for triggering rebuild operations
    * Progress visualization for rebuild pipeline
    * Settings management for rebuild preferences
    * Batch rebuild operations
    * Status reporting and error display

  - Created `public/assets/gallery-modules/browser-image-worker.js` (986 lines):
    * Browser-side image processing pipeline
    * EXIF metadata extraction
    * Image variant generation (thumbnails, previews)
    * Format detection and validation
    * Concurrent image processing coordination
    * Memory-efficient handling of large files

  - Enhanced `public/assets/gallery-modules/admin-side-panel.js`:
    * Integration with upload and rebuild modules

  - Enhanced `public/assets/gallery-modules/admin-thumbnail-progress.js`:
    * Better progress visualization for rebuild operations

  - Enhanced `public/assets/styles.css`:
    * Styling for upload and rebuild UI components

  #### Tests

  - Added `tests/browser_upload_settings_test.php`:
    * Validation of upload settings management
    * Configuration storage and retrieval tests

  #### Documentation

  - Updated `ARCHITECTURE.md` with browser upload architecture details.
  - Updated `CODEMAP.md` with new upload-related files.
  - Updated `DATABASE.md` documenting new migrations.
  - Updated `docblock_manifest.txt` for new services.

  ### User Impact

  #### For visitors

  - Transparent asset versioning ensures fresh media and thumbnails.
  - No browser cache issues after gallery updates.
  - Gallery content always displays correctly without manual cache clearing.

  #### For administrators

  - Upload large image collections with real-time progress feedback.
  - Browser processes images efficiently, reducing server load.
  - Automatic thumbnail generation as part of upload pipeline.
  - On-demand thumbnail rebuild for individual images or galleries.
  - Intelligent recovery from network interruptions.
  - Upload configuration available in admin settings.
  - Detailed progress visualization throughout upload and rebuild.

## Version 0.80.3

Version 0.80.3 improves upload robustness by automatically detecting and correcting file format mismatches. Instead of blocking uploads due to incorrect file extensions, the system now detects the actual file format and corrects the extension, reducing upload failures and improving user experience when importing files from other sources.

  ### Highlights

  #### Enhanced file format validation and auto-correction

  - Automatically detect actual file format from file contents (magic bytes).
  - Correct mismatched file extensions based on detected format.
  - Continue with corrected filename instead of blocking upload.
  - Provide detailed diagnostics when format validation occurs.
  - Support recovery from common file format issues.

  #### Improved upload error reporting

  - Detailed error information for validation failures.
  - Format suggestions and auto-correction details in error messages.
  - Better diagnostics to help users understand format issues.

  ### Technical Details

  #### Backend

  - Added `experimental_upload_detect_payload_format()`:
    * Detect file format from binary payload headers (magic bytes)
    * Support JPEG, PNG, GIF, WebP, BMP, TIFF formats
    * Accurate format identification independent of filename

  - Added `experimental_upload_extension_matches_detected_format()`:
    * Validate file extension against detected format
    * Support multiple valid extensions per format (jpg/jpeg)
    * Case-insensitive comparison

  - Added `experimental_upload_filename_with_detected_extension()`:
    * Generate corrected filename with proper extension
    * Replace mismatched extension with detected format
    * Preserve original filename when possible

  - Added `experimental_upload_prepare_original_filename()`:
    * Prepare and validate original filenames before storage
    * Apply auto-correction based on detected format
    * Support fallback behavior for ambiguous formats

  - Added `experimental_upload_original_payload_diagnostics()`:
    * Provide detailed diagnostic information for validation
    * Include detected format, expected extension, and suggestions
    * Help users understand validation decisions

  - Created `ExperimentalUploadPayloadValidationError` exception:
    * Include error details in exception context
    * Provide programmatic access to validation information
    * Better error reporting throughout upload pipeline

  - Enhanced `app/controllers/admin_uploads.php`:
    * Use format detection and correction in upload pipeline
    * Pass correction details to response

  - Updated `app/services/experimental_uploads.php`:
    * Integrate format detection into validation workflow
    * Apply filename correction automatically
    * Improved error handling and diagnostics

  #### Frontend

  - Enhanced `public/assets/gallery-modules/admin-experimental-upload.js`:
    * Display format correction information to users
    * Show correction applied when extension is changed
    * Improve error messages with format details

  - Updated `public/assets/gallery-modules/experimental-upload-worker.js`:
    * Enhanced payload validation with format detection
    * Provide detailed format information in upload manifest

  #### Database

  - No database migrations required.

  ### User Impact

  #### For visitors

  - No changes to public gallery functionality.

  #### For administrators

  - Uploads with mismatched file extensions no longer fail.
  - System automatically detects and corrects extension mismatches.
  - Better feedback when file format issues are detected.
  - Easier importing of files from other sources with incorrect extensions.
  - Fewer upload failures due to naming issues.

## Version 0.80.2

Version 0.80.2 improves code organization and maintainability by extracting gallery sorting logic into a dedicated service layer. This refactor reduces duplication, improves testability, and provides a foundation for extending sorting capabilities across different gallery management workflows.

  ### Highlights

  #### Extracted gallery sorting into dedicated service

  - Moved sorting logic from public gallery controller into a reusable service.
  - Created `app/services/gallery_sorting.php` as the single source of truth for all sorting operations.
  - Improved code organization and reduced controller complexity.
  - Foundation for consistent sorting behavior across admin and public interfaces.

  #### Enhanced admin gallery reorder workflow

  - Added admin-specific gallery sorting controls in the reorder interface.
  - Support for multiple sort strategies from a unified service.
  - Better integration between admin reorder and sorting operations.

  ### Technical Details

  #### Backend

  - Created new `app/services/gallery_sorting.php`:
    * `public_subgallery_date_sort_mode()` - Parse and validate sort mode from query parameter
    * `public_subgallery_has_start_date()` - Check if gallery has a usable start date
    * `public_count_dated_subgalleries()` - Count galleries with filled start dates
    * `public_sort_subgalleries_by_date()` - Reorder subgalleries by start date
    * `render_public_subgallery_date_sort_toolbar()` - Render the sort control UI
    * `is_valid_subgallery_sort_direction()` - Validate sort direction parameter

  - Refactored `app/controllers/public_gallery.php`:
    * Removed sorting logic (delegated to gallery_sorting service)
    * Simplified `cms_gallery()` by importing service functions
    * Reduced controller size and improved readability
    * Better separation of concerns

  - Enhanced `app/controllers/admin_galleries_reorder.php`:
    * Added admin-specific sorting controls
    * Integrated with gallery_sorting service
    * Support for multiple sort strategies
    * Improved admin workflow for managing gallery order

  - Updated `app/services.php`:
    * Registered new gallery_sorting service
    * Made sorting functions available throughout the application

  #### Database

  - No database migrations required.
  - Leverages existing `gallery_date` column for date-based sorting.

  #### Frontend

  - Enhanced `public/assets/styles/utilities.css`:
    * Additional styling for reorder and sort controls
    * Consistent visual indicators for active sort mode

  #### Internationalization

  - Updated Czech translations in `app/lang/cs.json` and `app/lang/cs.php`.
  - Updated English translations in `app/lang/en.json` and `app/lang/en.php`.
  - Consistent terminology for sort controls and options.

  ### User Impact

  #### For visitors

  - No changes to public gallery display or functionality.
  - Sorting behavior remains consistent and unchanged.

  #### For administrators

  - Cleaner, more intuitive sorting controls in the gallery reorder interface.
  - Consistent sorting behavior across different admin workflows.
  - Better organization of reordering and sorting options.

## Version 0.80.1

Version 0.80.1 adds an admin-only subgallery date sorting feature for curators reviewing galleries. Logged-in administrators can now temporarily reorder subgalleries by their start date while viewing a gallery page, without modifying the permanent gallery structure or affecting public visitors.

  ### Highlights

  #### Added admin-only subgallery date sorting

  - Administrators can temporarily reorder subgalleries by start date while viewing a gallery.
  - Sort direction can be toggled between ascending, descending, and default order.
  - Sorting only applies to subgalleries that have a filled start date.
  - Date sort control is hidden when fewer than 2 dated subgalleries exist.
  - Reorder toolbar is hidden when date sorting is active to avoid UI confusion.
  - Changes are temporary and do not modify the gallery structure.

  ### Technical Details

  #### Backend

  - Added `public_subgallery_date_sort_mode()` to parse and validate the sort mode query parameter.
  - Added `public_subgallery_has_start_date()` to check if a gallery has a usable start date.
  - Added `public_count_dated_subgalleries()` to count subgalleries with start dates.
  - Added `public_sort_subgalleries_by_date()` to reorder subgalleries by their start date.
  - Added `render_public_subgallery_date_sort_toolbar()` to render the admin-only sort control.
  - Updated `app/controllers/public_gallery.php` to integrate date sort functionality into the gallery rendering pipeline.
  - Updated logic to disable drag reorder on subgallery cards when date sorting is active.

  #### Database

  - No database migrations required.
  - Leverages existing `gallery_date` column for start date sorting.

  #### Frontend

  - Added CSS styling in `public/assets/styles/utilities.css` for the date sort toolbar.
  - Added visual indicators for active sort mode.
  - Responsive layout for sort controls on mobile and desktop views.

  #### Internationalization

  - Added Czech translations in `app/lang/cs.json` and `app/lang/cs.php`.
  - Added English translations in `app/lang/en.json` and `app/lang/en.php`.

  ### User Impact

  #### For visitors

  - Public gallery views are unchanged. Subgalleries continue to display in their default order.

  #### For administrators

  - An admin-only sort overlay is available while viewing gallery pages.
  - Click the sort control to toggle between ascending date, descending date, and default order.
  - Drag reordering is disabled while date sorting is active to prevent UI confusion.
  - Permanent gallery order is never modified by date sorting.

## Version 0.80

Version 0.80 is a major quality-of-life release focused on upload reliability, performance, and user experience. It refactors the experimental upload system with client-side EXIF metadata extraction, robust batch idempotency recovery, real-time progress tracking, and deterministic image ordering. It also fixes map pin positioning accuracy, improves admin storage statistics and logging, and strengthens the overall data integrity throughout the platform.

  ### Highlights

  #### Refactored experimental upload with client-side EXIF processing

  - Implemented browser-based EXIF and GPS metadata extraction from JPEG files, eliminating server-side parsing overhead during uploads.
  - Added robust batch idempotency recovery so uploads can be safely retried without duplicating stored images.
  - Introduced real-time upload event tracking with detailed progress feedback to users throughout the pipeline.
  - Preserved deterministic image ordering across multi-batch uploads to match the user's original file selection.

  #### Fixed map pin accuracy for location display

  - Corrected the icon anchor positioning so map pins now display location at the visual pin point rather than above the marker.
  - Improved marker positioning for active photo pins and route waypoint markers.

  #### Enhanced admin storage statistics

  - Added detailed storage breakdown by file type and category.
  - Improved query performance and accuracy of storage reporting.

  #### Improved admin logs and diagnostics

  - Enhanced log filtering and search capabilities.
  - Added better formatting and readability to log entries.

  ### Technical Details

  #### Backend

  - Refactored `app/services/experimental_uploads.php` with batch markers, state management, and recovery functions.
  - Enhanced `app/services/image_scanning.php` with uncached lookups and client-provided EXIF metadata handling.
  - Added `app/services/public_paths.php` for public path resolution utilities.
  - Expanded `app/services/thumbnail_metadata.php` with enhanced metadata record management.
  - Updated `app/services/uploads.php` with event tracking and improved status reporting.
  - Enhanced `app/controllers/admin_uploads.php` to track and report upload events.
  - Improved `app/controllers/admin_logs.php` filtering and display.
  - Updated `app/services/admin_storage_statistics.php` with comprehensive breakdown reporting.

  #### Database

  - No new migrations required.
  - Improved query performance with added LIMIT clauses and proper indexing.

  #### Frontend

  - Added new `public/assets/gallery-modules/experimental-upload-worker.js` for browser-side JPEG EXIF parsing.
  - Significantly enhanced `public/assets/gallery-modules/admin-experimental-upload.js` with progress indicators and event timeline.
  - Updated `public/assets/gallery-modules/admin-side-panel.js` with event display and order tracking.
  - Improved `public/assets/gallery-modules/lightbox.js` map pin positioning with corrected icon anchors.
  - Added CSS styling in `public/assets/styles/public.css` and `public/assets/styles/side-panel.css` for upload progress visualization.

  #### Tests

  - Verified EXIF parsing with various JPEG files.
  - Tested batch idempotency recovery under network failure scenarios.
  - Validated image order preservation across multi-batch uploads.

  ### User Impact

  #### For visitors

  - Map pins now display your location at the exact point of the marker pin.
  - Improved accuracy of location indicators on map-based galleries.

  #### For administrators

  - Faster uploads with client-side EXIF extraction reducing server load.
  - Automatic recovery from interrupted uploads without requiring manual retry.
  - Real-time visibility into upload progress with detailed event tracking.
  - Images always maintain their original selection order across multiple batch uploads.
  - Enhanced storage statistics showing detailed breakdown by file type.
  - Improved admin logs with better filtering and search capabilities.

## Version 0.79.2

Version 0.79.2 synchronizes the release metadata after the 0.79.1 admin editor and version-display fixes, so the public footer, Admin update menu, patch notes, and core integrity manifest all report the same patch-level release.

  ### Highlights

  #### Fixed release version metadata

  - Updated the runtime CMS version marker to `0.79.2`.
  - Added a `0.79.2` patch-note entry above the older release history.
  - Regenerated the core manifest with the `0.79.2` release version and current file hashes.

  #### Preserved A.B.C version handling

  - Kept the installed-version display aligned with full semantic-style patch versions such as `0.79.2`.
  - Kept footer and Admin update menu consumers reading the same runtime version marker.

  ### Technical Details

  #### Backend

  - Updated `app/bootstrap.php` so `CMS_VERSION` reports `0.79.2`.
  - Regenerated `app/core-manifest.json` from the current working tree after the version and patch-note changes.

  #### Database

  - No database migrations were required.

  #### Frontend

  - No frontend asset changes were required.

  #### Tests

  - Verified `app/bootstrap.php` with PHP syntax checks.
  - Verified `app/core-manifest.json` with the manifest check command.

  ### User Impact

  #### For visitors

  - The public footer now displays version `0.79.2`.

  #### For administrators

  - The Admin update menu now displays installed version `0.79.2`.
  - The core integrity manifest now matches the updated patch notes and runtime version marker.

## Version 0.79.1

Version 0.79.1 tightens the admin gallery editor and public media routing so the gallery edit workflow can render the media row list correctly while public media links keep using the canonical public base URL.

  ### Highlights

  #### Improved admin gallery editing

  - Added the missing admin gallery editor dependencies for gallery file-name display and thumbnail URL rendering.
  - Restored the gallery editor footer rendering path needed by the updated edit view.
  - Kept the gallery edit workflow aligned with the current tabbed admin panel structure.

  #### Refined public media routing

  - Added `public_base_url()` to the public media controller so public media responses can build canonical links consistently.
  - Kept public media handling aligned with the site’s public URL configuration.

  ### Technical Details

  #### Backend

  - Updated `app/controllers/admin_galleries_edit.php` to import `render_footer`, `gallery_shows_filenames`, and `thumbnail_url`.
  - Updated `app/controllers/public_media.php` to import `public_base_url`.

  ### User Impact

  #### For visitors

  - Public media links remain consistent with the configured public base URL.

  #### For administrators

  - The gallery editor now has the dependencies it needs to render file-name and thumbnail-related UI correctly.

## Version 0.79

Version 0.79 is a broad reliability, performance, and maintainability release focused on public gallery rendering,
  thumbnail metadata, media delivery, Admin discovery workflows, Admin log usability, and the internal namespace
  migration. The release keeps the plain PHP architecture and existing entry points, but moves most application
  internals into explicit Gallery\Core, Gallery\Services, Gallery\Controllers, and Gallery\Views namespaces. It also
  improves the public lightbox pipeline so visitors see fast thumbnail previews first, then full media after decode,
  while administrators get better progress feedback for discovery, thumbnail checks, log filtering, and database
  metadata refreshes.

  ### Highlights

  #### Added namespaced application internals

  - Added explicit namespaces for the main application layers:
      - Gallery\Core for bootstrap, routing, database, helpers, migrations, security, integrity, and loader modules.
      - Gallery\Services for reusable domain logic such as gallery lookup, thumbnail metadata, public search, settings,
        upload helpers, thumbnail maintenance, and public rendering support.

      - Gallery\Controllers for request handlers such as public gallery pages, media routes, admin dashboard actions,
        uploads, thumbnail actions, search, votes, and setup.

      - Gallery\Views for shared view helpers and server-rendered UI fragments.

  - Updated app/bootstrap.php so route dispatch points at namespaced controller handlers where applicable, for example
    \Gallery\Controllers\cms_gallery, \Gallery\Controllers\cms_media, \Gallery\Controllers\cms_admin_thumbnails, and
    \Gallery\Controllers\cms_public_search.

  - Preserved top-level browser and CLI entry points as global files so existing hosting setups and direct scripts
    continue to work:
      - index.php
      - public/index.php
      - install.php
      - setup-gallery.php
      - scripts/create_admin.php
      - scripts/migrate.php
      - scripts/site_maintenance.php

  - Updated scripts and standalone tests to import namespaced functions explicitly instead of relying on old global
    function names.

  - Added tests/support/namespaced_shims.php so standalone model tests can exercise namespaced services without
    bootstrapping the full browser application.

  - Example: a direct web request still enters through public/index.php, but the route handler now resolves through the
    namespaced controller table in app/bootstrap.php.

  #### Improved public gallery performance

  - Improved large parent gallery rendering by batching gallery branch image counts instead of repeatedly querying each
    child gallery.

  - Added gallery_branch_image_counts() in app/services/gallery_lookup.php so child gallery cards can receive their
    picture totals from one shared service path.

  - Updated app/controllers/public_gallery.php to preload gallery-card rendering context and branch counts before
    rendering subgallery cards.

  - Added request-local thumbnail bundle caching in app/services/thumbnail_bundles.php.
  - Added thumbnail_bundles_preload() so visible gallery images can warm thumbnail metadata and bundle data in a batch
    before individual cards ask for URLs.

  - Reduced repeated thumbnail filesystem probing by preferring durable database metadata when valid thumbnail rows are
    available.

  - Improved public render profiler counters for thumbnail bundle requests, cache hits, cache misses, fallback searches,
    rendered image cards, and rendered subgallery cards.

  - Example: a parent gallery with many child branches can now render child card counts and cover thumbnails through
    batched lookup helpers rather than performing repeated per-card database and filesystem work.

  #### Improved lightbox preview and full-media pipeline

  - Updated the public gallery image markup so each lightbox source carries two separate media URLs:
      - data-preview-src points to a generated preview thumbnail, usually a larger thumbnail such as thumb-1600.webp.
      - data-full-src points to the robust full-media route, for example index.php?page=media&id=236.

  - Updated public/assets/gallery-modules/lightbox.js so opening the viewer shows the preview source first, then loads
    and decodes the full media source in the background.

  - Preserved the preview image if the full media source fails, so navigation remains usable even when the original or
    display derivative cannot be loaded.

  - Updated fullscreen behavior so the viewer uses the decoded full media source after the full-media swap succeeds.
  - Preserved nearby-image preloading so previous/next navigation remains responsive.
  - Added a visible loading status message for initial lightbox startup:
      - English: Preparing gallery...
      - Czech: Připravuji galerii...

  - Added accessible loader semantics with role="status" and aria-live="polite" so assistive technology can announce the
    loading state.

  - Updated the deferred lightbox loader in public/assets/gallery-modules/lightbox-deferred.js so visitors get immediate
    feedback while the heavier lightbox module is loading.

  - Example: when a visitor opens a large photo, the black empty frame is replaced by a clear preparation message, then
    the preview thumbnail appears quickly, and the full image replaces it after the browser finishes decoding.

  #### Improved media and thumbnail route behavior

  - Updated app/controllers/public_media.php so robust media URLs such as index.php?page=media&id=... can serve
    browser-displayable media reliably.

  - Updated clean public media URLs such as /gallery/.../photo-slug/media to avoid namespace-related telemetry failures
    after the namespace migration.

  - Preserved generated thumbnail routes such as:
      - /gallery/.../photo-slug/thumb-600.webp
      - /gallery/.../photo-slug/thumb-1600.webp

  - Improved media fallback behavior so the main media route can serve the best available browser-displayable derivative
    when the original source is unavailable but generated derivatives exist.

  - Updated public media and immutable asset responses to use cache headers such as Cache-Control: public, max-
    age=31536000, immutable where appropriate.

  - Kept private media cases on private cache policies when gallery/image access rules require it.
  - Removed thumbnail-served telemetry from thumbnail routes so thumbnail requests no longer generate
    media.thumbnail.served events.

  - Preserved media.image.served telemetry for full media delivery where appropriate.
  - Example: the lightbox no longer depends on a clean /media URL being perfect; its full source uses the robust
    index.php?page=media&id=... route while thumbnail grids and previews keep using thumbnail routes.

  #### Added compact thumbnail metadata storage

  - Added migration database/migrations/202606130001_compact_thumbnail_variant_metadata.php.
  - Added master image metadata columns to images:
      - display_width
      - display_height
      - exif_orientation
      - thumbnail_derivative_version
      - thumbnail_metadata_refreshed_at

  - Added derivative_version to image_thumbnail_variants.
  - Removed duplicated source payload columns from image_thumbnail_variants after moving source-level display metadata
    to images.

  - Updated app/services/thumbnail_metadata.php so thumbnail variant rows are validated against compact image-level
    metadata and derivative version markers.

  - Updated app/services/image_scanning.php so scanned images synchronize display dimensions and EXIF orientation to the
    master images row.

  - Updated thumbnail metadata refresh behavior so stale variants can be invalidated by incrementing
    images.thumbnail_derivative_version.

  - Added thumbnail_metadata_storage_snapshot() diagnostics so Admin maintenance and database views can report whether
    the compact schema is active.

  - Example: instead of storing source width, height, MIME type, checksum, EXIF orientation, and EXIF JSON on every
    thumbnail variant row, the image stores source/display facts once and each derivative row stores only derivative-
    specific state.

  #### Improved thumbnail maintenance and repair progress

  - Updated app/controllers/admin_thumbnails.php so thumbnail checks can run in browser-driven batches.
  - Added dry-check progress reporting for missing or stale thumbnails.
  - Added targeted repair queue behavior so “Create missing thumbnails” can work from the latest successful missing-
    thumbnail check instead of blindly scanning everything again.

  - Updated public/assets/gallery-modules/admin-thumbnail-progress.js to show:
      - how many images were checked,
      - how many images still need thumbnails,
      - how many thumbnail variants are missing or stale,
      - when targeted repair is ready,
      - when targeted repair has nothing left to do.

  - Improved legacy JPG cleanup progress messaging with processed counts, deleted file counts, and freed byte totals.
  - Updated site maintenance so thumbnail metadata snapshots and thumbnail repair summaries include compact metadata
    counters such as refreshed metadata rows and source metadata syncs.

  - Example: an administrator can run “Check missing thumbnails”, watch progress update in the browser, and then run a
    targeted “Create missing thumbnails” pass only for the affected images.

  #### Improved Admin gallery discovery

  - Added app/services/admin_gallery_discovery.php to move filesystem discovery logic out of the controller and into a
    reusable service.

  - Updated app/controllers/admin_galleries_discovery.php to orchestrate discovery jobs, JSON responses, import actions,
    move actions, delete actions, and final user feedback.

  - Added browser-driven discovery progress in public/assets/gallery-modules/admin-refresh-progress.js.
  - Added discovery job state so large gallery trees can be scanned in smaller server batches instead of one long
    blocking request.

  - Added dynamic discovery results that show candidate folders and the action that will be applied.
  - Added support for three discovery actions:
      - Import selected folders in place as new galleries.
      - Move supported photo files into an existing gallery folder.
      - Delete selected unmanaged folders from disk.

  - Added destination-gallery selection for move actions.
  - Added clear explanations for what each action does before the administrator submits it.
  - Added safeguards so delete actions only target selected unmanaged directories under the gallery root.
  - Added duplicate and sibling-title detection so likely duplicate gallery folders are highlighted and not silently
    imported as confusing duplicates.

  - Added metadata-only folder handling so folders with only gallery.json or sidecar metadata but no supported photos
    are ignored instead of being offered as empty galleries.

  - Added thumbnail follow-up integration so discovery import or move actions can trigger thumbnail creation only when
    images were actually scanned.

  - Example: if a folder contains photos but is not yet a CMS gallery, Admin discovery can now show the folder, count
    its photos, warn about duplicate sibling titles, and let the administrator import it, move the photos elsewhere, or
    delete the unmanaged folder.

  #### Improved Admin logs

  - Updated the Admin logs page with grouped log rows for repeated events.
  - Added grouped instance counts so repeated log events can appear as one representative row with a visible count.
  - Added optional ungrouped view so administrators can still inspect individual rows when needed.
  - Added persistent multi-select severity filtering.
  - Added page-size choices for log browsing.
  - Added pagination with preserved filters and sort order.
  - Added newest-first and oldest-first sorting.
  - Added live filter updates in public/assets/gallery-modules/admin-logs.js so category, severity, grouping, row count,
    text search, and page changes can refresh without a full page reload.

  - Added grouped-row bulk status updates so selecting a grouped row can apply the chosen status to every matching
    instance in that group.

  - Added grouped TXT export support so administrators can export a representative event or an entire grouped set.
  - Preserved the full ZIP log export action.
  - Example: instead of scrolling through hundreds of identical thumbnail warmup warnings, an administrator can group
    similar events, see that one row represents many entries, expand all grouped instances, and mark the group as
    reviewed.

  #### Improved database usage and storage reporting

  - Updated app/services/admin_database_usage.php and app/views/admin_database_usage.php with safer table metadata
    handling for MySQL/MariaDB hosting environments.

  - Added admin_database_usage_recompute route support for refreshing database table metadata.
  - Added a “Recompute DB metadata” workflow that can run ANALYZE TABLE for current database tables, then reload row and
    size estimates.

  - Improved unavailable-state handling when information_schema.TABLES metadata cannot be read on restricted hosting.
  - Updated Admin storage statistics views with compact cards and clearer database-vs-file storage separation.
  - Example: administrators can refresh database size estimates from the dashboard without modifying gallery data or
    rebuilding tables.

  #### Improved public search and visibility filtering

  - Renamed public listing SQL helper functions to make their interpolation contract explicit:
      - public_gallery_listing_sql_fragment()
      - public_search_context_listing_sql_fragment()

  - Added @internal documentation to both helpers.
  - Documented that these SQL fragments are hardcoded-only and must not contain user-derived values.
  - Updated call sites in public gallery rendering, gallery lookup, tag metadata, public search, and picture game logic.
  - Preserved anonymous visibility filtering for public gallery listings and search results.
  - Improved picture-game availability checks so they use bounded database queries instead of loading all eligible
    images.

  - Example: public search still filters private and unlisted content, but the shared SQL helper names now make it clear
    that the returned SQL is a static fragment intended for prepared-query assembly.

  #### Improved public asset and telemetry handling

  - Added public/assets/usage.js.
  - Added public/assets/telemetry.js.
  - Updated asset loading in app/views/layout.php, public/assets/gallery.js, and public/assets/public-gallery.js.
  - Updated public asset loading tests so the public and admin bundles load the correct module sets.
  - Improved telemetry privacy and rollup service wiring in:
      - app/services/telemetry.php
      - app/services/telemetry_privacy.php
      - app/services/telemetry_rollup.php
      - app/services/telemetry_settings.php

  - Kept thumbnail route telemetry suppressed while preserving full media telemetry.
  - Example: public pages can load smaller purpose-specific client modules while telemetry and usage collection remain
    separated from thumbnail serving.

  #### Improved documentation and code comments

  - Updated ARCHITECTURE.md and CODEMAP.md for discovery, thumbnail, and namespace-related architecture changes.
  - Added docblock_manifest.txt.
  - Standardized many PHP and JavaScript docblocks to use consistent descriptions, @param, and @return annotations.
  - Updated app/core-manifest.json to reflect the changed application files and assets.
  - Preserved inline comments and avoided changing historical patch notes as part of the release work.
  - Example: service functions that are shared by controllers and tests now have clearer docblocks, making it easier to
    audit responsibilities after the namespace migration.

  ### Technical Details

  #### Backend

  - Added namespace declarations across core modules, many controllers, services, and views.
  - Updated app/bootstrap.php route dispatch to use fully qualified namespaced controller handlers.
  - Updated public/index.php and script entry points to import namespaced core functions.
  - Added app/services/admin_gallery_discovery.php for batched discovery jobs, candidate generation, unmanaged-folder
    deletion, move-photo workflows, duplicate detection, metadata-only folder detection, and discovery job persistence.

  - Updated app/controllers/admin_galleries_discovery.php to support discovery actions such as start, status, and step.
  - Updated app/controllers/admin_galleries_discovery.php to return JSON payloads for browser-driven discovery progress.
  - Updated app/controllers/admin_galleries_discovery.php to handle discovery import actions including import_in_place,
    move_photos, and delete_from_disk.

  - Updated app/services/gallery_mutations.php so discovery import workflows can reuse gallery mutation logic instead of
    duplicating filesystem/database behavior in the controller.

  - Updated app/controllers/admin_thumbnails.php with batched thumbnail check endpoints, repair token handling, targeted
    missing-thumbnail repair, and safer maintenance status responses.

  - Updated app/services/thumbnail_maintenance.php with report merging, batch checking, last-check storage, targeted
    image IDs, and compact missing/stale variant summaries.

  - Updated app/services/thumbnail_metadata.php with compact schema support, renderable row preloading, request-local
    caches, derivative version validation, image source payload syncing, metadata refresh results, and storage
    snapshots.

  - Updated app/services/thumbnail_bundles.php with request-local bundle resolution, database-backed variant selection,
    fallback selection, and media fallback URLs.

  - Updated app/services/thumbnail_sources.php with metadata-backed srcset and thumbnail URL resolution.
  - Updated app/services/image_scanning.php to persist display dimensions, EXIF orientation, and thumbnail metadata
    refresh timestamps to the master image row.

  - Updated app/services/dng_derivatives.php and related image services so DNG-derived display metadata can participate
    in the same compact thumbnail metadata workflow.

  - Updated app/controllers/public_media.php with improved full-media and thumbnail cache policies, robust media route
    behavior, and guarded media telemetry logging.

  - Updated app/controllers/public_gallery.php with batched gallery-card context loading, branch count preloading,
    lightbox preview/full source attributes, and loader markup.

  - Updated app/services/gallery_lookup.php with gallery_branch_image_counts() and related batched lookup helpers.
  - Updated app/services/public_paths.php with public_gallery_listing_sql_fragment().
  - Updated app/services/public_search.php with public_search_context_listing_sql_fragment().
  - Updated app/services/picture_game.php so availability checks remain fast and bounded.
  - Updated app/services/tag_metadata.php so tag metadata counts continue to honor public visibility filters after the
    SQL helper rename.

  - Updated app/controllers/admin_logs.php with grouped log rendering, persistent severity filters, pagination, live
    filter JSON payloads, grouped bulk updates, grouped exports, and improved fallback translation handling.

  - Updated app/services/logs.php with grouped log count/list helpers, group hashes, grouped status updates, and
    improved filter support.

  - Updated app/controllers/admin_dashboard.php and app/services/admin_database_usage.php with
    admin_database_usage_recompute support.

  - Updated app/services/site_maintenance.php with compact thumbnail metadata cleanup, thumbnail metadata snapshots,
    orphan cleanup, and richer maintenance summaries.

  - Updated app/controllers/upload_automation.php, app/services/upload_automation.php, app/services/uploads.php, and
    experimental upload/rebuild services to keep upload-derived thumbnails and metadata aligned with the compact
    metadata model.

  - Updated app/services/updates.php, scripts/generate_manifest.php, and app/core-manifest.json so update/integrity
    workflows understand the changed file set.

  #### Database

  - Added migration database/migrations/202606130001_compact_thumbnail_variant_metadata.php.
  - Added images.display_width for browser-display width after EXIF orientation is considered.
  - Added images.display_height for browser-display height after EXIF orientation is considered.
  - Added images.exif_orientation to store the source orientation used by thumbnail geometry validation.
  - Added images.thumbnail_derivative_version to invalidate stale derivative metadata when the source changes.
  - Added images.thumbnail_metadata_refreshed_at to record when image-level thumbnail metadata was refreshed.
  - Added image_thumbnail_variants.derivative_version.
  - Removed duplicated source metadata columns from image_thumbnail_variants, including legacy source dimensions, MIME
    type, file size, modified time, checksum, EXIF orientation, and EXIF JSON payload fields.

  - Removed the old image_thumbnail_variants.gallery_id dependency from the compact schema.
  - Migrated existing source dimensions from image_thumbnail_variants into images.display_width, images.display_height,
    and images.exif_orientation when valid values were available.

  - Updated existing variant rows so their derivative_version matches the corresponding image derivative version.
  - Updated maintenance cleanup to delete orphan thumbnail metadata rows when matching image or gallery records no
    longer exist.

  #### Frontend

  - Updated public/assets/gallery-modules/lightbox.js with preview-first loading, full-media decode-and-swap, fullscreen
    full-media behavior, initial loader persistence, and adjacent preloading preservation.

  - Updated public/assets/gallery-modules/lightbox-deferred.js so deferred lightbox activation shows a small loader
    immediately and imports the updated full lightbox module.

  - Updated public/assets/gallery-modules/lightbox-votes.js to keep vote controls synchronized with the lightbox after
    the module split.

  - Updated public/assets/gallery-modules/admin-refresh-progress.js with Ajax discovery progress, dynamic candidate
    rendering, action-specific controls, move-target handling, delete confirmation, and discovery result messages.

  - Updated public/assets/gallery-modules/admin-thumbnail-progress.js with missing-thumbnail check progress, targeted
    repair token propagation, repair completion messaging, and legacy JPG cleanup progress.

  - Updated public/assets/gallery-modules/admin-logs.js with live log filters, severity summary updates, page changes,
    time-sort changes, no-results handling, and progressive status text.

  - Updated public/assets/gallery-modules/admin-gallery-list.js, admin-side-panel.js, admin-media-renamer.js, picture-
    manager.js, public-home-search.js, responsive-thumbnails.js, and other modules to work with the namespace, asset-
    loading, and updated admin/public workflows.

  - Added public/assets/usage.js.
  - Added public/assets/telemetry.js.
  - Updated app/views/layout.php so public pages load the deferred public lightbox path and admin pages load the fuller
    admin module set.

  - Updated app/views/admin_dashboard_sections.php with discovery, thumbnail, and database usage controls.
  - Updated app/views/admin_database_usage.php and app/views/admin_storage_statistics.php for the improved Admin
    database/storage display.

  - Updated app/views/admin_upload_settings.php, app/views/admin_gallery_migration.php, and related Admin views to match
    the newer service/controller layout.

  - Updated language files app/lang/en.json and app/lang/cs.json with new labels, progress messages, Admin log strings,
    discovery messages, thumbnail check messages, and the lightbox loader text.

  #### Tests

  - Updated tests/admin_database_usage_test.php for database usage model changes.
  - Updated tests/admin_log_severity_filter_test.php for persistent multi-select severity filtering.
  - Updated tests/admin_storage_statistics_test.php for the refreshed storage/statistics model.
  - Updated tests/dng_conversion_policy_test.php for DNG metadata and conversion policy changes.
  - Updated tests/experimental_upload_settings_test.php for upload setting behavior that now participates in the compact
    metadata release.

  - Updated tests/favorite_galleries_model_test.php, tests/gallery_branding_model_test.php, and tests/
    gallery_dates_model_test.php to import namespaced service/view functions explicitly.

  - Added tests/support/namespaced_shims.php for deterministic standalone testing of namespaced helpers.
  - Updated tests/gallery_lightbox_mode_model_test.php for lightbox mode behavior.
  - Updated tests/gallery_migration_model_test.php for gallery migration behavior after service changes.
  - Updated tests/gallery_visibility_model_test.php for public visibility filtering and SQL helper behavior.
  - Updated tests/openai_text_assist_model_test.php for namespaced service compatibility.
  - Updated tests/public_asset_loading_model_test.php for public/admin asset loading and module inclusion.
  - Updated tests/thumbnail_compatibility_model_test.php and tests/thumbnail_warmup_model_test.php for thumbnail
    metadata and warmup behavior.

  - Updated tests/upload_accept_and_dng_gps_test.php and tests/upload_automation_sim_camera_metadata_test.php for upload
    metadata and DNG/GPS handling.

  - Updated tests/url_rewrite_settings_test.php for namespaced route and URL helper behavior.

  #### Tooling and scripts

  - Updated scripts/create_admin.php for namespaced core/database helpers.
  - Updated scripts/migrate.php for namespaced migration execution.
  - Updated scripts/site_maintenance.php for namespaced maintenance service calls.
  - Updated scripts/generate_manifest.php for the expanded manifest/integrity workflow.
  - Updated setup-gallery.php and install.php to remain compatible with the new namespaced bootstrap.
  - Updated winapp/gallery_watch_upload.pyw alongside upload/import workflow changes.
  - Added docblock_manifest.txt to support the code documentation consistency pass.

  ### User Impact

  #### For visitors

  - Public gallery pages should feel faster on large galleries because gallery-card counts, thumbnail bundles, and
    thumbnail metadata are loaded more efficiently.

  - The lightbox now gives clear visual feedback while preparing the viewer instead of showing only a black frame.
  - The lightbox shows a preview thumbnail quickly, then upgrades to the full image when the browser has decoded it.
  - Fullscreen viewing continues to use the full media source after the full image is ready.
  - Previous/next navigation remains usable even if a full media file fails to load, because the preview remains
    visible.

  - Public thumbnail and media responses use stronger cache headers where safe, improving repeat visits and browser-
    cache behavior.

  - Public search and gallery visibility rules continue to hide private or unlisted content from anonymous visitors.

  #### For administrators

  - Admin gallery discovery is more usable for large filesystem trees because it runs through visible progress steps
    instead of one opaque request.

  - Discovery results explain what will happen before an import, move, or delete action is run.
  - Move actions can physically move supported photo files into an existing gallery and scan the destination gallery
    afterward.

  - Delete actions are clearer and safer because they are limited to selected unmanaged folders.
  - Metadata-only folders are ignored with an explanation instead of becoming confusing empty import candidates.
  - Thumbnail maintenance is easier to operate because checks show progress and targeted repair becomes available only
    after a successful check.

  - Admin logs are easier to triage because repeated events can be grouped, filtered by multiple severities, paginated,
    searched live, exported, and bulk-updated.

  - Database and storage panels provide clearer capacity information and can refresh table metadata without changing
    gallery content.

  - The namespace migration makes future feature work safer by reducing accidental global-function collisions and making
    dependencies explicit.

  #### Compatibility notes

  - Existing public URLs, clean gallery URLs, thumbnail URLs, media URLs, setup scripts, migration scripts, and
    standalone tests remain supported.

  - The application still has no Composer or Node build requirement.
  - The compact thumbnail metadata migration changes the shape of image_thumbnail_variants; code or manual SQL that
    depended on the removed duplicated source columns should be updated to read source/display metadata from images.

## Version 0.78

Version 0.78 adds an experimental browser-side upload and thumbnail rebuild pipeline designed to reduce shared-hosting
  CPU pressure while keeping the existing server-side workflow available as the default. This release introduces new
  admin settings, worker-based browser processing, ZIP batch packaging, server-side unpacking for prepared uploads,
  stronger thumbnail maintenance checks, and supporting documentation, manifest, and test updates. The result is a more
  flexible upload system that can offload heavy image work to the browser when explicitly enabled, while preserving the
  existing reliable server path for normal use.

    ### Highlights

    #### Added experimental browser-side upload processing

    - Added an opt-in experimental upload mode that is disabled by default and clearly presented as non-default in the
    upload UI.
    - Added browser-side preparation of uploaded files so the client can generate thumbnails, package batches, and
    coordinate upload work before the server receives the final ZIP payloads.
    - Added a worker-based processing model so thumbnail generation and ZIP assembly can run in parallel without
    freezing the main thread during supported browser sessions.
    - Added controlled batching so large upload sets can be split into smaller ZIP archives instead of sending one huge
    request that would overload shared hosting or browser limits.
    - Added retry-oriented batch handling so failed batches can be queued and sent again instead of being dropped
    immediately.
    - Preserved the existing server-side upload path so unchecked uploads continue to behave exactly as before.
    - Example: an administrator can leave the feature off for ordinary uploads, or enable it for a large batch of photos
    when they want the browser to do the thumbnail work first.

    #### Added experimental thumbnail rebuild support

    - Added a browser-assisted thumbnail rebuild path that can stream source files from the server, process them in the
    browser, and upload prepared thumbnail ZIP batches back to the server.
    - Added per-image format policy handling so the rebuild pipeline follows the same thumbnail compatibility mode rules
    as the server-side maintenance logic.
    - Added worker-pool parallelization for rebuild jobs so the browser can process multiple source items concurrently
    when the machine and browser support it.
    - Added batch validation so each prepared rebuild package is checked for completeness before it is accepted by the
    server.
    - Added stronger failure handling so incomplete or invalid prepared rebuild content is rejected instead of silently
    producing partially rebuilt thumbnail sets.
    - Example: a thumbnail rebuild request can now be prepared from source files in chunks, processed in the browser,
    and uploaded back as store-only ZIP batches that the server unpacks into the gallery thumbs directory.

    #### Added upload and thumbnail administration controls

    - Added a dedicated Admin upload settings page for the new experimental upload controls and related browser-side
    behavior.
    - Added Admin controls for experimental upload worker count, batch sizing, and upload safety limits.
    - Added Admin controls for experimental thumbnail rebuild chunk sizing and source-batch limits.
    - Added Admin dashboard maintenance cards and action wiring for the experimental rebuild workflow.
    - Added clear warning labels and experimental feature language so the UI makes it obvious that these workflows are
    not the default path.
    - Example: administrators can tune the worker count and batch sizing from the Admin zone while leaving the end-user
    upload form with only a simple on/off checkbox.

    #### Improved thumbnail maintenance reporting and compatibility behavior

    - Updated thumbnail maintenance reporting so dry checks, repair flows, and rebuild flows share more consistent
    target-format logic.
    - Improved format normalization so browser-assisted rebuilds and server-side maintenance agree on which thumbnail
    variants are valid for the current policy.
    - Added stronger diagnostics for missing variants, stale files, and policy-driven rebuild expectations.
    - Preserved the existing thumbnail compatibility mode interface so modern WebP-only and legacy JPG plus WebP modes
    continue to work as configured.
    - Example: the maintenance checker can now distinguish between genuine missing thumbnail variants and prepared
    browser batches that did not match the active policy.

    ### Technical Details

    #### Backend

    - Added `app/services/experimental_uploads.php` for experimental upload settings, batch sizing, ZIP parsing, cached
    batch handling, payload validation, and prepared upload storage.
    - Added `app/services/experimental_thumbnail_rebuild.php` for experimental rebuild configuration, source chunk
    planning, per-image format policy, ZIP streaming, and prepared rebuild storage.
    - Added `app/controllers/admin_uploads.php` support for the new experimental upload settings page, the experimental
    upload batch endpoint, and upload-mode orchestration.
    - Added `app/controllers/admin_thumbnails.php` support for experimental thumbnail rebuild JSON endpoints,
    experimental batch handling, and compatibility-mode actions.
    - Added `app/services/thumbnail_generation.php` helpers for temporary thumbnail targets, publish steps, partial-file
    cleanup, source orientation handling, and WebP/JPEG writing support.
    - Updated `app/services/thumbnail_maintenance.php` so maintenance scans and repair reporting are more tightly
    aligned with the active thumbnail policy.
    - Updated `app/services/site_maintenance.php` to preserve maintenance behavior while integrating with the newer
    thumbnail maintenance logic.
    - Updated `app/services/admin_dashboard.php` so the dashboard can expose the new thumbnail and upload controls
    cleanly.
    - Updated `app/bootstrap.php`, `app/services.php`, `app/views.php`, and related controller registration paths to
    load the new services, controllers, and views.
    - Updated `app/helpers.php` so thumbnail-related URL and fallback helpers continue to honor the active public
    rendering format rules.
    - Updated `app/controllers/admin_uploads.php` and `app/controllers/admin_thumbnails.php` to reject malformed
    experimental requests and return JSON-safe responses for browser-driven batches.
    - Added `cms_admin_upload_settings`, `cms_admin_upload_experimental_batch`,
    `cms_admin_thumbnail_experimental_source_chunk`, and `cms_admin_thumbnail_experimental_upload_batch` endpoints.
    - Added `admin_upload_experimental_json_response()`, `admin_upload_experimental_verify_csrf()`,
    `admin_upload_experimental_reject_discarded_body()`, `cms_admin_thumbnail_experimental_json_response()`,
    `cms_admin_thumbnail_experimental_verify_csrf()`, `cms_admin_thumbnail_experimental_source_chunk()`, and
    `cms_admin_thumbnail_experimental_upload_batch()` as new request-handling helpers.
    - Added `experimental_upload_default_settings()`, `experimental_upload_normalize_settings()`,
    `experimental_upload_settings()`, `set_experimental_upload_settings()`,
    `experimental_upload_server_upload_limit_bytes()`, `experimental_upload_batch_target_bytes()`,
    `experimental_upload_effective_batch_target_bytes()`, and `experimental_upload_browser_config()` to manage upload
    policy.
    - Added `experimental_upload_parse_store_zip()`, `experimental_upload_store_cached_batch_response()`,
    `experimental_upload_cached_batch_response()`, and `experimental_upload_store_prepared_zip_batch()` to support
    store-only ZIP upload processing.
    - Added `experimental_thumbnail_rebuild_clamped_source_chunk_bytes()`,
    `experimental_thumbnail_rebuild_megabytes_to_bytes()`, `experimental_thumbnail_rebuild_source_chunk_bytes()`,
    `experimental_thumbnail_rebuild_source_chunk_item_cap()`, and `experimental_thumbnail_rebuild_browser_config()` to
    manage rebuild limits and browser configuration.
    - Added `experimental_thumbnail_rebuild_normalized_formats()`,
    `experimental_thumbnail_rebuild_target_formats_for_image()`,
    `experimental_thumbnail_rebuild_expected_variant_count()`, `experimental_thumbnail_rebuild_source_chunk_plan()`,
    `experimental_thumbnail_rebuild_stream_source_zip()`, and
    `experimental_thumbnail_rebuild_store_prepared_zip_batch()` to enforce per-image format policy during rebuilds.
    - Added ZIP helper methods in the new experimental services, including `experimental_upload_zip_uint16()`,
    `experimental_upload_zip_uint32()`, `experimental_upload_manifest_from_entries()`,
    `experimental_thumbnail_rebuild_pack_uint16()`, `experimental_thumbnail_rebuild_pack_uint32()`,
    `experimental_thumbnail_rebuild_zip_dos_time()`, `experimental_thumbnail_rebuild_zip_dos_date()`,
    `experimental_thumbnail_rebuild_crc32_data()`, `experimental_thumbnail_rebuild_crc32_file()`,
    `experimental_thumbnail_rebuild_zip_local_header()`, and `experimental_thumbnail_rebuild_zip_central_header()`.
    - Added experimental upload manifest and item helpers including `experimental_upload_image_rows_by_ids()`,
    `experimental_upload_validate_original_payload()`, `experimental_upload_validate_thumbnail_payload()`,
    `experimental_upload_batch_cache_dir()`, and `experimental_upload_batch_cache_key()`.
    - Added experimental rebuild helpers including `experimental_thumbnail_rebuild_request_image_ids()`,
    `experimental_thumbnail_rebuild_expected_variant_count()`, `experimental_thumbnail_rebuild_stream_file_payload()`,
    `experimental_thumbnail_rebuild_manifest_from_entries()`, and
    `experimental_thumbnail_rebuild_requested_chunk_bytes()`.
    - Added `thumbnail_compatibility_mode()` integration points so the browser-assisted rebuild path follows the same
    active format policy as server-side maintenance.
    - Updated `app/core-manifest.json` repeatedly to keep the bundled asset integrity manifest in sync with the new
    services and scripts.

    #### Database

    - Added migration `database/migrations/202606100001_experimental_client_upload_settings.php` for the new
    experimental client upload settings.
    - Added migration `database/migrations/202606100002_experimental_upload_batch_safety.php` for batch sizing and
    safety threshold settings.
    - Added migration `database/migrations/202606100003_experimental_thumbnail_rebuild_settings.php` for rebuild-
    specific browser and worker configuration.
    - Added migration `database/migrations/202606100004_experimental_thumbnail_rebuild_resilience.php` for rebuild
    resilience and policy-aligned behavior.
    - Stored new runtime settings in `app_settings` so the feature can be configured from the Admin zone without
    changing `config.php`.
    - Kept the new settings append-only and migration-driven so existing installs can upgrade without schema edits in
    controller code.

    #### Frontend

    - Added `public/assets/gallery-modules/admin-experimental-upload.js` for the browser-side upload pipeline, worker
    orchestration, ZIP batching, and upload progress handling.
    - Added `public/assets/gallery-modules/experimental-upload-worker.js` for worker-side thumbnail generation, image
    preparation, and store-only ZIP creation.
    - Added `public/assets/gallery-modules/admin-experimental-thumbnail-rebuild.js` for browser-assisted thumbnail
    rebuild batching and policy enforcement.
    - Added `public/assets/gallery-modules/admin-thumbnail-progress.js` updates so the existing progress system can
    drive the new experimental upload and rebuild jobs.
    - Updated `public/assets/gallery-modules/admin-side-panel.js` so the new upload settings and experimental controls
    can appear correctly in panel-driven admin flows.
    - Updated `public/assets/gallery-modules/admin-operations.js` to recognize the new experimental actions where
    needed.
    - Added `app/views/admin_upload_settings.php` to render the new upload settings page and its experimental controls.
    - Updated `app/views/admin_dashboard_sections.php` so the thumbnail maintenance card includes the experimental
    browser-side rebuild entry point and compatibility controls.
    - Updated `app/views/admin_chrome.php` and `app/views/layout.php` so the new admin/upload modules load in the
    correct places.
    - Added `public/assets/public-shared.css` and updated `public/assets/styles.css` to support the broader layout and
    shared admin/public styling needed by the new workflow.
    - Updated `public/assets/public-gallery.js` so the new shared public asset loading and module wiring behaves
    consistently.
    - Added browser capability checks, worker creation logic, and store-only ZIP creation paths that keep the main
    thread responsive when the experimental mode is enabled.

    #### Tests

    - Added `tests/experimental_upload_settings_test.php` for upload setting defaults, bounds, ratio behavior, and
    format normalization.
    - Added `tests/public_asset_loading_model_test.php` to verify public asset loading behavior and module inclusion
    rules.
    - Expanded coverage for experimental thumbnail rebuild format normalization and policy handling.
    - Added tests for upload batch target size calculations, worker cap logic, and rebuild-specific byte-limit
    calculations.
    - Added coverage for browser-side ZIP packaging helpers and prepared-batch validation logic.
    - Added coverage for experimental settings persistence and normalization edge cases.
    - Preserved and continued using the existing direct PHP test style so the new logic can be verified without
    requiring PHPUnit or browser automation.

    ### User Impact

    #### For visitors

    - Uploads can be made faster on the client side when the experimental mode is enabled and the browser supports the
    needed capabilities.
    - Large batches can be split into smaller prepared chunks, reducing the chance of long upload stalls on slower
    shared hosting.
    - The default behavior remains unchanged for users who do not enable the experimental option.

    #### For administrators

    - Administrators can now tune experimental upload and rebuild behavior from the Admin area instead of editing code.
    - Worker count, batch sizing, and safety limits are configurable with bounded defaults so the feature stays
    practical on shared hosting.
    - Thumbnail rebuilds can be offloaded to the browser when desired, reducing server CPU pressure during large
    maintenance runs.
    - Maintenance logs and rebuild diagnostics are more informative, making it easier to tell whether a missing
    thumbnail is caused by policy, runtime capability, or an incomplete prepared batch.
    - Existing server-side thumbnail generation and upload behavior remain available as the stable fallback path.

## Version 0.77

Version 0.77 adds a major thumbnail and maintenance upgrade across the gallery system. This release introduces durable
  thumbnail variant metadata, public thumbnail warmup, stronger thumbnail repair behavior, a new site maintenance
  runner, and expanded admin reporting for database usage and storage statistics. The result is a more resilient image
  pipeline, more informative admin tooling, and better control over long-running background tasks.

  ### Highlights

  #### Added durable thumbnail metadata and metadata-backed rendering

  - Added persistent thumbnail variant metadata so the system can resolve valid derivatives from the database instead of
    relying only on filesystem probing at request time.

  - Added a new thumbnail_variant_metadata migration to store and refresh derivative information.
  - Updated thumbnail generation, maintenance, warmup, and upload automation flows so metadata stays in sync after files
    change.

  - Improved public media rendering so gallery pages can select the best available thumbnail variant more reliably.
  - Example: when a thumbnail is repaired or regenerated, the public page can immediately use the refreshed variant
    record without waiting for a separate cache rebuild.

  #### Added guarded public thumbnail warmup

  - Added a public thumbnail warmup workflow that can prefetch and repair missing or stale thumbnail variants in
    controlled batches.

  - Added a browser-side warmup module that sends signed repair requests and handles progress updates.
  - Added locking, cooldowns, and access checks so warmup requests do not overwhelm the server or duplicate ongoing
    work.

  - Added repair-aware handling for image sources that need orientation correction or geometry validation before being
    published.

  - Example: a gallery with many newly uploaded photos can warm up the most useful public thumbnails in the background
    instead of making the first visitor wait for repair work.

  #### Improved thumbnail repair and geometry validation

  - Tightened thumbnail geometry checks so stale, square-canvas, or ratio-mismatched derivatives are detected
    consistently.

  - Updated repair behavior so invalid thumbnails can be marked for background repair instead of being deleted too
    early.

  - Added support for preserving public responses while a bad derivative is being repaired in the background.
  - Improved DNG derivative reporting so generated outputs and target formats are tracked more clearly.
  - Example: if a portrait image was rendered with the wrong canvas shape, the system can now recognize the mismatch,
    repair it, and keep the public page stable during the process.

  #### Added a cron-safe site maintenance system

  - Added a new maintenance service that can run on a schedule, resume after interruption, and continue work in batches.
  - Added a token-protected cron endpoint and a command-line runner for unattended execution.
  - Added maintenance state tracking for schedule timing, batch size, time budget, running status, and completion
    status.

  - Added cleanup phases so maintenance can coordinate thumbnail work and follow-up repair tasks instead of doing
    everything in one pass.

  - Added admin controls and status reporting for the new maintenance workflow.
  - Example: a site can now process background repair jobs in smaller scheduled chunks instead of depending on manual
    admin intervention.

  #### Added admin database usage reporting

  - Added a new database usage report with its own service and dedicated admin view.
  - Added dashboard integration so database usage is visible alongside storage statistics.
  - Added English and Czech translation updates for the new reporting UI.
  - Added tests for the database usage aggregation and report behavior.
  - Example: administrators can now see database footprint as a distinct operational metric instead of guessing from
    file storage alone.

  #### Expanded storage statistics and admin reporting

  - Improved the storage statistics workflow so it fits better alongside the new database usage view and maintenance
    tooling.

  - Updated admin dashboard cards, styles, and section rendering to present the reporting tools more clearly.
  - Added or refreshed manifest entries so the new admin surfaces load correctly.
  - Improved the overall maintenance area so related tools are grouped together and easier to navigate.

  ### Technical Details

  #### Backend

  - Added app/services/thumbnail_metadata.php for durable thumbnail variant storage and refresh logic.
  - Added app/controllers/thumbnail_warmup.php for guarded warmup and repair handling.
  - Added app/services/thumbnail_warmup.php for batch-based thumbnail warmup orchestration.
  - Added app/services/site_maintenance.php for cron-safe, resumable maintenance work.
  - Added app/controllers/site_maintenance.php and scripts/site_maintenance.php for web and CLI maintenance execution.
  - Added app/services/admin_database_usage.php and updated app/controllers/admin_dashboard.php to expose database usage
    reporting.

  - Updated app/controllers/admin_thumbnails.php, app/controllers/public_media.php, and app/services/
    thumbnail_generation.php to support metadata-backed thumbnail behavior.

  - Updated app/services/thumbnail_maintenance.php, app/services/thumbnail_sources.php, app/services/
    thumbnail_formats.php, app/services/thumbnail_bundles.php, app/services/thumbnail_compatibility.php, app/services/
    thumbnail_html.php, and app/services/thumbnails.php to align thumbnail rendering and repair with the new metadata
    model.

  - Updated app/services/upload_automation.php so metadata refresh happens during automated upload flows.
  - Updated app/services/seo_request_guard.php so internal maintenance routes are exempt from crawler blocking.
  - Updated app/bootstrap.php, app/controllers.php, app/services.php, app/views.php, and app/services/
    admin_dashboard.php to register the new services, controllers, and views.

  #### Database

  - Added migration database/migrations/202606080001_thumbnail_variant_metadata.php.
  - Added durable thumbnail variant storage for generation, warmup, maintenance, and rendering workflows.
  - Added support for tracking metadata refreshes when thumbnails are repaired or regenerated.
  - Kept the new storage compatible with existing gallery installs by integrating it through the current migration flow.

  #### Frontend

  - Added public/assets/gallery-modules/thumbnail-warmup.js for guarded public warmup and repair requests.
  - Updated public/assets/gallery-modules/admin-thumbnail-progress.js to support the new repair and warmup progress
    flow.

  - Updated public/assets/gallery-modules/lightbox.js, public/assets/gallery-modules/lightbox-deferred.js, and public/
    assets/styles/lightbox.css to avoid layout artifacts during image swaps and repair states.

  - Updated public/assets/gallery.js so the new maintenance and warmup modules load correctly.
  - Expanded public/assets/styles/admin-dashboard.css and public/assets/styles/admin.css for the new maintenance,
    reporting, and database usage screens.

  - Updated the admin dashboard section templates so the new cards and controls fit the existing admin layout.

  #### Tests

  - Added tests/thumbnail_warmup_model_test.php.
  - Added tests/admin_database_usage_test.php.
  - Covered thumbnail warmup behavior, source merging, token validation, and repair flow safety.
  - Covered database usage aggregation and reporting behavior.
  - Added coverage for thumbnail geometry validation, metadata refresh paths, and public rendering consistency.

  ### User Impact

  #### For visitors

  - Public galleries should load more reliably because thumbnail selection now uses durable metadata instead of ad hoc
    filesystem checks.

  - Broken or stale thumbnails are less likely to appear because repairs are handled more consistently.
  - Lightbox image swaps are less likely to flash incorrect white letterbox areas during transitions.

  #### For administrators

  - Thumbnail maintenance is easier to manage because warmup, repair, and metadata refresh are now connected.
  - Background maintenance can run safely in scheduled batches rather than requiring manual intervention for every pass.
  - Database usage is visible from the admin area as a dedicated operational metric.
  - Storage statistics and maintenance reporting are more coherent because related tools now share a common admin
    surface.

  - Translation, manifest, and dashboard updates make the new workflows discoverable in both English and Czech
    installations.

## Version 0.76

Version 0.76 expands the Admin area with deeper operational tooling and a more structured interface. This release adds
  detailed storage statistics for galleries and generated media, improves thumbnail compatibility handling with a modern
  WebP-first policy and legacy cleanup tools, and introduces a crawler-safety request guard that reduces duplicate or
  suspicious public requests. The Admin dashboard and theme editor also continue moving toward a shared cinematic layout
  system with reusable heroes, intros, tabs, and side panels.

  ### Highlights

  #### Added storage statistics for gallery files and generated media

  - Added a new Admin storage statistics workflow that tracks how much space original uploads, thumbnails, and DNG
    display masters consume.

  - Split the reporting into meaningful categories so administrators can see source photo sizes separately from
    generated derivative sizes.

  - Added statistics for image type distribution, source-size buckets, largest source files, and top galleries by
    storage usage.

  - Added cache and job handling so the statistics view can be built without blocking the dashboard on every request.
  - Added a browser-driven batch job mode so large installations can calculate generated-media totals in smaller
    requests instead of one long-running page load.

  - Added a dedicated Admin storage statistics page and dashboard entry point so the feature is easy to reach from the
    maintenance area.

  - Added dedicated progress and reporting behavior so administrators can monitor the scan while it runs.
  - Example: an installation can now show that gallery originals take 57 GB, thumbnails take 11 GB, and DNG display
    masters take 4 GB, rather than only showing one combined total.

  #### Improved thumbnail compatibility handling

  - Added a thumbnail compatibility mode that controls whether new thumbnail generation uses modern WebP-only output or
    legacy JPEG plus WebP compatibility pairs.

  - Added policy-aware format selection so the generator, bundle lookup, HTML rendering, and maintenance scanner all use
    the same output rules.

  - Added a safe fallback path for sources that cannot be written as WebP on the current server, so shared-hosting
    installs remain usable even when runtime capabilities vary.

  - Added legacy JPEG cleanup tools that remove generated JPG thumbnails without touching originals, WebP derivatives,
    or database rows.

  - Added an Admin control for switching compatibility mode and an Admin action for batch-deleting legacy JPEG
    thumbnails.

  - Added tests covering format normalization, policy decisions, and safe cleanup behavior.
  - Example: a site can switch to modern mode and keep new thumbnails as WebP only, while still retaining the option to
    delete older generated JPG variants later.

  #### Added crawler-safety request guarding

  - Added a public request guard that rejects suspicious query strings before they can render duplicate gallery pages.
  - Added 404 responses with X-Robots-Tag: noindex, nofollow for blocked requests and not-found pages.
  - Added Admin controls for enabling or disabling the guard and for sampled logging of rejected requests.
  - Added dashboard visibility for the guard state so administrators can see whether crawler safety is active.
  - Updated robots handling and shared header rendering so the public side emits the right indexing signals.
  - Example: malformed or spammy requests with unexpected query parameters no longer create crawlable duplicate pages.

  #### Refined the Admin dashboard and cinematic UI system

  - Continued the transition to shared Admin UI primitives for heroes, section intros, metric cards, and design-spec
    panels.

  - Reworked dashboard and theme pages to use the same reusable layout vocabulary.
  - Updated nested tabs and side panels so panel transitions feel smoother and the Admin shell reads as one coherent
    interface.

  - Added admin-cinematic.css and expanded the dashboard stylesheet to support the denser Admin layout.
  - Improved the theme editor and dashboard grouping so content, media, navigation, and system tools are easier to scan.
  - Preserved the existing workflows while making the Admin presentation more consistent across full pages and side-
    panel flows.

  ### Technical Details

  #### Backend

  - Added app/services/admin_storage_statistics.php for storage fingerprinting, caching, job execution, and source/
    generated media aggregation.

  - Added app/services/thumbnail_compatibility.php for thumbnail mode policy, legacy cleanup, and compatibility labels.
  - Added app/services/seo_request_guard.php for public request rejection, logging, and canonical response handling.
  - Added cms_admin_storage_statistics() and supporting dashboard/model wiring for the new statistics page.
  - Added cms_admin_thumbnail_compatibility_settings() and legacy thumbnail cleanup actions in app/controllers/
    admin_thumbnails.php.

  - Added cms_admin_seo_guard_settings() in app/controllers/admin_dashboard.php.
  - Updated app/controllers/admin_galleries_edit.php and related Admin UI flows to use the shared cinematic primitives.
  - Updated thumbnail_target_formats_for_source(), thumbnail_bundle_url(), thumbnail_srcset(), and related helpers so
    thumbnail output follows the active compatibility policy.

  - Updated cms_run() to enforce the SEO request guard early in request handling.
  - Updated public not-found behavior in app/controllers/http_helpers.php to emit crawler-safe headers.
  - Updated dashboard section rendering in app/views/admin_dashboard_sections.php to expose the new storage and security
    tools.

  - Updated app/views/layout.php to load the new Admin stylesheet and browser modules.

  #### Database

  - Refined cache and job storage through app settings for storage statistics and thumbnail cleanup workflows.
  - Extended manifest metadata in app/core-manifest.json for the new service and view surface.
  - No new SQL migration was introduced in this release.

  #### Frontend

  - Added public/assets/gallery-modules/admin-storage-statistics.js for batch processing and progress updates.
  - Updated public/assets/gallery-modules/admin-thumbnail-progress.js to support the new maintenance workflow.
  - Updated public/assets/gallery-modules/admin-nested-tabs.js, admin-tabs.js, admin-side-panel.js, and admin-
    operations.js for the refreshed Admin interaction model.

  - Expanded public/assets/styles/admin-dashboard.css and public/assets/styles/admin-cinematic.css for the new
    dashboard, statistics panels, and theme-editor presentation.

  - Updated public/assets/gallery.js so the new Admin modules boot automatically.
  - Updated app/views/admin_ui.php so shared hero, intro, metric, and design-spec components can be reused across Admin
    pages.

  #### Tests

  - Added tests/admin_storage_statistics_test.php.
  - Added tests/thumbnail_compatibility_model_test.php.
  - Covered file-extension normalization, size bucket selection, and grouped statistics calculations.
  - Covered thumbnail compatibility normalization, legacy versus modern format decisions, and safe JPG cleanup.
  - Covered compatibility cleanup behavior to ensure originals and WebP files are preserved.

  ### User Impact

  #### For visitors

  - Public pages are less likely to expose duplicate crawl targets from suspicious query strings.
  - Not-found pages and blocked requests now signal crawlers more clearly with noindex, nofollow.
  - Public thumbnails can be served in a more modern WebP-first configuration where the server supports it.

  #### For administrators

  - The Admin dashboard now gives a clearer view of storage usage and generated-media cost.
  - Thumbnail maintenance is easier to reason about because format policy, generation, and cleanup now follow the same
    rules.

  - Legacy thumbnail cleanup can free disk space without risking originals or newer derivative formats.
  - The Admin area feels more structured and easier to navigate because repeated layout patterns are now shared and
    consistent.

  - Crawler-safety controls are available directly from the dashboard, with visibility into whether the guard is enabled
    and logging activity is being sampled.

## Version 0.75

Version 0.75 expands gallery editing, administration, and navigation with several connected workflows: administrators
  can now store gallery date ranges, review EXIF-derived date suggestions across gallery branches, use a refreshed theme
  editor with sub-tabs, configure favorite gallery shortcuts in the top navigation, and benefit from broader admin-side
  polish across uploads, downloads, mobile WebDAV, and gallery maintenance. The release also includes supporting
  database migrations, frontend interaction updates, translation refreshes, and test coverage for the new date and
  favorite-gallery behavior.

  ### Highlights

  #### Added editable gallery date ranges and EXIF-driven date suggestions

  - Added support for storing a manual gallery date range instead of only a single date.
  - Added a new gallery_date_end value so a gallery can represent a range such as 2026-05-01 to 2026-05-03.
  - Preserved the old single-date workflow by allowing the end date to remain empty.
  - Added EXIF-based date suggestions built from scanned photo metadata, so existing imports can be used to propose
    likely gallery date ranges.

  - Added an admin review page for gallery dates that can show suggestions for a single gallery branch or the full
    gallery tree.

  - Added a focused “Apply to this gallery” action inside the gallery editor so admins can accept the suggested range
    without leaving the current edit workflow.

  - Added branch-aware aggregation so a parent gallery can collect EXIF capture dates from all of its descendants.
  - Added editable suggestion rows so admins can fine-tune a proposed range before saving it.
  - Added public display support for ranges and end-only dates, with readable visitor-facing formatting.

  #### Added configurable favorite gallery shortcuts in the top navigation

  - Added theme-managed favorite gallery shortcuts to the header navigation.
  - Allowed shortcuts to be resolved from configured gallery IDs and/or shortcut slots in the theme settings.
  - Added support for displaying those shortcuts in the public header only when appropriate for the current visitor
    context.

  - Kept the existing “Galleries” navigation experience intact while adding the new shortcut row ahead of it.
  - Added support for badge-aware and preview-friendly rendering in the navigation templates.

  #### Added nested admin subtabs and theme editor organization

  - Reworked the Admin Theme page into clearer sub-sections instead of one long form.
  - Added nested sub-tabs for appearance, branding/media, and layout-related settings.
  - Split preview content and controls into smaller panels so theme editing is easier to scan.
  - Preserved the live preview behavior while making the form layout more maintainable.
  - Added shared subtab styles so similar admin screens can reuse the same interaction pattern.

  #### Improved EXIF/GPS admin controls and gallery maintenance workflows

  - Added a global EXIF/GPS default display settings card on the admin dashboard.
  - Added support for a per-gallery EXIF/GPS override reset workflow.
  - Updated bulk gallery actions to support an “inherit GPS map default” option where the schema allows it.
  - Updated gallery feature indicators so the dashboard reflects effective GPS display behavior instead of only raw
    stored flags.

  - Added dedicated dashboard cards linking to the gallery date maintenance page and the EXIF/GPS settings workflow.
  - Added clearer admin-side feedback for successful and failed EXIF-derived date application.

  ### Technical Details

  #### Backend

  - Added the admin_gallery_dates route and controller in app/controllers/admin_gallery_dates.php.
  - Added the admin_gallery_date_suggestion route for focused AJAX and form submissions.
  - Added reusable date-range helpers in app/services/gallery_dates.php.
  - Added schema checks for gallery_date_end and EXIF-suggestion readiness.
  - Added range normalization and validation so the end date cannot be earlier than the start date.
  - Added gallery_date_save_range() to persist date ranges and refresh sidecar metadata.
  - Added EXIF suggestion aggregation helpers to compute branch-level min/max capture dates from scanned images.
  - Added branch membership helpers so the review page can scope suggestions to one gallery tree.
  - Added admin_apply_gallery_date_exif_suggestion() in app/controllers/admin_galleries_edit.php to support direct
    application from the gallery editor.

  - Updated admin_save_gallery_from_input() to persist both gallery_date and gallery_date_end.
  - Updated gallery discovery and creation paths to carry gallery_date_end from input and sidecar metadata.
  - Updated write_gallery_sidecar() and folder candidate metadata handling so the date-range end value survives
    filesystem sync.

  - Updated gallery_migration helpers so migration metadata includes gallery_date_end.
  - Updated gallery_migration_gallery_column_value() so date-range fields are normalized consistently during migration
    imports.

  - Added global EXIF/GPS settings handling in app/controllers/admin_dashboard.php.
  - Updated admin_galleries_bulk.php to support the new GPS inheritance action and to refresh sidecar state after bulk
    updates.

  - Updated app/views/layout.php so the new admin browser module is loaded on every page where it is needed.
  - Updated app/bootstrap.php and app/controllers.php to register the new route and controller.

  #### Database

  - Added migration 202606070001_gallery_date_ranges.php.
  - Added nullable galleries.gallery_date_end beside the existing gallery_date column.
  - Added an index on gallery_date_end for future filtering and maintenance use.
  - Added migration 202606060001_exif_gps_default_display.php.
  - Added support for storing a global EXIF/GPS default display state and per-gallery override cleanup behavior.
  - Kept both migrations backwards-compatible so older installations can continue operating while they are being
    upgraded.

  #### Frontend

  - Added public/assets/gallery-modules/admin-gallery-date-suggestion.js for in-place EXIF suggestion application.
  - Updated the gallery editor to show editable date-range fields instead of a single date-only control when the schema
    supports it.

  - Added AJAX handling so the gallery editor can apply EXIF suggestions without a full page reload.
  - Added refreshed notice handling so the admin sees immediate feedback after a suggestion is applied.
  - Updated public/assets/gallery.js so the new admin gallery-date suggestion module boots with the rest of the browser
    features.

  - Updated public/assets/styles.css and public/assets/styles/admin.css for date-range inputs, suggestion rows, and the
    maintenance page layout.

  - Added public/assets/styles/admin-subtabs.css for the new nested admin sub-tab interface.
  - Updated public/assets/gallery-modules/admin-side-panel.js, admin-nested-tabs.js, admin-gallery-list.js, admin-
    navdata-panel.js, admin-gallery-migration.js, lightbox.js, and related modules as part of the broader admin UI
    refresh.

  - Updated the public gallery render path so date ranges show up correctly in gallery cards and metadata rows.
  - Updated translation loading and browser i18n handling so the new UI text is available in both admin and public-side
    scripts.

  #### Tests

  - Added tests/gallery_dates_model_test.php.
  - Added tests/favorite_galleries_model_test.php.
  - Covered date-range normalization, range validation, and storage formatting.
  - Covered public date rendering for single dates, ranges, and end-only labels.
  - Covered branch membership logic used by EXIF suggestion aggregation.
  - Covered machine-readable public markup attributes for rendered gallery dates.
  - Covered favorite-gallery navigation data and theme shortcut behavior.

  ### User Impact

  #### For visitors

  - Gallery cards and gallery headers can now show a full date range instead of only a single day when the gallery
    metadata contains one.

  - Public navigation can now surface directly configured favorite galleries as shortcut links.
  - The top navigation can feel more tailored to the site’s most important galleries without changing the underlying
    gallery structure.

  - Public gallery metadata and date display remain readable even when the underlying data comes from a range rather
    than a single date.

  #### For administrators

  - Gallery editing is more flexible because a gallery can now represent a one-day event, a multi-day trip, or a broader
    time span.

  - EXIF capture dates can be reviewed as suggested ranges instead of requiring manual entry from scratch.
  - Parent galleries can inherit date evidence from child galleries, which is useful for trip hierarchies and multi-day
    albums.

  - The gallery editor now provides a direct “Apply to this gallery” action for suggested ranges.
  - The admin dashboard now exposes dedicated cards for date maintenance and EXIF/GPS defaults, making the new workflows
    easier to find.

  - The theme editor is easier to manage because settings are split into smaller, labeled sub-sections.
  - Gallery maintenance flows are more consistent because sidecar metadata, migration logic, and admin forms all carry
    the same range data.

  - Bulk gallery management now has more complete GPS inheritance behavior where the schema supports it.

  ### Notes

  - Date ranges are stored as gallery_date plus gallery_date_end, so older single-date galleries remain valid without
    any extra setup.

  - The EXIF suggestion workflow depends on scanned image rows with exif_taken_at data already present.
  - Suggestions are branch-based, so a parent gallery may collect dates from subgalleries as well as from its own
    images.

  - The new theme and navigation features are additive and keep existing layouts available unless the admin opts into
    the new settings.

  - The new admin workflows rely on the database migrations being applied before the related UI can be used fully.

## Version 0.74

Version 0.74 prepares PHP Gallery for a broader shared-hosting release by adding durable admin sessions, linked Google login, global feature switches, mobile WebDAV upload support, runtime diagnostics for RAW conversion, a context-aware media renamer, improved sitemap metadata, refreshed release documentation, and the new lightbox browsing modes.

  ### Highlights

  #### Added persistent admin login and prepared Google sign-in

  - Added longer-lived admin session cookies so production hosting cleanup is less likely to log administrators out unexpectedly.
  - Added optional persistent login tokens through a `Keep me signed in` login checkbox.
  - Stored durable login tokens as hashed selectors and secrets instead of raw browser tokens.
  - Added token revocation on logout and password changes.
  - Added Google OpenID Connect login routes for account linking and login callback handling.
  - Required a Google account to be linked from an already authenticated admin profile before it can be used for login.
  - Added Google account linking and disconnect controls to the admin account page.
  - Added config placeholders for Google OAuth client ID and secret in `config.example.php`.
  - Preserved password login as the primary fallback even when Google login is configured.

  #### Added global feature switches

  - Added an Admin Features page for enabling and disabling optional gallery functionality.
  - Kept all registered features enabled by default so existing installations retain current behavior after update.
  - Added route-level guards so disabled features cannot be opened directly through known admin or public routes.
  - Added feature-aware hiding for OpenAI tools, SimBrief controls, public search controls, gallery maps, flight maps, upload API controls, gallery migration, media renamer, image voting, picture game, AI metadata, and lightbox mode controls.
  - Grouped feature toggles by functional area so administrators can disable incomplete, unwanted, or hosting-heavy integrations without deleting code or data.

  #### Added context-aware media renamer workflow

  - Added a site-wide Media Renamer admin page.
  - Added a per-gallery File Renamer tab inside the gallery editor.
  - Added dry-run previews before physical file renames are applied.
  - Added deterministic rename patterns based on gallery context and image order.
  - Updated image database rows, generated derivative cache state, derived titles, public path data, and stale ZIP archive rows after renaming.
  - Added availability-aware filtering so galleries without rename candidates can be hidden after checking.
  - Added batched rename execution for large selections so the browser does not look frozen during long operations.
  - Added progress/status feedback for batch rename actions.
  - Added structured admin logging for completed, warning, and failed rename operations.

  #### Added mobile WebDAV upload framework

  - Added WebDAV-style mobile upload endpoints intended for external mobile upload tools such as PhotoSync.
  - Added gallery-scoped mobile upload token support.
  - Added HTTP authorization forwarding in `.htaccess` so bearer/basic credentials can reach PHP behind Apache rewrite rules.
  - Added admin-facing mobile upload controls and localized labels.
  - Kept the implementation optional and feature-gated so sites that do not need mobile WebDAV uploads can hide it.

  #### Added runtime diagnostics and stronger DNG conversion policy controls

  - Added an admin-only Runtime Diagnostics page for PHP, GD, Imagick, EXIF, WebP, HEIC, HEIF, DNG, and hosting-limit checks.
  - Added a copyable plain-text diagnostics report for support and issue reporting.
  - Added DNG conversion source policy settings for RAW-first, preview-first, and automatic fallback behavior.
  - Added DNG color handling policy settings for browser-safe sRGB, preserve-look, and camera-white-balance preferences.
  - Improved DNG derivative generation with more explicit runtime capability checks and fallback ordering.
  - Added tests covering upload acceptance, DNG GPS handling, and DNG conversion policy behavior.

  #### Improved public SEO and sitemap metadata

  - Added richer sitemap/image metadata handling.
  - Improved real `lastmod` handling for galleries and images by deriving freshness from file-backed data instead of using one generic timestamp.
  - Added image metadata to JSON-LD rendering where available.
  - Updated public path logic used by sitemap generation and SEO output.
  - Preserved existing public URLs while improving crawler-visible metadata.

  #### Improved theme branding and gallery tag visuals

  - Added configurable site branding separator dimensions.
  - Added separator stretching behavior so separators can be scaled without preserving aspect ratio when explicitly configured.
  - Reorganized the Admin Theme page so branding and separator controls are less ambiguous.
  - Aligned non-hero gallery tag pills with the compact hero tag style.
  - Preserved the existing hero-panel tags without changing their successful layout.

  #### Added and tuned lightbox browsing modes

  - Added Theme-level default lightbox browsing mode controls.
  - Added per-gallery lightbox browsing-mode overrides.
  - Added `picture_strip` browsing mode for nearby image previews.
  - Added `3d_carousel` browsing mode with neighboring photos layered behind the active image.
  - Increased the visible carousel context to three neighboring images on each side.
  - Enlarged the active photo and closest side photos for a more pronounced composition.
  - Slowed the carousel animation so transitions are easier to perceive.
  - Kept classic single-image lightbox behavior available through the `single` mode.

  #### Added release, architecture, database, and testing documentation

  - Added `PATCH_NOTES_TEMPLATE.md` for future agent-generated patch notes.
  - Documented accepted version formats as `X.Y` and `X.Y.Z` with numeric parts of any length and no leading zeroes.
  - Added `AGENTS.md` with repository guidelines and patch-note instructions for future coding agents.
  - Added `CODEMAP.md` to map feature areas to controllers, services, migrations, assets, and tests.
  - Added `DATABASE.md` to document tables, migrations, relationships, settings, and schema authoring rules.
  - Added `TESTING.md` with syntax-check, script-test, and manual smoke-test guidance.
  - Reworked `ARCHITECTURE.md` into a current maintainer-oriented architecture guide.

  ### Technical Details

  #### Backend

  - Bumped `CMS_VERSION` in `app/bootstrap.php` to `0.74`.
  - Added `admin_google_start` and `admin_google_callback` routes.
  - Added `admin_diagnostics` route.
  - Added `admin_features` route.
  - Added `admin_mobile_uploads` route.
  - Added `mobile_webdav` route.
  - Added `admin_media_renamer` route.
  - Added feature flag route guarding through `feature_flag_route_enabled()` and `feature_flag_render_disabled_route()`.
  - Added Google login/linking logic in `app/controllers/admin_auth.php`.
  - Added durable login service logic in `app/services/auth_persistence.php`.
  - Added Google OAuth service logic in `app/services/google_auth.php`.
  - Added feature flag service logic in `app/services/feature_flags.php`.
  - Added runtime diagnostics handling in `app/controllers/admin_diagnostics.php`.
  - Added feature-switch admin handling in `app/controllers/admin_features.php`.
  - Added media rename UI handling in `app/controllers/admin_media_renamer.php`.
  - Added media rename filesystem/database logic in `app/services/media_renamer.php`.
  - Added mobile WebDAV controller logic in `app/controllers/mobile_webdav.php`.
  - Added mobile WebDAV service logic in `app/services/mobile_webdav.php`.
  - Added lightbox browsing-mode model logic in `app/services/gallery_lightbox_mode.php`.
  - Updated `app/controllers/admin_galleries_edit.php` for feature-aware controls, gallery lightbox overrides, media renamer panel handling, and API tab gating.
  - Updated `app/controllers/admin_dashboard.php` for public-search gating and new admin navigation surfaces.
  - Updated `app/controllers/admin_theme.php` for theme separator settings and lightbox mode defaults.
  - Updated `app/controllers/public_gallery.php` for lightbox mode output and public rendering changes.
  - Updated `app/controllers/theme_assets.php` for theme branding asset behavior.
  - Updated `app/controllers/updates.php` and `app/services/updates.php` for patch note metadata and update display refinements.
  - Updated `app/services/public_paths.php` for sitemap and lastmod calculations.
  - Updated `app/services/dng_derivatives.php` for DNG conversion policy and capability handling.
  - Updated `app/services/uploads.php` for RAW/DNG upload behavior.
  - Updated `app/services/theme.php` for separator sizing, stretch settings, and lightbox mode defaults.
  - Updated service and controller loaders for the new modules.
  - Updated release metadata in `release-metadata.json` for version `0.74`.
  - Regenerated `app/core-manifest.json` for the updated release surface.

  #### Database

  - Added migration `202605310001_admin_persistent_auth_and_google_login.php`.
  - Added migration `202606010001_gallery_lightbox_browsing_mode.php`.
  - Added migration `202606010002_gallery_lightbox_browsing_mode_carousel.php`.
  - Added migration `202606040001_mobile_webdav_upload_tokens.php`.
  - Added `admin_remember_tokens` storage for durable login selectors and hashed token secrets.
  - Added `user_google_accounts` storage for linked Google identities.
  - Added nullable `galleries.lightbox_browsing_mode` storage for per-gallery lightbox overrides.
  - Added mobile WebDAV upload token storage for gallery-scoped mobile integrations.
  - Added compatibility normalization for legacy lightbox mode values.

  #### Frontend

  - Added `public/assets/gallery-modules/admin-media-renamer.js` for preview, availability checks, batched execution, progress updates, and AJAX refresh behavior.
  - Updated `public/assets/gallery-modules/lightbox.js` for picture-strip and 3D-carousel browsing modes.
  - Updated `public/assets/gallery-modules/lightbox-deferred.js` for deferred lightbox behavior.
  - Updated `public/assets/gallery-modules/admin-operations.js` and `public/assets/gallery-modules/admin-side-panel.js` for admin workflow integration.
  - Updated `public/assets/gallery.js` for public behavior initialization.
  - Added and updated `public/assets/styles/lightbox.css` for carousel and strip rendering.
  - Added and updated `public/assets/styles/mobile-gallery.css` for mobile lightbox fallback behavior.
  - Added and updated `public/assets/styles/side-panel.css` for admin side-panel polish.
  - Updated `public/assets/styles/admin.css` for feature settings, diagnostics, media renamer, theme controls, Google login, and related admin UI.
  - Updated `public/assets/styles/admin-media-tools.css` for media-maintenance controls.
  - Updated translations in `app/lang/cs.json`, `app/lang/en.json`, `app/lang/cs.php`, and `app/lang/en.php`.

  #### Tests

  - Added `tests/dng_conversion_policy_test.php`.
  - Added `tests/gallery_lightbox_mode_model_test.php`.
  - Added `tests/upload_accept_and_dng_gps_test.php`.
  - Covered DNG conversion policy normalization and attempt ordering.
  - Covered gallery lightbox browsing-mode normalization, storage, inheritance, and label behavior.
  - Covered upload acceptance and DNG GPS-related behavior.

  ### User Impact

  #### For visitors

  - Public gallery browsing can use the classic lightbox, picture strip, or 3D carousel depending on site and gallery settings.
  - Public search, maps, voting, and optional integrations can be hidden cleanly when an administrator disables them.
  - Sitemap and JSON-LD metadata should better represent current gallery and image freshness for search engines.
  - Gallery tags outside the hero area now use a more compact and consistent visual style.

  #### For administrators

  - Admin login can remain active for days instead of depending only on short shared-host PHP session cleanup windows.
  - Google login can be enabled after linking a Google account from the admin profile.
  - Optional, unfinished, or unwanted features can be disabled from a central feature settings page.
  - Media files can be renamed from previewed plans without manually touching database rows or generated derivatives.
  - Large rename operations provide progress feedback instead of appearing frozen.
  - Mobile upload integrations can be prepared through WebDAV-style endpoints and scoped tokens.
  - Runtime diagnostics make it easier to inspect whether the host supports DNG, HEIC, WebP, Imagick, GD, EXIF, and required upload limits.
  - DNG conversion can prefer embedded previews or full RAW decoding depending on what works better for the hosting environment and user devices.
  - Theme branding controls are clearer and separator sizing/stretching is configurable.

  ### Notes

  - Google login requires OAuth client configuration and a linked admin account before it can authenticate anyone.
  - Persistent login stores hashed browser tokens and should be revoked automatically on logout or password change.
  - Feature switches hide and guard features, but they do not remove existing data.
  - Mobile WebDAV support is a compatibility framework for external upload clients, not a dedicated native app.
  - DNG conversion behavior still depends on server capabilities such as Imagick delegates and available memory.
  - Media renaming physically changes source filenames, so administrators should review dry-run plans before applying changes.
  - The patch notes template and maintenance docs are included so future release notes can be generated consistently.

  ### Files changed

  - `.htaccess`
  - `AGENTS.md`
  - `ARCHITECTURE.md`
  - `CODEMAP.md`
  - `DATABASE.md`
  - `PATCH_NOTES.md`
  - `PATCH_NOTES_TEMPLATE.md`
  - `README.md`
  - `TESTING.md`
  - `app/bootstrap.php`
  - `app/controllers.php`
  - `app/controllers/admin_auth.php`
  - `app/controllers/admin_dashboard.php`
  - `app/controllers/admin_diagnostics.php`
  - `app/controllers/admin_features.php`
  - `app/controllers/admin_galleries_edit.php`
  - `app/controllers/admin_media_renamer.php`
  - `app/controllers/admin_public_inline.php`
  - `app/controllers/admin_theme.php`
  - `app/controllers/admin_uploads.php`
  - `app/controllers/mobile_webdav.php`
  - `app/controllers/public_gallery.php`
  - `app/controllers/theme_assets.php`
  - `app/controllers/updates.php`
  - `app/core-manifest.json`
  - `app/helpers.php`
  - `app/lang/cs.json`
  - `app/lang/cs.php`
  - `app/lang/en.json`
  - `app/lang/en.php`
  - `app/security.php`
  - `app/services.php`
  - `app/services/admin_dashboard.php`
  - `app/services/ai_image_analysis.php`
  - `app/services/auth_persistence.php`
  - `app/services/dng_derivatives.php`
  - `app/services/exif.php`
  - `app/services/feature_flags.php`
  - `app/services/gallery_lightbox_mode.php`
  - `app/services/gallery_sidecars.php`
  - `app/services/google_auth.php`
  - `app/services/media_renamer.php`
  - `app/services/mobile_webdav.php`
  - `app/services/openai_text_assist.php`
  - `app/services/picture_game.php`
  - `app/services/public_paths.php`
  - `app/services/public_search.php`
  - `app/services/theme.php`
  - `app/services/updates.php`
  - `app/services/uploads.php`
  - `app/views/admin_chrome.php`
  - `app/views/admin_dashboard.php`
  - `app/views/seo.php`
  - `config.example.php`
  - `database/migrations/202605310001_admin_persistent_auth_and_google_login.php`
  - `database/migrations/202606010001_gallery_lightbox_browsing_mode.php`
  - `database/migrations/202606010002_gallery_lightbox_browsing_mode_carousel.php`
  - `database/migrations/202606040001_mobile_webdav_upload_tokens.php`
  - `public/assets/gallery-modules/admin-media-renamer.js`
  - `public/assets/gallery-modules/admin-operations.js`
  - `public/assets/gallery-modules/admin-side-panel.js`
  - `public/assets/gallery-modules/lightbox-deferred.js`
  - `public/assets/gallery-modules/lightbox.js`
  - `public/assets/gallery.js`
  - `public/assets/styles/admin-media-tools.css`
  - `public/assets/styles/admin.css`
  - `public/assets/styles/lightbox.css`
  - `public/assets/styles/mobile-gallery.css`
  - `public/assets/styles/side-panel.css`
  - `release-metadata.json`
  - `tests/dng_conversion_policy_test.php`
  - `tests/gallery_lightbox_mode_model_test.php`
  - `tests/upload_accept_and_dng_gps_test.php`

## Version 0.73

Version 0.73 expands PHP Gallery with context-aware tagging, live public search, internal AI image analysis, and optional OpenAI-assisted description generation. It also polishes gallery hero presentation and improves the admin editing flow around gallery and photo metadata.

  ### Highlights

  #### Added context-aware tag suggestions and pill-based tag editing

  - Added weighted tag suggestions in gallery editors.
  - Ranked suggestions by local gallery context before falling back to site-wide tag usage.
  - Preferred tags from current gallery photos, sibling galleries, descendants, ancestors, and nearby folder context.
  - Reworked tag editing into removable selected-tag pills.
  - Added comma, semicolon, newline, Enter, blur, and separator handling for committing tags.
  - Added duplicate prevention and normalized submission through the existing comma-separated backend format.
  - Improved tag suggestion display so already selected tags are hidden from the suggestion list.
  - Updated tag editor styling for compact, accessible tag pills and suggestion chips.

  #### Improved gallery hero and tag presentation

  - Refined public gallery hero layout so long descriptions no longer collapse into a narrow central column.
  - Kept the title, breadcrumbs, description, and metadata better aligned in the primary hero column.
  - Improved visual spacing around tags and gallery metadata.
  - Updated admin and public styling so large tag sets are less intrusive.
  - Preserved existing gallery data, URLs, and public rendering behavior.

  #### Added optional live public search

  - Added an optional public search feature controlled from the admin dashboard.
  - Added a thin live search bar on the front page when enabled.
  - Added the same search bar to gallery pages.
  - Added gallery-context search mode so gallery pages can search only the current gallery and its subgalleries.
  - Searched across gallery titles, descriptions, tags, filenames, photo titles, photo descriptions, and available AI metadata.
  - Added debounced browser-side searching with stale-request cancellation.
  - Added loading, empty, error, and clear states.
  - Added compact result cards for gallery and photo matches.
  - Kept the search feature disabled by default until an admin enables it.

  #### Added server-backed AI image analysis metadata

  - Added database-backed internal AI image-analysis metadata.
  - Added a leased queue system for analysis jobs claimed by the Windows companion app.
  - Added worker actions for claiming jobs, extending leases, downloading assets, completing jobs, and recording failures.
  - Stored AI metadata separately from public descriptions.
  - Exposed generated AI metadata as read-only information in the admin photo editor.
  - Added a gallery-level action to force AI metadata regeneration.
  - Added search integration so generated internal metadata can improve search results.
  - Kept heavy image analysis off the shared PHP host and delegated processing to the Windows worker.

  #### Extended the Windows companion app with AI metadata worker support

  - Added optional AI metadata worker behavior to the Windows uploader app.
  - Added local analysis, queue polling, lease heartbeat handling, and completion reporting.
  - Added dependency installation and backend selection support for local AI tooling.
  - Added documentation for the AI worker setup and reprocessing workflow.
  - Preserved normal watch-folder and upload behavior.

  #### Added optional OpenAI text assistance

  - Added profile-level OpenAI settings with encrypted API key storage.
  - Added model selection and password-confirmed settings updates.
  - Kept OpenAI assistance fully optional and hidden unless enabled for the current account.
  - Added reusable OpenAI text assistance for gallery descriptions, photo descriptions, parent-gallery summaries, spelling cleanup, grammar cleanup, expansion, and rewrites.
  - Added editor controls for both gallery and photo description fields.
  - Added browser-side insertion, replacement confirmation, status reporting, and error handling.
  - Added admin logging for successful and failed OpenAI generation requests without logging API keys.

  #### Added thumbnail opt-in for OpenAI visual prompts

  - Added a separate account setting for sending generated thumbnails to OpenAI.
  - Kept image input disabled by default.
  - Sent small generated thumbnails only when the user explicitly enables image input.
  - Never sent original image files through the OpenAI text-assistance workflow.
  - Added clear UI copy explaining the thumbnail-based behavior.
  - Added server-side gating so visual prompt actions fail safely when consent is disabled.

  #### Added language selection for generated AI text

  - Added output-language selection for AI-generated text.
  - Supported automatic language handling, Czech, and English.
  - Threaded the selected language through browser requests, controller validation, prompt construction, and generation.
  - Added localized labels and JavaScript strings for the language selector.

  #### Added bulk OpenAI photo-description generation

  - Added a gallery-level bulk action for generating descriptions for all eligible photos in a gallery.
  - Counted photos before starting the operation.
  - Required explicit confirmation by entering the exact number of photos to process.
  - Processed photos one at a time, with one OpenAI request per photo.
  - Saved each generated photo description immediately.
  - Reported saved, completed, and failed counts during the run.
  - Validated gallery ownership and image ownership before saving generated descriptions.
  - Reused the same image-input consent gates as individual visual description actions.

  ### Technical Details

  #### Backend

  - Added the `public_search` route.
  - Added the `admin_public_search_settings` route.
  - Added the `admin_openai_text_assist` route.
  - Added public search service logic in `app/services/public_search.php`.
  - Added OpenAI text-assistance service logic in `app/services/openai_text_assist.php`.
  - Added AI image-analysis queue and metadata logic in `app/services/ai_image_analysis.php`.
  - Added weighted tag suggestion helpers in `app/services/tag_metadata.php`.
  - Added OpenAI settings handling to the account controller.
  - Added AI metadata inspection to the admin photo editor.
  - Added AI metadata regeneration controls to the gallery API tab.
  - Added upload automation API actions for AI worker polling, heartbeat, asset streaming, and completion.
  - Updated service and controller loaders for the new modules.
  - Regenerated the core manifest for the expanded file set.

  #### Database

  - Added migration `202605280001_ai_image_analysis_queue.php`.
  - Added migration `202605290001_user_openai_text_settings.php`.
  - Added migration `202605290002_user_openai_image_input_flag.php`.
  - Added storage for internal AI metadata and leased analysis jobs.
  - Added profile-level OpenAI text-assistance settings.
  - Added a separate profile-level thumbnail-consent flag for image-input prompts.

  #### Frontend

  - Added `public/assets/gallery-modules/public-home-search.js`.
  - Added `public/assets/gallery-modules/admin-openai-text-assist.js`.
  - Updated `public/assets/gallery-modules/tag-suggestions.js` for pill editing and weighted suggestions.
  - Updated public gallery initialization to load the new search module.
  - Updated admin-side browser strings for OpenAI actions, confirmation prompts, bulk progress, and error handling.
  - Updated gallery, admin, side-panel, dashboard, and media-tool styles for the new controls.

  #### Windows companion app

  - Updated `winapp/gallery_watch_upload.pyw` with optional AI metadata worker support.
  - Updated `winapp/README.md` with setup and operation notes for the new worker mode.
  - Kept upload automation compatible with existing gallery API keys.

  #### Tests

  - Added `tests/openai_text_assist_model_test.php`.
  - Covered model normalization.
  - Covered language normalization.
  - Covered prompt and task selection.
  - Covered image-input gating.
  - Covered bulk-generation helper behavior.

  ### User Impact

  #### For visitors

  - Public search can make galleries, photos, tags, and descriptions easier to discover when enabled.
  - Gallery pages with long descriptions should read better and waste less horizontal space.
  - Search results can become more useful when internal AI metadata exists.
  - The search interface remains compact and does not alter normal gallery browsing when unused.

  #### For administrators

  - Tagging is faster and more context-aware.
  - Gallery and photo descriptions can be drafted or cleaned up through optional OpenAI assistance.
  - Bulk photo-description generation can process a whole gallery after explicit confirmation.
  - Generated OpenAI text remains reviewable and controlled by normal save workflows, except confirmed bulk photo descriptions, which are saved one photo at a time.
  - OpenAI API keys are stored at profile level and protected by password confirmation when changed.
  - Thumbnail-based OpenAI actions require separate consent.
  - Internal AI metadata can be inspected without confusing it with public descriptions.
  - The Windows companion app can now perform heavier local analysis work instead of pushing that burden onto shared hosting.

  ### Notes

  - Public search is optional and admin-controlled.
  - OpenAI text assistance is optional and profile-controlled.
  - Thumbnail-based OpenAI prompts are disabled by default and require separate opt-in consent.
  - Internal AI metadata is not the same as public photo descriptions.
  - The AI image-analysis worker stores metadata for indexing and inspection, while public description text remains controlled separately.
  - Bulk OpenAI photo-description generation can be expensive because it sends one request per photo.
  - AI image analysis requires the new queue migration before worker actions are available.
  - Existing galleries and photos remain valid without AI settings, without OpenAI keys, and without the Windows AI worker.
  - The core manifest was refreshed for the new controllers, services, migrations, scripts, styles, translations, tests, and Windows companion app changes.

  ### Files changed

  - `app/bootstrap.php`
  - `app/controllers.php`
  - `app/controllers/admin_auth.php`
  - `app/controllers/admin_dashboard.php`
  - `app/controllers/admin_galleries_edit.php`
  - `app/controllers/admin_gallery_renderers.php`
  - `app/controllers/admin_openai_text_assist.php`
  - `app/controllers/admin_public_inline.php`
  - `app/controllers/public_gallery.php`
  - `app/controllers/upload_automation.php`
  - `app/core-manifest.json`
  - `app/helpers.php`
  - `app/lang/cs.json`
  - `app/lang/en.json`
  - `app/services.php`
  - `app/services/ai_image_analysis.php`
  - `app/services/openai_text_assist.php`
  - `app/services/public_search.php`
  - `app/services/tag_metadata.php`
  - `app/views/admin_dashboard.php`
  - `app/views/admin_gallery_forms.php`
  - `database/migrations/202605280001_ai_image_analysis_queue.php`
  - `database/migrations/202605290001_user_openai_text_settings.php`
  - `database/migrations/202605290002_user_openai_image_input_flag.php`
  - `public/assets/gallery-modules/admin-openai-text-assist.js`
  - `public/assets/gallery-modules/admin-operations.js`
  - `public/assets/gallery-modules/admin-side-panel.js`
  - `public/assets/gallery-modules/lightbox-deferred.js`
  - `public/assets/gallery-modules/lightbox.js`
  - `public/assets/gallery-modules/public-home-search.js`
  - `public/assets/gallery-modules/tag-suggestions.js`
  - `public/assets/gallery.js`
  - `public/assets/styles/admin-dashboard.css`
  - `public/assets/styles/admin-layout.css`
  - `public/assets/styles/admin-media-tools.css`
  - `public/assets/styles/admin.css`
  - `public/assets/styles/public.css`
  - `public/assets/styles/side-panel.css`
  - `public/assets/styles/utilities.css`
  - `tests/openai_text_assist_model_test.php`
  - `winapp/README.md`
  - `winapp/gallery_watch_upload.pyw`

## Version 0.72.1

Version 0.72.1 focuses on map widget polish and lightbox map behavior stability.

  ### Highlights

  #### Map widget improvements

  - Centered the map widget more cleanly inside the lightbox layout.
  - Adjusted the split-view presentation so the map area feels more balanced.

  #### Lightbox map zoom persistence

  - Preserved lightbox map zoom state while navigating between photos.
  - Kept the current zoom level stable so users do not need to re-zoom after each navigation step.

  #### Styling updates

  - Updated related gallery and admin styling so the map widget matches the improved interaction flow.
  - Refined the surrounding layout behavior without changing gallery data or map content.

  ### Notes

  - No database changes were required.
  - No new gallery features were added in this patch.
  - This release is limited to map widget presentation and lightbox zoom behavior fixes.

## Version 0.72

Version 0.72 adds navigation-data integration, API-based gallery migration, and a deeper SimBrief-driven route
  workflow. It also extends the Windows uploader with Flight Simulator camera metadata support and improves gallery map
  rendering so route data and photo GPS data can coexist cleanly.

  ### Highlights

  #### Added Navigraph OAuth and AIRAC navigation data support

  - Added Navigraph OAuth support so users can connect navigation-data accounts directly in the app.
  - Added AIRAC/navigation-data caching and local navpoint support for route and map generation.
  - Added a new admin navigation-data panel for managing synced navdata and account state.
  - Added supporting database migrations and configuration updates for the new navigation-data workflow.

  #### Improved SimBrief route generation and gallery descriptions

  - Improved SimBrief-based path generation so route data can be built from imported flight plans more reliably.
  - Refined SimBrief description rendering so gallery descriptions can be generated from flight-plan data with richer
  presentation.
  - Kept route generation and description generation integrated with the existing gallery admin model.

  #### Added gallery migration over API

  - Added gallery migration over API with both source-push and target-pull workflows.
  - Transfer gallery settings, images, metadata, thumbnails, route/map data, and other gallery-defining assets in staged
  batches.
  - Added version compatibility checks so migration is only allowed between matching app versions for now.
  - Added new admin-side UI and scripting for migration workflows.
  - Added migration tests and manifest updates for the new transfer flow.

  #### Added Flight Simulator camera metadata for watched screenshots

  - Added WinApp support for Flight Simulator camera metadata on watched screenshots.
  - The uploader can query SimConnect and attach camera-location data to uploads when enabled.
  - Added a checkbox to turn camera-location tagging on or off, with the feature enabled by default.
  - Added supporting server-side upload handling and tests for the new GPS metadata flow.

  #### Improved gallery maps and combined route/photo display

  - Improved gallery maps so a route and a photo GPS point can appear together on the same map.
  - The combined map now shows the gallery route line, route points, and the active photo marker at the same time.
  - The photo marker is visually emphasized so it stands out from route markers.
  - Updated admin and lightbox map rendering so route-only and photo-only cases still behave as before.

  #### Updated supporting admin and frontend assets

  - Updated gallery JavaScript modules for admin operations, navigation data, migration, and image presentation.
  - Updated public gallery and lightbox assets to support the newer map and workflow behavior.
  - Updated admin CSS to match the newer dashboard, migration, and navigation-data layouts.

  ### Technical Details

  - Added new controllers, services, and views for navigation data and gallery migration.
  - Added database migrations for navigation-data caching and linked accounts.
  - Added `data/navdata/local_nav_points.csv` for local navpoint support.
  - Added dedicated browser modules for navigation-data and migration administration.
  - Added supporting tests for SimBrief description handling and gallery migration behavior.
  - Regenerated `app/core-manifest.json` for the new file set.

## Version 0.71

- Added Flight Simulator camera-location uploads for watched screenshots.
  - The Windows watcher can now read the current MSFS 2024 camera position through SimConnect and send latitude,
    longitude, and altitude to PHP Gallery during upload.
  - Added a checkbox to turn camera-location tagging on or off, with the feature enabled by default.
  - Added automatic SimConnect DLL discovery plus a local SimConnect.dll fallback in the winapp folder.
  - Added a system tray mode for the Windows uploader, including a tray icon and minimize-to-tray behavior.
  - Fixed the top Picture manager panel refresh bug so it no longer gets stuck open after editing gallery data from the
    right admin panel.
  - Improved gallery maps so a route and a photo GPS point can appear together on the same map.
  - The combined map now shows the gallery route line, route points, and the active photo marker at the same time, with
    the photo marker visually emphasized.
  - Updated the admin and lightbox map rendering so route-only and photo-only cases still behave as before.
  - Added the supporting server-side upload handling, tests, and manifest updates for the new GPS metadata flow.

## Version 0.70.1

- Fixed a bug where the top Picture manager panel could reopen in a stuck state after editing gallery data from the
    right admin side panel.
  - The panel now rebinds correctly after admin-side fragment refreshes, so it can be collapsed again without reloading
    the page.
  - No database changes, no new features, and no behavior changes outside this admin UI fix.

## Version 0.70

Version 0.70 expands gallery tooling around SimBrief route content, adds a dedicated picture manager workflow, and continues polishing mobile lightbox and admin usability. It also tightens uploader diagnostics, improves tag management, and cleans up backend boundaries around the newer admin features.

### Highlights

#### Added SimBrief route-backed gallery maps and descriptions

  - Added SimBrief route-backed gallery maps for flight-themed galleries.
  - Added automatic gallery description generation from SimBrief route data.
  - Added a dedicated SimBrief admin workflow for managing route content.
  - Added flight-map persistence support through a new database migration.
  - Added translation and admin UI support for the new SimBrief tooling.
  - Kept route generation and description generation integrated with the existing gallery admin model.

#### Added a dedicated picture manager workflow

  - Added a dedicated picture manager controller and service layer.
  - Added picture manager browser modules for managing gallery images more directly.
  - Added drag-and-drop and drop-action support for public and admin image handling.
  - Added a HUD overlay for the picture manager so image actions stay visible on top of the photo.
  - Added gallery picker and drag-ghost UI support for image organization tasks.
  - Consolidated picture-management behavior into a more explicit workflow instead of spreading it across unrelated admin screens.

#### Improved mobile gallery and lightbox behavior

  - Reworked the mobile gallery into a more isolated layout layer.
  - Improved mobile lightbox swipe handling and fullscreen interaction on touch devices.
  - Refined viewport handling so the mobile lightbox behaves more predictably during gestures.
  - Added a fullscreen slideshow path for lightbox viewing.
  - Improved lightbox fullscreen presentation and supporting CSS for mobile and desktop layouts.
  - Preserved the existing gallery navigation model while making touch behavior less fragile.

#### Improved admin gallery management

  - Refined admin tag management with sortable usage and usage links.
  - Improved gallery list, reordering, and bulk action interactions in the admin UI.
  - Added a safer admin side-panel refresh flow for updated gallery content.
  - Fixed the API manager panel and upload API revoke flow.
  - Cleaned up MVC boundaries and controller/service responsibilities around the newer admin pages.
  - Improved admin dashboard rendering and telemetry handling for the updated admin stack.

#### Improved upload watcher diagnostics

  - Added a watcher health indicator for the Windows uploader.
  - Added color-coded upload log output for easier scanning during batch uploads.
  - Ignored Python cache files in the uploader workflow.
  - Improved Windows companion app behavior for upload automation and API-key handling.
  - Tightened upload automation behavior to better support the newer gallery workflows.

#### Updated supporting frontend assets

  - Updated gallery JavaScript modules for admin operations, side panels, navigation data, and image reordering.
  - Updated public gallery and lightbox assets to support the new mobile, fullscreen, and picture-manager flows.
  - Updated admin and public CSS to match the newer layouts and interaction patterns.

## Version 0.69

Version 0.69 is a major large-gallery scalability, fullscreen-map stability, uploader automation, and deferred lightbox-loading release. It focuses on making very large galleries usable without blocking the browser, improving fullscreen map behavior, introducing lazy lightbox dataset generation, expanding the Windows uploader tooling, and stabilizing dynamic public refresh behavior for galleries with thousands of images.

### Highlights

#### Added deferred lazy lightbox dataset generation

  - Added deferred lightbox dataset generation for large galleries.
  - Added asynchronous background preparation of remaining lightbox items.
  - Added progressive lightbox-state hydration instead of requiring full blocking initialization.
  - Added gallery-aware lazy item expansion for paginated galleries.
  - Added safer initialization guards for race conditions during rapid opening and closing.
  - Preserved keyboard navigation, fullscreen mode, voting, EXIF overlays, pagination, and public admin controls during deferred loading.

#### Added fullscreen lightbox loading progress UI

  - Added a dedicated lightbox loading state.
  - Added a loading overlay inside the lightbox frame.
  - Added progress text such as `Preparing photo 1 of 1500`.
  - Added animated progress behavior while lazy lightbox data are generated.
  - Prevented empty fullscreen frames during initial lightbox preparation.
  - Limited the progress UI to initial lightbox loading, not normal photo switching.

#### Improved fullscreen map mode

  - Fixed fullscreen map mode for galleries where some photos have GPS EXIF data and some do not.
  - Photos without GPS now keep the map split area visible but show it as unavailable instead of reusing the previous photo map.
  - Added disabled-map messaging for photos without coordinates.
  - Blocked fullscreen map behavior when gallery EXIF or GPS map support is disabled.
  - Fixed keyboard shortcut behavior so maps cannot be activated when the gallery does not allow GPS maps.
  - Fixed horizontal image fitting in fullscreen map split mode so images fit by their longest dimension instead of being cropped or zoomed.
  - Preserved fullscreen map mode while navigating between GPS and non-GPS photos.

#### Added lazy lightbox JSON endpoint

  - Added the `gallery_lightbox_data` public route.
  - Added `app/controllers/gallery_lightbox.php`.
  - Added `app/services/lightbox_metadata.php`.
  - The endpoint returns ordered windows of image metadata for asynchronous lightbox navigation.
  - The endpoint enforces the same gallery access checks as the public gallery page.
  - The endpoint respects public-only visibility rules.
  - The endpoint avoids exposing restricted NSFW image rows to anonymous visitors.
  - The endpoint keeps visitor vote state private and disables shared caching.
  - The endpoint returns map metadata only when GPS maps are allowed for the gallery.

#### Optimized public gallery rendering for very large galleries

  - Public gallery pages now query only the currently visible photo page when pagination is enabled.
  - Full-gallery lightbox metadata is no longer rendered eagerly into hidden DOM nodes.
  - Gallery photo counts are queried separately from visible photo rows.
  - Lightbox counts are computed separately from normal grid pagination counts when restricted items must be hidden.
  - Direct image links now compute the requested image position without loading the whole gallery image list.
  - Public image cards now expose stable `data-lightbox-index` values for asynchronous lightbox order.
  - Public reorder toolbar totals now use the full image count instead of the current visible slice.
  - SEO and social-preview fallback metadata stay bounded to visible content.

#### Improved lightbox browser modules

  - Updated deferred lightbox activation to work with asynchronous dataset loading.
  - Updated full lightbox navigation to request missing metadata windows as needed.
  - Added lazy window loading around the active image.
  - Added item cache handling for fetched lightbox metadata.
  - Added loading-state rendering for the first requested image.
  - Added progress animation while initial metadata are still being prepared.
  - Added safer teardown behavior for pending lazy-load operations.
  - Preserved voting panel synchronization after asynchronous lightbox item insertion.
  - Preserved map split state when navigating through lazily loaded items.
  - Improved resilience when users click photos before the full lightbox module has finished loading.

#### Added upload API manager

  - Added the `admin_api_manager` route.
  - Added an admin-wide API manager page for upload automation keys.
  - Added an API manager entry to the admin menu.
  - Added a dedicated API tab to the gallery editor.
  - Moved gallery-scoped upload API key management out of the image-management tab.
  - API keys remain scoped to one gallery.
  - The global manager lists active upload API keys across all galleries.
  - Admins can revoke keys from either the gallery editor or the global API manager.
  - Revocation redirects now preserve the correct return context.
  - Added schema-readiness guards to avoid fatal errors on partially migrated installations.
  - Fixed the API manager query to use existing user schema fields instead of a missing `display_name` column.

#### Improved upload automation concurrency

  - Added a gallery-scoped advisory lock around upload automation storage, scanning, and thumbnail installation.
  - Parallel Windows uploader requests can still run, but server-side mutation of one target gallery is serialized.
  - Prevented duplicate image insertion races when multiple upload requests scan the same gallery folder at the same time.
  - Added a clear busy-gallery error when the target gallery is already being processed.
  - Kept the existing gallery upload pipeline as the source of truth.

#### Added client-generated thumbnail upload support

  - Upload automation can now accept thumbnails generated by the Windows companion app.
  - Added request-local client IDs to correlate original images with uploaded thumbnails.
  - Added validation for client thumbnail size, format, MIME type, dimensions, and uploaded-file integrity.
  - Accepted thumbnail formats are limited to supported gallery thumbnail formats.
  - Client thumbnails are installed only for images accepted by the existing upload pipeline.
  - Added fresh uncached image lookup after scanning so thumbnails can attach to newly imported images in the same request.
  - Added counters for installed, skipped, and failed client thumbnails.
  - Added thumbnail installation diagnostics to upload automation JSON responses and admin logs.

#### Improved Windows uploader behavior

  - Extended the Windows uploader workflow for manual bulk upload alongside watch-folder uploading.
  - Preserved existing watch-folder behavior.
  - Added support for ignoring files that already existed before watch mode starts.
  - Added client-side thumbnail generation mode.
  - Improved installer behavior for launching the `.pyw` app without a console window.
  - Improved dependency installation handling for Windows environments with multiple Python versions.
  - Added multiprocessing-style parallel worker behavior for faster thumbnail generation and uploading on many-core CPUs.
  - Improved worker communication and upload-result reporting.
  - Clarified rejection and skip reporting in the uploader output.

#### Improved admin side-panel refresh behavior

  - Updated admin side-panel JavaScript to handle refreshed gallery content more safely.
  - Preserved side-panel workflow context after upload and gallery-editor transitions.
  - Improved handling for dynamically loaded admin tabs inside panel content.
  - Reduced stale DOM state after public gallery fragments are replaced.
  - Kept responsive thumbnails, back-to-top behavior, and lightbox modules aligned after dynamic refreshes.

#### Added and updated translations

  - Added English and Czech strings for lightbox initial loading.
  - Added English and Czech strings for lightbox loading progress counts.
  - Added English and Czech strings for unavailable fullscreen map state.
  - Added admin strings for the API manager and gallery upload automation tab.
  - Added upload automation error strings for client thumbnail validation and gallery busy states.
  - Updated browser-side i18n exports for no-GPS fullscreen map messaging.

### Technical Details

#### Backend

  - Added route registration for `gallery_lightbox_data`.
  - Added route registration for `admin_api_manager`.
  - Added `app/controllers/gallery_lightbox.php`.
  - Added `app/services/lightbox_metadata.php`.
  - Loaded the new lightbox metadata service from `app/services.php`.
  - Loaded the new lightbox controller from `app/controllers.php`.
  - Added reusable lightbox metadata helpers for:
    - total photo counts
    - paged image fetching
    - gallery-local image position lookup
    - public visibility filtering
    - restricted NSFW filtering
  - Refactored public gallery image loading to avoid eager full-gallery row loading.
  - Added JSON serialization helpers for lightbox image metadata.
  - Added private no-store cache headers for lightbox JSON responses.
  - Added gallery-scoped upload automation locking.
  - Added client thumbnail validation and installation helpers.
  - Added API key manager query helpers.

#### Frontend

  - Updated `public/assets/gallery-modules/lightbox.js`.
  - Updated `public/assets/gallery-modules/lightbox-deferred.js`.
  - Updated `public/assets/gallery-modules/admin-side-panel.js`.
  - Updated `public/assets/gallery.js`.
  - Updated `public/assets/styles/lightbox.css`.
  - Added loading-state UI inside the existing lightbox frame.
  - Added animated loading progress styling.
  - Added disabled fullscreen-map styling for photos without GPS.
  - Added lightbox map availability checks.
  - Added lazy lightbox metadata fetching and caching behavior.
  - Preserved teardown support for dynamic public content replacement.

#### Upload automation

  - Added multipart handling for client-generated thumbnails.
  - Added `image_client_ids[]`, `thumbnail_client_ids[]`, `thumbnail_sizes[]`, and `thumbnail_formats[]` request handling.
  - Added strict server-side validation before any client thumbnail is written into the cache.
  - Added support for reporting client thumbnail installation results in JSON responses.
  - Added compatibility checks for the upload automation token schema.
  - Added a global admin manager for active upload API keys.

### User Impact

#### For visitors

  - Large galleries open faster.
  - The first fullscreen photo appears without waiting for the full gallery dataset.
  - Initial fullscreen loading now shows progress instead of an empty frame.
  - Fullscreen map mode behaves correctly when navigating between GPS and non-GPS photos.
  - Horizontal photos fit correctly in fullscreen map split mode.
  - Galleries with many photos should feel lighter and less likely to stall the browser.

#### For administrators

  - Large paginated galleries are cheaper to render and easier to browse.
  - Upload automation keys can be reviewed globally from the API manager.
  - Gallery-specific API keys have a dedicated gallery editor tab.
  - Parallel uploader activity is safer against duplicate scan/import races.
  - Windows uploader bulk uploads can use client-generated thumbnails for faster server-side processing.
  - Upload logs now expose client thumbnail install, skip, and failure counts.
  - The uploader workflow is better suited for many-core Windows systems.

### Notes

  - The lazy lightbox endpoint intentionally returns private, no-store JSON because vote state can be visitor-specific.
  - Full-gallery hidden lightbox source nodes are no longer emitted for paginated galleries.
  - The public gallery grid still renders only visible page content.
  - The lightbox can still navigate across the whole gallery by fetching metadata windows as needed.
  - GPS map controls are disabled when gallery EXIF map support is not available.
  - Photos without GPS no longer reuse the last valid map while fullscreen map mode is active.
  - Client-generated thumbnails are accepted only after the original image is accepted by the existing upload pipeline.
  - The upload automation gallery lock serializes server-side mutation for one gallery, not the entire site.
  - The core manifest was refreshed for the new controllers, services, scripts, styles, translations, and upload automation changes.

### Files changed

  - `app/bootstrap.php`
  - `app/controllers.php`
  - `app/controllers/admin_galleries_edit.php`
  - `app/controllers/gallery_lightbox.php`
  - `app/controllers/public_gallery.php`
  - `app/controllers/upload_automation.php`
  - `app/core-manifest.json`
  - `app/helpers.php`
  - `app/lang/cs.json`
  - `app/lang/en.json`
  - `app/services.php`
  - `app/services/lightbox_metadata.php`
  - `app/services/upload_automation.php`
  - `public/assets/gallery-modules/admin-side-panel.js`
  - `public/assets/gallery-modules/lightbox-deferred.js`
  - `public/assets/gallery-modules/lightbox.js`
  - `public/assets/gallery.js`
  - `public/assets/styles/lightbox.css`

## Version 0.68

Version 0.68 extends the Windows packaging and uploader workflow, keeps deployment paths aligned, and continues the release of the broader admin and gallery maintenance work.

### Highlights

#### Added Windows packaging and uploader workflow updates

  - Added the Winapp installer script path to the release packaging surface.
  - Added the Winapp uploader path so uploader changes can ship with the same release.
  - Added the Winapp uploader companion entry to keep related tooling grouped together.
  - Kept the deployment files aligned with the current branch structure.

#### Continued admin and gallery maintenance

  - Refined the admin and gallery release surface to match the current module split.
  - Kept the core versioned files aligned for the 0.68 release.
  - Preserved the existing patch notes format for the next tag.

## Version 0.67

Version 0.67 is a large maintenance, update-system, diagnostics, and admin-workflow release. It focuses on making GitHub update checks safer and cheaper, adding optional automatic stable updates, improving URL rewrite compatibility handling, expanding telemetry exports, making admin logs more usable, preserving navigation context across login, upload, and lightbox flows, and refreshing the project documentation.

### Highlights

#### Added a central GitHub API gateway

  - Added `app/services/github.php` as the single controlled access layer for GitHub REST API calls.
  - All updater GitHub Contents API requests now pass through the shared GitHub gateway.
  - Added local file-backed GitHub API cache metadata.
  - Added ETag and Last-Modified validator storage.
  - Added conditional GitHub requests so unchanged GitHub content can return `304 Not Modified`.
  - Added cached body reuse when GitHub returns `304 Not Modified`.
  - Added persisted GitHub response diagnostics for the Updates page.
  - Added support for recording:
    - HTTP status
    - ETag
    - Last-Modified
    - rate-limit resource
    - used quota
    - remaining quota
    - reset time
    - Retry-After wait windows
  - Added a wait-state model so GitHub primary rate-limit and Retry-After responses are respected by later update checks.
  - Avoided calling GitHub `/rate_limit` just to inspect quota state.
  - Kept the updater based on normal required API responses instead of adding extra quota-consuming diagnostic requests.

#### Added five-hour update-check caching and safer force checks

  - Changed the update status flow so the admin page uses a cache-aware update status with a five-hour TTL.
  - Added a Force check action that bypasses the local five-hour cache when an admin explicitly asks for a fresh GitHub check.
  - Force checks still record and respect GitHub rate-limit headers after the response.
  - Added a non-network fallback status when the installation is waiting for a GitHub retry window.
  - Added clearer handling for unknown cached update state when no remote metadata has been cached yet.
  - Improved remote version probing across allowed branches.
  - Added diagnostics when a branch is reachable but does not expose a usable version marker.
  - Prevented stale remote branch metadata from making the update page appear to offer a downgrade.
  - Added parsing of version markers from remote `PATCH_NOTES.md` headings as an additional version source.
  - Improved update-source labels so the admin can see when the detected version came from bootstrap metadata, patch notes, or branch diagnostics.

#### Added optional automatic stable updates

  - Added an admin setting for automatic stable updates.
  - Automatic updates are disabled unless explicitly enabled.
  - When enabled, safe browser requests can check for a stable update at most once every five hours.
  - Automatic checks do not run on unsafe request methods.
  - Automatic checks do not run while another automatic update check is locked.
  - Automatic checks respect beta installations and do not replace beta code automatically.
  - Added automatic update dry-run support.
  - Dry runs check metadata and update diagnostics without installing files.
  - Beta installs use dry-run behavior so update detection can be validated without replacing the beta build.
  - Added an admin dry-run button on the Updates page.
  - Added persisted automatic update diagnostics:
    - last automatic check time
    - relative check age
    - last result
    - no-update result
    - check-failed result
    - updated result
    - dry-run result
  - Added admin log events for:
    - automatic update installed
    - automatic update failed
    - automatic update dry run checked
    - automatic update dry run failed

#### Added URL rewrite settings and compatibility diagnostics

  - Added `url_rewrite_enabled()` and `set_url_rewrite_enabled()` app-setting helpers.
  - Clean URL generation remains enabled by default to preserve existing behavior.
  - Admins can now disable clean rewritten public URLs when their hosting does not support rewrite routing.
  - Added rewrite compatibility checks for typical Apache, LiteSpeed, and shared-hosting signals.
  - Added `.htaccess` marker checks so the app can detect whether rewrite rules are likely present.
  - Added runtime compatibility diagnostics with status values such as:
    - supported
    - likely supported
    - unsupported
    - disabled intentionally
    - unknown
  - Added an `admin_url_rewrite` route for saving the setting.
  - Added a URL rewrite card to the dashboard maintenance area.
  - Added a dashboard warning when URL rewrite is enabled but support is not detected.
  - Updated public gallery, image, and tag URL helpers so they fall back to `index.php` URLs when clean URL emission is disabled.
  - Kept query-string routes as the durable fallback for hosts where pretty URLs are unreliable.

#### Preserved login return targets

  - Added `current_login_return_target()` for capturing the current browser request as a same-site relative return target.
  - Added `sanitize_login_return_target()` to reject unsafe login redirects.
  - Login return targets reject:
    - absolute external URLs
    - protocol-relative URLs
    - control characters
    - login routes
    - logout routes
    - setup routes
    - password reset routes
  - The public Admin login link now includes the current page as a safe return target.
  - Successful login now redirects back to the originating page instead of always opening the admin dashboard.
  - Failed login attempts keep the sanitized return target in a hidden form field.
  - Admin account and upload redirects now preserve the intended post-login context.

#### Fixed paginated gallery return behavior from photo view

  - The lightbox now treats the current browser URL as the preferred gallery return URL when a photo is opened.
  - When a visitor opens a photo from a paginated gallery page, closing the photo now restores that exact paginated gallery URL.
  - Added URL comparison logic so direct photo URLs can still fall back to the server-rendered base gallery URL when appropriate.
  - Avoided resetting a paginated gallery back to the base gallery URL after viewing a photo.
  - Kept normal non-paginated lightbox navigation behavior intact.

#### Improved side-panel upload refresh context

  - Added `admin_upload_safe_refresh_url()` to validate the page that opened a side-panel upload workflow.
  - Side-panel upload forms now include the source page URL.
  - Existing-gallery uploads can refresh the exact page that opened the upload panel.
  - Paginated gallery views preserve their active `photo_page` or clean pagination URL after upload.
  - Upload JSON responses now return an editor URL that opens the image-management tab after upload.
  - The side panel can switch to the gallery editor after both new-gallery and existing-gallery upload flows.
  - Reworded the side-panel loading status from created-gallery-specific wording to generic gallery editor wording.

#### Added dashboard original-storage metric

  - Added an admin dashboard metric for total imported original file storage.
  - The metric sums `images.file_size` from imported source files.
  - Generated thumbnails, DNG display masters, caches, and other derivatives are excluded.
  - The value is formatted as a compact byte label in the Galleries summary card.
  - The query is profiled through the existing admin dashboard render profiler.

#### Expanded standalone telemetry HTML export

  - Expanded the telemetry export from a basic report into a much richer standalone diagnostics document.
  - Added report helper functions for bounded windows, scalar queries, table counts, and reusable table rendering.
  - Added session quality metrics:
    - sessions
    - page views
    - photo views
    - total capped duration
    - average pages per session
    - average photos per session
    - average session duration
    - bounced sessions
    - recent versus previous sessions
  - Added daily trend rows for sessions, page views, photo views, photo viewing time, client errors, and media bytes.
  - Added top gallery engagement reporting.
  - Added top route reporting.
  - Added browser, device, locale, viewport, and referrer-style distributions where telemetry data exists.
  - Added performance metrics and web-vital style summaries.
  - Added client error distributions.
  - Added recent anonymized telemetry event access-log output.
  - Added database telemetry summaries and fingerprint hot-spot tables.
  - Added recent telemetry job-run reporting.
  - Added compact bar-chart and trend-chart HTML renderers for the export.
  - Improved metric cards with optional explanatory hints.
  - Kept the telemetry report privacy-oriented and based on already collected anonymous telemetry data.

#### Redesigned admin log filters

  - Reworked the admin Logs filter panel into a more coherent grouped interface.
  - Added fieldset and legend structure for better semantic grouping.
  - Added a persistent multi-select severity filter.
  - Severity selections are stored in app settings and reused across log views.
  - Empty severity selection now explicitly means all severities.
  - Added severity filter reset behavior.
  - Added active severity summary text.
  - Preserved selected severities when building sort and filter URLs.
  - Kept backward compatibility with the legacy single `severity` query parameter.
  - Updated live filtering JavaScript to handle severity checkboxes and summary updates.
  - Added a new `admin_log_severity_filter_test.php` test.

#### Improved unpublished-gallery visibility for admins

  - Public gallery cards now expose normalized visibility metadata in `data-gallery-visibility`.
  - Logged-in admins now get a visible marker for unpublished galleries in public listings.
  - Anonymous preview mode does not show the admin unpublished marker.
  - Added dedicated public CSS for unpublished gallery cards.
  - Unpublished galleries visible only to admins are visually greyed and labeled instead of looking identical to public galleries.
  - Added English and Czech strings for the unpublished admin hint.

#### Improved theme background optimization UI

  - Added UI strings and styling for optimized theme background handling.
  - Theme media controls can now show whether an optimized WebP background is active.
  - Added controls and labels for:
    - generating an optimized background
    - regenerating an optimized background
    - deleting the optimized background
    - viewing the original image
    - viewing the served image
    - selecting optimized background size
  - Added admin theme preview CSS for background optimization states.
  - Added clearer labels for whether the site is serving the original background or the optimized WebP copy.

#### Improved admin tab hash behavior

  - Updated admin tab JavaScript to preserve and resolve tab hashes more reliably.
  - Added configurable hash suppression for cases where a panel should not write browser history.
  - Added helper behavior for activating admin tabs inside dynamic side-panel roots.
  - Updated module versioning for the side-panel and tab modules.

#### Updated project documentation

  - Rewrote `README.md` into a more structured project overview.
  - Expanded feature descriptions for gallery management, image management, thumbnails, access control, tags, voting, navigation, downloading, theming, updates, admin tools, telemetry, and security.
  - Reorganized installation instructions around the one-file shared-hosting installer.
  - Added clearer local-development and troubleshooting sections.
  - Rewrote `ARCHITECTURE.md` for the modern v0.66+ codebase.
  - Documented the request flow, route table, app directory structure, data model, feature responsibilities, performance model, security practices, and extension workflow.
  - Updated documentation to reflect the split controller and service structure.

### Updates and GitHub API details

  - The update page now uses cached update status by default.
  - Normal update-page reloads no longer need to consume a fresh GitHub API request each time.
  - The Force check button intentionally performs a fresh check.
  - GitHub API diagnostics are shown on the update page using stored response headers.
  - The app records rate-limit state from the responses it already needed to make.
  - The app does not spend an extra API request only to display rate-limit status.
  - `Retry-After` and primary reset times are turned into a local next-safe-check window.
  - Cached GitHub response bodies are reused for unchanged remote files.
  - Branch probes now retain diagnostics when a branch is reachable but missing a usable marker.
  - Update status can distinguish:
    - current installation
    - pending update
    - stale remote marker
    - unavailable remote marker
    - rate-limited wait state
    - unknown cached state

### Automatic update behavior

  - Automatic stable updates are deliberately conservative.
  - They run only when enabled by admin setting.
  - They run only from safe browser requests.
  - They are throttled by a local five-hour interval.
  - They use a lock setting to avoid overlapping checks.
  - They never silently switch a beta installation back to stable.
  - Beta installations can still run dry checks for metadata and diagnostics.
  - Automatic install results are written into admin logs with update category and severity metadata.
  - Failures are logged with current version, beta state, PHP version, and error detail.

### URL rewrite behavior

  - Clean URLs remain the default behavior.
  - Admins can turn clean URL emission off from the dashboard maintenance area.
  - When disabled, public helpers emit compatible query-string URLs.
  - Compatibility checking is advisory rather than a false guarantee.
  - The system checks practical signals such as rewrite environment variables, request routing state, and `.htaccess` markers.
  - The dashboard warning explains likely hosting causes such as missing `.htaccess` support or missing rewrite support.

### Admin logs behavior

  - The severity filter is no longer a single dropdown.
  - Multiple severities can be selected at once.
  - The selection persists between visits.
  - Resetting severity returns the page to all severities.
  - Search, status, category, severity, and time-order filters remain compatible with live AJAX refresh.
  - The filter layout now has clearer visual hierarchy and better grouping.

### Telemetry export details

  - The standalone HTML telemetry export now has broader operational value.
  - It includes engagement, performance, error, access-log-like, database, and job-run sections.
  - Tables use reusable rendering helpers.
  - Charts are generated as compact HTML/CSS report elements.
  - The report remains local and anonymous.
  - Existing telemetry tables are queried defensively so missing optional telemetry tables do not break export rendering.

### Public and admin navigation fixes

  - Admin login now returns users to the page that initiated login.
  - Upload workflows preserve the side-panel source URL.
  - Photo view close behavior preserves paginated gallery state.
  - Existing gallery uploads reopen the relevant gallery editor tab after completion.
  - Public clean URL generation can be disabled without breaking the underlying query-string routes.

### Translations

  - Added English and Czech strings for:
    - URL rewrite settings and warnings
    - GitHub API policy diagnostics
    - Force check actions
    - automatic update settings
    - automatic update dry runs
    - automatic update log messages
    - admin log severity filter summaries
    - theme background optimization controls
    - dashboard original-storage metric
    - unpublished gallery admin marker
    - date picker JavaScript labels
  - Updated PHP translation fallback files with the new string coverage.
  - Kept the multilingual structure compatible with existing `t()` and JavaScript translation usage.

### Tests

  - Added `tests/admin_log_severity_filter_test.php`.
  - Added `tests/url_rewrite_settings_test.php`.
  - Covered severity-filter normalization, persistence behavior, reset semantics, and compatibility with legacy query values.
  - Covered URL rewrite setting defaults, saved values, compatibility states, marker detection, and query-string fallback behavior.

### Files changed

  - `ARCHITECTURE.md`
  - `README.md`
  - `app/bootstrap.php`
  - `app/controllers/admin_auth.php`
  - `app/controllers/admin_dashboard.php`
  - `app/controllers/admin_logs.php`
  - `app/controllers/admin_telemetry.php`
  - `app/controllers/admin_uploads.php`
  - `app/controllers/public_gallery.php`
  - `app/controllers/updates.php`
  - `app/core-manifest.json`
  - `app/helpers.php`
  - `app/lang/cs.json`
  - `app/lang/cs.php`
  - `app/lang/en.json`
  - `app/lang/en.php`
  - `app/security.php`
  - `app/services.php`
  - `app/services/app_settings.php`
  - `app/services/github.php`
  - `app/services/logs.php`
  - `app/services/updates.php`
  - `public/assets/gallery-modules/admin-logs.js`
  - `public/assets/gallery-modules/admin-operations.js`
  - `public/assets/gallery-modules/admin-side-panel.js`
  - `public/assets/gallery-modules/admin-tabs.js`
  - `public/assets/gallery-modules/lightbox-deferred.js`
  - `public/assets/gallery-modules/lightbox.js`
  - `public/assets/gallery.js`
  - `public/assets/styles/admin-theme-preview.css`
  - `public/assets/styles/admin.css`
  - `public/assets/styles/public.css`
  - `tests/admin_log_severity_filter_test.php`
  - `tests/url_rewrite_settings_test.php`

### Notes

  - Clean rewritten URLs remain enabled by default.
  - Disable URL rewrite only on hosting where clean routed URLs do not work.
  - Normal update-page reloads should now use cached update status instead of spending repeated GitHub API calls.
  - Use Force check only when a fresh GitHub request is intentional.
  - Automatic updates install only stable releases and are skipped for beta code.
  - Automatic update dry runs never install files.
  - The dashboard original-storage metric counts imported original files only.
  - The telemetry export depends on telemetry tables and collected telemetry data; missing optional data results in empty report sections, not fatal errors.
  - The core manifest was refreshed for the new services, controllers, scripts, styles, tests, and documentation changes.

## Version 0.66

### Large internal refactor

  - Split the large gallery admin controller into focused controller files while preserving the old include contract through `app/controllers/admin_galleries.php`.
  - Moved gallery discovery, bulk gallery operations, gallery editing, gallery reordering, image bulk actions, image reordering, public inline admin actions, and shared admin renderers into separate files.
  - Split the thumbnail service into focused service files for formats, sources, HTML rendering, cached bundles, generation, maintenance, and DNG display derivatives.
  - Split the browser admin JavaScript into focused ES modules while keeping `admin-operations.js` as the legacy re-export entry point.
  - Split the admin CSS into focused stylesheet files for dashboard, layout, gallery list, media tools, reordering, tags, update page, patch notes, and theme editor areas.
  - Regenerated the integrity manifest for the new file structure.

### Admin tag management

  - Added a new `admin_tags` route and controller.
  - Added a dedicated Admin Tags page where admins can list, edit, rename, delete, and review reusable tags.
  - Added editable tag metadata:
    - display name
    - slug
    - public description
    - usage counts
  - Added a `tag_metadata` service for tag normalization, metadata editing, public lookup, and deletion logic.
  - Added database migration `202605120002_tag_metadata.php` for tag descriptions and metadata support.
  - Kept tags lowercase and safe for clean public URLs.
  - Added public tag admin actions so logged-in admins can edit a tag directly from a public tag page.
  - Added compact tag rendering so gallery cards can show a limited number of tags without expanding the layout too much.
  - Added Czech and English translation coverage for the new tag management UI.

### Clean public tag URLs

  - Refactored public tag rendering into `app/controllers/public_tags.php`.
  - Kept the legacy `tags.php` include contract while moving public tag page logic into focused code.
  - Added clean tag URL support so tag pages no longer have to rely only on query-style URLs.
  - Added public tag lookup by slug.
  - Added public gallery lookup by tag id.
  - Added tag descriptions to public tag pages when configured by the admin.

### Lightbox and fullscreen voting

  - Moved voting rendering into a focused vote controller.
  - Added reusable vote form rendering through `render_vote_form_html()`.
  - Added a dedicated `lightbox-votes.js` module for synchronizing lightbox and fullscreen vote controls.
  - Lightbox and fullscreen voting now clone and reuse the same vote form concept used by gallery cards instead of maintaining a separate inconsistent implementation.
  - Vote button state is synchronized after voting so gallery cards, lightbox, and fullscreen views stay consistent.
  - Voting controls are hidden when voting is disabled for the gallery or picture context.
  - Vote score display is suppressed when voting is disabled, preventing a half-visible inactive voting UI.
  - Adjusted fullscreen toolbar layout so the vote arrow aligns inline with the other controls.
  - Refined fullscreen map and toolbar spacing so the map label and empty lower gap do not reappear in map split mode.

### Admin side panel workflow

  - Moved side-panel behavior into a focused `admin-side-panel.js` module.
  - Improved side-panel form preparation for gallery edit, image edit, upload, and bulk image actions.
  - Added better incremental refresh handling after side-panel saves.
  - Added public gallery fragment replacement so side-panel actions can update the visible gallery page without a full manual reload.
  - Added image row updates after side-panel image edits.
  - Added public image card updates after side-panel image edits.
  - Added created-gallery focus handling so newly created galleries can be visually located after panel actions.
  - Improved upload progress handling inside the panel.
  - Improved upload result propagation for uploaded, scanned, thumbnail-created, and thumbnail-failed counts.

### Admin date picker

  - Added a focused `admin-date-picker.js` module.
  - Reworked native date inputs into a compact admin control.
  - Moved the clickable calendar icon before the date value.
  - Added Today and Delete quick actions.
  - Kept the real submitted value on the native date input.
  - Re-applies the enhancement when forms are loaded through the admin side panel.
  - Added CSS for consistent date picker sizing and alignment in both admin zone and side-panel forms.

### Admin logs diagnostics

  - Added database migration `202605120003_admin_log_diagnostics.php`.
  - Added diagnostic columns for admin logs:
    - fingerprint
    - HTTP method
    - AJAX flag
  - Added indexes for log fingerprint and route/method/time filtering.
  - Added migration logic to categorize older logs more accurately.
  - Added migration logic to mark low-severity todo logs as done when appropriate.
  - Added migration logic to infer subject type for older gallery, image, thumbnail, update, telemetry, and tag events.
  - Updated log rendering to force English labels on the logs page, making exported and displayed operational logs easier to share for debugging.
  - Improved live log filters through the new `admin-logs.js` module.

### Thumbnail and DNG handling

  - Refactored thumbnail handling out of the monolithic thumbnail service.
  - Added `dng_derivatives.php` for DNG display master generation and derivative handling.
  - Added support checks for DNG derivative generation.
  - Added fallback paths for embedded DNG previews when full RAW decoding is not available.
  - Added thumbnail bundle helpers for cached variant selection.
  - Added focused thumbnail source helpers for paths, URLs, srcsets, WebP srcsets, and fallback selection.
  - Added focused thumbnail generation helpers for JPEG and WebP output.
  - Added focused thumbnail maintenance helpers for inventory and repair workflows.
  - Improved partial-file cleanup when thumbnail generation fails.
  - Preserved EXIF-sensitive WebP generation paths where supported.

### Public gallery rendering

  - Updated public gallery rendering to work with the new tag metadata and compact tag display.
  - Improved public gallery card tag layout so tags can sit near date metadata without forcing unnecessary vertical expansion.
  - Added public CSS refinements for tag and metadata display.
  - Improved public gallery admin actions for tags.
  - Kept public gallery rendering compatible with the existing visibility and admin-edit workflows.

### Theme and background handling

  - Updated theme asset handling so theme CSS revisions can be refreshed more reliably after admin theme changes.
  - Added focused theme editor and theme preview CSS files.
  - Improved gallery background service handling.
  - Added small modern-theme CSS refinements.
  - Improved update-page and patch-notes styling through dedicated admin stylesheets.

### Patch notes viewer styling

  - Added a dedicated `admin-patch-notes.css` stylesheet.
  - Restyled patch-note content cards, headings, paragraphs, lists, inline code, and preformatted code.
  - Added loading-state styling for dynamically refreshed patch-note fragments.
  - Improved the version picker styling so installed and latest markers are easier to scan.
  - Kept the patch notes panel visually consistent with the dashboard-style admin update page.

### Admin dashboard and gallery list JavaScript

  - Added `admin-core.js` for shared browser helpers used by multiple admin modules.
  - Added `admin-gallery-list.js` for gallery filters, tree handling, dashboard reordering, and public page reordering.
  - Added `admin-image-reordering.js` for image table drag sorting and name sorting.
  - Added `admin-refresh-progress.js` for refresh progress feedback.
  - Added `admin-thumbnail-progress.js` for thumbnail progress feedback.
  - Added `admin-tabs.js` for reusable admin tab behavior.
  - Added `tag-suggestions.js` for safer tag autocomplete behavior.
  - Added `admin-picture-game.js` for keyboard support on the picture game admin screen.

### Upload workflow

  - Simplified upload controller responsibilities after moving shared side-panel logic into browser modules.
  - Improved upload progress display.
  - Improved upload result reporting for side-panel workflows.
  - Kept normal upload routes and non-JavaScript fallback behavior intact.

### Translations

  - Updated English and Czech JSON translation files for the new admin tag page, tag metadata, logs, date picker, patch notes viewer, and admin UI refinements.
  - Updated English and Czech PHP translation loaders with the new string coverage.
  - Kept the multilingual structure compatible with existing `t()` usage.

### Integrity, migrations, and tests

  - Updated `app/core-manifest.json` for the new controllers, services, browser modules, stylesheets, and migrations.
  - Added new migrations for tag metadata and admin log diagnostics.
  - Updated the initial schema migration with the new tag metadata field.
  - Extended the gallery branding model test coverage.
  - Preserved legacy include entry points where large files were split, reducing regression risk for existing routes.

## Version 0.65.7

### Tag normalization and autocomplete sanitizing

  - Added canonical tag normalization so stored tag names and slugs are forced into a safe lowercase form.
  - Existing tags are merged when they resolve to the same canonical value.
  - Gallery sidecar tag lists now stay normalized in the same format as database tags.
  - Browser tag autocomplete now mirrors the server-side normalization instead of preserving raw tag text.
  - Tag helper text was expanded to explain the lowercase safe-tag behavior to admins.

## Version 0.65.6

### Tag suggestion autocomplete refresh

  - Extended tag suggestions so autocomplete can run inside a specific DOM root instead of only on the whole document.
  - Wired the admin side-panel loader so newly loaded panel content initializes tag suggestions too.
  - Restyled the public tag suggestion dropdown so reused tag chips are easier to scan and select.
  - Relaxed the root guard so tag suggestions work with any render root that exposes `querySelectorAll`.

## Version 0.65.5

### Theme content revision caching

  - Bumped the public content revision when the gallery description layout changes so public HTML caches see the new
  card class right after a Theme save.
  - Split anonymous cache handling so gallery pages that render DB-backed card HTML revalidate on refresh.
  - Kept short public caching for static routes such as robots, sitemap, and theme_css.
  - Refreshed the integrity manifest for the revised theme and security code.

## Version 0.65.4

### Updates page layout refresh

  - Reworked the Updates page into a dashboard-style layout with summary cards and clearer primary actions.
  - Added a dedicated advanced tools section for beta installs, restores, and clean reinstalls.
  - Added matching admin styling for the new hero, metric cards, and responsive layout.
  - Regenerated the integrity manifest for the updated controller and stylesheet.

## Version 0.65.3

### Updates page patch notes picker

  - Replaced the plain patch-notes version select with a grouped picker that shows release streams, installed status,
  and latest markers.
  - Kept the native select in sync so form submission and fallback behavior still work.
  - Added translation strings for release counts and the Installed/Latest badges.
  - Refined the patch-notes panel styling so the new picker and version badges fit the admin layout cleanly.

## Version 0.65.2

### Updates page hardening

  - Patch notes and update version checks now use the GitHub Contents API instead of raw branch file URLs.
  - Remote HTTP fetching for update data now uses shared headers and timeout handling more consistently.
  - The update page now reuses cached patch-note data more deliberately while still fetching fresh content when needed.

## Version 0.65.1

Version 0.65.1 tightens public thumbnail loading so the first visible cards paint more consistently and progressive replacements do not flicker as aggressively during decode.

### Highlights

- Public gallery cards now prioritize the first visible thumbnails during initial paint.
- Progressive thumbnail upgrades now preload the likely replacement image before swapping the visible `srcset`.
- Public thumbnail slots keep a stable painted background while images decode.

## Version 0.65

Version 0.65 focuses on gallery metadata, public card presentation, translation coverage, admin diagnostics, update visibility, and access hardening.

### Highlights

#### Added configurable gallery description layouts

- Added a Theme-level default gallery-card description layout.
- Added per-gallery override support.
- Added two public card systems:
  - `Vertical system`
  - `Horizontal system`
- Existing galleries inherit the Theme default unless overridden.
- Horizontal cards place the picture at the top, then title, date, tags, and a compact Markdown-capable description.
- Added database support for `galleries.description_layout`.
- Added sidecar support for gallery description layout metadata.

#### Added manual gallery dates

- Added optional manual gallery dates to galleries.
- Dates are admin-entered and independent from upload dates or EXIF dates.
- Existing galleries keep the date empty by default.
- Empty dates are not displayed publicly.
- Added date fields to create, edit, side-panel, and create-and-upload workflows.
- Added public rendering for gallery dates in hero metadata and gallery cards.
- Added database support for `galleries.gallery_date`.

#### Improved gallery description formatting

- Public gallery descriptions now preserve user-entered line breaks.
- Added Markdown formatting hints in gallery description editors.
- Added guidance for bold, italic, inline code, links, and paragraph spacing.
- Improved description display in public gallery cards.

#### Added admin login and password reset throttling

- Added rate limiting for admin login attempts.
- Added rate limiting for password reset requests.
- Added visitor-level and identifier-level throttle buckets.
- Stored only hashed throttle subjects.
- Avoided storing raw submitted usernames, raw email addresses, or raw IP addresses in the throttle table.
- Added database support for `auth_rate_limits`.

#### Improved password reset and account localization

- Converted account, login, forgot-password, reset-password, SMTP, and password reset messages to translation keys.
- Localized password reset email subject and body.
- Localized SMTP diagnostics and password reset delivery messages.
- Localized account settings notices and validation errors.

#### Expanded translation infrastructure

- Added a dedicated translation service.
- Added request language bootstrap during routing.
- Added English fallback behavior for missing translation keys.
- Added browser-side translated string export.
- Added translation coverage diagnostics in Theme language settings.
- Expanded Czech and English language packs.

#### Added admin dashboard render profiling

- Added an admin-only dashboard render profiler.
- Added counters and timers for schema checks, DB queries, setting reads, gallery ordering, thumbnail maintenance summary reads, preview cover lookup, and rendered gallery rows.
- Added diagnostic output for dashboard performance tuning.
- Kept profiling admin-only.

#### Optimized admin dashboard thumbnail maintenance checks

- Dashboard thumbnail maintenance can now use cached summaries.
- Expensive exact thumbnail scans can be deferred.
- The dashboard can show that thumbnail status was not checked instead of forcing heavy work during first render.
- Dedicated thumbnail actions remain available for exact scans and repairs.

#### Added dynamic patch notes viewer to Updates

- Added a collapsible patch notes panel on the Updates page.
- Patch notes can be fetched from GitHub.
- Parsed patch notes are cached locally.
- Bundled local patch notes are used as fallback when GitHub cannot be reached.
- Admins can select installed, pending, or other available versions.
- Version switching loads dynamically without a full page reload.
- The patch notes panel received modern admin styling.

#### Hardened Apache access rules

- Disabled directory indexes.
- Blocked common sensitive file extensions.
- Blocked `.git` access.
- Blocked dotfiles except `.well-known/`.
- Extended direct-access protection to additional private directories.
- Hardened gallery directory access rules.

#### Improved admin UI localization and polish

- Converted more dashboard, gallery, upload, logs, telemetry, integrity, update, reset, theme, tag, media, and picture game UI strings to translation keys.
- Improved dashboard action labels and notices.
- Improved gallery editor labels, hints, and helper text.
- Improved side-panel field layout for date, description, and layout controls.
- Improved compact tag/date placement in horizontal gallery cards.

### Technical Notes

New or heavily updated areas include:

- `app/services/translations.php`
- `app/services/auth_throttle.php`
- `app/services/admin_render_profiler.php`
- `app/services/gallery_dates.php`
- `app/services/gallery_description_layout.php`
- `app/services/updates.php`
- `database/migrations/202605110001_auth_rate_limits.php`
- `database/migrations/202605110002_gallery_description_layout.php`
- `database/migrations/202605110003_gallery_manual_date.php`
- `app/controllers/admin_auth.php`
- `app/controllers/admin_dashboard.php`
- `app/controllers/admin_galleries.php`
- `app/controllers/admin_theme.php`
- `app/controllers/updates.php`
- `app/controllers/public_gallery.php`
- `app/lang/en.json`
- `app/lang/cs.json`
- `.htaccess`
- `galleries/.htaccess`

### User Impact

#### For visitors

- Gallery cards can use a new horizontal presentation.
- Gallery dates appear only when admins set them.
- Descriptions preserve intentional line breaks.
- Public pages keep a cleaner metadata layout.
- Protected and private content remains guarded by the same access model.

#### For administrators

- Theme settings can define the default gallery description layout.
- Individual galleries can override that layout.
- Gallery dates can be managed from normal and side-panel workflows.
- Password reset delivery and SMTP diagnostics are easier to understand.
- Login and reset endpoints are more resistant to repeated attempts.
- The Updates page can show release notes before installing an update.
- Dashboard performance is easier to diagnose.

### Notes

- Existing galleries do not display dates until an admin sets one.
- Existing galleries inherit the Theme gallery-card layout default.
- Login throttling requires the new `auth_rate_limits` migration.
- Gallery dates require the new `gallery_date` migration.
- Per-gallery layout overrides require the new `description_layout` migration.
- The patch notes viewer caches fetched release notes under the application cache.
- The core manifest was refreshed for the updated file set.

## Version 0.64

Version 0.64 is a major public rendering performance and admin workflow refinement release focused on making large galleries faster, reducing unnecessary thumbnail work, improving dynamic refresh stability, and restoring the full create-and-upload gallery workflow from the public hero and gallery editor actions.

This release is especially important for real galleries with many photos, GPS metadata, pagination, subgalleries, and active admin-side editing. A large part of the work is internal optimization, but the result should be visible in normal browsing: faster first render, fewer filesystem checks, fewer repeated thumbnail lookups, lighter gallery map handling, and cleaner admin side-panel behavior.

### Highlights

#### Public gallery rendering was profiled and optimized

A new admin-only public render profiler was added for public gallery and home page rendering.

What changed:

- added a dedicated public render profiling service
- added request timing for public home and gallery pages
- added counters for:
  - database queries
  - filesystem checks
  - thumbnail lookups
  - thumbnail direct hits
  - thumbnail fallback searches
  - thumbnail fallback checks
  - thumbnail fallback hits
  - thumbnail media fallbacks
  - thumbnail bundle requests
  - thumbnail bundle cache hits
  - thumbnail bundle cache misses
  - thumbnail bundle variant hits
  - gallery scan calls
  - gallery map cache hits
  - gallery map cache misses
  - rendered subgalleries
  - rendered images
  - SEO JSON-LD images
- added named timers for expensive render phases such as:
  - gallery image query
  - child gallery lookup
  - image tag lookup
  - image vote lookup
  - gallery grid settings
  - picture game lookup
  - background asset lookup
  - SEO metadata lookup
  - gallery card rendering
  - image card rendering
  - thumbnail bundle discovery
  - filesystem checks
- added thumbnail-purpose tracking so expensive thumbnail work can be tied back to the exact render feature that caused it
- added a compact admin-only diagnostic panel on public pages
- kept the profiling invisible to anonymous visitors

User impact:

- admins can now see what actually makes a public gallery page expensive
- large gallery performance tuning is much easier
- thumbnail and filesystem pressure can be diagnosed directly from the rendered page
- anonymous visitors do not see the diagnostic panel
- normal visitor behavior is not changed by the profiler UI

#### Thumbnail rendering now uses request-local thumbnail bundles

Thumbnail lookup behavior was heavily optimized by resolving available thumbnail variants once per image during a request.

What changed:

- added request-local thumbnail bundle discovery
- added a stable thumbnail bundle cache key per image
- collected generated JPEG and WebP variants in one pass
- reused discovered variants for:
  - visible image cards
  - subgallery covers
  - subgallery collages
  - lightbox preview URLs
  - map marker thumbnails
  - responsive `srcset` output
- added bundle-aware URL selection
- added bundle-aware `srcset` generation
- added safe media fallback handling when no generated thumbnail exists
- preserved existing fallback behavior for missing, partially generated, deleted, regenerated, or DNG-derived thumbnails
- reduced repeated `is_file()` checks for the same image and size combinations
- added request-local caching to legacy `thumbnail_url()` calls
- added profiling counters for direct thumbnail hits, fallback hits, media fallbacks, and cache hits

User impact:

- gallery pages with many visible photos should render with fewer repeated thumbnail checks
- public pages should spend less time repeatedly searching for the same thumbnail variants
- DNG display derivatives and fallback media behavior remain safe
- thumbnail generation and maintenance behavior remains compatible with existing galleries
- admins get better diagnostic visibility into which thumbnail paths are expensive

#### Progressive thumbnail rendering was added

Public gallery cards now use a progressive thumbnail strategy.

What changed:

- added `thumbnail_progressive_picture_html()`
- visible cards initially render with a small thumbnail candidate
- larger responsive `srcset` candidates are attached as deferred progressive data
- the browser can paint the public grid sooner
- JavaScript upgrades thumbnails after initial render when appropriate
- first visible images can be marked eager with high fetch priority
- later images remain lazy and low priority
- gallery covers and collage images also use the progressive thumbnail path
- existing `thumbnail_picture_html()` was updated to support precomputed thumbnail bundles

User impact:

- first paint should feel faster on image-heavy gallery pages
- large image grids should avoid forcing all responsive candidates immediately
- public galleries should feel more responsive during initial load
- browser bandwidth and decode pressure should be better aligned with what is actually visible

#### Responsive thumbnail sizing was rebuilt for lifecycle-safe updates

The responsive thumbnail JavaScript module was updated to work safely with dynamically replaced public gallery content.

What changed:

- added teardown support for responsive thumbnail behavior
- added lifecycle state tracking with `AbortController`
- added deferred idle work for thumbnail upgrades
- measured card widths are used to update `sizes`
- progressive thumbnails are upgraded only when needed
- high-DPI screens are handled with a capped device-pixel-ratio heuristic
- thumbnails are processed in small batches instead of all at once
- responsive thumbnail listeners are cleaned up before public gallery fragments are replaced
- responsive thumbnail behavior is rebound after server-rendered content refreshes

User impact:

- dynamically refreshed gallery content no longer keeps stale thumbnail listeners
- side-panel edits and uploads can refresh the public gallery more safely
- thumbnail sizing remains accurate after gallery content changes
- large galleries avoid a large burst of immediate client-side thumbnail work

#### Public lightbox loading is now deferred

The public lightbox module was split so the heavy viewer logic is loaded only when needed.

What changed:

- added a new `lightbox-deferred.js` module
- the full lightbox implementation is loaded dynamically
- the real lightbox is activated:
  - after page load and idle time
  - immediately when a visitor clicks a photo
  - immediately when a visitor opens a photo map
  - immediately when a gallery map is opened
- the first user click is replayed after the full module is loaded
- deferred activation ignores admin controls and side-panel triggers
- the existing lightbox implementation remains preserved
- gallery.js now imports the deferred lightbox entry point instead of the full module directly

User impact:

- public gallery pages do less JavaScript work during first render
- visitors still get normal lightbox behavior when clicking a photo
- gallery maps and photo maps still work when requested
- admin edit/delete/sidebar actions do not accidentally trigger lightbox initialization
- large photo pages should feel lighter before the first photo is opened

#### Lightbox lifecycle cleanup was added

The full lightbox module now supports explicit teardown and cleaner reinitialization.

What changed:

- added `teardownGalleryLightbox()`
- added internal lightbox state tracking
- added `AbortController` based listener cleanup
- cleaned up pending animation frames and timers
- cleaned up fullscreen and map-related state before DOM replacement
- removed stale map instances during teardown
- removed stale split-map resize observers during teardown
- refreshed lightbox order after public content replacement
- kept public gallery reorder integration compatible with the refreshed DOM

User impact:

- dynamic gallery refreshes are safer
- side-panel saves, uploads, and gallery changes are less likely to leave stale lightbox state behind
- map overlays are less likely to reference removed DOM nodes
- fullscreen navigation order remains aligned with the current rendered gallery state

#### Gallery map handling is now lazy and cacheable

GPS gallery map payloads were optimized so normal gallery rendering no longer has to build full map marker data just to decide whether the map button should appear.

What changed:

- added a cheap `gallery_has_map_points()` availability check
- gallery pages now check whether map points exist without building every marker payload
- full map marker payload generation remains available through the map endpoint
- added a gallery map cache directory under `cache/gallery-maps`
- added deterministic map payload cache fingerprints
- map cache fingerprints include:
  - gallery id
  - public/admin access mode
  - recursive/direct mode
  - point count
  - image update timestamps
  - GPS extraction timestamps
  - gallery update timestamps
- added cache hit and miss profiling counters
- added pruning of older cache files after writing a fresh map payload
- added a global map cache clear helper
- thumbnail maintenance now clears cached gallery map payloads so marker thumbnails do not keep stale fallback URLs
- `image_map_point()` can now skip thumbnail generation when only metadata is needed

User impact:

- gallery pages with GPS-enabled branches should render faster
- the gallery map button can still appear correctly
- full marker data is built only when the map payload is actually needed
- cached map payloads make repeated map openings cheaper
- regenerated thumbnails no longer leave old marker thumbnail URLs behind

#### Hidden lightbox source nodes no longer resolve large previews eagerly

Pagination-aware lightbox source nodes were optimized to avoid resolving large preview thumbnails for non-rendered photos during normal page rendering.

What changed:

- hidden lightbox source nodes now keep preview URLs empty
- hidden source nodes remain available for fullscreen ordering
- visible image cards still provide their normal preview data
- map metadata for hidden nodes can skip thumbnail generation
- JSON-LD image metadata is capped to the visible page slice

User impact:

- paginated galleries no longer spend work resolving 1600px thumbnails for every hidden image
- fullscreen ordering remains compatible with pagination
- large galleries avoid unnecessary preview URL construction during normal page load
- SEO metadata remains present but is kept bounded and practical

#### SEO JSON-LD image output was capped to visible content

Gallery JSON-LD rendering was adjusted to avoid expensive metadata generation for very large galleries.

What changed:

- gallery JSON-LD now receives the currently visible image slice
- JSON-LD image output is capped to the first 20 visible images
- NSFW-restricted images continue to be skipped
- thumbnail resolution for JSON-LD content URLs is now profiled
- hidden lightbox ordering remains separate from crawler metadata

User impact:

- large galleries avoid unnecessary SEO thumbnail lookups across the whole image set
- crawler metadata remains useful without turning public rendering into a full-gallery thumbnail scan
- paginated galleries now keep structured metadata aligned with the visible page

#### Create gallery here now uses create-and-upload mode

The `Create gallery here` workflow was restored and corrected so it opens the combined gallery creation and optional upload workflow.

What changed:

- added `upload_mode=new` support to the upload controller
- added `parent_id` handling for the create-and-upload workflow
- added validated parent-gallery prefill logic
- added contextual notices for the selected parent gallery
- updated non-panel upload rendering so create-and-upload mode shows only the new-gallery form
- updated side-panel rendering so create-and-upload mode opens a focused gallery workflow
- the side panel now explains that photos are optional
- the workflow can still create an empty gallery when no photos are selected
- `Create gallery here` in the gallery editor now links to `admin_upload` with `upload_mode=new`
- the old empty-gallery-only admin-new-gallery side-panel path is no longer used for this action

User impact:

- admins can create a child gallery and upload photos in one workflow
- the action no longer creates only an empty gallery unless the user intentionally uploads nothing
- the parent gallery context is explicit
- gallery creation from the editor is consistent with the public hero action
- fewer clicks are needed when building nested galleries

#### Add gallery here was restored to the public hero action bar

The public gallery hero now includes a compact admin-only child-gallery creation action.

What changed:

- added `render_public_gallery_admin_add_child_link()`
- added an admin-only `Add gallery here` hero icon
- the icon is hidden during anonymous preview mode
- the icon opens the side panel in create-and-upload mode
- the action uses the current gallery as the new gallery parent
- the action includes accessibility labels and title text
- the button uses the compact hero icon button styling

User impact:

- logged-in admins can create a child gallery directly from the public gallery hero
- the public page workflow matches the admin edit workflow
- anonymous preview remains clean and visitor-like
- public gallery management is faster when building nested albums

#### Dynamic public gallery refresh now has proper lifecycle teardown and rebind

The side-panel refresh pipeline was improved so replacing public gallery content also resets dependent browser modules cleanly.

What changed:

- public gallery refresh now tracks whether the public gallery was replaced
- responsive thumbnails are torn down before content replacement
- back-to-top behavior is torn down before content replacement
- lightbox behavior is torn down before content replacement
- public gallery lifecycle modules are rebound after replacement
- public page reordering is rebound after replacement
- a `php-gallery:public-content-replaced` event is dispatched after replacement
- the back-to-top shell can be preserved while replacing the server-rendered gallery frame
- attributes are copied from the fresh server-rendered frame to the persistent frame
- stable controls such as the back-to-top button are preserved instead of being discarded unnecessarily
- subgallery refresh now uses the same replacement path as the full public gallery refresh

User impact:

- side-panel upload and edit workflows refresh the visible gallery more reliably
- back-to-top behavior survives dynamic gallery updates
- lightbox behavior does not keep stale references after updates
- responsive thumbnails continue working after panel saves
- public reorder mode remains compatible with refreshed server-rendered content

#### Back-to-top behavior was made refresh-safe

The back-to-top module was rewritten to avoid holding stale DOM references.

What changed:

- added module-level lifecycle state
- added `teardownBackToTopButton()`
- DOM elements are looked up on demand
- click handling is delegated safely
- scroll and resize listeners use `AbortController`
- animation-frame updates are cancelled during teardown
- visibility checks now confirm that current elements are connected
- fullscreen and lightbox states continue to suppress the button

User impact:

- back-to-top no longer breaks after gallery content is dynamically replaced
- the button remains correctly hidden during fullscreen/lightbox states
- repeated refreshes do not stack duplicate event listeners
- long gallery pages keep the expected scroll helper behavior

#### Browser rendering of large public grids was improved

Public gallery cards and image cards now allow the browser to skip work for offscreen content when supported.

What changed:

- added `content-visibility: auto` for public gallery cards and image cards
- added intrinsic size hints for skipped cards
- cards become fully visible when focused or dragged
- support is applied only in browsers that support `content-visibility`

User impact:

- large public grids can be cheaper for the browser to lay out and paint
- keyboard focus and drag interactions remain safe
- unsupported browsers simply keep the previous behavior

### Public UI and Admin Workflow Refinements

#### Hero and editor gallery creation

- The public hero now exposes the missing `Add gallery here` action for logged-in admins.
- The admin gallery editor now routes `Create gallery here` to the combined create-and-upload workflow.
- Both paths use the same parent-gallery context.
- Both paths support optional photo upload during gallery creation.
- Empty gallery creation remains possible by submitting without selecting photos.

#### Upload panel copy and context

- Existing-gallery upload mode now clearly says it adds photos to an existing gallery.
- Create-and-upload mode now clearly says it creates a child gallery and optionally uploads photos.
- Errors in create-and-upload mode now use `Create or upload failed` wording.
- Parent context is shown when available.

#### Admin-side public refresh behavior

- Public gallery refresh now prefers server-rendered HTML as the source of truth.
- The dynamic refresh path avoids stale module state.
- The refresh system now handles gallery frames, subgallery sections, and image lists more consistently.

### Performance Details

This release reduces several expensive public render behaviors.

#### Reduced repeated thumbnail work

Before this release, a visible photo could trigger multiple independent thumbnail checks for the same generated files. The new thumbnail bundle path discovers available generated variants once and reuses them for multiple outputs during the same request.

This affects:

- image card thumbnail HTML
- lightbox preview URLs
- map marker thumbnails
- progressive picture HTML
- WebP source sets
- JPEG source sets
- subgallery covers
- subgallery collages

#### Reduced full-gallery work during paginated rendering

Paginated galleries now avoid some work that was previously performed across the complete image set during normal rendering.

Reduced work includes:

- large preview URL generation for hidden lightbox source nodes
- full map marker payload construction just to show the map button
- JSON-LD thumbnail resolution across the whole gallery

#### Better first-render behavior in the browser

The browser now performs less immediate work on large gallery pages because:

- full lightbox setup is deferred
- responsive thumbnail upgrades are batched
- progressive thumbnails start smaller
- offscreen cards can use `content-visibility`
- lifecycle modules are rebound only after server-rendered refreshes

### Technical Notes

Files heavily updated in this release include:

- `app/controllers/admin_galleries.php`
- `app/controllers/admin_uploads.php`
- `app/controllers/public_gallery.php`
- `app/helpers.php`
- `app/services.php`
- `app/services/exif.php`
- `app/services/gallery_backgrounds.php`
- `app/services/gallery_lookup.php`
- `app/services/public_render_profiler.php`
- `app/services/thumbnails.php`
- `public/assets/gallery-modules/admin-operations.js`
- `public/assets/gallery-modules/back-to-top.js`
- `public/assets/gallery-modules/lightbox-deferred.js`
- `public/assets/gallery-modules/lightbox.js`
- `public/assets/gallery-modules/responsive-thumbnails.js`
- `public/assets/gallery.js`
- `public/assets/styles/public.css`
- `app/core-manifest.json`

### Internal Changes

#### New backend service

- Added `app/services/public_render_profiler.php`
- Loaded the profiler from `app/services.php`
- The profiler is admin-only and disabled for CLI requests
- The profiler exposes helpers for:
  - request start
  - gallery id assignment
  - counters
  - timers
  - database timing
  - filesystem timing
  - thumbnail-purpose tracking
  - final diagnostic panel rendering

#### Thumbnail service changes

- Added request-local thumbnail URL caching
- Added thumbnail bundle discovery
- Added bundle variant selection
- Added bundle `srcset` generation
- Added progressive picture HTML generation
- Added profiling instrumentation around thumbnail lookups
- Added profiling instrumentation around filesystem checks
- Added map cache invalidation when thumbnail maintenance changes

#### EXIF and map service changes

- Added optional thumbnail generation to `image_map_point()`
- Added map query helper reuse
- Added map availability checks
- Added map payload cache files
- Added map cache fingerprinting
- Added map cache pruning
- Added map cache clearing

#### Front-end module changes

- Added deferred lightbox bootstrap module
- Added teardown support for lightbox, responsive thumbnails, and back-to-top modules
- Added dynamic import for the full lightbox
- Added public content replacement lifecycle handling
- Added cache-busted imports for refreshed modules

#### Public rendering changes

- Gallery and home rendering now start a public render profile for admins.
- Home gallery queries are profiled.
- Gallery image queries are profiled.
- Child gallery lookup is profiled.
- Tag, vote, picture game, grid settings, background, and SEO lookups are profiled.
- Subgallery and image rendering are profiled.
- Visible image cards use thumbnail bundles and progressive picture HTML.
- Hidden lightbox source nodes avoid large preview resolution.
- Gallery map button availability no longer requires full marker payload generation.
- JSON-LD image output is capped to visible content.

### User Impact

#### For visitors

- Large galleries should load faster.
- Initial gallery rendering should feel lighter.
- Photo grids should become interactive sooner.
- Lightbox behavior remains the same when a photo is opened.
- Gallery maps remain available when GPS points exist.
- Offscreen cards may cost less browser rendering work.
- Paginated galleries avoid unnecessary work for non-visible photos.

#### For administrators

- `Add gallery here` is available again in the public hero action bar.
- `Create gallery here` in the gallery editor now opens the create-and-upload workflow.
- New child galleries can be created with photos in one side-panel workflow.
- Empty child galleries can still be created when needed.
- Public gallery refreshes after side-panel actions are more stable.
- Admins get a new public render profile panel for diagnosing slow galleries.
- Thumbnail, filesystem, database, map, and render costs are now visible per request.

### Notes

- The public render profiler is intentionally admin-only.
- Anonymous visitors do not see profiling output.
- The thumbnail bundle cache is request-local only and does not persist thumbnail metadata.
- Gallery map payload cache is persisted under `cache/gallery-maps`.
- Thumbnail maintenance clears gallery map cache files to avoid stale marker thumbnails.
- The full lightbox implementation is preserved, but it is now loaded through the deferred entry point.
- The create-and-upload workflow still allows empty gallery creation when no files are selected.
- The core manifest was refreshed for the updated file set.

## Version 0.63

Version 0.63 is a major public-page admin workflow refinement release focused on replacing bulky inline editing with compact contextual actions, improving side-panel workflows, stabilizing live refresh behavior, and modernizing gallery interaction ergonomics.

This release transforms the public gallery page into a cleaner, more content-focused experience while preserving the existing full admin editing system as a fallback.

### Highlights

#### Public inline editors were replaced with compact contextual admin actions

The old large inline Edit gallery and Edit photo forms were removed from public gallery pages and replaced with compact contextual icon actions.

What changed:

- removed bulky inline public-page edit panels from:
  - gallery cards
  - photo cards
  - gallery hero sections
- added compact pencil edit actions for:
  - galleries
  - photos
  - current gallery hero
- added compact delete/remove actions for:
  - galleries
  - photos
- added confirmation dialogs before CMS removal actions execute
- preserved full-page admin edit routes as fallback entry points
- reused the existing side-panel admin workflow instead of introducing a second editing system
- added accessibility labels and titles for all compact admin actions
- unified the new controls with the translucent glass-style badge design already used by gallery collection indicators

User impact:

- public gallery pages are significantly cleaner
- admin controls no longer dominate gallery content
- editing now feels integrated into the gallery itself instead of layered on top of it
- gallery management is faster and visually lighter

### Side-panel workflows were redesigned into focused actions

The side-panel administration flow was reorganized into clearer, more task-focused workflows.

What changed:

- separated:
  - Upload photos here
  - Create gallery here
  into distinct workflows
- removed the confusing mixed upload/create panel behavior
- upload panels now focus only on uploading into existing galleries
- gallery creation panels now focus only on creating empty galleries
- added a dedicated compact Create gallery icon into the hero action bar
- improved side-panel gallery creation UI with:
  - dedicated identity section
  - cleaner field grouping
  - focused parent selection
  - improved toggles and spacing
- upload side-panels now display:
  - explicit target gallery
  - cleaner upload drop area
  - simplified upload messaging

User impact:

- workflows are easier to understand
- upload and gallery creation are no longer mixed together
- admins make fewer mistakes during nested gallery management
- side-panel interactions feel more intentional and modern

### Public-page editing now fully uses side-panel workflows

Public-page editing was fully integrated into the existing side-panel architecture.

What changed:

- gallery edit icons now open:
  - the existing gallery admin side panel
- photo edit icons now open:
  - the existing photo admin side panel
- panel actions now stay isolated from fullscreen/lightbox behavior
- edit actions now stop event propagation before lightbox handlers execute
- lightbox click handling explicitly ignores:
  - admin edit actions
  - admin delete actions
  - side-panel triggers

User impact:

- clicking photos still opens fullscreen normally
- clicking edit icons now always opens the correct admin panel
- no accidental fullscreen openings during editing
- editing feels faster and more stable

### Dynamic gallery refresh behavior was stabilized

The public refresh pipeline was redesigned to avoid duplicate gallery and photo rendering after edits or uploads.

What changed:

- replaced fragmented refresh logic with a single gallery-frame refresh model
- removed conflicting partial DOM replacement paths
- removed additive update flows that caused duplicate cards after:
  - uploads
  - edits
  - panel saves
- public refreshes now replace the gallery content as a single source of truth
- gallery and photo save handlers now:
  - refresh once
  - avoid local duplicate mutation passes
- hero refresh handling was stabilized during partial updates

User impact:

- edited photos no longer appear twice
- uploaded photos no longer duplicate visually
- gallery updates feel cleaner and more reliable
- panel-based workflows now behave consistently without full reloads

### Hero action bar was modernized into compact icon controls

The gallery hero action bar was redesigned into a cleaner compact icon-based system.

What changed:

- converted:
  - Download gallery
  - Play picture game
  into compact icon buttons
- added compact hero icons for:
  - edit gallery
  - remove gallery
  - create child gallery
- added icon-based visual treatment using:
  - translucent glass surfaces
  - blur effects
  - compact hover states
- unified hero actions with:
  - collection counters
  - subgallery indicators
  - overlay badge styling
- improved icon spacing and responsive sizing
- fixed overlapping hero action positioning
- fixed overlap between:
  - reorder handles
  - compact admin controls
  on smaller gallery cards

User impact:

- the hero area feels dramatically cleaner
- actions remain accessible without dominating the layout
- visual consistency across overlays and controls is improved
- small gallery cards remain usable even with reorder mode active

### Public reorder and compact action overlays were improved

The interaction between drag handles and compact action overlays was refined.

What changed:

- cards with reorder handles now receive dedicated layout handling
- compact edit/delete overlays automatically reposition below reorder handles
- gallery and photo action overlays now avoid collision with:
  - subgallery counters
  - drag handles
  - compact overlay badges
- overlay opacity and hover transitions were refined

User impact:

- reorder mode remains readable on compact cards
- overlay controls no longer visually collide
- gallery card interactions feel more polished

### Technical Notes

Files heavily updated in this release include:

- `app/controllers/public_gallery.php`
- `app/controllers/admin_galleries.php`
- `app/controllers/admin_uploads.php`
- `public/assets/gallery-modules/admin-operations.js`
- `public/assets/gallery-modules/lightbox.js`
- `public/assets/styles/public.css`
- `public/assets/styles/side-panel.css`
- `public/assets/styles/utilities.css`

### Notes

- This release intentionally removes the old bulky public inline editing model in favor of compact contextual admin controls.
- Existing full admin edit routes remain fully functional as fallback workflows.
- Public-page administration now primarily uses the side-panel editing system.
- The refresh pipeline was intentionally simplified to avoid duplicate rendering paths and stale fragment conflicts.

## Version 0.62.2

This is a focused admin workflow bugfix release for side-panel gallery creation, upload refreshes, and photo move behavior.

- Fixed side-panel refresh context after creating a new gallery, so the UI now refreshes the correct parent gallery instead of guessing from the newly created gallery URL.
- Fixed create-and-upload refresh behavior for new galleries, including root-level and nested gallery creation.
- Improved the Move to new gallery workflow so admins can explicitly choose the parent gallery for the newly created destination.
- Updated move-to-new-gallery inheritance so the new gallery now inherits visibility, voting, and file-name display settings from the selected parent gallery.
- Fixed AJAX handling for moving photos from the side panel, including move-to-existing-gallery and move-to-new-gallery actions.
- Kept the side panel open after photo move operations, matching the existing dynamic behavior for cover changes and deletions.
- Improved JSON responses for bulk photo moves with source gallery, destination gallery, parent gallery, and refresh URL metadata.
- Fixed public inline gallery visibility controls so public galleries show Unpublish and non-public galleries show Publish.
- Split the large public stylesheet into smaller imported CSS files for base, public, lightbox, admin, side-panel, and utility styling.
- Refreshed the core manifest for the updated file set and stylesheet structure.

## Version 0.62.1

This is just a small bugfix:

- admin panel (right) now correctly reloads panel & gallery view during addin and removing pictures from gallery

## Version 0.62

- Fixed the version number

## Version 0.61B

- Fixed gallery inline editor overflow so the public gallery edit form stays inside the card layout instead of
  spilling under the content on the right.

## Version 0.61

Version 0.61 is a major public UI and UX refinement release focused on modernizing the gallery presentation layer, reducing visual clutter, improving typography consistency, and making public gallery management dramatically more compact and efficient for both visitors and logged-in admins.

This release introduces a redesigned hero layout, modernized gallery cards, compact inline editors, glass-style collection badges for subgalleries, and a broad typography unification pass across the entire public interface.

### Highlights

#### Public gallery hero was completely redesigned

The public gallery hero panel was rebuilt to reduce vertical space usage while preserving visual hierarchy and gallery identity.

What changed:

- redesigned the hero into a compact horizontal layout
- moved gallery actions into a compact top-right action cluster
- reduced oversized stacked button layouts
- reduced vertical whitespace and dead padding
- improved spacing between title, actions, tags, and breadcrumbs
- preserved large visual gallery titles while making the overall panel much smaller
- reorganized hero metadata into:
  - title area
  - actions area
  - tag area
  - breadcrumbs
- improved mobile responsiveness for smaller screens
- added more compact action button sizing
- refined hero spacing and glass-panel rendering
- improved hero typography consistency and heading rhythm

User impact:

- gallery pages feel significantly more modern
- the hero no longer dominates the page vertically
- visitors reach gallery content faster
- actions remain accessible without overwhelming the layout

#### Modernized subgallery card layout

The subgallery section was redesigned into a more modern, compact media-card presentation inspired by contemporary gallery and social-media layouts.

What changed:

- removed the visible `Subgalleries` section heading from public rendering
- redesigned gallery cards into horizontal split layouts
- gallery thumbnails now act as dedicated media surfaces
- gallery metadata was reorganized into cleaner visual blocks
- reduced vertical spacing inside gallery cards
- improved spacing and density for gallery descriptions and tags
- compacted gallery panels and pagination spacing
- refined responsive behavior for mobile gallery cards
- gallery cards now visually align more closely with the admin dashboard styling direction

User impact:

- more galleries fit on screen at once
- gallery browsing feels faster and cleaner
- visual scanning of subgalleries is easier
- the public gallery UI now feels significantly more premium and modern

#### Added glass-style stacked-image collection badges

Subgallery thumbnails now include a modern stacked-image indicator inspired by social-media multi-image overlays.

What changed:

- added a stacked-image collection icon overlay in the top-right corner of subgallery thumbnails
- added live image counts directly into the overlay badge
- implemented glass-style translucent rendering with backdrop blur
- added semi-transparent layered rendering for the stack icon
- integrated the same collection-badge system onto the homepage gallery listing
- removed redundant visible image-count text from gallery cards while preserving it invisibly for SEO and accessibility
- ensured badges honor theme corner-radius settings
- refined badge spacing and compact sizing for mobile layouts

User impact:

- visitors immediately understand which cards contain nested galleries
- the UI feels more visual and less text-heavy
- gallery cards gained clearer visual hierarchy
- repeated "X images" labels no longer clutter the layout

#### Inline public admin editors were redesigned

The logged-in inline editing experience on public pages was modernized and heavily compacted.

What changed:

- redesigned `inline-editor` layouts into compact dashboard-style control cards
- reduced vertical spacing and oversized form layouts
- reorganized edit forms into:
  - content fields
  - option toggles
  - compact action bars
- improved action button hierarchy
- added dedicated destructive-action styling
- refined responsive stacking behavior
- improved edit summaries and contextual helper labels
- aligned inline-editor styling with the newer admin dashboard design language
- improved compact toggle rendering
- improved inline form spacing and typography consistency

User impact:

- public-page editing is significantly faster
- admins can edit content inline without large visual interruptions
- editing tools now feel integrated into the page instead of bolted on

#### Typography was unified across the entire public interface

The public UI received a broad typography consistency pass focused on readability, spacing rhythm, and modern UI alignment.

What changed:

- standardized body typography variables
- standardized heading line-height behavior
- standardized tracking and letter spacing
- unified heading rendering across:
  - hero titles
  - gallery titles
  - buttons
  - forms
  - inline editors
- switched default sans-serif rendering to:
  - Inter
  - system UI fallback stack
- improved text rendering quality using:
  - optimized legibility
  - antialiasing
  - grayscale smoothing
- refined hero heading proportions
- refined brand-title typography
- reduced inconsistent font-height behavior across sections

User impact:

- the interface feels visually coherent
- typography now matches modern application UI expectations
- headings feel cleaner and more balanced
- readability is improved across desktop and mobile layouts

### Public UI Refinements

Additional visual and layout refinements include:

- theme radius settings are now consistently honored by:
  - hero buttons
  - tag pills
  - gallery badges
  - inline editors
  - reorder controls
- tags were redesigned into smaller compact pills
- tag placement was moved into cleaner right-side metadata areas
- public hero buttons now inherit theme colors and styling correctly
- reorder handles were visually corrected and repositioned
- gallery-card positioning behavior was hardened for overlays and floating controls
- hero panel spacing was optimized further after initial redesign feedback

### Technical Notes

Files heavily updated in this release include:

- `app/controllers/public_gallery.php`
- `app/controllers/theme_assets.php`
- `public/assets/styles.css`
- `public/assets/custom.css`
- `custom_css/modern.css`

### Notes

- This release focuses primarily on UX/UI modernization and public-page editing ergonomics.
- The visual redesign intentionally reduces vertical page expansion without removing functionality.
- Existing theme settings and custom CSS compatibility were preserved.

## Version 0.60

This release focuses on major admin workflow improvements, direct public-gallery management, gallery editing UX modernization, and front-end maintainability improvements.

### Highlights

#### Public gallery pages now support direct admin reordering

Logged-in admins can now manage gallery ordering directly from the public gallery view without switching back to the dedicated admin dashboard.

What changed:

- added direct drag-and-drop reordering for subgalleries on public gallery pages
- added direct drag-and-drop reordering for pictures on public gallery pages
- gallery and picture ordering now reuse the existing backend ordering infrastructure
- public gallery reordering respects the existing layout structure:
  - subgalleries remain grouped first
  - pictures remain grouped underneath
- pagination-aware move handling was added so reordering only affects the currently visible page
- public gallery reorder behavior was integrated into the modular front-end gallery operations system

User impact:

- admins can reorganize galleries much faster during normal browsing
- gallery maintenance now feels more natural and less disconnected from the public presentation
- moving galleries and pictures requires fewer page transitions and less context switching

#### Admin gallery editing was redesigned

The gallery editing experience received a broad UI and workflow overhaul focused on clearer structure, faster media management, and modernized interaction patterns.

What changed:

- added a new side-panel editing workflow for gallery administration
- gallery editing panels now preserve active tabs and editing context more reliably
- upload workflows now dynamically refresh edited content without unnecessary full-page reloads
- image management panels now keep their previous state after operations complete
- improved upload progress visibility and automatic viewport positioning during uploads
- upload and media-management interactions were visually reorganized into cleaner grouped layouts
- added direct upload entry points inside gallery editing flows
- improved admin image selection handling and bulk-selection feedback
- added dedicated bulk image move workflows with integrated destination handling
- gallery edit actions now provide clearer visual hierarchy and spacing
- improved gallery move and image move controls with more readable visual states
- refined selected and unselected button states for better accessibility and contrast

User impact:

- media management workflows are faster and easier to understand
- admins spend less time navigating between separate administration screens
- large upload sessions are easier to monitor visually
- gallery editing feels more responsive and modern

#### Admin dashboard and gallery tree interactions were improved

The admin dashboard gained additional structural and interaction refinements for large gallery trees.

What changed:

- gallery tree movement behavior was refined for better nesting clarity
- gallery movement feedback now updates more consistently during drag operations
- public gallery links refresh more reliably after gallery moves
- dashboard interaction states were visually softened and cleaned up
- admin-side panels now use more structured layouts and consistent spacing
- gallery ordering interactions received additional styling and usability refinements

User impact:

- large gallery hierarchies are easier to reorganize
- drag-and-drop workflows feel more predictable
- visual clutter during gallery management was reduced

#### Front-end assets and CSS structure were reorganized

The public and admin front-end assets were cleaned up and reorganized to improve maintainability.

What changed:

- reorganized the main stylesheet into clearly indexed sections
- grouped related UI styles into dedicated structural areas
- improved separation between:
  - public layout styling
  - lightbox styling
  - admin dashboard styling
  - telemetry and maintenance styling
  - gallery ordering styling
  - theme preview styling
- removed older fragmented styling comments and redundant layout markers
- expanded modular JavaScript bootstrapping for new admin and public-gallery features

User impact:

- future UI development is easier to maintain
- styling consistency across admin and public pages is improved
- front-end behavior initialization is more modular and easier to extend

### Technical Notes

Files changed include:

- `public/assets/gallery.js`
- `public/assets/styles.css`
- `public/assets/gallery-modules/admin-bulk-actions.js`
- `public/assets/gallery-modules/admin-operations.js`
- gallery editing templates and related admin UI components

## Version 0.59

This release focuses on telemetry reliability, upload workflow improvements, DNG image support groundwork, and breadcrumb correctness for nested unpublished galleries.

### Highlights

#### Telemetry is now more accurate and exportable

The anonymous telemetry subsystem now tracks public activity more reliably and can generate standalone HTML reports for diagnostics and sharing.

What changed:

- added a downloadable HTML telemetry export report from the admin telemetry page
- telemetry reports now include:
  - anonymous sessions
  - page views
  - photo opens
  - average viewing time
  - browser mix
  - cache-event statistics
  - top viewed photos
  - longest viewed photos
- image transfer statistics now use human-readable byte formatting
- telemetry collection was attached more consistently to public gallery rendering
- added clearer admin guidance explaining why logged-in admins may not see their own telemetry events
- public telemetry collection now uses a more neutral endpoint naming strategy to reduce blocking from privacy filters
- telemetry schema support was expanded for the new `thumb_1280` thumbnail variant

User impact:

- telemetry statistics are more trustworthy during real browsing sessions
- admins can export clean standalone telemetry reports for diagnostics or support
- responsive thumbnail traffic is now represented more accurately in telemetry metrics

#### Breadcrumbs now preserve unpublished gallery hierarchy

Public gallery breadcrumbs now reflect the real gallery structure even when unpublished galleries exist in the parent chain.

What changed:

- breadcrumb generation now walks the true gallery ancestry instead of filtering unpublished ancestors
- unpublished parent galleries remain visible inside the breadcrumb trail for public child galleries
- breadcrumb logic was separated from public gallery-list visibility filtering

User impact:

- nested galleries no longer appear disconnected from their real hierarchy
- direct-linked public galleries inside unpublished branches now show their full structural path
- breadcrumb navigation is more predictable and easier to understand

#### Upload workflow and admin editing were improved

The admin upload flow now behaves more consistently after uploads and reports failed processing more clearly.

What changed:

- upload redirects now preserve the currently active `Media` tab after upload completion
- upload redirects now jump directly to the image-management section
- upload responses now report:
  - failed thumbnail generations
  - failed image scans
  - filenames that could not be processed
- upload-related admin logging was expanded with failed-scan diagnostics

User impact:

- admins stay in the correct editing context after uploads
- failed uploads and processing issues are easier to diagnose
- large upload sessions provide clearer operational feedback

#### DNG support groundwork was added

The gallery now includes safer handling and recognition for Adobe Digital Negative files.

What changed:

- added explicit DNG extension detection helpers
- DNG support now uses dedicated conversion capability checks
- direct public access to raw `.dng` files is blocked through gallery `.htaccess` rules

User impact:

- DNG handling is more explicit and safer
- raw source negatives are less likely to be exposed directly
- future RAW/DNG processing support is easier to extend safely

### Technical Notes

Files changed include:

- `app/controllers/admin_telemetry.php`
- `app/controllers/admin_uploads.php`
- `app/controllers/public_gallery.php`
- `app/services/gallery_lookup.php`
- `app/helpers.php`
- `database/migrations/202605080001_telemetry_thumbnail_variants.php`
- `public/assets/gallery-modules/admin-bulk-actions.js`
- `galleries/.htaccess`

### Notes

- This release continues the telemetry and responsive-thumbnail work introduced in previous versions.
- DNG support currently focuses on safer detection and infrastructure preparation rather than full RAW rendering support.

## Version 0.58

This release brings a broad admin and public-gallery update set:

- gallery access rules were simplified and made more explicit
- anonymous preview was added in the public gallery view
- per-gallery branding assets were added
- the admin dashboard and gallery tree UI were redesigned
- thumbnail handling gained quality and bounds controls
- gallery path handling was hardened for nested moves and clean public URLs
- update/maintenance helpers now cache more expensive checks
- new tests were added for visibility and branding behavior

### Highlights

#### Admin gallery management is much more capable

The admin area now supports more direct tree editing, clearer gallery status display, and a reorganized dashboard layout.

What changed:

- galleries can be nested and re-parented directly from the dashboard tree
- drag-and-drop ordering now supports moving galleries left and right to change nesting
- the gallery tree shows live nesting depth feedback while dragging
- the dragged row and ghost preview are visually softened so the move state is easier to read
- the dashboard tabs and navigation hashes were updated for the new admin layout
- the gallery detail editor was reorganized into clearer sections
- admin actions now use more consistent status messaging and metadata updates

User impact:

- nesting and un-nesting galleries is faster
- the current tree structure is easier to understand while dragging
- the public gallery link in the admin tree updates immediately after a move
- the admin interface feels more structured and less crowded

#### Public gallery access is more explicit

The public gallery flow now understands a clearer gallery visibility model and supports anonymous preview in the admin view.

What changed:

- gallery visibility now uses `public`, `unpublished`, and `private` more consistently
- legacy `draft` and `unlisted` behaviors are normalized during migration
- anonymous preview mode was added for public gallery pages
- public media and gallery access checks were updated to match the new visibility model
- password and NSFW gating continue to work through the public flow

User impact:

- unpublished galleries can be direct-linked but stay out of public listings
- private galleries remain hidden from normal public browsing
- admins can preview the public gallery flow in a more visitor-like mode

#### Per-gallery branding is now supported

The release adds branding assets that can be attached to galleries and to the theme fallback area.

What changed:

- gallery banner uploads were added
- gallery logo uploads were added
- gallery separator uploads were added
- theme-level fallback branding assets were added
- new public asset routes serve the branding files
- admin forms were updated to upload, replace, and remove branding assets
- public gallery rendering now integrates the branding model

User impact:

- galleries can carry their own visual identity
- shared theme branding can provide fallback visuals when a gallery has no custom branding
- branding assets are validated and stored through dedicated upload handling

#### Thumbnail generation now has bounded quality controls

The admin editing flow includes new thumbnail-size and quality-bound support.

What changed:

- thumbnail bounds logic was added as a dedicated service
- migration support was added for thumbnail quality bounds
- the admin editing UI now exposes thumbnail-bound controls
- thumbnail handling is now more explicit about allowed sizing behavior

User impact:

- thumbnail generation is easier to tune
- admin users can better control the visual quality and size boundaries of generated thumbnails

#### Gallery path handling is more robust

The release improves how gallery paths are built and updated.

What changed:

- gallery URL regeneration now works with cleaner public-path logic
- nested galleries preserve a canonical public path
- client-side admin tree updates now refresh gallery links immediately after a move
- URL-safe segments are preserved when gallery names include spaces or accented characters
- filesystem move logic and public URL logic were both hardened

User impact:

- moved galleries keep their public links aligned without a refresh
- nested gallery URLs stay clean and stable
- admin users get immediate feedback after tree changes

#### Maintenance and dashboard performance were improved

Several expensive admin tasks were optimized or cached.

What changed:

- dashboard gallery rows now use more explicit SQL and pre-aggregated counts
- thumbnail maintenance summaries are cached
- update-check navigation data is cached
- app settings now support cache-aware deletion
- public gallery lookup and sidecar refresh flows were streamlined

User impact:

- the admin dashboard should load faster
- thumbnail maintenance and update checks should feel less expensive
- gallery refresh operations are more predictable

### Detailed Change Areas

#### Admin dashboard and gallery tree

Files:

- `app/controllers/admin_dashboard.php`
- `app/controllers/admin_galleries.php`
- `public/assets/gallery-modules/admin-operations.js`
- `public/assets/styles.css`

Notable updates:

- redesigned admin dashboard sections and tabs
- gallery tree drag-and-drop with nesting support
- immediate public-link refresh after tree changes
- live placeholder hints for move direction and nesting depth
- lighter drag preview styling
- improved visibility and status labels
- better mobile and compact-layout support in the admin CSS

#### Public gallery and access logic

Files:

- `app/controllers/public_gallery.php`
- `app/controllers/public_media.php`
- `app/services/gallery_access.php`
- `app/services/public_paths.php`
- `app/helpers.php`

Notable updates:

- new anonymous preview behavior
- revised gallery visibility model
- public gallery rendering now understands updated visibility and branding logic
- media and branding routes were added for public gallery assets
- public path regeneration and lookup rules were improved

#### Branding

Files:

- `app/services/gallery_branding.php`
- `app/services/uploads.php`
- `app/controllers/admin_theme.php`
- `app/controllers/theme_assets.php`
- `app/controllers/public_media.php`
- `public/assets/gallery-modules/theme-form.js`
- `public/assets/styles.css`

Notable updates:

- gallery-level branding assets: banner, logo, separator
- theme fallback branding assets
- upload validation and MIME checks
- public serving routes for branding assets
- admin-side preview and editing controls

#### Thumbnail quality and size controls

Files:

- `app/services/thumbnail_bounds.php`
- `database/migrations/202605070003_thumbnail_quality_bounds.php`
- `app/controllers/admin_galleries.php`
- `public/assets/gallery-modules/theme-form.js`
- `public/assets/styles.css`

Notable updates:

- new backend logic for thumbnail bounds
- migration support for stored bounds settings
- admin UI controls for editing thumbnail limits

#### Sidecars, paths, and storage handling

Files:

- `app/services/gallery_paths.php`
- `app/services/gallery_sidecars.php`
- `app/services/gallery_mutations.php`
- `app/services/gallery_lookup.php`
- `app/services/thumbnails.php`
- `app/services/updates.php`

Notable updates:

- more resilient path handling for future destinations and nested moves
- sidecar regeneration now carries more metadata
- thumbnail and update maintenance use cached summaries
- gallery mutations better preserve filesystem and database consistency

### Migrations

Files:

- `database/migrations/202604270001_initial_schema.php`
- `database/migrations/202605070001_gallery_visibility_model.php`
- `database/migrations/202605070002_gallery_branding_assets.php`
- `database/migrations/202605070003_thumbnail_quality_bounds.php`

Migration summary:

- gallery visibility defaults were aligned with the new `unpublished` model
- legacy visibility states are converted during upgrade
- gallery branding columns are added to the schema
- thumbnail quality-bound storage is added

### Tests Added

Files:

- `tests/gallery_visibility_model_test.php`
- `tests/gallery_branding_model_test.php`

Coverage added:

- gallery visibility model behavior
- public/unpublished/private behavior
- branding asset validation rules
- MIME/extension handling for gallery branding uploads

### UI and Behavior Changes Users Will Notice

- admin dashboard layout and tabs are reorganized
- gallery tree reordering is more interactive and more readable
- gallery links update immediately after nesting changes
- drag previews are lighter and easier to distinguish
- drag hints now show how many levels a gallery will move
- public gallery preview supports an anonymous-view mode in the admin flow
- galleries can now have custom branding assets
- thumbnails can be governed by new size and quality bounds

### Important Release Notes

- This release changes gallery visibility semantics and requires migration testing.
- Anonymous preview should be verified against real public/private/password/NSFW cases before release.
- Per-gallery branding placement should be checked in a browser on both desktop and mobile widths.
- The admin tree drag-and-drop flow should be smoke-tested after the new link-refresh behavior.
- The new thumbnail bounds feature should be verified against a few representative galleries.

### Suggested Short Release Blurb

If you want a concise announcement version:

> This release adds anonymous preview, gallery branding, and a redesigned admin gallery tree with live nesting feedback. It also improves thumbnail bounds control, public-path handling, and dashboard performance.

### Files Changed

The branch touches 33 files and introduces several new backend services, migrations, admin UI updates, and public-gallery rendering changes.

## Version 0.57

This release is the largest public-facing and administrative step forward since 0.56. It adds richer public gallery presentation, NSFW access control, admin recovery email support, and a complete password reset workflow, while also tightening the generated theme asset pipeline and keeping release metadata in sync.

### Highlights

#### Public gallery presentation

Public gallery pages now show more of the gallery story directly on the card and image surfaces instead of hiding all context behind the detail page.

The latest branch work adds:

- Public photo metadata overlays on gallery cards
- Better display of image titles, descriptions, and tags in the card layout
- Cleaner handling of public-facing metadata for crawlers and social previews
- Gallery and image JSON-LD output that avoids exposing restricted 18+ content

This makes galleries feel more alive at first glance, while also improving how search engines and social platforms understand the content.

#### NSFW Guard

A new NSFW Guard system was added for both galleries and individual photos.

It allows admins to:

- Mark an entire gallery as 18+ content
- Mark individual photos as 18+ content
- Inherit the restriction down through child galleries

For visitors, the public site now:

- Shows a dedicated age confirmation gate when restricted content is encountered
- Remembers the confirmation for the current browser session
- Hides restricted previews, thumbnails, and embedded metadata until access is granted

This is not just a visual warning. The access rules now affect gallery rendering, image access, thumbnails, lightbox sources, and structured metadata.

#### Admin recovery email

Administrator accounts now support a recovery email address.

That email is used for:

- Username-or-email login
- Recovery workflows
- Password reset delivery
- Optional test-email checks from the account settings page

Existing admins are also prompted to add a recovery email in account settings so the new login and recovery flows are fully usable.

#### Password reset workflow

The branch now includes a full password reset flow for admin accounts.

Admins can:

- Request a reset link from the login screen
- Receive the reset email through the configured transport
- Open a one-time reset link
- Set a new password without needing the old one

The reset system also includes:

- A dedicated token table for one-time reset links
- Token expiry handling
- Automatic invalidation of older unused reset tokens for the same user
- Mail diagnostics in the admin log without exposing the full token value

#### Email delivery settings

Password reset delivery is now configurable from the admin account area.

The new settings cover:

- Enable or disable password reset delivery
- Sender email address
- Sender display name
- Token lifetime
- Mail transport selection
- SMTP host, port, encryption, username, and password

This keeps the password reset flow flexible for both shared hosting and self-managed environments.

#### Theme asset and preview sync

Generated theme assets are now tied more tightly to the implementation that produces them.

That means:

- Theme cache keys now include the theme asset controller revision
- The public site is less likely to serve stale generated CSS after a theme-rendering change
- UI radii and generated theme assets stay in sync more reliably

This is the kind of low-level change that matters most when users customize theme styling heavily.

#### Social and SEO preview support

Gallery metadata output was upgraded for modern sharing behavior.

The new behavior includes:

- Stronger Open Graph metadata
- Better Twitter card metadata
- Safer image selection for social previews
- Cache-busted preview URLs so regenerated thumbnails refresh more predictably on crawlers

#### Runtime and schema resilience

The branch also hardens several runtime paths so newer code can coexist more safely with older database state during upgrades.

Notable changes include:

- Safer admin session loading when the `users.email` column has not been migrated yet
- Schema checks for the new NSFW Guard and password reset features
- Branch-aware routing for the new admin forgot-password and reset-password pages
- New migrations for gallery NSFW flags, user email login, and password reset tokens

### What Changed

#### Public site rendering

- Added public photo metadata overlays on gallery cards
- Expanded gallery image cards so titles, descriptions, and tags can be shown inline
- Added age-gate rendering for NSFW galleries and photos
- Restricted thumbnails and media now respect NSFW access state
- Public JSON-LD output now skips restricted content
- Social preview metadata now prefers richer preview images and stronger card metadata

#### Admin authentication and recovery

- Added username-or-email login resolution
- Added admin recovery email storage and editing
- Added a recovery-email reminder notice inside the admin area
- Added a forgot-password page for admin accounts
- Added a reset-password page with one-time token validation
- Added delivery diagnostics for password reset requests and test emails

#### Admin account settings

- Added a dedicated password reset settings panel
- Added support for PHP mail and SMTP configuration
- Added validation for sender address, SMTP host, port, encryption, and reset token lifetime
- Added test-email sending from the account settings screen

#### Gallery access control

- Added gallery-level NSFW inheritance
- Added per-image NSFW flags
- Added session-based 18+ confirmation for anonymous visitors
- Tightened gallery access checks so restricted content is blocked before content, thumbnails, and lightbox sources are emitted
- Kept password-protected gallery behavior working alongside the new NSFW gating

#### Database and migrations

- Added a nullable `users.email` column and unique email index
- Added a `password_reset_tokens` table for selector/token storage
- Added `nsfw_enabled` columns for `galleries` and `images`
- Added supporting indexes for NSFW filtering and reset-token expiry cleanup

#### Theme and assets

- Adjusted generated theme cache keys to account for the theme asset controller revision
- Updated public styling to support the new gallery card metadata overlays and age-gate UI
- Refreshed generated asset references in the manifest

#### Technical notes

- `develop` currently contains 26 changed files relative to `main`
- The branch is centered on feature expansion rather than a small maintenance patch
- The release is suitable for a `v_0.57` tag once you are satisfied with runtime validation on your environment

### User Impact

#### For site visitors

- Gallery cards reveal more useful metadata immediately
- Restricted 18+ content is handled consistently across the public site
- Social sharing previews look richer and more accurate

#### For administrators

- Account recovery is now practical instead of manual
- Admin login is more forgiving because username or email can be used
- Password reset delivery can be configured and tested from the UI
- New schema-based content controls are available for mature content management

### Notes

- Password reset delivery is disabled by default in the example configuration and should only be enabled after the sender address and transport are verified.
- The new recovery flow depends on the `users.email` migration and the `password_reset_tokens` table.
- The NSFW Guard system depends on its new gallery and image columns, so those migrations need to be applied before the new visibility logic is fully active.

## Version 0.56

This changeset focuses on two user-facing improvements:

  - A new public page-width system for themes, including default, wide, custom, and full layouts
  - Faster admin workflow by adding contextual shortcuts from public gallery pages into create/upload screens

### Highlights

#### Theme layout controls

  The Theme editor now supports selecting the public page container width separately from colors, fonts, and background
  settings.

  Available layout modes:

  - Default for the existing standard-width layout
  - Wider for a larger container
  - Custom for a user-defined width between 1024px and 2048px
  - Full width for a near-viewport layout

  The live preview in the admin panel now reflects the selected layout mode, including custom width changes in real
  time.

#### Contextual gallery actions

  Public gallery admin controls now include direct action links:

  - Create a gallery inside the current gallery
  - Upload photos directly into the current gallery

  This reduces navigation steps when managing nested gallery content.

### What Changed

#### Theme system

  - Added persistent theme settings for:
      - theme_page_width
      - theme_page_width_custom
  - Added normalization and validation helpers for page width values
  - Prevented unrelated Theme saves from re-copying the same preset CSS and unintentionally resetting visual overrides
  - Kept page width as a structural preference instead of treating it like a color/font override

#### Public site rendering

  - Public pages now receive a body class that reflects the active width mode
  - Theme-generated CSS now defines the final container widths for:
      - default
      - wide
      - custom
      - full width
  - The public header, main content, and footer all follow the selected layout mode

#### Admin Theme UI

  - Added a new Page width selector to the Theme settings screen
  - Added slider and numeric input controls for custom width
  - Added live preview support for the width controls
  - Preview UI now visually shows the selected width mode in the admin panel

#### Gallery admin workflow

  - Public gallery action panel now links to:
      - Create gallery here
      - Upload photos here
  - New gallery form can preselect a parent gallery from the query string
  - Upload form can preselect a target gallery from the query string
  - Helper notices now confirm which gallery was preselected
  - Gallery select helpers now support a selected option for contextual navigation

#### Frontend polish

  - Adjusted admin preview layout so the live theme panel behaves more reliably inside the page
  - Updated styles to support the new width presets and custom-width control block
  - Manifest hashes were refreshed to reflect the code changes

### User Impact

#### For site visitors

  - Public galleries can now be displayed in narrower, wider, custom, or full-width layouts
  - Layout choice applies consistently across the main page structure

#### For administrators

  - Theme customization is more flexible
  - Live preview better matches the final public layout
  - Creating nested galleries and uploading into a specific gallery takes fewer clicks

### Notes

  - Custom width is clamped server-side and client-side to keep values safe and consistent
  - Page width changes persist independently from color/font overrides
  - Existing theme presets remain compatible

## Version 0.55

This update is a broad admin workflow rebuild focused on three areas: gallery ordering, log tooling, and updater
  hardening. It also adds safer maintenance actions and a larger set of client-side admin behaviors.

### Highlights

  - Gallery ordering is now handled directly from the admin table, including drag-to-reorder and drag-to-nest
    subgalleries.
  - Admin logs gained live filtering and better ordering controls.
  - Thumbnail maintenance now includes a controlled “delete all thumbnails” workflow with extra confirmation safeguards.
  - The updater was made sturdier, with better cleanup of obsolete managed files and cleaner rollback/reinstall flows.
  - The admin UI got a noticeable polish pass for ordering, logs, warnings, and destructive actions.

### Admin Dashboard

  - Gallery rows are now rendered in a deterministic tree order that respects sibling sort_order plus title fallback.
  - A new gallery-order toolbar was added to the All Galleries table.
  - The gallery table now includes a move handle column for drag-based nesting and reordering.
  - The old “ordering” info panel was updated to reflect the new in-table workflow.
  - The media tools card now includes:
      - Create all thumbnails
      - Delete all thumbnails
      - Download all galleries

### Gallery Reordering

  - Added full gallery-tree reordering support in the admin UI.
  - Dragging a gallery now moves its descendants as a unit.
  - Horizontal drag motion changes nesting depth:
      - Move right to nest under a parent
      - Move left to pull back out to a higher level
  - The full flattened tree is submitted to the server for validation and persistence.
  - Server-side checks now reject:
      - Invalid JSON
      - Duplicate gallery IDs
      - Missing galleries or stale tree state
      - Self-parenting
      - Invalid parent references
      - Subgalleries submitted before their parent
  - Parent changes are propagated to the folder structure on disk.
  - Reordering now logs the operation with counts for total galleries and moved folders.
  - The gallery edit screen now includes quick links back to the gallery list and to the public gallery view.

### Image Ordering

  - The image-order toolbar copy now explicitly mentions that filename sorting is available.
  - The image table now has a clickable Name column that sorts photos alphabetically.
  - Sorting by name is saved immediately, just like drag-based ordering.
  - The Name header updates its arrow direction and accessibility label based on the next action.
  - Image rows now carry explicit sortable name data for more reliable client-side ordering.

### Admin Logs

  - Logs now support live filtering without a full page reload.
  - Filter changes can be applied immediately with debounced search input.
  - The log results area updates dynamically with:
      - Row HTML
      - Result count
      - Empty-state copy
      - Current sort direction
  - The time-sort control can now toggle direction cleanly in the live view.
  - The UI now prevents duplicate log-status listeners from being attached repeatedly.
  - Log detail rows now have better structure and readability:
      - Summaries are clearly interactive
      - Metadata is shown in a compact definition-list layout
      - Long request values wrap safely
  - Log table scrolling and header link styling were improved for usability.

### Thumbnail Maintenance

  - Added a new admin action to delete all generated thumbnails.
  - The delete flow includes a browser prompt with a randomly chosen confirmation word.
  - The server still verifies the typed confirmation word, so the action is not dependent only on the browser prompt.
  - Thumbnail deletion is constrained to generated thumbnail cache directories under the gallery root.
  - Original images, database rows, and unrelated files are not touched.
  - A thumbnail inventory fingerprint was added so maintenance warning dismissal can be invalidated when the gallery
    content changes.

### Updater Hardening

  - Update install paths now return richer diagnostics, not just a copied-file count.
  - The updater now tracks removed obsolete managed files during installation.
  - A new clean reinstall flow was added to reinstall the stable branch over the current site.
  - The updater now removes stale generated ZIPs and temporary extraction folders from cache.
  - More file types and directories are now treated as protected from update cleanup, including:
      - .user.ini
      - php.ini
      - robots.txt
      - .well-known
  - Update and restore operations now provide better backup metadata and cleanup reporting.
  - The updater now distinguishes between normal managed paths and a stricter full-clean mode.

### Client-Side Admin Boot

  - New admin actions were wired into the app bootstrap:
      - admin_delete_thumbnails
      - admin_dismiss_thumbnail_notice
      - admin_reorder_galleries
      - admin_log_export
  - The main gallery JavaScript now loads the new admin behavior modules.
  - Cache-busting query strings were added to the admin module imports.

### UI and Styling

  - Added styling for:
      - Thumbnail maintenance notices
      - Gallery drag handles and placeholders
      - Gallery drag ghost previews
      - Log detail blocks
      - Dangerous maintenance buttons
      - Live log state text
  - Destructive admin actions are now visually separated with danger styling.
  - Gallery ordering mode disables text selection and changes the cursor for clearer drag feedback.
  - Image sorting and gallery reordering now have clearer keyboard focus states.

### Technical Notes

  - This change also updates the core manifest, reflecting the new file set and hashes.
  - The commit title matches the scope: “Update and log redone.”

## Version 0.54

- Added a new anonymous telemetry system with admin reporting, privacy controls, retention settings, and maintenance/
    rollup tooling.
  - Expanded the admin log view with richer filtering by status, category, severity, and search, plus contextual log
    details and request IDs.
  - Introduced a separate “Main page gallery grid” configuration so the home page can use its own layout independent of
    gallery pages.
  - Added support for resetting all per-gallery grid overrides, including cleanup of stale gallery.json sidecar data.
  - Updated the public home page and gallery rendering to use the new effective grid settings logic.
  - Added telemetry hooks to the public site so anonymous usage and performance data can be collected when enabled.
  - Improved the theme admin screen with a live visual preview, better organization, and additional controls for the
    homepage grid.
  - Split the front-end into modular assets under public/assets/gallery-modules/, replacing the previous monolithic
    public/assets/gallery.js.
  - Added new UI assets and behavior for lightbox, admin bulk actions, responsive thumbnails, favicon cropping, back-to-
    top, votes, and theme form interactions.
  - Added new maintenance and deployment helpers, including migration and telemetry maintenance scripts.
  - Introduced a number of new database migrations covering telemetry, gallery grid overrides, log observability, public
    URL slugs, background handling, voting, and related schema changes.
  - Updated the core manifest and application bootstrap/controller/service wiring to reflect the new modules and routes.
  - Added or refreshed security and server config files such as .htaccess, cache/public/gallery access rules, and
    install/reset entry points.

## Version 0.53

- Added public pagination for the home gallery list, gallery subgalleries, and photo grids.
  - Added new admin controls for pagination settings: enable/disable, columns per page, and rows per page.
  - Added clean pagination URLs for gallery pages, including route handling for /galleries/{page} and gallery photo/
    subgallery pagination paths.
  - Updated public gallery rendering to slice visible items by page while keeping lightbox navigation aware of the full
    ordered image set.
  - Added hidden lightbox source nodes so fullscreen navigation still works across paginated galleries.
  - Improved responsive thumbnail sizing so the browser selects better image candidates based on the actual rendered
    grid width.
  - Added new pagination styles and grid column classes to support configurable public layouts.
  - Minor bootstrap and service wiring updates to load the new pagination helpers.

## Version 0.52

Version 0.52 changes public photo captions so raw uploaded file names are hidden by default. Galleries now have an explicit file-name display setting, with matching controls in admin editing, inline logged-in editing, and bulk gallery operations.

### File name display privacy

- Added a per-gallery setting for showing uploaded file names. The default is off, so public gallery cards and lightbox metadata no longer show raw uploaded file names when a photo has no custom title.
- Preserved manually entered photo titles. If a photo has a custom title, it is still shown even when uploaded file names are hidden.
- Treated older filename-derived photo titles as file names for display purposes. This prevents existing records such as `IMG_4708` from staying visible after file names are disabled.
- Kept photo descriptions, tags, voting controls, map pins, and lightbox navigation behavior unchanged.

### Admin controls

- Added a direct Edit gallery checkbox named `Show file names`.
- Added the same control to the logged-in inline gallery editor on public gallery pages.
- Added bulk admin actions named `Show file names` and `Hide file names` across selected gallery branches.
- Added an `N` status column in the admin gallery list so galleries with visible file names are easy to identify.
- Added an `N` status column in the Edit gallery image table. A green arrow marks images in galleries where uploaded file names are shown.

### Database and release metadata

- Added a database migration for the new `galleries.show_filenames` column.
- Updated the fresh-install schema so new installations receive the same setting immediately.
- Added a focused gallery display service for file-name schema checks and public title display logic.
- Updated sidecar metadata writing so the file-name display preference is preserved with gallery metadata.

## Version 0.51

Version 0.51 makes update installs safer on long-running PHP processes by clearing cached opcode state after copied files are in place. This helps newly deployed code take effect immediately during beta and normal update installs.

### Update reliability

- Invalidated OPcache after application files are copied during beta installs.
- Invalidated OPcache after application files are copied during standard update installs.

## Version 0.50

Version 0.50 adds drag-based picture sorting inside gallery management. It lets admins reorder photos directly from the gallery view, and updates the related admin upload, image scanning, and front-end assets so the new ordering workflow stays consistent.

### Gallery ordering

- Added drag-and-drop picture sorting to the gallery admin interface.
- Extended the gallery script and styles to support the new ordering interaction.
- Updated admin upload and image scanning behavior to respect picture order changes.
- Refreshed the release manifest for the 0.50 file set.

## Version 0.49

Version 0.49 focuses on the admin zone and updater workflow. It adds a dedicated admin integrity screen, expands gallery administration, improves theme and thumbnail maintenance, and hardens public gallery and media routing. The updater also gains sturdier branch/version handling and safer restore logic.

### Admin zone rework

- Added a dedicated admin integrity controller and screen for manifest and application checks.
- Expanded the admin dashboard with the new integrity entry point and related admin actions.
- Reworked gallery administration into a dedicated controller with clearer gallery mutation handling.
- Tightened admin log, thumbnail, upload, and theme controllers around their specific responsibilities.
- Extended the bootstrap route map to include the new integrity admin route.

### Updater improvements

- Strengthened GitHub version detection and branch handling in the update service.
- Improved release ZIP download, copy, backup, and restore flow for safer update installs.
- Preserved protected local areas such as config, galleries, cache, and custom CSS during update operations.
- Added clearer update status and messaging in the admin update screen.

### Public rendering and media handling

- Expanded public gallery routing and media streaming helpers.
- Added gallery access, cover, background, sidecar, and public path service modules to keep public rendering logic separated and easier to maintain.
- Tightened thumbnail generation and public asset serving paths.
- Improved theme asset generation, including stylesheet and custom CSS handling.

### Installation and maintenance

- Updated installer and setup flow support for the current application structure.
- Added integrity helpers and manifest generation support for release validation.
- Expanded upload and download helpers for safer file handling.

## Version 0.48

Version 0.48 is a major internal architecture release. It restructures the PHP Gallery codebase by splitting the large service and controller files into focused modules, while preserving the existing public function names, route handlers, include contracts, gallery data model, filesystem-first behavior, theme settings, favicon storage, custom CSS handling, and public rendering behavior.

The goal of this release is maintainability, safer future development, and easier debugging. The public application should behave the same as before, but the implementation is now divided into clearer domains instead of concentrating most backend behavior in `app/services.php` and `app/controllers.php`.

### Architecture refactor

- Split the previous monolithic `app/services.php` into focused service modules under `app/services/`.
- Split the previous monolithic `app/controllers.php` into focused controller modules under `app/controllers/`.
- Kept `app/services.php` and `app/controllers.php` as compatibility loaders, so existing bootstrap logic and route dispatch can continue requiring the same files.
- Preserved original global function names and signatures to avoid breaking templates, controllers, migrations, public endpoints, admin forms, and existing internal calls.
- Regenerated `app/core-manifest.json` for the new file layout.
- Verified the refactor with PHP syntax checks and duplicate-function checks during packaging.

### Service modules introduced or expanded

The service layer is now organized into smaller files by responsibility:

- `app/services/logs.php` for admin log persistence, log status handling, and log maintenance helpers.
- `app/services/downloads.php` for gallery ZIP creation, ZIP cache helpers, archive entry collection, and download streaming helpers.
- `app/services/updates.php` for GitHub version checks, release ZIP downloads, beta install and restore helpers, protected-path handling, update copy logic, backup logic, and OPcache invalidation.
- `app/services/picture_game.php` for picture-game availability checks, vote pair selection, pair history, vote recording, and ranking helpers.
- `app/services/tags.php` for tag parsing, tag slugging, tag lookup, entity-tag synchronization, vote totals, current-viewer vote lookups, and tag-based public gallery listings.
- `app/services/exif.php` for EXIF extraction, GPS metadata handling, map eligibility checks, and gallery map data helpers.
- `app/services/gallery_paths.php` for gallery and image filesystem path resolution, root-boundary checks, and safe path handling.
- `app/services/gallery_sidecars.php` for `gallery.json` sidecar metadata, gallery discovery helpers, and empty gallery creation helpers.
- `app/services/gallery_lookup.php` for read-oriented gallery and image database lookup helpers.
- `app/services/public_paths.php` for clean public gallery paths, image slugs, sitemap entries, and public path regeneration helpers.
- `app/services/gallery_mutations.php` for gallery creation, gallery moves, imports, subtree deletion, ancestor creation, and parent synchronization.
- `app/services/image_scanning.php` for filesystem image reconciliation and database indexing.
- `app/services/uploads.php` for upload validation, safe filename handling, gallery image storage, cover uploads, and uploaded-image ID tracking.
- `app/services/thumbnails.php` for thumbnail paths, thumbnail URLs, `srcset` generation, thumbnail maintenance status, GD resizing, Imagick resizing, and thumbnail regeneration helpers.
- `app/services/gallery_covers.php` for gallery cover resolution, collage candidates, cover choices, and sidecar cover application.
- `app/services/gallery_access.php` for password gates, share tokens, visitor access checks, and public gallery listing rules.
- `app/services/favicon.php` for favicon storage paths, favicon asset URLs, uploaded favicon processing, square cropping, PNG resizing, and favicon removal.
- `app/services/custom_css.php` for active custom CSS paths, preset discovery, preset validation, and public custom CSS URL generation.
- `app/services/gallery_backgrounds.php` for global theme background paths, uploaded theme background storage, gallery background source resolution, and public background asset URLs.
- `app/services/theme.php` for theme settings, theme override settings, theme CSS defaults, CSS custom property parsing, font mode detection, and hex color sanitization.
- `app/services/app_settings.php` for DB-backed application settings such as site name, dev mode, and collapsed gallery state.
- `app/services/database_helpers.php` for reusable schema helper logic such as checking whether a database column exists.
- `app/services/download_signatures.php` for gallery ZIP signature calculation.

### Controller modules introduced or expanded

The controller layer is now organized into route and screen modules:

- `app/controllers/admin_logs.php` for admin log pages and log status actions.
- `app/controllers/downloads.php` for public gallery ZIP download routes.
- `app/controllers/updates.php` for the admin update workflow.
- `app/controllers/picture_game.php` for public picture-game rendering and vote submission.
- `app/controllers/tags.php` for tag pages, tag list rendering, direct image voting, and vote form rendering.
- `app/controllers/exif.php` for gallery map JSON output.
- `app/controllers/http_helpers.php` for shared controller response helpers.
- `app/controllers/public_gallery.php` for public gallery rendering routes.
- `app/controllers/public_media.php` for public media and image streaming routes.
- `app/controllers/admin_auth.php` for admin authentication flow.
- `app/controllers/admin_integrity.php` for admin integrity check screens and actions.
- `app/controllers/admin_galleries.php` for admin gallery management actions.
- `app/controllers/admin_uploads.php` for admin upload actions.
- `app/controllers/admin_thumbnails.php` for thumbnail maintenance actions.
- `app/controllers/admin_dashboard.php` for dashboard-related admin rendering.
- `app/controllers/setup.php` for setup and installation-related controller flow.
- `app/controllers/admin_theme.php` for the admin theme screen.
- `app/controllers/theme_assets.php` for generated theme CSS, favicon asset serving, and theme background asset serving.

### Theme, favicon, custom CSS, and background preservation

Version 0.48 includes the final theme-sensitive split, with special care taken to preserve existing runtime behavior:

- Preserved stored theme colors, radius settings, font mode settings, and theme overrides.
- Preserved the active custom CSS file and custom CSS preset discovery.
- Preserved uploaded favicon storage and favicon asset URLs.
- Preserved global theme background storage and gallery background fallback behavior.
- Preserved runtime paths for project-root assets such as `public/assets/styles.css`, `public/assets/custom.css`, `custom_css/`, `cache/favicon/`, and `cache/theme-background/`.
- Fixed module-relative filesystem path resolution during extraction so moved helpers continue resolving files from the project root, not from inside `app/`.
- Kept the loader order explicit so settings helpers, custom CSS helpers, theme helpers, favicon helpers, and background helpers are available before dependent code executes.

### Uploads, thumbnails, media, and gallery core

The larger non-theme split keeps the active gallery behavior intact while separating the implementation into clearer subsystems:

- Upload validation and storage logic now lives in a dedicated upload service.
- Thumbnail generation, thumbnail URL construction, and thumbnail maintenance logic now live in a dedicated thumbnail service.
- Gallery cover logic is separated from general gallery lookup and mutation logic.
- Gallery access control is separated from gallery discovery and rendering logic.
- Filesystem scanning and database reconciliation are separated from upload handling.
- Public media streaming routes are separated from public gallery rendering routes.
- Gallery path calculation, sidecar metadata loading, database lookup helpers, and clean public paths are now separate concerns.

### Admin and maintenance improvements from the refactor

- Admin log handling is now isolated from unrelated controller code.
- Integrity actions are now isolated into their own admin controller.
- Update workflow logic is now split between update services and update controllers.
- Dashboard, upload, gallery-management, thumbnail-maintenance, authentication, setup, and theme admin code now have clearer ownership.
- Future changes to one admin area are less likely to accidentally affect unrelated admin screens.

### Compatibility and behavior notes

- The gallery remains filesystem-first. Gallery folders and image files continue to be the source of truth.
- The database remains the index, metadata, permissions, tags, votes, share links, and settings layer.
- Existing clean public URLs are preserved.
- Existing gallery folders, uploaded images, cached thumbnails, custom CSS files, favicon files, theme background files, and configuration files are not replaced by this refactor.
- Existing admin forms and public routes continue using the same function names and route handlers.
- The refactor is intentionally structural. It does not introduce a new theme model, a new gallery model, or a new upload model.

### Developer impact

This release makes the codebase easier to work on:

- Smaller service files are easier to read and review.
- Controller code is grouped by route family instead of being concentrated in one large file.
- Theme-related behavior is isolated from upload, thumbnail, update, and public rendering code.
- Filesystem path helpers are easier to audit.
- Future features can be added into a relevant module instead of expanding the old monolithic files.
- Regression risk is reduced because each subsystem now has a clearer boundary.

Detailed release notes for PHP Gallery CMS. Versions are listed newest first.

## Version 0.47

Version 0.47 adds the new online gallery setup flow in `setup-gallery.php`. The repository now includes a one-file bootstrap installer that can download the gallery archive, unpack it into the deployment directory, and create the bootstrap lock used to prevent repeat installs.

The setup flow is intentionally minimal and is designed for first deployment on shared hosting:

- Validates the runtime environment before starting
- Downloads the published gallery archive from GitHub
- Extracts the archive safely into the current project directory
- Preserves `config.php` and `setup-gallery.php` while copying the rest of the project
- Writes `cache/bootstrap-installed.lock` after a successful bootstrap

Additional notes:
- The bootstrap installer now has its own lock handling so it cannot be run again once setup is complete
- The release manifest includes the new `setup-gallery.php` file hash



## Version 0.46

Version 0.46 focuses on the first-run installation experience. The installer has been redesigned into a simpler guided flow that avoids exposing technical configuration that the application can safely derive on its own.

The installation process is now split into a clear two-step flow:

Step 1:
- Gallery name
- Database server
- Optional database port
- Database name
- Database username
- Database password

The installer now expects an existing database instead of attempting to create one. This aligns with how most shared hosting environments operate.

The database connection is validated immediately. The user can only continue once the connection is confirmed to work.

Step 2:
- Admin username
- Admin password

The installer clearly distinguishes between database credentials and gallery admin credentials. The admin account is used for logging into the gallery itself and is not related to the database user.

The installer no longer exposes internal configuration such as Base URL, galleries path, or cache paths. These values are now derived automatically using safe defaults:

/galleries
/cache/zips

The detected domain is used automatically and only confirmed, rather than manually configured.

Additional improvements:
- Removal of database creation logic
- Reduced number of installer fields
- Immediate validation of database connectivity
- Admin redirects now land on clean URLs after destructive or expensive actions, so gallery deletes, migrations, and thumbnail regeneration do not leave repeatable action parameters in the address bar.
- Clearer explanation of each step
- Cleaner separation between setup phases
- Automatic redirect after installation with visible fallback link

The installer now follows a simplified flow:
Name the gallery, connect to database, create admin account, start using the application.

This significantly reduces the cognitive load during installation and makes the system usable without requiring technical knowledge of filesystem paths or URL configuration.

## Version 0.45 – Diagnostics, Stability & UX Refinement

### ?? Dev Mode (Admin-only Diagnostics Overlay)
A new internal diagnostics system has been introduced to support performance tuning and preload optimization.

- Added **Dev Mode toggle** in Admin settings (stored in `app_settings`)
- Introduced **real-time diagnostics overlay** inside gallery viewer and fullscreen
- Overlay is **visible only to logged-in admins**
- Designed as a **compact, non-intrusive HUD**, inspired by "stats for nerds"

#### Overlay capabilities:
- Preload system:
 - Current preload radius
 - Active preload queue
 - Preload hits / misses
- Image lifecycle tracking:
 - Loading / ready / error states
 - Thumbnail vs full-resolution usage
- Cache insights:
 - Decoded image count
 - Cache size estimation
 - Eviction tracking
- Memory monitoring:
 - Estimated decoded image memory footprint
 - Browser heap usage (when available via `performance.memory`)
- Rendering performance:
 - Frame timing (recent frame durations)
 - Lightweight graph visualization (canvas-based)
- Network hints:
 - Connection type (if available)
- Viewer context:
 - Current image index
 - Preload window boundaries

This system is intentionally lightweight and runs only when enabled.

---

### ?? Admin UI Adjustments

- **Dev Mode control moved**:
 - Relocated to a less prominent position in Admin panel
 - Now placed lower in the settings flow to avoid distracting standard users
 - Maintains full functionality, just reduced visual priority

---

### ?? Checkbox Styling Improvements

Global checkbox redesign applied across Admin and Upload UI:

- Reduced overall size for better visual balance
- Removed overly large "blocky" appearance
- Improved alignment with surrounding UI elements
- More subtle, modern styling
- Consistent behavior across:
 - Admin settings
 - Upload interface
 - Bulk actions

---

### ?? Minor UX Polishing

- Improved spacing in Admin panels
- Reduced visual noise in configuration sections
- Better hierarchy between primary and secondary settings

---

### ? Internal

- Added dev-mode flag handling in bootstrap layer
- Extended gallery runtime with instrumentation hooks
- Introduced lightweight telemetry collector in `gallery.js`
- No impact on public users or performance when disabled

---

### ? Notes

- Dev Mode is intended strictly for diagnostics and tuning
- Not optimized for production usage visibility
- Some metrics depend on browser support (e.g., memory API)


## Version 0.44

### Major Changes

- Added a system integrity manifest and admin integrity dashboard so core files can be checked for modifications, missing files, and unknown release-surface files.
- Added deployment support for refreshing the integrity manifest automatically before a release upload.
- Added a floating back-to-top button for long gallery and tag listings, with responsive placement for desktop and mobile layouts.
- Reworked gallery uploads so files can be sent in smaller batches and thumbnail generation tracks each uploaded image more reliably.
- Added bulk gallery deletion from the admin gallery table.
- Refined lightbox interaction and focus styling so the image stage behaves more naturally in normal view and the mouse-focused outline boxes are gone.

### Fixes

- Prepared the application metadata for the 0.44 release cycle.

## Version 0.43

### Major Changes

- Reworked gallery and full-site ZIP downloads so generated archives can now include the gallery folder structure and nested subfolders instead of flattening everything into a single-level file list.
- Added ZIP cache expiry handling with a 7-day lifetime for generated downloads. Expired cache files are removed automatically and rebuilt on demand for both per-gallery and all-galleries ZIP exports.
- Updated ZIP signature handling so cached downloads are invalidated more reliably when gallery structure, image metadata, visibility, or update timestamps change.
- Improved the lightbox interaction model so the preview image now acts as the fullscreen toggle, making the primary image area behave more naturally on desktop and mobile.
- Removed the separate "Open original" link from the lightbox toolbar and streamlined the fullscreen controls to match the new stage toggle behavior.
- Reduced lightbox transition timing to make image swaps feel faster and more responsive during navigation and decode swaps.
- Tightened the fullscreen styling for the lightbox so the image stage fills the available area more cleanly and the fullscreen background stays consistently dark.

### Fixes

- Prepared the application metadata for the 0.43 release cycle.
- Kept release and update version detection aligned with the new 0.43 branch metadata.

## Version 0.42

### Major Changes

- Added theme-managed favicon setup with admin upload, square crop preview, generated PNG icon sizes, and public favicon links.

### Fixes

- Prepared the application metadata for the 0.42 release cycle.

## Version 0.41

### Major Changes

- Added slug-based public URLs for files so image pages can use stable, readable paths.
- Optimized routing and public-path handling so gallery and file URLs resolve with less overhead.
- Improved cache handling for public pages and navigation so repeated requests reuse more of the generated state.
- Added database migrations to backfill public URL slugs and clean up older public path records.

### Fixes

- Prepared the application metadata for the 0.41 release cycle.

## Version 0.40

### Major Changes

- Tightened public gallery-card thumbnail selection so previews prefer the 800px variant and only fall back to 300px on very small viewports.
- Fixed responsive gallery-card thumbnail srcsets so they no longer advertise missing sizes that could resolve to full-size media.
- Normalized uploaded gallery cover assets to bounded preview images instead of streaming the original upload directly.

### Fixes

- Prepared the application metadata for the 0.40 release cycle.

## Version 0.39

### Major Changes

- Added a global theme background image upload stored in private cache storage
 instead of gallery media folders.
- Added a background opacity slider in the Theme admin panel, including a live
 percentage display while adjusting the value.
- Added theme-level header title color and gallery title color controls so dark
 backgrounds can keep text readable.
- Added a fallback background source at the theme level and per-gallery
 background source selection in gallery admin.
- Added public background rendering as a base color layer with an optional
 background image layer on top.
- Moved gallery breadcrumbs and action buttons into the hero panel so the
 public gallery header is more compact and aligned.
- Updated the header and hero surfaces to share the rounded-corner theme
 setting while keeping the hero styling translucent over the background.
- Hardened the Leaflet map overlay path so normal public maps and fullscreen
 split maps keep working after dynamic DOM changes and resize/rebuild cycles.
- Added a dedicated public route for serving the stored global theme background
 asset.
- Added a bulk Theme-panel action to reset every gallery background override
 back to the theme background.
- Added a compact `B` indicator to the admin gallery table for galleries that
 have an explicit background override.

### Fixes

- Fixed normal gallery map overlays so they remain visible in public view.
- Fixed the inline-style guard so legitimate Leaflet runtime styles no longer
 trigger the tamper warning path.
- Fixed Leaflet tile sizing and viewport initialization during overlay
 creation.
- Fixed stale map pane errors caused by initializing the map before the
 overlay had finished sizing.
- Fixed the `background_source` gallery column schema so clearing the per-
 gallery setting can store `NULL` and fall back to the theme background.
- Fixed `custom.css` tracking so uploaded custom styles are ignored by Git.

### Notes

- This release continues the theme-backed background model introduced in the
 previous cycle, but now includes a bulk reset flow for gallery overrides and
 a table-level indicator so admins can see which galleries diverge from the
 theme.

## Version 0.38

### Major Changes

- Added a global theme background image upload stored in private cache storage
 instead of gallery media folders.
- Added a background opacity slider in the Theme admin panel, including a live
 percentage display while adjusting the value.
- Added theme-level header title color and gallery title color controls so dark
 backgrounds can keep text readable.
- Added a fallback background source at the theme level and per-gallery
 background source selection in gallery admin.
- Added public background rendering as a base color layer with an optional
 background image layer on top.
- Moved gallery breadcrumbs and action buttons into the hero panel so the
 public gallery header is more compact and aligned.
- Updated the header and hero surfaces to share the rounded-corner theme
 setting while keeping the hero styling translucent over the background.
- Hardened the Leaflet map overlay path so normal public maps and fullscreen
 split maps keep working after dynamic DOM changes and resize/rebuild cycles.
- Added a dedicated public route for serving the stored global theme background
 asset.

### Fixes

- Fixed normal gallery map overlays so they remain visible in public view.
- Fixed the inline-style guard so legitimate Leaflet runtime styles no longer
 trigger the tamper warning path.
- Fixed Leaflet tile sizing and viewport initialization during overlay
 creation.
- Fixed stale map pane errors caused by initializing the map before the
 overlay had finished sizing.
- Fixed the `background_source` gallery column schema so clearing the per-
 gallery setting can store `NULL` and fall back to the theme background.
- Fixed `custom.css` tracking so uploaded custom styles are ignored by Git.

## Version 0.37

### Major Changes

- Added a standalone admin reset entrypoint at `reset.php` so the site can be
 restored to the current stable branch head even when the normal admin update
 page is no longer usable after a broken beta deploy.
- Added a new gallery thumbnail asset path column so uploaded gallery cover
 images can be stored separately from imported gallery photos and served
 through a dedicated public route.
- Kept gallery-card cover rendering responsive by introducing thumbnail
 `srcset`/`sizes` hints and an intermediate 600px size, which lets the browser
 choose a sharper preview for wide cards without forcing the full 800px asset
 everywhere.
- Reduced gallery-page and lightbox overhead by batching per-image tag and vote
 lookups, memoizing request-scoped gallery helpers, and preloading adjacent
 lightbox images for smoother forward and backward viewing.
- Added reverse tag indexes so tag-filter pages and contained-tag lookups scale
 better on larger libraries.

## Version 0.36

### Major Changes

- Prepared the application metadata for the 0.36 release cycle.
- Removed browser-side and app-side reuse from the update check path so the
 admin update page always queries GitHub fresh.
- Added cache-busting request parameters and no-cache request headers to the
 GitHub version and archive fetches used by the updater.
- Kept the fullscreen split map improvements from the previous release:
 persistent map display during fullscreen browsing, restored map pins, and
 the desktop-only split layout for the map panel.
- Kept the non-image cache policy in place so HTML, CSS, JavaScript, JSON, and
 theme CSS do not linger from older gallery states.

## Version 0.35

### Major Changes

- Kept the fullscreen lightbox map split open when browsing to the next or
 previous image in fullscreen, so the map now persists until it is turned off
 or fullscreen mode ends.
- Restored the map pins in the fullscreen split view by reusing the same marker
 icon path as the normal public map overlay and keeping the Leaflet marker
 panes above the tile panes.
- Added a strict non-cache policy for non-image responses so browsers stop
 reusing stale HTML, CSS, JavaScript, JSON, and theme CSS from older gallery
 states.
- Versioned the main public stylesheet URL so updated UI and map code cannot be
 masked by a previously cached `styles.css`.

## Version 0.34

### Major Changes

- Prepared the application metadata for the 0.34 release cycle.

## Version 0.33

### Major Changes

- Prepared the application metadata for the 0.33 release cycle.
- Made `app/bootstrap.php` the single source of truth for update version checks.
- Reworded the beta update UI to say "beta code" instead of "Git commit hash".
- Switched stable rollback to restore the current GitHub branch head directly.

## Version 0.32

### Major Changes

- Prepared the application metadata for the 0.32 release cycle.
- Made `app/bootstrap.php` the single source of truth for update version checks.
- Reworded the beta update UI to say "beta code" instead of "Git commit hash".

## Version 0.31

### Major Changes

- Prepared the application metadata for the 0.31 release cycle.
- Hardened the GitHub update check so the admin update button can detect the
 newest version from both `PATCH_NOTES.md` and `app/bootstrap.php`.
- Made remote version parsing tolerate `v0.31` and `v_0.31` style headings, so
 release notes and tags cannot hide a valid newer version.
- Added explicit update-source reporting on the admin update page to show whether
 the detected GitHub version came from patch notes or the remote bootstrap file.

### Notes

- Upload this release, then push the same files to the GitHub branch used by the
 updater. Older installed copies will then see 0.31 as the newest available
 version even if one of the remote version sources is temporarily stale.

## Version 0.30

### Major Changes

- Made the lightbox fullscreen controls usable on mobile devices as a visible
 overlay action, while keeping desktop keyboard behavior unchanged.
- Simplified the mobile fullscreen path so it now relies on the app's own
 overlay state instead of depending on the browser fullscreen API.
- Added a lightweight debug flag for tracing the lightbox fullscreen toggle
 path during local testing.

## Version 0.29

### Major Changes

- Removed the public tamper-warning overlay so beta installs and normal gallery
 browsing no longer get blocked by the inline-style guard.
- Continued the manual beta updater work so the stable/beta distinction stays
 explicit while rollback remains available from the admin update screen.
- Kept the fullscreen and mobile viewer work aligned with the existing overlay
 model rather than introducing a separate viewer path.

## Version 0.28

### Major Changes

- Expanded the updater to support manual beta installs from a specific Git
 commit hash, with rollback to the last stable backup when beta is active.
- Continued the fullscreen and mobile gallery work with swipe-friendly viewer
 behavior and a CSS fallback path for devices that do not expose native
 fullscreen the same way.
- Kept the release and update UI aligned with the live version state so the
 admin navigation and update screen remain responsive to new releases.

## Version 0.27

### Major Changes

- Improved the public fullscreen overlay so the lightbox HUD, navigation, and
 centered image staging behave more consistently across normal and fullscreen
 states.
- Fixed update-badge detection so new releases are reflected immediately in the
 admin navigation rather than waiting on stale cached state.
- Continued the 0.26 admin polish by keeping migration prompts conditional and
 preserving the compact dashboard feature indicators.

## Version 0.26

### Major Changes

- Refined the public lightbox and fullscreen overlay so navigation and HUD
 controls behave more predictably in both normal and fullscreen states.
- Tightened the admin migration detection and dashboard presentation so pending
 migrations are shown only when needed and use the striped update treatment.
- Continued the gallery voting and picture game cleanup work with stricter
 admin-side state normalization and clearer per-gallery feature handling.

## Version 0.25

### Major Changes

- Added optional per-gallery voting controls with admin-side enable/disable
 support, public UI gating, and preserved vote history when disabled.
- Added admin dashboard bulk actions and compact status columns for gallery
 voting, GPS maps, and picture game settings.
- Added admin-side self-healing for gallery voting/game flag mismatches on
 dashboard load so game-enabled galleries always keep voting enabled.

## Version 0.24

### Major Changes

- Hardened public SEO routing without changing the working gallery navigation:
 - public gallery cards now link to clean `/gallery/{slug}/` URLs as the first
 baby step toward cleaner public navigation
 - other gallery navigation, redirects, forms, and admin links remain on the
 stable query-string route
 - clean `/gallery/{slug}/` URLs are supported and used as canonical URLs
 - nested `/gallery/folder/path/` URLs remain compatibility routes, but they
 are not used as generated public links
 - `/robots.txt` and `/sitemap.xml` now resolve correctly in subfolder installs
 - `base_url = ''` installs now emit root-relative app links instead of
 fragile page-relative links
- Improved crawler metadata:
 - gallery pages emit title, description, canonical, Open Graph, Twitter card,
 and JSON-LD metadata
 - gallery page headings keep a single `<h1>` that matches the resolved gallery
 title
 - image `alt` text falls back from caption metadata to filename to a
 gallery-based fallback
 - `sitemap.xml` lists public, non-protected galleries with absolute URLs
 - `gallery.json` tags can be comma-separated text or a JSON array
 - saved gallery sidecars now include gallery tags

## Version 0.23

### Major Changes

- Added filesystem-first gallery management groundwork:
 - changing a gallery parent now moves the real folder subtree on disk
 - changing the folder name on a gallery edit page renames the real folder
 - all moved descendant gallery `folder_path` values are updated together
 - failed folder moves are logged and leave an admin-visible error
- Added admin gallery creation and upload flows:
 - `Create empty gallery` creates a real empty folder and a gallery row
 - `Upload photos` can upload multiple images into an existing gallery
 - uploads can also create a new gallery folder before storing images
 - upload progress is shown in the browser, followed by existing thumbnail
 batch progress when optimized thumbnails are requested

## Version 0.22

### Major Changes

- The dashboard `Check for new gallery folders` button now also scans all
 already-imported galleries for new or changed direct image files before
 showing the discovery screen.
- Added an admin log entry and on-screen summary for that refresh scan, including
 how many existing galleries were scanned and how many image records changed.
- Added a visible wait indicator for the dashboard refresh scan so large gallery
 checks no longer leave the admin page looking frozen while the request runs.
- Added an admin gallery status filter for drafts, public galleries, and private
 galleries. The bulk `Select displayed galleries` checkbox now only selects
 rows that remain visible after filtering and collapsed tree branches.

## Version 0.21

### Major Changes

- Prepared the application for the next release cycle and kept the updater badge logic aligned with the current installed release.

## Version 0.20

### Major Changes

- Fixed the pending-update indicator on the admin update page so the fresh
 GitHub check updates the same cached state used by the header and dashboard.
- Pending update links and buttons now show `Update(1)` and use the fixed
 warning background, including the primary update action button.
- The updater now checks both allowed GitHub branches and uses the highest
 advertised version, so a stale `main` branch cannot hide a newer `master`
 release.

## Version 0.19

### Major Changes

- The pending-update cache now invalidates when the application version changes,
 so the `Update(1)` badge stays accurate after a release.

## Version 0.18

### Major Changes

- Opened public gallery titles now use a slightly smaller display size while
 still taking the full panel width, so long names fit more comfortably without
 wrapping early on desktop layouts.

## Version 0.17

### Major Changes

- The admin Updates button now switches to a fixed warning style and shows
 `Update(1)` when a newer GitHub version is available.

### Notes

- The pending-update badge uses a cached GitHub check and does not use theme
 colors, so custom CSS and sliders cannot make it look like a normal button.

## Version 0.16

### Major Changes

- Theme controls now read defaults from the active CSS skin, and saved slider
 values override custom CSS through the generated theme stylesheet.
- Added a `Reset to CSS` button on the Theme screen to clear saved color, radius,
 and font overrides while keeping the selected custom CSS skin.
- Added a dedicated Theme color control for the open public gallery panel.

### Notes

- Existing installs with saved theme overrides may need one click on `Reset to
 CSS` to return the sliders to the selected CSS skin defaults.

## Version 0.15

### Major Changes

- Clarified the browser installation flow in the README so setup starts from the
 site root and redirects automatically to the installer when `config.php` is
 missing.

### Notes

- The installer still supports opening `install.php` directly, but that is not
 required for a normal first-time setup.

## Version 0.14

### Major Changes

- Added an admin-only application updater:
 - the Updates page checks GitHub `PATCH_NOTES.md` for a newer version
 - admins can install newer branch archives with one button when PHP `ZipArchive` and outbound HTTPS are available
 - overwritten application files are backed up under `cache/updates/backups`
 - local `config.php`, galleries, cache files, and active custom CSS are left untouched
- Share-link display tokens are encrypted at rest while link validation continues to use token hashes.

### Notes

- The updater does not delete obsolete files from older releases; remove those manually if a future release note asks for it.
- Keep a normal hosting backup before using one-button updates on production sites.

## Version 0.13

### Major Changes

- Added password-protected public galleries:
 - protected galleries can be listed publicly without thumbnails or set as unlisted/direct-link-only
 - protected access is inherited by subgalleries
 - visitors can unlock a protected branch with a gallery password for 10 minutes
 - admins can generate, regenerate, expire, or revoke share links
 - share-link-only galleries are an explicit admin access mode and generate a usable link when saved
 - generated share links use the canonical query route with the gallery id and token so the token cannot resolve to the wrong gallery
 - active share links remain visible in the admin edit form and can be revoked later, with the display token encrypted at rest
 - share links use `page=share&id=...&token=...` so they work without rewrite rules
- Added a follow-up migration for existing v0.13 installs so the persistent share-link token column is applied even when the first v0.13 migration already ran.
- Made configured `http://` base URLs upgrade to `https://` automatically on same-host HTTPS requests, including common reverse-proxy headers, so CSS and JavaScript are not blocked as mixed content after enabling HTTPS.
- Made same-host `base_url` paths self-correct when the configured path does not match the current front-controller path, which helps shared-hosting deployments where `/subdom/name` is an internal folder but the public site is served from the domain root.
- Added progress feedback to the gallery import flow when `Create optimized thumbnails during import` is checked.
- Prevented browser/mobile auto-translation wrappers from triggering the inline-style compromise warning, and marked the gallery document as non-translatable to preserve Czech gallery titles such as `Den 01`.
- Centralized protected-gallery access checks across public gallery pages, thumbnails, original media, downloads, maps, tags, votes, and the picture game.
- Added admin edit controls and dashboard access labels for protected/listed/unlisted galleries.
- Updated the installer and initial schema for the v0.13 protected-gallery fields.

### Notes

- Run database migrations after uploading this version.
- Regenerating a share link immediately invalidates the old link.
- Unlisted protected galleries and their subgalleries are reachable by direct link only.

## Version 0.12

### Major Changes

- Added optional EXIF/GPS map support for gallery branches:
 - image scans extract safe EXIF fields when the PHP EXIF extension is available
 - GPS coordinates are stored separately from the source file and refreshed on rescan
 - admins can enable EXIF GPS maps on a gallery branch, recursively including subgalleries
 - public image cards show a map pin only when the branch allows GPS maps and the photo has GPS coordinates
 - the lightbox shows a map button for GPS-enabled photos
 - gallery pages can open a combined map of all GPS-enabled public photos in the current gallery branch
- Added a migration for EXIF/GPS columns and the recursive gallery map flag.
- Added Leaflet/OpenStreetMap-based map overlays without requiring a paid Google Maps API key.
- Added a JSON gallery-map endpoint for the public gallery page and lightbox controls.

### Notes

- Run database migrations after uploading this version.
- Rescan galleries after migration so existing images receive EXIF/GPS metadata.
- OpenStreetMap public tiles are suitable for light usage and testing. For heavy public traffic, configure a dedicated tile provider later.

## Version 0.11

### Major Changes

- Simplified the browser installer migration runner:
 - `install.php` no longer wraps each migration file in an explicit database transaction
 - migration statements now run directly, which avoids redundant transaction handling during schema setup

### Documentation

- Updated `README.md` and `ARCHITECTURE.md` to describe the installer migration flow accurately

## Version 0.10

### Major Changes

- Added the optional picture comparison game for opt-in gallery branches:
 - pair history prevents the same viewer from seeing the same image pair again in the same gallery game
 - visitors can choose the left or right image with clicks or arrow keys
 - the chosen image receives a normal upvote
 - the game shows global top-picture statistics for the current gallery
 - admins can enable or disable the game across gallery trees
- Added a database-backed admin log:
 - admins can record operational events and failures without server log access
 - the log page supports workflow states, filtering, and bulk status updates
- Hardened migration-aware admin behavior:
 - admin features detect missing schema more safely
 - the dashboard shows a clear migration prompt when the schema is stale
 - admins can run migrations from the dashboard when needed
 - migration attempts and rejected admin actions are logged
- Kept the earlier public-page admin editing, lightbox vote display, custom CSS skins, and site-name configuration in the same release branch

### Documentation

- Updated the public UI documentation for admin log workflow, migration prompts, and picture-game admin flow

## Version 0.9

### Major Changes

- Added public-page admin editing:
 - logged-in admins can edit gallery names and descriptions directly from
 public gallery and subgallery cards
 - logged-in admins can edit photo titles and descriptions directly from public
 image cards
 - inline controls can publish, hide, or remove CMS records without deleting
 the underlying files from disk
 - admins can see draft/private images and subgalleries while browsing public
 gallery pages
- Improved the public lightbox metadata and voting experience:
 - image descriptions are visible in the overlay
 - missing descriptions show a clear `No description.` fallback
 - score is shown as a dedicated badge under the picture
 - up/down vote controls and the current vote indicator are visible under the
 picture metadata
 - keyboard up/down voting updates the overlay vote state
- Made the public site name configurable:
 - the default `Gallery CMS` label can be changed from Admin -> Theme
 - the configured name is used in the header and browser title
 - the home page no longer renders a default `Galleries` hero block when no
 gallery is selected
- Added selectable custom CSS skins:
 - the Theme screen lists `.css` files from `custom_css/`
 - selecting a skin copies it to `public/assets/custom.css`
 - uploaded CSS is still supported and can be reset
 - added a new `modern.css` skin with matching active CSS output
- Added an optional picture comparison game for gallery branches:
 - new galleries are opted out by default
 - admins can enable or disable the game from gallery edit pages
 - admins can bulk-enable or bulk-disable selected galleries and their
 subgalleries from the dashboard
 - picture-game controls stay hidden until the required migration is applied
 - stale databases show an admin-only `Run database migration` prompt instead
 of throwing a fatal error
 - public gallery pages show a `Play picture game` button when enough eligible
 public images exist
- Added side-by-side image voting:
 - two pictures are shown at the same visual height
 - visitors choose the picture they prefer by clicking it
 - left and right arrow keys can select the left or right picture
 - the selected picture receives a normal upvote
 - the non-selected picture receives no vote and is not downvoted
- Added per-viewer pair history:
 - image pairs are normalized so A/B and B/A are treated as the same pair
 - a pair is recorded as soon as it is displayed
 - the same viewer does not see the same pair again in that gallery game
 - when all pairs are depleted, the game shows a completion message
- Added global game statistics:
 - the game page shows the top three pictures for the current gallery game
 - stats are global, not per-user
 - top pictures show game wins and normal score

### Data Model

- Added `galleries.picture_game_enabled`
- Added `picture_game_votes` to store pair display history and selected winners
- Picture-game winners also write into the existing `image_votes` table so game
 choices contribute to normal image scores
- Added schema-readiness checks around picture-game admin controls so upgraded
 code can load before the new migration has been applied

### Documentation

- Added the root `PATCH_NOTES.md` file with backwards release history
- Updated README with the picture game workflow, admin opt-in behavior, voting
 rules, pair depletion behavior, statistics, selectable CSS skins, configurable
 site naming, and admin-run migrations
- Updated architecture notes with the new route, pair-history table, and admin
 workflow additions

## Version 0.8

### Major Changes

- Hardened the first-run installation flow:
 - unconfigured browser requests now redirect to `install.php`
 - `install.php` refuses to run after `config.php` exists
 - `install.php` also refuses to run after `cache/installed.lock` exists
 - successful browser installs write `cache/installed.lock`
 - the setup route self-locks when an administrator already exists
- Added admin account management:
 - logged-in admins can update their username
 - logged-in admins can change their password
 - current password verification is required before account changes
 - username uniqueness is validated
 - password confirmation and minimum length are enforced
 - sessions are regenerated after successful account updates
- Improved public thumbnail quality:
 - public gallery cards now use the `800` thumbnail variant
 - public image previews now use the `800` thumbnail variant
 - gallery cover collages now use the `800` thumbnail variant
 - the `300` thumbnail variant is kept for compact admin table previews
- Refined thumbnail administration:
 - dashboard "Create all thumbnails" now uses the AJAX batch workflow
 - thumbnail progress is shown consistently during long-running jobs
 - created and skipped thumbnail counts remain visible during processing
- Added documented custom CSS examples:
 - `custom_css/css_template.css` provides a commented starter template
 - `custom_css/custom.css` provides a compact admin-oriented example
 - the admin theme page can reset uploaded custom CSS

### Admin Workflow

- Added an `Account` navigation link for logged-in admins
- Added `page=admin_account` for authenticated account updates
- Made the admin gallery table denser for large gallery trees:
 - smaller table text
 - tighter cell padding
 - smaller checkboxes
 - smaller tree toggles
 - compact Edit and Thumbs row actions
- Reworked the dashboard "Create all thumbnails" control so it selects all
 galleries and runs the same thumbnail action used by the bulk form
- Kept gallery row and gallery edit thumbnail buttons available as normal form
 submissions when JavaScript is unavailable

### Security

- Prevented accidental installer reuse after setup by treating `config.php` as a
 hard installer lock
- Kept `cache/installed.lock` as a second installer lock signal
- Prevented the setup endpoint from replacing an existing admin account after an
 administrator has already been created
- Preserved the existing CSRF, session, and media visibility protections from
 previous releases

### Theme And Assets

- Added a custom CSS reset action to the Theme admin screen
- Continued loading uploaded custom CSS from `public/assets/custom.css`
- Added cache busting for `public/assets/gallery.js` using the file modified time
- Added `/galleries/` to `.gitignore` so local gallery media is not accidentally
 committed

### Documentation

- Updated `README.md` with the automatic installer lock behavior
- Updated deployment notes to explain that deleting or blocking `install.php` is
 now optional defense in depth
- Updated `ARCHITECTURE.md` to describe the first-run installer lock model
- Updated thumbnail documentation so public `800` thumbnails and admin `300`
 thumbnails are described accurately
- Documented the `custom_css/` example folder and its relationship to uploaded
 `public/assets/custom.css`

## Version 0.7

### Major Changes

- Added a generated thumbnail pipeline:
 - thumbnails are created inside each gallery folder under `thumbs/`
 - generated thumbnails are progressive JPEG files
 - supported sizes are `300` and `800`
 - stale thumbnails are rebuilt only when the source image is newer
 - up-to-date thumbnails are counted as skipped
- Added a protected thumbnail route:
 - `page=thumb&id=...&size=...` streams generated thumbnails
 - thumbnail access uses the same gallery and image visibility checks as media
 - missing thumbnails fall back through the normal media route where applicable
- Added AJAX thumbnail jobs:
 - thumbnail forms progressively enhance to batch requests
 - progress includes total images, processed images, created files, skipped files,
 and completion state
 - import, dashboard, gallery, and image workflows can create thumbnails
- Hardened public and admin requests:
 - CSRF protection was added to voting and admin actions
 - anonymous vote rate limiting was added per image
 - session cookies now use `HttpOnly`, `SameSite=Lax`, and `Secure` on HTTPS
 - global security headers were added
 - media MIME checks use `finfo`
 - media responses include stricter content headers
- Overhauled the admin gallery tree:
 - gallery rows can be collapsed and expanded
 - collapse state is saved in `app_settings`
 - `Collapse all` and `Expand all` controls were added
 - gallery and image select-all checkboxes were added

### Public Gallery Experience

- Public gallery cards now render thumbnail-backed images instead of originals
- Gallery image previews use generated thumbnails
- The lightbox was expanded with:
 - image counter
 - link to the original protected media route
 - keyboard help text
 - integrated voting controls
 - keyboard voting with up and down arrows
- Vote buttons were changed to compact icon-style arrows
- Inline-style tamper detection remains strict so visual changes go through theme
 settings or custom CSS

### Media Pipeline

- Media and thumbnails are served through controlled application routes
- Public media continues to respect gallery and image visibility
- Thumbnail and media responses include caching headers
- ZIP signatures and image scans were updated to work with direct gallery images
 and generated thumbnail folders
- Thumbnail folders are ignored by discovery and scans

### Admin Workflow

- Import can scan images and optionally create thumbnails in one flow
- Bulk gallery actions can create thumbnails
- Gallery edit pages can create thumbnails for all images or selected images
- Admin previews use thumbnails instead of full-size source images
- Gallery tree state persists across admin page reloads

### Documentation

- `README.md` gained detailed thumbnail workflow notes
- `README.md` now lists GD as required for thumbnail creation
- `ARCHITECTURE.md` documents thumbnail routing, AJAX batches, media visibility,
 and admin tree state
- Deployment scripts and notes were updated for the expanded media pipeline

## Version 0.6

### Major Changes

- Added public tag filtering through `page=tag&slug=...`
- Made gallery tags and image tags clickable on public pages
- Included image tags when filtering galleries by tag
- Added inherited `Containing tags` displays:
 - parent galleries aggregate tags from descendant galleries
 - parent galleries also aggregate tags from descendant images
 - top-level gallery cards can expose useful tags even when the top-level folder
 only contains subgalleries
- Added inline-style tamper detection:
 - public JavaScript checks for inline `style` attributes
 - a full-page warning is shown when inline styling is detected
 - theme customization is intentionally routed through theme settings or custom
 CSS

### Tag Workflow

- Tags became navigation controls on public pages
- Public tag pages list galleries connected to a tag through gallery tags or
 image tags
- Gallery cards were restructured so card links and tag links do not create
 invalid nested clickable markup
- Existing tag suggestions remain available in admin tag inputs

### Gallery Hierarchy And UI

- Replaced inline indentation styles in the admin gallery tree with fixed
 `tree-depth-*` classes
- Improved subgallery nesting display without relying on inline CSS
- Continued support for parent galleries and subgallery discovery from earlier
 releases

### Documentation And Code Clarity

- Expanded `README.md` with:
 - tag filtering behavior
 - inherited tag behavior
 - inline-style restrictions
 - naming conventions
 - UI conventions
 - CSS conventions
 - route conventions
 - form conventions
 - documentation expectations
- Expanded `ARCHITECTURE.md` with:
 - tag routes
 - inherited tag aggregation
 - inline-style warning behavior
 - route and request flow notes
- Added docblocks and explanatory comments across PHP, JavaScript, and CSS

## Version 0.5

### Major Changes

- Expanded project documentation substantially in `README.md`
- Documented the application as a plain PHP gallery CMS for shared hosting
- Clarified the supported environment:
 - PHP 8+
 - MySQL or MariaDB
 - PDO MySQL
 - ZipArchive
 - image metadata support through `getimagesize`
 - Apache `.htaccess` support as recommended but not mandatory

### Setup Documentation

- Added clearer manual setup steps:
 - copying `config.example.php`
 - editing database and path settings
 - creating the database
 - running migrations
 - creating the first admin user
- Documented the browser setup route for shared hosting without shell access
- Added local PHP built-in server instructions for root and `public/` web roots

### Usage Documentation

- Documented the gallery folder workflow
- Documented explicit gallery discovery from the admin area
- Documented admin scan and edit steps
- Documented query-string routes for environments without pretty URLs
- Documented ZIP download behavior and cache signatures
- Documented public voting behavior
- Documented FTP deployment and post-upload setup
- Added security notes for protected directories, media routing, escaping, SQL,
 CSRF, and password hashing

## Version 0.4

### Major Changes

- Added the broader plain-PHP application core:
 - routing
 - PDO database access
 - sessions
 - CSRF protection
 - migration runner
 - controller and service layers
- Added a standalone browser installer capable of:
 - creating the database
 - creating or updating the database user
 - writing `config.php`
 - creating writable folders
 - running migrations
 - creating the first admin account
- Added filesystem-backed gallery management:
 - gallery discovery from `galleries_root`
 - imports from filesystem folders
 - image scans
 - nested subgallery support
 - `gallery.json` sidecar metadata
 - ZIP download caching

### Admin Features

- Added an admin dashboard with gallery import and management actions
- Added bulk gallery and image actions
- Added editable gallery metadata:
 - title
 - description
 - slug
 - visibility
 - sort order
 - parent gallery
 - cover image
- Added editable image metadata:
 - title
 - description
 - visibility
 - sort order
- Added tags for galleries and images
- Added tag suggestions in admin forms
- Added theme settings:
 - accent colors
 - page and panel backgrounds
 - corner radius
 - font mode
 - custom CSS upload

### Public Features

- Added public gallery cards
- Added breadcrumb navigation
- Added subgallery display
- Added gallery cover images and cover collages
- Added lightbox browsing with keyboard navigation
- Added public image voting
- Added ZIP downloads for single public galleries
- Added admin download of all imported galleries

### Deployment And Documentation

- Added Apache rewrite and protection files for root, public, cache, and gallery
 directories
- Added FTP and local deployment scripts
- Added deployment exclusions for local-only files
- Expanded `README.md` with release highlights, setup, workflow, deployment, and
 security guidance
- Added `ARCHITECTURE.md` covering request flow, web-root layouts, migrations,
 filesystem rules, and protected directories

## Version 0.3

### Major Changes

- Added first-class nested subgallery behavior:
 - homepage lists only top-level public galleries
 - gallery pages can show direct child subgalleries
 - breadcrumbs show the path through parent galleries
 - parent relationships are synchronized from filesystem paths
- Added gallery title pictures:
 - galleries can choose an explicit cover image
 - galleries can automatically use the first direct image as a cover
 - parent galleries without direct images can show a collage from child gallery
 covers
- Changed scans to import direct images for each gallery instead of recursively
 importing every descendant image into the parent gallery

### Admin Workflow

- Added parent gallery selection on gallery edit pages
- Added title picture selection on gallery edit pages
- Added bulk gallery visibility actions
- Added bulk image visibility actions
- Added bulk action to set an image as the gallery title picture
- Added image previews to the admin gallery edit table
- Added per-gallery scan/import form on gallery edit pages
- Improved dashboard table with parent gallery and folder hierarchy information

### Filesystem And Import Behavior

- Discovery now detects folders that contain descendant images, allowing empty
 parent folders to become gallery records
- Parent galleries are imported before deeper child galleries
- Parent IDs are synchronized after imports
- ZIP creation now includes only direct images owned by each gallery
- Gallery ZIP and all-gallery ZIP signatures ignore descendant images that belong
 to child galleries
- `gallery.json` sidecars can persist cover image paths

### Public UI

- Gallery cards gained image covers and child-cover collages
- Gallery detail pages gained subgallery sections
- Breadcrumb navigation was added to gallery pages
- Public image grids show only direct images from the current gallery

## Version 0.2

### Major Changes

- Added the browser installer as a standalone setup path:
 - database creation
 - database user creation or update
 - `config.php` writing
 - migration execution
 - writable folder creation
 - first admin account creation
- Added database port support:
 - `config.example.php` includes a `database.port` value
 - the PDO DSN appends the configured port when present
- Improved MySQL compatibility:
 - installer supports server default authentication
 - installer supports `caching_sha2_password`
 - installer supports `mysql_native_password`
 - README explains how to handle missing `mysql_native_password`

### Deployment

- Added local deploy folder mode to the PowerShell deployment script
- Kept FTP upload mode available
- Added explicit deployment mode support through `deploy.bat`
- Improved deploy exclusions:
 - `.git`
 - `config.php`
 - cache folders
 - logs
 - temporary files
 - optional gallery media
- Preserved required `.htaccess` files for protected cache and gallery folders

### Documentation

- Added browser installer setup instructions
- Added local Laragon and shared-hosting oriented setup notes
- Added deployment mode examples:
 - `deploy.bat -Mode local`
 - `deploy.bat -Mode ftp`
- Clarified post-upload setup steps

## Version 0.1

### Initial Release

- Added the initial PHP Gallery CMS application structure:
 - root `index.php`
 - `public/index.php`
 - `app/` PHP application files
 - `database/migrations/`
 - `public/assets/`
 - `scripts/`
 - cache and gallery protection files
- Added core routing through `page=...` query-string routes
- Added Apache rewrite support through `.htaccess`
- Added database configuration through `config.example.php`
- Added migration support and initial schema
- Added CLI helpers:
 - `scripts/migrate.php`
 - `scripts/create_admin.php`
 - `scripts/deploy.ps1`

### Gallery Features

- Added filesystem-backed gallery discovery
- Added gallery import and image scanning
- Added gallery metadata storage in the database
- Added image metadata storage in the database
- Added public gallery listings
- Added public gallery detail pages
- Added protected media streaming through the application
- Added public image lightbox browsing
- Added public image voting
- Added ZIP download support with cache records

### Admin Features

- Added admin login and logout
- Added admin dashboard
- Added gallery edit pages
- Added image edit pages
- Added gallery visibility controls
- Added image visibility controls
- Added sort order fields
- Added manual setup route protected by `setup_key`
- Added CSRF helpers for admin forms

### Security And Deployment

- Added password hashing with `password_hash`
- Added prepared-statement based database access with PDO
- Added output escaping helpers
- Added path normalization and path-inside-root checks for gallery files
- Added protection guidance for `config.php`, application folders, cache folders,
 and gallery folders
- Added initial README with setup, workflow, routing, voting, ZIP, deployment, and
 security notes
