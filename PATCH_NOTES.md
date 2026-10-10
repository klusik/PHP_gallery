# Patch notes

## Version 0.126.2

Version 0.126.2 added idempotent preparation of an unpublished GitHub Release draft after the verified protected merge and immutable tag. Maintainers remained responsible for uploading assets and publishing the release.

### Highlights

#### Automatic release draft preparation [#101](https://github.com/klusik/PHP_gallery/issues/101) [#133](https://github.com/klusik/PHP_gallery/issues/133)
- Staged an unpublished GitHub Release draft using the exact-version notes from the qualified candidate after verifying the protected merge and immutable tag.
- Left existing drafts and published releases unchanged, rejected conflicts, and reported `TAGGED / DRAFT_PENDING` on staging failure without rolling back the verified merge or tag.
- Kept asset uploads and publication as manual maintainer actions.

### Technical Details

#### Backend
- Updated `.github/scripts/release-completion.mjs` to check the complete paginated release inventory and stage drafts idempotently.
- Added immutable origin and predecessor records for `v_0.126.2`.

#### Frontend
- Updated `.github/workflows/release-promotion.yml` to use the revised release completion flow.

#### Tests
- Added `tests/release_draft_test.mjs` coverage for exact candidate notes, pagination, conflicts, preserving existing draft edits and assets, publication safety, and recovery after a lost create response.

#### Documentation
- Updated `AGENTS.md`, `RELEASE.md`, `docs/RELEASE_LIFECYCLE.md`, and all four maintained manual editions with the draft preparation behavior.

### User Impact

#### For visitors
- Public gallery behavior did not change.

#### For administrators
- Administrator-facing gallery behavior did not change.

#### For maintainers
- Maintainers gained automatic, retry-safe staging of an unpublished release draft after the verified merge and tag, while retaining responsibility for uploading assets and publishing it.

## Version 0.126.1

Version 0.126.1 simplified the reviewed release lifecycle with maintainer-pushed release branches, exact-candidate qualification, and automated completion after one owner approval. GitHub Release publication remained manual, and this release did not establish that a production release was approved or published.

### Highlights

#### Reviewed release lifecycle [#101](https://github.com/klusik/PHP_gallery/issues/101) [#133](https://github.com/klusik/PHP_gallery/issues/133)
- Started release preparation from maintainer-pushed `release/v_X.Y[.Z]` branches and recorded immutable origins and predecessor evidence.
- Automated exact-candidate pull request completion after one owner approval, with guarded merge, immutable tagging, and a non-forced `develop` fast-forward when safe.
- Kept GitHub Release publication manual and blocked `develop` synchronization when parallel work prevented a fast-forward.

#### Exact-candidate qualification
- Bound qualification, prepared assets, and completion to the exact candidate commit and comparison base.
- Added integrity checks for production and evidence assets, all four manual PDFs, and checksum records.
- Removed the separate manual start-new-release and release-reconciliation workflows.

### Technical Details

#### Backend
- Added release readiness, completion handoff, completion, and asset-integrity logic under `.github/scripts/`.
- Updated origin, owner-authorization, promotion, reconciliation, and retirement logic to validate release identity and preserve guarded, non-forced operations.
- Added immutable origin and predecessor records for `v_0.126.1`.

#### Frontend
- Updated GitHub Actions workflows for release initialization, exact-candidate qualification, automatic completion, and manual publication assets.
- Kept the Windows installer build conditional on changes to shipped WinApp content.

#### Database
- Added no database migration.

#### Tests
- Added coverage for release asset integrity and completion handoff and expanded lifecycle tests for qualification, approval, retries, merge identity, tagging, and safe `develop` synchronization.
- Added a disposable Git and synthetic GitHub API fixture; these tests did not establish live protected-write acceptance.
- Recorded live feature-branch dispatch probes as successful; protected approval, merge, tagging, and fast-forward acceptance remained unrun.

#### Documentation
- Updated release lifecycle, qualification, testing, architecture, contributor, and agent guidance, including all four maintained manual sources.

### User Impact

#### For visitors
- Public gallery behavior did not change.

#### For administrators
- Administrator-facing gallery behavior did not change.

#### For maintainers
- Maintainers gained a documented release path from a pushed release branch through exact-candidate qualification and one-owner-approval completion. They retained responsibility for publishing the GitHub Release; protected-write acceptance was not performed.

## Version 0.126

Version 0.126 established an immutable, owner-reviewed release lifecycle with exact-SHA hosted qualification, guarded promotion, and optional post-publication reconciliation and branch retirement. It also added release initialization and expanded documentation and regression coverage; these changes did not themselves establish that a release was approved or published.

### Highlights

#### Guarded release lifecycle [#101](https://github.com/klusik/PHP_gallery/issues/101) [#133](https://github.com/klusik/PHP_gallery/issues/133)
- Bound releases to checked-in immutable origins and verified predecessor provenance, including the preserved `v_0.126` origin and an explicitly unqualified legacy record for `v_0.125`.
- Required exact-candidate hosted qualification and owner review before promotion; blocked automatic merging and red-check overrides.
- Added separate, owner-controlled workflows for release initialization, post-publication reconciliation, and optional release-branch retirement.

#### Hosted release qualification [#101](https://github.com/klusik/PHP_gallery/issues/101)
- Added pre-generation source and MVC/mutation-contract checks, then retained exact prepared-candidate qualification through the hosted release matrix.
- Bound initial release metadata to the source commit timestamp and preserved validated existing timestamps for deterministic preparation.

### Technical Details

#### Backend
- Added release-origin validation, owner-authorization, reconciliation, retirement, and new-release initialization logic under `.github/scripts/`.
- Updated promotion to verify origin and predecessor evidence, candidate and CI identities, owner approval, current `main` state, and expected merge parents and tree before publication.
- Added guarded reconciliation for a verified fast-forward or reviewed single-parent equivalent; the workflow prepared evidence but did not update protected refs.
- Kept release ruleset examples as documentation; they did not activate GitHub protections or change server settings.

#### Frontend
- Updated hosted workflows for staged release qualification, guarded promotion, reconciliation, retirement, and new-release initialization.
- Added SHA-bound aggregate required-CI check runs and configured isolated gallery workflow CI to run on `develop`.

#### Database
- Added no database migration.

#### Tests
- Added regression fixtures for immutable origins and predecessor identity, release graph and reconciliation, promotion safeguards, initialization races, timestamp determinism, and leased branch retirement.
- Extended hosted-policy and audit-runner coverage; fixtures modeled GitHub and Git behavior and did not establish live release approval or qualification.

#### Documentation
- Added `docs/RELEASE_LIFECYCLE.md` and updated release, qualification, testing, contributor, architecture, and agent guidance.
- Synchronized the lifecycle and hosted-verification guidance across all four maintained manual sources.

### User Impact

#### For visitors
- Public gallery behavior and visitor-facing features did not change.

#### For administrators
- Administrator-facing gallery behavior did not change.

#### For maintainers
- Maintainers gained documented, guarded workflows for qualifying and promoting releases, reconciling published changes, initializing future releases, and optionally retiring release branches. Actual approval, publication, and live GitHub policy acceptance remained separate steps.

## Version 0.125

Version 0.125 added advanced public appearance controls, a protected visual CSS editing workflow, and a local upload-preview queue in Admin. It also strengthened hosted candidate qualification and guarded release promotion, with updated documentation and regression coverage.

### Highlights

#### Theme appearance and visual CSS editing [#125](https://github.com/klusik/PHP_gallery/issues/125)
- Added controls for public gallery spacing, card padding and shadows, typography scale, transparent headers, and individual resets.
- Added a visual CSS workspace with responsive public-page previews, element selection and styling, resizing, and undo/redo. Visual edits remained drafts until explicitly saved.
- Added a separate manual CSS override layer and revision-checked, atomic save workflows for CSS and supported global background changes.

#### Admin upload previews [#118](https://github.com/klusik/PHP_gallery/issues/118)
- Added an ordered, browser-local upload queue to the Admin upload drawer for selected, pasted, and dropped files, with preview, reordering, removal, and duplicate-name warnings.
- Kept uploaded originals unchanged and preserved unresolved selections for retry; preview decoding used bounded resources and did not replace server-side validation.

#### Hosted qualification and release promotion [#100](https://github.com/klusik/PHP_gallery/issues/100) [#124](https://github.com/klusik/PHP_gallery/issues/124)
- Strengthened candidate qualification and added protected, maintainer-reviewed release promotion and publication workflows.

#### OFP viewer navigation [#119](https://github.com/klusik/PHP_gallery/issues/119)
- Added paced held-arrow-key PDF paging and stopped repeat navigation when viewer focus or lifecycle state changed.

### Technical Details

#### Backend
- Added protected visual-preview policy and rendering support in `app/services/public_visual_preview.php`; preview requests were restricted to approved read-only routes and used private, no-store responses.
- Added revision-checked Custom CSS and background-save workflows, with locking and atomic activation. Installation-specific override and lock files were excluded from updater and release ownership.
- Updated Theme controllers and services to support advanced appearance settings, preview handling, and separate CSS submissions.

#### Frontend
- Added the visual editor and its draft, import, resize, and customization modules, plus Admin editor styling and localized interface text in English, Czech, German, and Swedish.
- Updated the Admin upload drawer with local preview and ordered-queue modules while retaining the existing upload pipeline.
- Loaded manual CSS overrides after public styles and omitted them from Admin pages.

#### Database
- Added no database migration; the CSS override and visual editing workflows used the existing settings and filesystem mechanisms.

#### Tests
- Added regression coverage for visual-preview policy and HTTP workflows, CSS/background save and recovery, advanced appearance rendering, visual-editor draft and resize models, and browser interactions.
- Added upload queue and thumbnail-preview tests, and expanded OFP viewer keyboard-navigation coverage.
- Added hosted release-policy tests for qualification and publication safeguards.

#### Documentation
- Updated maintainer, testing, release, and architecture documentation, added `docs/LIVE_VISUAL_CSS_EDITOR.md` and `docs/UPLOAD_SELECTION.md`, and synchronized all four maintained manual sources.

### User Impact

#### For visitors
- Visitors saw public appearance settings and saved CSS/background changes selected by administrators; the visual editing workspace itself remained protected.
- Public visitor access behavior did not change as a result of the visual preview workflow.

#### For administrators
- Administrators gained additional public appearance controls, a draft-based visual CSS editor, and a separate manual CSS override layer that ordinary Theme changes preserved.
- Admin upload drawers provided local previews and queue management, while retaining selected originals for upload and retry.

#### For maintainers
- Maintainers gained stricter hosted candidate qualification and guarded release-promotion tooling, plus updated workflow documentation and regression coverage.

## Version 0.124.4

Version 0.124.4 hardened first-install migration behavior and tightened repository authoring and audit evidence. It resolved installer lock handling before `config.php` exists and expanded the checked authoring guidance, audit reporting, and regression coverage for config-free installation and source diagnostics.

### Highlights

#### Installer setup safety [#123](https://github.com/klusik/PHP_gallery/issues/123)
- Fixed the installer and migration runner to use the migration PDO for canonical gallery-writer lock acquisition and release before `config.php` exists.
- Kept configured upgrade roots, replay checkpoints, and fail-closed locking behavior intact while refusing to proceed when lock ownership could not be verified.
- Covered config-free HTTP installation, CSRF refusal, lock contention and retry, migration recording, administrator creation, fresh layout defaults, lock release, and post-install HTTP 403 in the installer regression test.

#### Authoring and audit enforcement [#121](https://github.com/klusik/PHP_gallery/issues/121)
- Added or expanded checked authoring guidance for declaration documentation, typing, structured-shape contracts, runtime-policy explanations, and source-feedback workflows.
- Updated the central audit to record source HEAD, comparison base, and complete failure evidence, including changed-source checks and inventory budgets.
- Refreshed contributor and maintainer documentation and the four manual editions to describe the updated authoring and diagnostics workflow.

### Technical Details

#### Backend
- Updated the installer and migration lock flow in `app/models/gallery_edit_concurrency.php` and `app/services/gallery_description_layout_compatibility.php` so layout migration storage and lock handling work before configuration exists.
- Preserved the configured upgrade root and fail-closed behavior when the lock cannot be acquired or ownership cannot be verified.
- Kept migration callbacks pending for retry without introducing a new migration.

#### Database
- Reused the existing migration runner and ledger flow rather than adding a new schema change; the fix focused on lock ownership and migration completion semantics.
- Confirmed the installer handles migration recording and retry safely during first setup and busy-lock recovery.

#### Tests
- Added `tests/installer_first_install_test.php` for config-free HTTP installation, CSRF refusal, connection-only setup, writer-lock contention and retry, migration-ledger completion, administrator password verification, fresh layout defaults, lock release, and post-install HTTP 403.
- Updated `tests/audit_runner_test.php`, `tests/agent_authoring_examples_test.php`, and related compatibility/audit coverage for the new source-evidence and authoring-contract behavior.
- Included a real HTTP installation regression in the database workflow qualification path and marked it as a required non-quick workflow check.

#### Documentation
- Updated `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `CONTRIBUTING.md`, `DATABASE.md`, `README.md`, `TESTING.md`, `docs/COMPATIBILITY_LIFECYCLE.md`, `docs/GALLERY_EDIT_CONCURRENCY.md`, and all four maintained PHP Gallery manual sources.
- Added `docs/AGENT_AUTHORING.md` and `scripts/audit_source_evidence.php` to document the checked authoring contracts and preserve complete source-failure evidence.
- Refreshed generated candidate artifacts in `app/core-manifest.json` and `app/production-files.json`.

### User Impact

#### For visitors
- Public visitor behavior did not change; this release addressed installation reliability and maintainer workflow enforcement rather than public gallery rendering or visitor-facing features.

#### For administrators
- Administrators could complete a first installation without an existing `config.php`, while the installer enforced CSRF refusal, lock contention handling, migration recording, and validation of created administrator credentials.
- Busy lock conflicts now failed closed and could be retried without leaving the migration state ambiguous, and the install completed with protected defaults and lock release behavior consistent with the configured setup path.

#### For maintainers
- Maintainers gained clearer authoring contracts, stronger audited source feedback, and more explicit evidence retention for source checks and workflow failures.
- The repository documentation and manual set now describe the updated installation, authoring, and audit expectations for future changes.

## Version 0.124.3

Version 0.124.3 added clipboard image pasting to the existing gallery upload workflow, with localized instructions and regression coverage for upload selection and side-panel behavior.

### Highlights

#### Clipboard image uploads
- Added support for pasting clipboard images or screenshots into the focused or most recently used visible gallery uploader with Ctrl+V or Cmd+V.
- Added pasted images to the existing file selection; pasting alone did not submit the upload.
- Kept upload targeting predictable: an open side panel took precedence over background upload forms, and text editors retained normal paste behavior.
- Preserved existing filenames and assigned unique, MIME-matched filenames to images without names.
- Kept file chooser and drag-and-drop uploads available when clipboard image access or writable file selection was unavailable.

### Technical Details

#### Frontend
- Added `public/assets/gallery-modules/admin-upload-selection.js` and updated `app/views/admin_uploads.php` and the Admin JavaScript modules to support clipboard selection.
- Added localized clipboard instructions and status messages in `app/lang/en.json`, `app/lang/cs.json`, `app/lang/de.json`, and `app/lang/sv.json`.
- Kept clipboard filtering aligned with picker rules; HEIC, HEIF, and DNG required an explicit server-capability hint. Unsupported content, hidden or disabled controls, and paste during an upload were ignored.
- Preserved side-panel completion behavior so uploads kept the panel open and the browser URL unchanged.

#### Tests
- Added `tests/admin_upload_clipboard_test.mjs`, `tests/admin_upload_clipboard_browser_test.mjs`, and `tests/fixtures/admin_upload_clipboard.html` for clipboard filtering, filenames, multiple images, upload integration, dynamic controls, errors, and panel persistence.
- Updated related side-panel lifecycle and operational-policy regression coverage.
- Documented a manual browser and operating-system test matrix; automated browser fixtures used synthetic paste events and did not verify operating-system clipboard delivery.

#### Documentation
- Updated `README.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `TESTING.md`, `docs/COMPATIBILITY_LIFECYCLE.md`, and all four maintained PHP Gallery manual sources.
- Refreshed the prepared runtime inventory and integrity manifest artifacts.

### User Impact

#### For visitors
- Public visitor behavior did not change; clipboard image pasting applied to administrator gallery uploads.

#### For administrators
- Administrators could paste screenshots and other supported clipboard images directly into an uploader and then submit them through the existing classic or browser-prepared upload workflow.
- Upload chooser and drag-and-drop alternatives remained available, and side-panel uploads completed without closing the panel or changing the URL.

#### For maintainers
- Maintainers received automated coverage for clipboard selection and upload integration, plus documented manual checks for browser and operating-system clipboard behavior.

## Version 0.124.2

Version 0.124.2 hardened gallery media, upload integrity, migration safety, and the OFP viewer after the v0.124.1 release. The work focused on filesystem containment, archive validation, trusted HTTPS/proxy handling, and stricter authorization for Theme and gallery assets.

### Highlights

#### Security and asset hardening
- Hardened gallery media and branding responses against path traversal, symlink escapes, and unsupported raster restrictions, including protected gallery branding and cached Theme background responses.
- Restricted administrator-only access to retained original Theme background previews and enforced supported raster MIME types for gallery covers and Theme branding.
- Tightened gallery cover and thumbnail resolution to the owning gallery scope and rejected unsafe symlink-containing paths without blocking normal cache generation. [#116](https://github.com/klusik/PHP_gallery/issues/116)

#### Upload and migration safety
- Added ZIP integrity validation for browser-prepared uploads, rejecting duplicate canonical paths, incomplete central-directory records, CRC mismatches, unsupported flags, and trailing archive data before ingestion.
- Hardened migration asset staging with unique temporary files, byte-count and optional SHA-256 verification, conflict rejection, and idempotent retry behavior while preserving existing files on refusal.
- Staged Theme replacements before removing prior files and removed incomplete staging artifacts on failure.

#### HTTPS and OFP viewer
- Unified HTTPS detection across Admin cookies, public URL generation, and Viewer authentication behind explicit trusted proxy and forwarded-header policy checks, with malformed or conflicting forwarded values rejected.
- Rejected unsafe login-return redirect targets and preserved safe local navigation.
- Corrected OFP fit/reset behavior after manual pan or zoom by clearing manual pan gutters before recalculating automatic fit.

### Technical Details

#### Backend
- Updated `app/services/gallery_covers.php`, `app/services/gallery_branding.php`, `app/services/gallery_backgrounds.php`, `app/services/thumbnail_sources.php`, `app/services/gallery_paths.php`, and `app/services/gallery_migration/install.php` to validate canonical filesystem scope, reject symlink escapes, and preserve private-cache semantics for protected gallery media.
- Updated `app/services/browser_uploads/zip_parsing.php`, `app/controllers/public_media.php`, `app/controllers/theme_assets.php`, and `app/helpers_request.php` to enforce archive integrity, safe return targets, and HTTPS/proxy policy.
- Updated `app/services/client_ip.php` and request-aware URL/cookie logic to require explicit trusted proxy scope and reject forged or conflicting forwarded protocol headers.
- Added or updated regression coverage in `tests/browser_upload_zip_parser_safety_test.php`, `tests/gallery_cover_asset_safety_test.php`, `tests/gallery_migration_asset_containment_test.php`, `tests/login_return_target_security_test.php`, `tests/request_https_proxy_test.php`, and related theme and thumbnail containment suites.

#### Frontend
- Updated `public/assets/gallery-modules/simbrief-ofp-viewer.js`, `public/assets/gallery.js`, and `public/assets/public-gallery.js` to recalculate automatic OFP fit correctly after manual zoom/pan and keep the refreshed browser fixture aligned with the queued request.
- Kept the viewer changes scoped to the existing gallery viewer behavior; no new public feature or UI flow was introduced.

#### Documentation
- Synced `README.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `TESTING.md`, `docs/COMPATIBILITY_LIFECYCLE.md`, and all four maintained `docs/PHP_Gallery_Manual*.tex` sources with the supported proxy, Theme asset, and media containment behavior.
- Retained the reviewed generated runtime module plan, production inventory, and final integrity manifest in `app/runtime/modules.php`, `app/production-files.json`, and `app/core-manifest.json`.

#### Tests
- Expanded the audit and browser regression coverage for ZIP integrity, filesystem path containment, migration atomic-copy safety, HTTPS/trusted-proxy policy, login return-target handling, Theme replacement safety, and OFP fit/refit after manual zoom. These tests defined the guardrails for the release and did not establish a new public feature.

### User Impact

#### For visitors
- Public visitors were protected from unsafe gallery covers, branding, and protected-media responses that escaped the owning gallery or used unsupported MIME types.
- Public visitor behavior did not add a new product feature; the work focused on preventing unsafe behavior and correcting existing gallery media and OFP viewing behavior.

#### For administrators
- Administrators saw tighter controls on Theme background previews, Theme replacement safety, and HTTPS handling for proxy-terminated deployments, with malformed or conflicting forwarded protocol values rejected instead of being trusted.
- Admin login redirects now rejected unsafe targets and retained safe local navigation, preserving a safer return flow after authentication.
- Administrative behavior did not gain a new interface; the release improved safety and correctness of existing admin workflows.

#### For maintainers
- Maintainers received stronger regression coverage and clearer safeguards for archive parsing, symlink and path containment, migration safety, Theme replacement, HTTPS trust configuration, and OFP viewer fit behavior.
- The release also kept the reviewed generated runtime module plan and release-manifest artifacts synchronized with the source changes.

## Version 0.124.1

Version 0.124.1 strengthened release-note qualification and audit diagnostics. It required complete, correctly structured release notes and retained rejected AI-generated prose for maintainers to diagnose failures.

### Highlights

#### Release-note qualification

- Required the exact `### Highlights`, `### Technical Details`, and `### User Impact` sections, including for test-, documentation-, and tooling-only releases.
- Rejected missing, renamed, inline, or incorrectly leveled required headings, and required user impact to be evidence-based and explicit when visitor or administrator behavior did not change.
- Retained incomplete or invalid AI-generated notes as a failure-only Actions artifact for seven days; invalid notes continued to block release preparation and were not applied.

### Technical Details

#### Release tooling

- Updated `.github/scripts/patch_notes_ai.php`, `.github/workflows/release-qualification.yml`, `PATCH_NOTES_TEMPLATE.md`, and `RELEASE.md` to enforce complete release notes and preserve rejected prose for failure diagnosis.

#### Audit diagnostics

- Updated `scripts/audit.php` and `scripts/audit_lib.php` to print up to five bounded audit failure details, with a suite-log pointer when available.
- Added specific assertion or runtime errors to Node fixture diagnostics.

#### Tests

- Expanded `tests/audit_runner_test.php`, `tests/patch_notes_ai_chunks_test.php`, and `tests/patch_notes_ai_evidence_test.php` to cover audit reporting and release-note requirements.
- Updated `tests/admin_panel_lifecycle_browser_test.mjs` and its fixture to report bounded Chromium stderr and page exceptions, and the last assertion progress on timeout.
- Extended the Admin panel lifecycle fixture to check SimBrief review dismissal across viewport sizes and after editor replacement, including URL, drawer, focus, background isolation, and dispatch-submission assertions. These changes expanded test coverage and did not establish a product behavior change.

#### Documentation

- Updated `TESTING.md` and all four maintained `docs/PHP_Gallery_Manual*.tex` editions with the corresponding testing and release-qualification guidance.

### User Impact

#### For visitors

- Public visitor behavior did not change.

#### For administrators

- Administrator-facing behavior did not change.

#### For maintainers

- Received clearer, bounded audit failure details and more actionable browser-test diagnostics.
- Could inspect retained AI-generated release-note prose when qualification failed, while incomplete or invalid notes remained unapplied.

## Version 0.124

Version 0.124 completed the legacy-gallery SimBrief OFP workflow, adding a reviewable dispatch prefill and a guarded per-gallery PDF attachment flow for administrators. The update also tightened validation, localized the workflow across English, Czech, German and Swedish, and expanded regression coverage for historical dispatch data, drawer focus behavior, and responsive Admin layouts.

### Highlights

#### Legacy gallery SimBrief OFP workflow [#104](https://github.com/klusik/PHP_gallery/issues/104)

- Added a reviewed dispatch prefill for legacy galleries that let administrators edit transient flight details before opening the official SimBrief redirect in a protected tab.
- Added per-gallery PDF attachment handling with explicit provenance selection, upload validation, and confirmed replacement for existing attachments.
- Kept gallery metadata, photos, routes and saved SimBrief JSON unchanged while the upload was staged and rolled back on failure.
- Updated Admin drawer and mobile layouts so the nested review modal stayed inside the active drawer and preserved Tab and Escape behavior without navigation.

### Technical Details

#### Backend

- Extended `app/services/simbrief_ofp_attachments.php` to validate saved SimBrief data, normalize legacy cruise levels to `FLxxx`, reject stale gallery submissions, and preserve the last working attachment when an install failed.
- Updated `app/controllers/admin_galleries_edit_page/post_actions.php` and `app/controllers/admin_galleries_edit_page/tab_tools.php` to expose the review, upload, replacement and provenance workflow in the Admin API tab.
- Indexed attachment provenance and timestamps in the canonical manifest while keeping the source gallery access and PDF validation checks aligned with the documented public HTTP behavior.
- Added the corresponding localized strings in `app/lang/en.json`, `app/lang/cs.json`, `app/lang/de.json` and `app/lang/sv.json`.

#### Frontend

- Added `public/assets/gallery-modules/admin-simbrief-description.js` and updated `public/assets/gallery.js` and `public/assets/styles/admin-gallery-api.css` for the review dialog, responsive date/layout handling, and drawer-safe key isolation.
- Updated `app/views/admin_gallery_edit_tabs.php` to show the canonical legacy dispatch and PDF actions in the Admin gallery editor.
- Documented the revised workflow in `docs/SIMBRIEF_OFP.md` and the four maintained manual editions under `docs/`.

#### Tests

- Added `tests/simbrief_legacy_dispatch_browser_test.mjs` for historical dispatch redirect encoding, validation and gallery isolation.
- Expanded `tests/simbrief_ofp_document_test.php` to cover legacy dispatch prefill, normalized saved dates, PDF validation, provenance and confirmed replacement.
- Updated the Admin panel lifecycle checks and documentation in `TESTING.md` to cover drawer focus behavior and responsive date layout.

### User Impact

#### For visitors

- Continued to rely on the source gallery’s visitor-access rules for saved OFP PDFs, and invalid, missing or rejected attachments returned the same no-store plain-text 404 behavior as the existing public route.
- Kept the review and upload workflow restricted to administrators; public HTTP coverage exercised access gates, admin preview isolation, and root/subdirectory URL handling.

#### For administrators

- Reviewed and edited transient historical SimBrief dispatch fields before redirecting to the official SimBrief site, without persisting gallery data during the review.
- Uploaded or replaced gallery-scoped OFP PDFs with explicit provenance confirmation, while preserving the last valid attachment if installation failed.
- Used the localized review, upload and validation flow across the supported Admin languages, and followed the updated documentation for legacy dispatch and PDF attachment behavior.

## Version 0.123

Version 0.123 added a self-hosted viewer for saved SimBrief OFP PDFs, improved photo-lightbox gestures, and strengthened candidate preparation and release-note evidence handling. Administrators gained an optional way to convert OFP pages into a private child gallery; visitors could view or download saved PDFs subject to the source gallery’s access rules.

### Highlights

#### SimBrief OFP documents [#103](https://github.com/klusik/PHP_gallery/issues/103)

- Added separate view and download actions for saved OFP PDFs, including on galleries without photos.
- Added a PDF viewer with page navigation, whole-page, fit-width and actual-size modes, zoom, fullscreen and download controls.
- Added an optional administrator conversion workflow that created a private child gallery from PDF pages; existing OFP subgalleries remained unchanged, and administrators could publish generated galleries separately.
- Limited conversion to 40 pages, 9 million pixels per page, 64 MiB of JPEG output and 60 seconds.

#### Photo lightbox [#111](https://github.com/klusik/PHP_gallery/issues/111) [#112](https://github.com/klusik/PHP_gallery/issues/112) [#113](https://github.com/klusik/PHP_gallery/issues/113)

- Updated enlarged-photo gestures so ordinary wheel and trackpad scrolling panned, while Ctrl+wheel and trackpad pinch zoomed.
- Added Safari gesture-event support and mobile touch handoff between pinch and one-finger pan.

#### Candidate preparation and release evidence [#106](https://github.com/klusik/PHP_gallery/issues/106)

- Added hosted candidate preparation and qualification for feature and fix branches, with generated artifacts prepared on the pushed revision and qualification run against the exact published candidate SHA.
- Updated release-note generation to process complete long diffs through bounded, verified evidence stages rather than truncating source input.

### Technical Details

#### Backend

- Added the `gallery_ofp_pdf` route and shared SimBrief OFP attachment and conversion services.
- Served saved PDFs from same-origin URLs that supported subdirectory installations without URL rewriting; retained compatibility with `media&ofp=1` links.
- Applied source-gallery visitor access rules to PDF delivery. Denied, missing and invalid PDFs returned the same no-store plain-text 404.
- Limited SimBrief PDF downloads to 25 MiB, required HTTPS and successful non-redirect responses, and staged downloads before replacement so a failed download preserved the last saved attachment.

#### Frontend

- Added the self-hosted PDF.js viewer and responsive document controls, with fullscreen, zoom and page-display modes.
- Added localized OFP viewing, download and conversion controls in English, Czech, German and Swedish.
- Updated lightbox styles and input handling for wheel, trackpad and touch interactions.

#### Tests

- Added HTTP and browser regressions for OFP delivery, viewer interactions, access grants, root and subdirectory mounts, and legacy URL compatibility.
- Added conversion and attachment tests, including private subgallery behavior and preservation of the last saved attachment.
- Added release-evidence tests for bounded diff slicing, evidence reduction, Git output limits and large diffs.
- Added candidate-preparation tests for complete hashing, idempotence and stale-input refusal.

### User Impact

#### For visitors

- Viewed or downloaded saved SimBrief OFP PDFs directly from gallery pages, including galleries without photos.
- Used PDF-specific page fitting, zoom, navigation and fullscreen controls without interfering with photo navigation.
- Continued to use supported legacy PDF links; access restrictions followed the source gallery’s visitor-access rules.

#### For administrators

- Optionally converted saved OFPs into private child galleries without replacing existing OFP subgalleries.
- Used updated contributor and maintainer guidance for candidate preparation, exact-SHA qualification and bounded release-note evidence processing.

## Version 0.122.1

Version 0.122.1 updated Admin patch-note rendering to show safe, clickable HTTP(S) links and deferred manual PDF builds during ordinary development. Patch-note history now stays as raw Markdown until the Admin view renders it, including history from older caches.

### Highlights

#### Admin patch notes

- Rendered Markdown references, autolinks, and plain HTTP(S) URLs as links that open in a new tab with `noopener noreferrer`.
- Preserved code spans, URL bytes, emphasis, and existing headings, lists, and fenced-code presentation; rejected unsafe and non-HTTP(S) links as active links.
- Re-rendered cached history from Markdown, discarding obsolete cached HTML during passive reads without requiring a network refresh or cache deletion.

#### Manual build workflow

- Kept all four manual source editions aligned while allowing tracked PDFs to lag during ordinary development.
- Moved the manual PDF batch build to final hosted release preparation.

### Technical Details

#### Backend

- Updated `app/services/updates_patch_notes.php` to parse and provide raw Markdown instead of generated HTML.
- Removed obsolete cached HTML from version history returned to the viewer.

#### Frontend

- Moved patch-note Markdown rendering into `app/views/admin_updates.php`, where HTTP(S) links are rendered safely for the Admin view.
- Documented the deferred four-manual PDF build workflow in `RELEASE.md`, `docs/GALLERY_WORKFLOWS.md`, and `docs/LATEX_BUILD.md`.

#### Tests

- Added `tests/patch_notes_links_render_test.php` for safe links, escaped unsafe input, code handling, and offline rendering of cached Markdown.
- Updated `tests/admin_updates_ui_test.php` and `tests/updater_metadata_budget_test.php` to verify Markdown-based rendering and offline history behavior.

### User Impact

#### For administrators

- Made issue references and other HTTP(S) links in Admin patch notes clickable without activating unsafe links or obsolete cached HTML.
- Removed the need to rebuild manual PDFs after routine source or documentation edits; all four PDFs are built together during final hosted release preparation.

## Version 0.122

Version 0.122 added GitHub-hosted release preparation and qualification for release branches. It prepared release metadata and manuals, preserved completed maintainer-authored notes, validated generated notes before applying them, and qualified the exact prepared commit through the existing CI matrix. Ref: [#100](https://github.com/klusik/PHP_gallery/issues/100).

### Highlights

#### GitHub-hosted release preparation

- Added automatic qualification for `release/v_*` branches and support for manually dispatched qualification runs with an optional version cross-check.
- Updated deterministic release markers and metadata, aligned all four maintained manual editions, refreshed generated inventories and the integrity manifest, and committed approved preparation files back to the same release branch.
- Refused stale branch write-back when the remote branch changed during preparation.
- Preserved completed maintainer-authored release notes and generated prose only when the target section was absent or incomplete. Validated generated output before replacing only that section.

#### Release qualification

- Reused the central CI workflow for the exact prepared candidate commit, including the existing platform, database, runtime, and required-Chromium jobs.
- Added an authoritative `release`-profile audit and an aggregate gate that required preparation and the complete qualification matrix to succeed.
- Kept merge, tagging, and publication outside this qualification stage.

### Technical Details

#### Backend

- Added `.github/scripts/prepare_release_candidate.php` to apply deterministic release markers, metadata, translated manual markers, and the canonical patch-note scaffold.
- Added `.github/scripts/patch_notes_ai.php` to prepare bounded release evidence and validate generated Markdown before applying it.
- Configured `.github/workflows/gallery-workflows.yml` as a reusable workflow with explicit audit profile, checkout reference, and source-base inputs; retained `full` as its default audit profile.
- Added `.github/workflows/release-qualification.yml` to prepare release candidates, build all four manuals, refresh generated integrity data, and run qualification against the prepared commit.
- Replaced repeated Ubuntu `apt-get` TeX Live provisioning with checksum-locked TinyTeX-1 2026.02 and the frozen TeX Live 2025 final repository. Verified the explicit manual-package inventory, bounded downloads/package installation, and saved the exact input-bound cache only after all four manual builds succeeded. Ref: [#101](https://github.com/klusik/PHP_gallery/issues/101).
- Added the central `release-preflight` profile before AI or TeX to block syntax, declaration documentation, source inventory, Python import policy and workflow-contract failures early; preserved the complete exact-candidate qualification matrix.
- Fixed PDF and manifest timestamps to release metadata for reproducible reruns, allowed reuse only of an identical already-prepared tree, and rejected a stale candidate at the final release gate.
- Configured generated release notes to use the Copilot CLI with `gpt-6-luna` and fall back to automatic model selection when that model was unavailable. The workflow blocked qualification when required authentication, generation, or output validation failed.

#### Tests and documentation

- Added `tests/manifest_reproducible_timestamp_test.php` for stable timestamps, default-clock preservation and invalid epoch refusal.
- Extended `tests/gallery_workflow_ci_trigger_policy_test.php` and `tests/audit_runner_test.php` to guard reusable workflow inputs, release-audit configuration, cache identity/provenance, frozen provisioning, fail-fast ordering, rerun/write-back safeguards and the AI patch-note boundary.
- Documented GitHub-hosted release preparation and qualification in `RELEASE.md` and `docs/GALLERY_WORKFLOWS.md`.

### User Impact

#### For administrators

- Enabled release preparation and qualification on GitHub-hosted runners, with a single gate summarizing preparation and matrix results.
- Kept completed editorial notes intact; when notes were incomplete, invalid or unavailable generated content blocked qualification rather than being accepted.
- Preserved maintainer control over promotion: a successful qualification did not merge, tag, or publish a release.

## Version 0.121.3

Version 0.121.3 fixes Maintenance Center analysis and execution under selective runtime loading, keeps migration inspection read-only, and protects Windows updater checkpoints and gallery-layout sidecar replacement from transient sharing locks. Ref: [#97](https://github.com/klusik/PHP_gallery/issues/97).

### Highlights

#### Reliable Maintenance Center steps

- Fixed missing subsystem functions during separate Analyze and Execute requests by loading the reviewed dependencies for the current task before its callback runs.
- Preserved bounded checkpoints, resumable jobs, existing capability checks, central mutation locking and server-authorized plans.
- Removed table creation from the pending-migration check so Analyze inspects migration state without repairing or creating schema storage.

#### Windows updater reliability

- Fixed brief Windows file-sharing locks during durable updater checkpoint replacement with at most ten atomic rename attempts and a 450 ms total pause budget.
- Preserved the previous checkpoint on persistent refusal and removed only the failed staging file; no delete-before-replace fallback was introduced.
- Fixed the same short Windows sharing refusal in legacy gallery-layout sidecar migration, with bounded retries, concurrent-edit rechecks and cleanup of only the owned staging file.

### Technical Details

#### Backend

- Updated `app/controllers/admin_maintenance_center.php` to inject the active request kernel's logical-module loader into analysis and execution steps.
- Added task/phase dependency mapping in `app/services/maintenance_center.php` for `site-maintenance-work`, `archive-maintenance-work` and `domain-admin-database-maintenance`; updated the analysis/execution workers to load their dependencies before invoking task callbacks.
- Updated `gallery_description_layout_apply_sidecar_plan()` in `app/services/gallery_description_layout_compatibility.php`; retained full preflight, prior document/mode preservation and replay markers, and refused concurrent edits before retrying.
- Updated `application_update_write_json_atomic()` in `app/services/updates_jobs/state.php` and its module-owned retry limits; Windows retries reuse the same complete staging bytes, while other platforms retain one commit attempt. Permanent permission failure remains an explicit refusal.
- Registered the injected loader in `scripts/runtime_dynamic_dependencies.php`. Reused the existing generated runtime modules and retained selective loading without an umbrella-loader fallback.
- Retained the optional null loader for full-bootstrap CLI/test consumers whose dependencies are already loaded. The Maintenance Center service owns this compatibility path; its usage is unknown, and retirement requires migrating those consumers and proving their include contracts remain supported.

#### Database and frontend

- Updated `pending_migrations_exist()` in `app/migrations.php` to read the migration ledger without `CREATE TABLE`. A missing ledger or failed inspection remains fail-closed as pending work; the explicit migration runners retain ownership of ledger creation.
- Added no migration, schema column, setting, capability or browser asset change. Existing Admin authentication, CSRF, mutation responses and in-place browser continuation remain in force.
- Preserved the independent WinApp 0.3.2 version and existing installer.

#### Tests and documentation

- Extended `tests/gallery_description_layout_compatibility_test.php` with deterministic first-rename refusal, bounded Windows recovery, unchanged non-Windows refusal, concurrent-edit preservation, read-only refusal and staging cleanup.
- Extended `tests/updater_resumable_state_machine_test.php` with complete atomic replacement, failed-commit staging cleanup, destination preservation and Windows read-only checkpoint refusal.
- Updated `tests/admin_panel_lifecycle_browser_test.mjs` and `tests/support/headless_browser_fixture.mjs` to attach to a fresh blank tab and activate its headless focus and navigate the owned localhost fixture exactly once through DevTools. Preserved browser sandboxing, endpoint/profile confinement and all assertions, including authenticated workflows.
- Updated the isolated DEV-dashboard browser fixture to measure media request initiation at its owned loopback server, preventing old transfer completions from being misreported as new diagnostic requests. Retained the idle assertion and all existing workflow checks.
- Updated the gallery-tag browser fixture to observe synchronous Escape dismissal inside the trusted key event, retaining accessible focus and final geometry checks even when protocol acknowledgment arrives after the animation.
- Registered a 240-second `runtime_module_plan_test.php` limit in `scripts/audit_registry.php` for full dependency compilation and both loaders across all logical modules; the focused Windows run completed in 72 seconds, beyond the former 45-second default.
- Extended `tests/maintenance_center_test.php` to cover task/phase module selection, controller loader injection, dependency loading before callbacks, bounded analysis diagnostics and reviewed dynamic-loader policy.
- Updated architecture, database, code-map and verification guidance, the compatibility register, all four manual editions and PDFs, release metadata and `app/core-manifest.json`.

### User Impact

#### For administrators

- Fixed Maintenance Center steps that could fail when a new browser request had not loaded the required maintenance subsystem.
- Improved Windows updater checkpoint and layout-migration sidecar commits while preserving prior state on storage refusal and rejecting concurrent metadata edits.
- Kept Analyze read-only for application content and migration storage; pending migrations still require the explicit migration workflow.

#### For visitors

- Preserved public gallery rendering and media access behavior.

## Version 0.121.2

Version 0.121.2 fixes inline links and code in gallery descriptions, accepts Windows checkout line endings in production-inventory checks, and reduces avoidable sequential work in the central audit.

### Highlights

#### Gallery description links

- Fixed Markdown and BBCode links containing underscores and query parameters by protecting destinations before emphasis formatting.
- Preserved formatted link captions, literal inline code, safe fallback text for rejected targets, and links within the surrounding paragraph.

#### Faster verification

- Updated PHP and JavaScript syntax checks and independent Node/Chromium fixtures to use the existing bounded process pool instead of launching every check sequentially.
- Reserved the complete runtime-module compilation and composed/legacy loading matrix for `full`/`release`. Quick feedback retains dependency and graph contracts plus clean-process bootstrap probes without starting every module twice.
- Grouped parallel PHP regressions before exclusive fixtures, retaining exclusive isolation and deterministic report order.
- Replaced repeated namespace/import scans in `scripts/check_mvc_boundaries.php` with one context index per source file, preserving source-position aliases, PDO provenance and all strict/architecture findings.
- Removed unused enclosing-body fingerprint work from runtime-policy ownership scans without changing declaration identities or policy-site enforcement.

### Technical Details

#### Backend and tooling

- Updated `app/views/gallery_descriptions.php` to protect rendered link and code fragments while parsing surrounding inline formatting.
- Updated `scripts/generate_production_files.php` to normalize CRLF checkout conversion only; real inventory content and membership differences still fail.
- Added `run_file_checks()` in `scripts/audit_lib.php` and reused the existing scheduler in `scripts/audit.php`. PHP lint parses with `-n`, avoiding extension/configuration startup while regression children retain their normal runtime.
- Kept `PHP_GALLERY_AUDIT_WORKERS` at four by default with the existing one-to-eight range. Invalid worker values block syntax and Node suites as well as PHP regression. Browser required-mode failures, per-child deadlines, separate captures and failure attribution remain enforced.
- Updated `scripts/source_contracts/changes.php` and `scripts/source_contracts/policy_scan.php` so policy ownership reads declaration identities and records without hashing their bodies. Declaration comparisons continue hashing bodies by default.

#### Database, frontend and compatibility

- Added no migration, schema change, setting, capability or browser asset change. Existing MVC, media authorization and link-target validation remain in force.
- Retained permanent CRLF/LF checkout support in the production-inventory generator for Windows Git installations. Only checkout newline conversion is normalized; retirement would require ending support for those checkout environments. Usage is unknown.
- Preserved the independent WinApp 0.3.2 version and existing installer.

#### Tests and documentation

- Added `tests/gallery_description_links_render_test.php` coverage for Markdown/BBCode destinations, query strings, formatted captions, inline code, unsafe targets and paragraph flow; registered it in the quick PHP subset.
- Canonicalized the temporary root in `tests/thumbnail_source_identity_test.php` so macOS temporary-directory symlinks do not produce a false path-containment failure; production path-safety checks remain strict.
- Extended `tests/production_file_policy_test.php` to accept CRLF inventory text while refusing altered content.
- Extended `tests/audit_runner_test.php` with concurrent syntax success/failure attribution, literal paths with spaces and proof that lint never executes source.
- Extended `tests/mvc_pdo_provenance_test.php` with bracketed namespace changes and source-position import aliases across strict, Core and architecture scans.
- Extended `tests/policy_constants_changes_test.php` to compare ownership records and identities for PHP namespaces/classes/methods and nested JavaScript callbacks with and without body fingerprints.
- Updated audit guidance, all four manual editions and PDFs, release metadata and `app/core-manifest.json`.

### User Impact

#### For visitors

- Fixed gallery-description links that could previously acquire a damaged destination when URL punctuation was interpreted as text formatting.

#### For administrators and maintainers

- Improved Windows inventory verification and reduced idle/sequential audit work while retaining full release coverage.
- Kept exclusive database, recovery and session-contention scenarios isolated; their complete qualification still depends on fixture cost and machine performance.

## Version 0.121.1

Version 0.121.1 corrects Windows packaging CI failure propagation and makes the viewer-language workflow fixture deterministic across filesystems and database ID allocation. It also completes release inventory membership for the localization documentation and regression tests shipped with Version 0.121.

### Highlights

#### Reliable release verification

- Fixed the Windows packaging preflight to stop immediately when production membership or manifest validation fails.
- Fixed the localization workflow fixture to select its two known originals by filename and set their page order explicitly, preserving separate source-fallback and out-of-page translated preview checks. Ref: [#93](https://github.com/klusik/PHP_gallery/issues/93).
- Added the localization investigation document and two existing localization regression tests to their reviewed production/source-review inventory lists.

### Technical Details

#### Tooling and inventory

- Updated `.github/workflows/gallery-workflows.yml` to use PowerShell explicitly and propagate each native PHP preflight exit code before continuing the Windows packaging job.
- Updated `app/production-files.json` to include `docs/ISSUE_93_LOCALIZATION.md` in production documentation and `tests/content_language_workflow_integration_test.php` and `tests/content_request_language_test.php` in the optional source-review test inventory. FTP deployment continues to exclude tests.
- Refreshed CMS release metadata, all four manual editions and PDFs, and `app/core-manifest.json`.

#### Database, frontend and compatibility

- Added no migration, schema change, runtime feature, setting, capability, browser asset or compatibility branch. Preserved Version 0.121 gallery behavior and the independent WinApp 0.3.2 version and installer.

#### Tests

- Updated `tests/content_language_workflow_integration_test.php` to identify `sample-1.jpg` and `sample-2.jpg` without relying on scan order or auto-increment IDs and explicitly place the larger preview on the second photo page.
- Retained real disposable database/HTTP coverage for source-language fallback and translated Open Graph/Twitter captions outside the selected photo page.

### User Impact

#### For administrators

- Improved the reliability of release packaging gates and localization regression evidence across Windows and CI database environments.
- Kept upgrade and configuration workflows unchanged; no database migration or companion installer update is required.

#### For visitors

- Preserved the breadcrumb styles and viewer-language presentation introduced in Version 0.121.

## Version 0.121

Version 0.121 adds configurable visual styles for public breadcrumb navigation and completes viewer-language selection for gallery and photo titles and descriptions.

### Highlights

#### Breadcrumb presentation

- Added nine selectable breadcrumb styles with graphical examples in Theme, physical-gallery Display settings, and Smart Gallery presentation controls. Gallery-level inheritance previews the active Theme style. Ref: [#84](https://github.com/klusik/PHP_gallery/issues/84).
- Preserved accessible ancestor links, keyboard selection, responsive wrapping, and native controls without JavaScript. Gallery overrides remain in sidecars and survive Trash restore; unsupported values resolve through Theme defaults.

#### Viewer-language localization

- Applied the selected viewer language to public gallery and photo titles and descriptions across gallery controls, tags, Smart Galleries, breadcrumbs, and access-gate titles. Ref: [#93](https://github.com/klusik/PHP_gallery/issues/93).
- Kept protected gallery descriptions out of password/share/age gates while localizing their visible titles; preserved canonical source text when the request language changes.

### Technical Details

#### Backend and frontend

- Added `app/services/breadcrumbs.php`, `app/views/breadcrumbs.php`, and `public/assets/styles/breadcrumbs.css` for the shared breadcrumb style registry, presentation, and nine visual variants.
- Added Theme and gallery presentation settings through existing settings owners; gallery-specific values continue through the existing sidecar and Trash recovery lifecycle.
- Updated `app/services/content_localization.php` and public controllers to apply the effective request language when resolving entity text and to avoid applying physical-gallery translations to virtual Smart Galleries.
- Updated the reviewed runtime module plan, language catalogs, production inventory, cache-busted assets, and `app/core-manifest.json`.

#### Database and compatibility

- Added no migration, table, column, index, capability, or new configuration store. Existing settings and sidecar owners remain authoritative.
- Kept both features compatible with native no-JavaScript controls and existing fallback presentation.

#### Tests and documentation

- Added breadcrumb component, settings, workflow integration, browser, and content-language regression coverage; extended existing Admin Smart Gallery, theme layout, presentation, and public asset contracts.
- Updated architecture, code map, settings inventory, testing and compatibility documentation, and all four maintained manual editions.

### User Impact

#### For visitors

- Public gallery breadcrumbs can use a visual style selected by the administrator, while links remain accessible and responsive.
- Changing the viewer language now updates public titles and descriptions as well as interface labels.

#### For administrators

- Administrators can select a site-wide breadcrumb style and optionally override it for a physical gallery or Smart Gallery presentation.
- Existing inheritance and stored gallery presentation settings continue to work without a schema upgrade.

## Version 0.120.1

Version 0.120.1 fixes incoming release inventory compatibility so installations with the older CMS updater can install the stable release through Admin without first uploading a replacement validator. Reviewed WinApp sources remain available in deployment packages while CMS updater ownership stays unchanged.

### Highlights

#### Automatic update compatibility

- Fixed the `package_validate` refusal with error reference `385BCD5058D6` when an installed pre-WinApp inventory reader encountered companion sources in a newer production list. Ref: [#70](https://github.com/klusik/PHP_gallery/issues/70).
- Moved reviewed WinApp sources and assets into an additive `companion_files` list within schema 1. Older readers can validate the CMS inventory before the updated reader is installed.
- Preserved the complete reviewed file set for deploy folders, ZIPs, FTP and source-review packages, alongside the independent WinApp 0.3.2 version and installer.

### Technical Details

#### Backend and compatibility

- Updated `app/release_file_policy.php` to validate optional companion membership separately and combine it with the base list for production and source-review packaging. CMS updater activation and obsolete-file ownership continue to use only `updater_files`.
- Updated `scripts/generate_production_files.php` and regenerated `app/production-files.json` with the older-reader-compatible base list. New readers still accept already-published schema-1 inventories without the optional field, including prior WinApp production entries.
- Kept malformed present fields, unsafe or colliding paths, private WinApp state, and companion updater claims as explicit refusals. Incoming archive PHP is never executed to obtain a newer validator.
- Recorded the supported installed-reader bridge, its owner, unknown adoption and retirement conditions in `docs/COMPATIBILITY_LIFECYCLE.md`.

#### Database and frontend

- Added no migration, schema change, setting, capability or browser interaction. Existing protected installation state, media authorization, resumable checkpoints and rollback behavior retain their owners.

#### Tests and documentation

- Added `tests/updater_inventory_compatibility_test.php` with a frozen trusted pre-WinApp validator from commit `ffe5b5d` (CMS version 0.119). It reproduces the reported error reference, validates the real incoming inventory, and proves old/current archive readers select the same CMS files while still refusing unlisted application files.
- Extended `tests/production_file_policy_test.php` for both inventory layouts, complete package profiles, malformed optional fields, private companion state, cross-list collisions and excluded updater ownership.
- Updated permanent packaging, architecture and test guidance, and aligned all four manual editions and rebuilt PDFs.

### User Impact

#### For administrators

- Enabled older inventory-aware installations to receive the corrected release through the existing updater without a manual validator upload.
- Documented recovery when a previous job stopped before activation: cancel that job, force a fresh release check and start a new stable update once the corrected source is available on the stable branch. A retry may retain the earlier job's target version.

#### For visitors

- Preserved public gallery behavior and existing installation-owned files throughout the normal update workflow.

## Version 0.120

Version 0.120 hardens internal HTTP boundaries, deployment packaging and updater file ownership, separates lightbox navigation and resource lifecycles, and makes runtime dependencies and source-contract debt measurable. The database support policy now names directly tested MySQL and MariaDB representatives while preserving existing gallery, media and administration workflows.

### Highlights

#### Private runtime trees and command-line tools

- Added rewrite-independent Apache access denial for internal application, tooling, documentation and runtime-storage trees; preserved the public front controllers, assets and query-string routes. Ref: [#78](https://github.com/klusik/PHP_gallery/issues/78).
- Added an early CLI-only guard so direct HTTP requests to maintenance, migration, update and release commands receive an empty `404` before bootstrap or mutation; preserved includeable tooling and normal shell invocation.
- Preserved media authorization by denying unchecked static access to gallery originals and derivatives. Hosts must honor `.htaccess` authorization or configure equivalent server rules.

#### Reviewed production membership and safer deployment

- Added one exact production file inventory shared by Bash/PowerShell packaging, integrity ownership and updater activation. Dirty workspace state, caches, live data and unlisted files cannot silently enter a production package. Ref: [#70](https://github.com/klusik/PHP_gallery/issues/70).
- Added verification of missing/unexpected package members, normalized integrity hashes, linked source paths, unsafe destinations and existing output collisions; prepared and verified temporary stages before publishing requested artifacts.
- Improved the interactive Windows deployment launcher to retain completion/error diagnostics until Enter and choose a fresh timestamped destination when its prompted target already contains a package. Explicit automation targets still refuse collisions.
- Included reviewed WinApp sources and required runtime assets in production packaging while excluding tests, settings, caches and installer build outputs; retained the independent WinApp version and installer.

#### Lightbox navigation and resource ownership

- Extracted navigation generations/targets and decoded-resource ownership into separate modules, with stale-result rejection, cancellation, pending-load reuse and bounded cache eviction. Ref: [#80](https://github.com/klusik/PHP_gallery/issues/80).
- Preserved the single viewer, both permanent thumbnail renderers, preview-only nearby warming, slideshow preparation, maps, votes, fullscreen and synchronous active-original promotion when deliberately zooming above 100%.
- Kept close/hidden cleanup and terminal teardown distinct; diagnostic counters distinguish detached image loads, tracked quality loads and Fetch transfers. The extraction does not establish a browser performance improvement.

#### Explicit runtime, support and maintenance contracts

- Composed route modules through reviewed shared dependencies and one canonical file order, preserving complete transitive closures and existing procedural entrypoints; added dependency/file/memory regression budgets. Ref: [#79](https://github.com/klusik/PHP_gallery/issues/79).
- Centralized request/session transport through Core adapters while retaining language, navigation-token, gallery-unlock, NSFW and flash policy with their domain owners. Ref: [#72](https://github.com/klusik/PHP_gallery/issues/72).
- Aligned database guidance with required MySQL 8.4/PHP 8.3, MariaDB 10.11/PHP 8.3 and MariaDB 11.4/PHP 8.5 CI representatives; distinguished legacy, unsupported and unqualified series. Ref: [#71](https://github.com/klusik/PHP_gallery/issues/71).
- Documented compatibility owners, protected behavior, evidence, unknown usage and retirement conditions; added decrease-only budgets for reliable source-contract debt without weakening changed-source checks. Refs: [#73](https://github.com/klusik/PHP_gallery/issues/73), [#74](https://github.com/klusik/PHP_gallery/issues/74).

### Technical Details

#### Backend and updater

- Added `app/session_context.php` and migrated request/session helper consumers without starting sessions during include, changing flat persisted keys, or moving domain policy into the adapter. Preserved one-use active-session flash and the existing gallery-unlock lifetime.
- Updated `app/runtime/ModuleLoader.php`, `scripts/generate_runtime_modules.php` and the schema-2 `app/runtime/modules.php` plan with explicit dependencies and deterministic union loading. Added `scripts/runtime_plan_metrics.php` and `scripts/runtime_plan_baseline.json`; wall time remains observational.
- Added `app/release_file_policy.php`, `app/production-files.json`, `scripts/generate_production_files.php` and `scripts/release_files.php`; updated manifest generation, deployment and updater planning/activation to consume the canonical membership owner.
- Limited obsolete removal to prior owned inventory/manifest evidence. Retained a bounded older-archive path when the inventory is absent; malformed present inventory refuses activation, and invalid prior ownership produces no deletion authority.
- Hardened updater copy/rollback paths through `app/services/updates_path_safety.php`: contained source/destination ancestors, symlinks and Windows junction aliases are checked before replacement. Partial rollback snapshots use their server-written index; unindexed files are refused, and file rollback does not reverse database migrations.
- Expanded reconciliation of the exact application-owned server-policy files while preserving neighboring storage and configuration. Retained the existing incoming-hash verification policy; canonical membership does not introduce a new verification claim.
- Used header-first MIME detection for common raster media to avoid fileinfo's large read buffer, retaining content-based fileinfo fallback for other supported formats and historical MIME aliases.

#### Frontend

- Added `public/assets/gallery-modules/lightbox-navigation-lifecycle.js` and `public/assets/gallery-modules/lightbox-resource-lifecycle.js`; integrated them with the existing preload scheduler and viewer presentation.
- Updated versioned module imports and `app/views/layout.php` asset revision inputs so deployed browsers invalidate the changed lifecycle modules.
- Preserved authorized source selection, protected-preview recovery, current-image quality tokens and no-JavaScript navigation; stale navigation and image failures cannot overwrite newer presentation state.

#### Database, configuration and compatibility

- Added no database migration, table, column, index, capability, System Health group or administrator setting. Existing `available`, confirmed `missing`, `unknown` and configuration-disabled schema decisions remain with their established owners.
- Added direct disposable engine contracts for InnoDB/utf8mb4 metadata, JSON validation/extraction, vote constraints and advisory-lock exclusion. Retained portable interrupted-DDL replay and application-side vote validation.
- Preserved PHP 8.1 source compatibility and shared-hosting operation without a production Composer/Node build. Database qualification covers named representatives, not every PHP/server combination or higher database version.
- Documented the permanent Apache 2.2 access-control fallback alongside Apache 2.4 authorization and the existing query-route, full-bootstrap, hand-built module-plan and older updater archive compatibility paths in `docs/COMPATIBILITY_LIFECYCLE.md`.
- Preserved WinApp 0.3.2 and its installer because shipped companion code, assets, dependencies and build behavior did not change.

#### Tests and tooling

- Added HTTP/CLI boundary, session-context and request-helper fixtures; strengthened MVC ownership and PDO-provenance checks while keeping the strict baseline empty.
- Added real folder/ZIP packaging and production-policy regressions, dirty-tree exclusion, explicit/interactive collision handling, distributable WinApp source coverage, bounded subprocess stderr/timeout handling and updater path-safety/rollback cases.
- Added runtime-plan ratchets, decrease-only source-debt budgets and immutable comparison-base contracts; integrated them with the authoritative central audit and required platform/database CI jobs.
- Added `tests/lightbox_navigation_lifecycle_test.mjs`, `tests/lightbox_resource_lifecycle_test.mjs`, `tests/lightbox_race_browser_test.mjs` and their real-viewer fixture; extended navigation, preload, zoom-quality, cache and telemetry ownership coverage.
- Updated permanent runtime, HTTP-entrypoint, production-file, browser-lifecycle, database-support and compatibility documentation; aligned all four manual editions and their PDFs.
- Updated `PATCH_NOTES_TEMPLATE.md` to require clickable links for available, evidenced issue and pull-request references in future entries.

### User Impact

#### For visitors

- Public galleries retain their existing authorized media, navigation, maps, lightbox controls and no-JavaScript behavior while obsolete asynchronous work cannot replace a newer lightbox state.
- Internal implementation and stored media paths remain inaccessible through direct HTTP access on correctly configured hosting.

#### For administrators and maintainers

- Deployment artifacts and updater activation use an explicit reviewed file set and retain installation-owned configuration, custom CSS, gallery media and runtime data.
- Windows interactive deployment keeps useful diagnostics visible and preserves earlier packages; automated destination collisions remain explicit failures.
- Database support and compatibility obligations are documented precisely; new runtime paths require reviewed module inputs, and new production paths require reviewed inventory membership before manifest generation.
- Local release evidence remains separate from manual browser acceptance and post-publication updater checks. Routine manual rebuilds require compiler/consistency checks without an additional PDF visual-approval gate.

## Version 0.119

Version 0.119 introduces a small request kernel that loads reviewed PHP modules for the selected route, restores saved route maps in galleries without photographs, and replaces the lightbox's development readout with a localized diagnostic dashboard. Existing procedural handlers, access rules and shared-hosting deployment remain supported.

### Highlights

#### Route-aware application runtime

- Added a request-local `Gallery\Core` kernel with `Request`, `Router`, `RouteDefinition`, `RouteRegistry` and `ModuleLoader`; retained existing controller functions and the canonical security/route table in `app/bootstrap/dispatch.php`.
- Replaced ordinary public-request umbrella loading with a checked-in module plan. Production requests load the selected logical modules without scanning application sources, compiling dependencies or falling back to loading the entire application.
- Reduced the include-only bootstrap baseline from 533 PHP files to 24, with new audited ceilings of 40 files and 16 MiB; added bounded measurements for nine real route lifecycles.
- Preserved an explicit `app/bootstrap_full.php` entrypoint for CLI and test consumers that need the complete procedural API, including the legacy umbrella ordering.

#### Maps before the first photograph

- Fixed gallery-map initialization when the page contains no photo cards or lightbox markup, so a saved flight route can be opened before photographs are uploaded.
- Preserved lazy map loading, complete route geometry, route-point markers, viewport fitting and reset controls without adding synthetic photo markers.
- Added map overlay cleanup for dynamically replaced pages and retained useful empty-map feedback when no stored coordinates are available.

#### Lightbox development dashboard

- Added an administrator-only compact overview and separate detail pages for displayed media and quality, preload/cache state, runtime graphs, zoom/viewport and navigation lifecycle.
- Added adjacent Freeze/Resume, Collapse and Expand controls; preserved the selected page and existing DOM while values refresh, including native fullscreen.
- Separated the currently displayed photograph from pending navigation and quality requests, and distinguished retained reusable cache entries from historical decode success.
- Added individually labeled graphs for decoded-cache pixel estimates, cached images/active detached loads and animation-frame intervals, with units, explanatory legends and a 16.7 ms reference. Bounded history to 90 samples at 350 ms intervals.
- Hid URL credentials, query parameters and fragments; kept diagnostics read-only without diagnostic image or metadata requests. Disabled DEV does not load the dashboard, closing stops monitoring, and teardown removes it.
- Added English, Czech, German and Swedish dashboard labels and content-revision cache invalidation for the dynamically imported module.

### Technical Details

#### Backend and runtime loading

- Added the six core classes, `app/runtime/autoload.php`, `app/runtime/bridge.php` and generated `app/runtime/modules.php`; updated bootstrap, request initialization, routing, maintenance and dispatch to preserve their established lifecycle and security order.
- Added `scripts/runtime_dependencies.php`, `scripts/runtime_dynamic_dependencies.php`, `scripts/runtime_module_roots.php` and `scripts/generate_runtime_modules.php` for development-time compilation and explicit review of dynamic callback/class targets. Invalid modules, cycles, missing files and unsafe include paths fail explicitly.
- Isolated automatic-update eligibility in `app/services/updates_request.php` and active-job lookup in `app/services/updates_job_lookup.php`. Preserved active-job continuation before request-method, preference and timer gates; loaded updater execution only for eligible active/due work.
- Preserved due archive/site-maintenance execution at the existing shutdown boundary and front-controller ownership of optional early Admin Test Run instrumentation.
- Updated CLI utilities and selected fixtures to request full compatibility loading explicitly. Updated updater archive preflight to require the new runtime files before activation, preserving recoverable staging and the existing activation gate.

#### Frontend

- Added `public/assets/gallery-modules/lightbox-dev-dashboard.js` and updated `lightbox.js` to prepare bounded read-only snapshots, source observations and lifecycle events from the existing viewer.
- Updated `public/assets/styles/lightbox.css` for compact and expanded diagnostics, individual detail pages and viewport fitting; updated the shared/admin styles that embed the lightbox styling.
- Updated `app/views/layout.php` asset revision inputs and the dashboard import to invalidate both cold and warm immutable browser caches after application updates.
- Initialized Leaflet viewport/follow state before the no-photo early return and disposed the independently opened gallery map during teardown.

#### Database, settings and compatibility

- Added no database migration, table, column, index, application route, capability or System Health group. Reused the existing `dev_mode_enabled` setting and administrator restriction.
- Preserved PHP 8.1 source compatibility and ordinary PHP/MySQL or MariaDB shared hosting without Composer, Node, npm, Python, SSH, Docker or a production build step.
- Preserved authentication, CSRF, Viewer identity, gallery/password/NSFW/media authorization, three-state schema policy and both permanent thumbnail renderers. This release changes loading and diagnostic presentation rather than schema availability or mutation policy.
- Preserved the independent WinApp 0.3.2 version and installer because shipped Windows companion inputs did not change.

#### Verification tooling and documentation

- Added `scripts/audit_route_probe_registry.php`, `scripts/audit_route_probe.php` and `scripts/audit_route_performance.php`; integrated their evidence into the central runtime-performance suite and disposable workflow launcher.
- Registered robots, home, gallery, thumbnail, original media, authenticated Admin/telemetry and anonymous Admin/telemetry denial cases. Validated route identity, status/outcome, safe include inventory, finite metrics and semantic response evidence, so an HTTP 200 error page cannot count as a successful product response.
- Set per-route ceilings of 160–300 included PHP files and 32–48 MiB peak memory. Kept wall time observational and reported actual-route coverage as SKIP when the owned disposable fixture is absent.
- Added `tests/runtime_kernel_test.php`, `tests/runtime_dependencies_test.php`, `tests/runtime_module_plan_test.php`, `tests/runtime_route_probe_test.php` and their fixture/support owners; registered the runtime checks in the quick subset and isolated the nested module-plan worker pool.
- Added the registered `tests/lightbox_dev_dashboard_browser_test.mjs` and `tests/fixtures/lightbox_dev_dashboard.html` for real-viewer diagnostics, warm immutable cache invalidation, sanitized URLs, selected-page persistence, Freeze/Resume, quality errors, fullscreen ownership, bounded layout and teardown.
- Extended the production map browser fixture for route-only zero-photo pages with and without viewer markup, reset controls, unchanged URL, teardown/reinitialization and an empty payload.
- Updated existing updater, session, include-boundary, diagnostics and workflow contracts for their current owners without weakening access assertions.
- Updated `README.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `TESTING.md` and the Settings inventory; aligned all four administrator manuals and rebuilt their PDFs. Removed the completed temporary runtime implementation roadmap after incorporating permanent guidance.

### User Impact

#### For visitors

- Saved gallery flight routes can be viewed before the first photograph is added, with existing map and access policy.
- Ordinary requests load the modules needed for their route while retaining public navigation, authorized media and no-JavaScript behavior.

#### For administrators and maintainers

- Development diagnostics explain displayed quality, pending work, cache ownership and graph limits directly in the existing lightbox; the feature remains disabled during ordinary operation unless deliberately enabled.
- New routes and dynamic dependencies require updates to the authored loading inputs and regeneration of the shipped plan; existing CLI consumers can retain full procedural loading explicitly.
- Release qualification retains central automated evidence, explicit environment gaps, optional PDF review fields, manual browser acceptance and post-publication updater checks as separate records.

## Version 0.118.6

Version 0.118.6 shortens development feedback and improves automated verification. The quick audit uses an explicit PHP subset, complete audits share a bounded parallel worker pool, and CI now requires Chromium coverage in its own job. Fresh-process bootstrap probes detect unexpected include or memory growth without depending on workstation speed.

### Highlights

#### Faster and explicit audit coverage

- Updated `quick` to run the curated `php-fast` subset alongside strict source, MVC, mutation, Python import, syntax and fast Node checks; retained complete PHP, WinApp, advisory inventory, slow Node and browser coverage in `full` and `release`.
- Added a portable PHP worker pool with four workers by default and an explicit range of one through eight via `PHP_GALLERY_AUDIT_WORKERS`; invalid values block the suite.
- Added documented exclusive barriers for shared-resource, contention and nested-process fixtures, independent output capture, per-child timeouts and deterministic result ordering.
- Added fresh-child early-runtime and application-bootstrap measurements with file-count and peak-memory ceilings; recorded elapsed time as an observation.

#### Required and isolated browser verification

- Added a dedicated `browser-tests` CI job using PHP 8.3, Node 22 and discovered Chrome/Chromium with `PHP_GALLERY_BROWSER_REQUIRED=1`.
- Made missing or unstartable browsers and skipped required browser fixtures produce nonzero coverage results; retained job-local browser opt-out for the database/runtime matrices.
- Updated selected browser fixtures to use their own bounded DevTools session and close the actual browser before cleaning up its private profile, including Windows launcher behavior.
- Updated the Settings fixture to match the production save bar and start geometry checks at an explicit desktop viewport.

### Technical Details

#### Audit backend and runtime probes

- Added `scripts/audit_process.php` for worker scheduling, process status and bounded child/process-tree cleanup; updated `scripts/audit.php` and `scripts/audit_lib.php` to use it.
- Added `scripts/audit_php_registry.php` for the explicit quick-test list and serial-test reasons, and updated profile and requirement registration in `scripts/audit_registry.php`.
- Added `scripts/audit_performance.php`, `scripts/audit_performance_registry.php` and `scripts/audit_runtime_probe.php` for validated include-only metrics before `cms_run()`, without route dispatch, database access or sessions.
- Set early-runtime ceilings to one included PHP file and 16 MiB peak memory; set application-bootstrap ceilings to 560 files and 64 MiB, with a documented 533-file baseline.
- Updated `scripts/gallery_workflow_mysql.php` and `scripts/gallery_workflow_run.php` so `--quick` delegates directly to the central quick profile before creating a database, application copy or daemon; retained `--audit` and `--release` for disposable workflow qualification.

#### Browser fixtures and CI

- Added `tests/support/headless_browser_fixture.mjs` for confined loopback fixtures, unique profiles, bounded result polling and private DevTools browser shutdown.
- Migrated `tests/admin_gallery_title_completion_browser_test.mjs`, `tests/gallery_picker_parent_integration_browser_test.mjs`, `tests/lightbox_map_browser_test.mjs` and `tests/gallery_workflow_browser.mjs` to the shared helper.
- Updated `tests/admin_panel_lifecycle_browser_test.mjs` and `tests/fixtures/admin_settings_workspace.html` for the Settings viewport and current markup.
- Updated `.github/workflows/gallery-workflows.yml` to run the required browser suite once and preserve existing database/HTTP and runtime coverage without adding npm, Composer, Playwright or Selenium dependencies.

#### Database and compatibility

- Added no migration, schema object, application route, setting or capability. Preserved runtime MVC, authorization and three-state schema policy; the bootstrap measurements stop before application execution.
- Preserved PHP 8.1+ compatibility, optional local browser discovery and the complete `php tests/run.php` compatibility entrypoint. The shared DevTools helper requires Node with global WebSocket support, as provided by Node 22 in CI.
- Preserved the independent Windows uploader version and installer because shipped WinApp code and build inputs did not change.

#### Documentation and tests

- Updated `README.md`, `ARCHITECTURE.md`, `TESTING.md`, `CODEMAP.md`, `AGENTS.md`, `docs/GALLERY_WORKFLOWS.md` and `docs/RUNTIME_SUPPORT.md` for profile coverage, scheduling, probe metrics and required browser CI.
- Extended `tests/audit_runner_test.php` for worker bounds, exclusive barriers, child failures, timeout attribution and recovery, descendant cleanup, probe validation, report metrics and required-browser blocking.
- Extended `tests/gallery_workflow_ci_trigger_policy_test.php` and `tests/gallery_workflow_safety_test.php` for job-scoped opt-out, mandatory Chromium discovery and quick delegation before disposable provisioning.
- Aligned English, Czech, German and Swedish manual instructions and edition metadata; rebuilt all four PDFs for Version 0.118.6 and documented routine compiler checks with optional PDF visual review.

### User Impact

#### For visitors

- Preserved public gallery behavior while strengthening automated browser regression coverage.

#### For administrators and maintainers

- Reduced edit-cycle verification work while retaining the complete handoff and release audit profiles.
- Made PHP worker limits, exclusive fixtures, bootstrap growth and required browser coverage observable in the existing compact audit reports.
- Kept automated audit evidence separate from manual browser acceptance and post-publication updater checks.

## Version 0.118.5

Version 0.118.5 brings the gallery header's tag disclosure into the same animated overlay used by gallery cards. Visitors can inspect every direct and contained tag without expanding the header, while saved Theme limits, ordering and scrollbar preferences continue to apply from the first server-rendered response.

### Highlights

#### Shared gallery tag panel

- Updated the header's ellipsis control to open an animated panel containing every tag, preserving direct and containing-tag groups and their order.
- Kept header and card dimensions stable during opening and closing; long tag names wrap inside the panel.
- Kept the enhanced panel within the viewport, including narrow screens with a classic scrollbar, and prevented header and tag-row clipping.
- Preserved keyboard access, Escape with focus restoration, outside-click dismissal, shared single-panel behavior and dynamically replaced header controls.
- Preserved native disclosure without JavaScript: the complete header tag panel opens below the header and remains reachable by scrolling.

### Technical Details

#### Backend and presentation

- Added `view_render_public_gallery_tag_info_panel()` in `app/views/public_tags.php` as the shared presentation helper for gallery cards and headers, consuming prepared view-model data.
- Updated `view_render_public_hero_tags()` to render the configured preview across groups, omit a group label when none of its tags are previewed, and render a tags-only panel only when the limit is exceeded.
- Preserved card panels with public title, description, date and all tags; kept display-all and exact-limit headers free of an unnecessary disclosure control.

#### Database and compatibility

- Added no migration, table, column, index, setting, route, capability or System Health group. Reused existing Theme preferences and localized labels.
- Preserved existing gallery/media authorization and schema policy; this presentation change introduces no persistence or schema inspection.
- Preserved the independent Windows uploader version and installer.

#### Frontend and documentation

- Updated `public/assets/gallery-modules/hero-tags.js` to position the shared panel relative to any `data-hero-tags` root and calculate its width against the document viewport excluding a classic scrollbar.
- Updated `public/assets/styles/public-shared.css` for header stacking, open-panel clipping relief, native below-header placement and wrapping tag pills.
- Updated both `public/assets/gallery.js` and `public/assets/public-gallery.js` imports to `20261005-hero-info-panel-v2` for authenticated and anonymous public views.
- Updated `README.md`, `ARCHITECTURE.md`, `CODEMAP.md` and `TESTING.md`; aligned all four administrator manuals and rebuilt their PDFs for Version 0.118.5.

### Tests

#### Tag preview and overlay behavior

- Extended `tests/hero_tag_theme_model_test.php` to verify that an open header panel is not clipped by its configured scrollbar.
- Extended `tests/support/gallery_tags_render_fixture.php` with group-boundary, exact-limit and display-all headers using production rendering.
- Extended the registered `tests/fixtures/gallery_tags.html` browser fixture for complete grouped panel order, first-paint limits, native keyboard fallback, unchanged geometry, narrow-viewport bounds, wrapped tag names, animated closing and Escape focus restoration.
- Added browser assertions for delegated handling after header replacement, cross-card/header dismissal and outside-click dismissal while retaining the existing gallery-card and no-overflow checks.

### User Impact

#### For visitors

- All gallery header tags are available in a compact panel with the same interaction as gallery cards, without shifting the surrounding gallery content.
- Native keyboard disclosure remains usable without JavaScript; the saved display-all preference still shows every tag immediately.

#### For administrators

- Existing Theme tag limits, sorting, scrollbar and public-panel animation preferences continue to control the presentation without new configuration or database changes.

## Version 0.118.4

Version 0.118.4 fixes SimBrief route import in gallery creation and editing. The description and route preview now retain the filed route, including departure and arrival airports, and long routes keep their destination. Imported OFP data remains a private draft until the gallery is saved, and an import response cannot overwrite route text changed while the request was running.

### Highlights

#### Complete SimBrief route previews

- Added missing departure and arrival airports to the filed route without duplicating existing endpoint or airport/runway tokens.
- Preserved filed route instructions such as `DCT`, repeated waypoints, and the full route in English, Czech, German, and Swedish description drafts instead of shortening it to 300 characters.
- Filled the existing route editor from the draft response and showed that the OFP and available route-map points will attach when the gallery is saved.
- Preserved descriptions, route text, and the previous private draft reference when an administrator changes the form during an import; the stale response asks for another import.

### Technical Details

#### Backend

- Updated `app/controllers/admin_simbrief.php` to return a non-persistent `route` preview with `saved: false`, endpoint-complete `route_text`, and the OFP `point_count` alongside the existing private `draft_ref`.
- Added `simbrief_description_complete_route_text()` in `app/services/simbrief_descriptions.php`; preferred the filed route over coordinate-point names, retaining ordered point names as a fallback when filed text is absent.
- Removed the 300-character route shortening from localized description generation and `app/views/simbrief_descriptions.php`. Preserved original OFP coordinate geometry when the draft is attached after a gallery save.

#### Database and compatibility

- Added no migration, table, column, index, setting, or route. Kept the existing authenticated, CSRF-protected SimBrief endpoint and administrator/session-bound, 30-minute draft ownership.
- Kept draft generation independent of optional map persistence. Verified `available` flight-map storage permits the existing attachment; confirmed `missing` or `unknown` storage cannot authorize a route-map write. Disabled capabilities retain their existing route/UI policy and stored data.
- Added no System Health capability or diagnostic group. Preserved existing bounded failure responses and the independent Windows uploader version and installer.

#### Frontend and documentation

- Updated `public/assets/gallery-modules/admin-simbrief-description.js` to detect route edits made during the remote request before applying any draft fields, populate the existing route editor, and describe staged OFP/map data.
- Added the live route-status element to `app/views/admin_gallery_forms.php`; showed map attachment status only when at least two coordinate points are available.
- Updated the module import in `public/assets/gallery.js` to `20261005-simbrief-route-preview-v1`, ensuring deployed browsers load the corrected handler.
- Updated `README.md`, `ARCHITECTURE.md`, `CODEMAP.md`, and `TESTING.md`; synchronized all four administrator manual editions and rebuilt their PDFs for Version 0.118.4.

### Tests

#### Route preview and draft persistence

- Extended `tests/simbrief_description_model_test.php` for endpoint insertion without duplication, airport/runway tokens, filed-route preservation, coordinate-name fallback, and complete long routes in every maintained language and the production English view.
- Added `tests/simbrief_route_preview_test.php` to exercise the production endpoint for both new and existing galleries, verify private draft ownership and original OFP preservation, refuse premature route persistence, and retain complete route text and OFP coordinates during attachment.
- Extended `tests/fixtures/gallery_creation.html` and `tests/support/gallery_creation_render_fixture.php` to use the production editor form, import and save a complete route after dynamic editor replacement, preserve route edits during a delayed response, and assert that the panel remains open with an unchanged URL.
- Corrected the fixture save endpoint to follow the side-panel workflow's actual POST URL while retaining staged-route, draft-reference, and repeated-import assertions.

### User Impact

#### For visitors

- Saved descriptions retain the full filed route and endpoint airports; public flight maps continue to display stored OFP coordinates under the existing gallery access rules.

#### For administrators

- Importing SimBrief fills the route editor as well as the description and explains that OFP/map data attaches after saving the gallery.
- Editing route text while an import is running preserves the entered form values and asks for a fresh import instead of applying a stale response.
- Repeated imports and saves continue inside the existing side panel after its content refreshes.

## Version 0.118.3

Version 0.118.3 refreshes local navigation data in the background when administrators work with flight routes or import SimBrief drafts. Gallery saves remain available while the refresh runs, and unresolved saved manual routes can gain coordinates without overwriting a newer edit, existing coordinates, or SimBrief OFP geometry. The update adds no database migration.

### Highlights

#### Background route navigation data

- Added automatic freshness checks when administrators enter or focus a nonempty route, request a SimBrief description draft, or save route-bearing input.
- Kept the refresh independent of gallery saving and preserved the editor draft, open side panel, and browser URL.
- Reused the weekly OurAirports refresh interval, one-hour failure backoff, and installation-wide import lock; coalesced checks and bounded retries when another request owns the import.
- Completed unresolved saved manual routes only when additional coordinates were found, preserving captured points and leaving SimBrief OFP geometry intact.

### Technical Details

#### Backend

- Added the authenticated, CSRF-protected `admin_route_navdata_refresh` POST in `app/controllers/navigation_data.php` and registered it in `app/bootstrap/dispatch.php`.
- Released the PHP session before importing data so concurrent gallery saves can continue; requests contain the saved gallery identity rather than draft route text or pilot identifiers.
- Extended `app/services/flight_maps/navdata_update.php` to complete saved routes after a due import or from already-current data. Used `app/models/flight_maps.php` to compare captured route text, point payloads, source type, and timestamp before writing, preserving concurrent edits and deletions.
- Returned the canonical Admin mutation envelope with affected gallery and parent/root contexts when route coordinates changed, using the shared public refresh coordinator without replacing the editor.

#### Database and capability policy

- Added no migration, table, column, index, or setting. Reused existing navigation-data and flight-map storage and freshness settings.
- Required both effective `navigation_data` and `flight_maps` capabilities for the new route and its editor endpoint. Disabling either prevents background requests without deleting stored data.
- Required verified `available` import schema before downloads and writes. Confirmed `missing` or `unknown` import storage refuses refresh; unavailable flight-map storage prevents route completion, and a route write requires verified schema again before persistence. Optional refresh failures leave the ordinary gallery save available.
- Kept failure responses translated and bounded; diagnostic context contains the gallery ID and exception class without returning raw provider or database exceptions. Added no System Health capability or group.

#### Frontend and documentation

- Added `public/assets/gallery-modules/admin-route-navdata.js` with delegated handling for dynamically replaced editors, coalesced requests, bounded busy retries, and a final pass after a successful gallery save or creation.
- Updated `app/controllers/admin_gallery_form_models.php` and `app/views/admin_gallery_forms.php` to pass prepared refresh availability to manual-route and SimBrief controls, including when SimBrief is disabled.
- Updated `public/assets/gallery.js` and the versioned interaction-policy import for the new module; synchronized the safe refresh-failure label across English, Czech, German, and Swedish PHP/JSON catalogs.
- Updated `ARCHITECTURE.md`, `TESTING.md`, and `CODEMAP.md`, synchronized all four administrator manual editions, and rebuilt their PDFs for Version 0.118.3.

### Tests

#### Route refresh and panel contracts

- Added `tests/route_navdata_background_test.php` for due imports, saved-route completion, coordinate/OFP preservation, conditional writes against concurrent edits and deletions, schema refusal, and busy/backoff behavior.
- Extended `tests/admin_navigation_data_ui_test.php` for POST-only behavior, authentication, CSRF, session release, safe failure responses, and canonical affected contexts.
- Extended `tests/feature_policy_core_test.php`, `tests/frontend_operational_policy_test.mjs`, and `scripts/check_admin_mutation_contracts.php` for compound capability ownership, retry policy, and shared mutation completion.
- Extended the registered `tests/fixtures/admin_update_jobs.html` browser fixture to hold an import open while Save completes, verify the final pass and server-assigned gallery identity, and exercise dynamic controls, busy retries, disabled features, and failure isolation with an unchanged URL and open panel.
- Fixed `tests/fixtures/gallery_tags.html` to wait for the summary-triggered closing animation before checking restored card geometry, retaining the bounded completion and exact layout assertions already used for Escape dismissal.

### User Impact

#### For visitors

- Saved manual flight routes can display newly resolved points after an administrator's background refresh. Public rendering continues to use stored coordinates and existing media/access rules.

#### For administrators

- Working with a route or importing SimBrief can refresh local navigation data without a separate trip to Navigation Data or waiting before saving the gallery.
- Refresh failures leave gallery editing available; saved coordinates and newer edits remain protected.
- Preserved the independent Windows uploader version and installer because this release changes only CMS behavior, documentation, and tests.

## Version 0.118.2

Version 0.118.2 streamlines Admin storage reporting with one resumable refresh for file statistics, database estimates, and read-only database inspection. It also makes storage details easier to scan and groups Maintenance Center tasks around the work found during analysis. The update adds no database migration and leaves public gallery behavior unchanged.

### Highlights

#### Unified storage refresh

- Added an `Update all` action that refreshes file statistics, database table estimates, and the read-only database inspection in sequence.
- Kept progress on the server and processed files and database tables in bounded requests, so an administrator can continue an active workflow without restarting completed stages.
- Reported partial failures by stage and refreshed the selected Storage statistics view in place when processing finishes.
- Kept the operation read-only with respect to gallery data: it does not clean records, repair schema, optimize tables, or execute a Maintenance Center plan.

#### Storage reports and maintenance review

- Reworked storage summary cards and charts with clearer grouping, concise chart help, file counts, and accessible chart meters.
- Grouped Maintenance Center tasks by area and moved available optional tasks with no detected work into a collapsed disclosure.
- Kept deep media verification and available full-table optimization visible in the plan review, and labeled required automatic tasks and unavailable tasks directly.

### Technical Details

#### Backend

- Added `app/services/admin_storage_refresh.php` for the server-owned, administrator-bound refresh lifecycle, persisted progress, bounded stage transitions, and safe partial-result reporting.
- Registered the service and extended `cms_admin_storage_statistics_update()` with start/step actions for the combined workflow.
- Added `admin_database_usage_recompute_statistics_batch()` to run `ANALYZE TABLE` for at most five tables per request.

#### Database

- Added no migration, table, column, index, or setting. The workflow refreshes existing table metadata and runs the existing read-only database inspection.
- Continued storing refresh progress in the application cache with a one-hour expiry; bounded diagnostic events record stage failures without returning exception details to the browser.

#### Frontend

- Updated `public/assets/gallery-modules/admin-storage-statistics.js` to show staged progress and replace only the active Storage statistics content after completion.
- Updated `public/assets/gallery-modules/admin-maintenance-center.js` to group review tasks, preserve the no-work disclosure state during redraws, and retain discoverability of opt-in deep verification and full optimization.
- Updated `public/assets/gallery.js` cache-busting versions for both changed Admin modules and synchronized the storage labels across all four language catalogs.
- Updated `CODEMAP.md` with the storage-report and refresh service ownership.
- Updated the English, Czech, German, and Swedish administrator manuals and rebuilt their PDFs for Version 0.118.2.

### Tests

- Added `tests/admin_storage_refresh_test.php` for server-side ownership, rotating workflow identities, bounded phase progression, retry behavior, partial failures, and safe browser responses.
- Updated `tests/maintenance_center_test.php` for the revised review groups, collapsed no-work tasks, preserved task selections, and accessible database-maintenance disclosures.

### User Impact

#### For visitors

- Public gallery pages, access rules, and visitor workflows are unchanged.

#### For administrators

- One action now updates the file scan, database estimates, and database inspection while showing resumable progress and any incomplete stages.
- Storage charts and Maintenance Center review present the available information more clearly without expanding work that analysis found unnecessary.

## Version 0.118.1

Version 0.118.1 corrects the source-documentation checks so anonymous functions and callbacks do not require docstrings. Named functions, methods, and class-like declarations retain their documentation requirements. The update aligns contributor guidance and regression coverage without changing gallery behavior, settings, or database storage.

### Highlights

#### Declaration documentation

- Exempted anonymous functions, closures, arrow functions, and inline or variable/member-bound callbacks from declaration-docstring requirements.
- Exempted variables, constants, and class properties from declaration-docstring requirements.
- Kept documentation checks for named functions, methods, classes, interfaces, traits, and enums, including named declarations nested inside anonymous callbacks.
- Aligned the historical JavaScript documentation-presence check with the shared source-contract policy.

### Technical Details

#### Audit tooling

- Updated `scripts/source_contracts/php.php` to apply docstring validation only to named callables and class-like declarations while retaining independent native PHP signature-typing checks.
- Updated coverage descriptions in `scripts/check_source_documentation.php` and `scripts/source_contracts/changes.php` to state the anonymous-callback exemption and the separate typing and parser-coverage contracts.
- Preserved file-attribution headers and operational-policy checks as independent requirements.

#### Backend and frontend

- Completed the return and value-shape documentation in `app/controllers/theme_assets.php`, `app/services/theme.php`, and `public/assets/gallery-modules/theme-form.js`.
- Replaced the optional callback docblock in the Theme animation reset control with an ordinary explanatory comment. Kept runtime behavior and browser imports unchanged.

#### Database and compatibility

- Added no migration, setting, route, or storage change.
- Kept visitor and administrator workflows compatible with Version 0.118 and preserved the independent Windows uploader version and installer.

### Tests

#### Documentation contracts and guidance

- Updated `tests/function_documentation_test.php`, `tests/source_contract_inventory_test.php`, `tests/source_contract_changes_test.php`, and `tests/source_type_documentation_test.php` to cover callback and property exemptions, undocumented named declarations, nested named functions, native PHP typing, and removal of optional callback documentation.
- Kept named-function parameter and return-contract coverage for JavaScript defaults, rest parameters, and destructured tuples.
- Fixed `tests/support/gallery_workflow_browser.js` to wait for panel-owned form initialization after an Images fragment refresh before exercising its next row action; retained unchanged-URL, open-panel, canonical-response, and persistence assertions.
- Updated `AGENTS.md`, `ARCHITECTURE.md`, and `TESTING.md` with the same declaration-documentation rules.
- Synchronized the English, Czech, German, and Swedish administrator manuals and rebuilt their PDFs for Version 0.118.1.

### User Impact

#### For visitors and administrators

- Preserved public gallery presentation, access rules, Theme animation controls, and existing administration workflows.

#### For contributors

- Removed false documentation findings for anonymous callbacks while keeping named-declaration documentation, signature typing, and parser coverage visible in the central audit.

## Version 0.118

Version 0.118 adds adjustable motion for public gallery information panels and the Admin side panel, while improving how visitors open and dismiss a gallery card's public information. It also adds contributor, accessibility, issue-reporting, and security guidance to the project repository.

### Highlights

#### Theme animation controls

- Added an Appearance > Animations section with separate durations for the public information panel on gallery cards and the Admin side panel.
- Allowed each duration to be set from 0 to 800 ms, with a reset-to-default control; setting 0 disables that animation.
- Kept the selected Theme tab and Appearance subsection visible after saving.

#### Gallery card information

- Replaced the overflow-tag disclosure with a compact ellipsis control that opens the gallery's public title, description, date, and complete tag list.
- Kept the configured visible tag preview rendered on the server and the native disclosure usable without JavaScript.
- Positioned the information panel beside its card when JavaScript is available, kept it inside the viewport, and added outside-click and Escape dismissal.
- Honored the browser's reduced-motion preference.

#### Project contribution guidance

- Added repository guidance for accessibility reports, contribution workflow, responsible security reporting, and issue and pull request templates.

### Technical Details

#### Backend and settings

- Added `theme_gallery_info_motion_ms` and `theme_admin_side_panel_motion_ms` to the existing Theme settings flow and Admin Settings registry. Values are normalized to 0–800 ms and default to 320 ms and 260 ms respectively.
- Added the `admin-theme-appearance-subtab-animations` destination and restored the submitted Theme tab and Appearance subsection after a save.
- Updated `app/controllers/theme_assets.php` to expose the normalized durations as CSS custom properties, and updated `app/services/theme.php`, `app/controllers/admin_theme_actions.php`, `app/controllers/admin_theme_appearance.php`, and `app/services/admin_settings_registry.php` to load, save, and discover the settings.

#### Database

- Added no database migration. Both values use the existing application settings storage.

#### Frontend and presentation

- Updated `app/views/admin_theme.php` and `app/views/public_tags.php` for the animation controls and public card information disclosure.
- Updated `public/assets/gallery-modules/theme-form.js`, `public/assets/gallery-modules/admin-side-panel.js`, and `public/assets/gallery-modules/hero-tags.js` for synchronized duration inputs, tab retention, transition-aware closing, and the public information-panel interactions.
- Updated `public/assets/styles/admin-theme-editor.css`, `public/assets/styles/admin-cinematic.css`, and `public/assets/styles/public-shared.css` for responsive controls and the two animated panels. Updated relevant gallery and Admin asset cache versions.
- Updated `app/controllers/public_gallery_cards.php` and `app/views/public_gallery_cards.php` to prepare and render public title, description, date, and tag data for the card panel.
- Added the new labels, help text, and example values to both PHP and JSON catalogs for English, Czech, German, and Swedish.

### Tests

- Updated `tests/fixtures/gallery_tags.html`, `tests/support/gallery_tags_render_fixture.php`, and `tests/hero_tag_theme_model_test.php` for the public information panel and duration normalization.
- Updated `tests/theme_appearance_rendering_test.php`, `tests/frontend_operational_policy_test.mjs`, `tests/stage4_mutation_hardening_contract_test.php`, `tests/smart_gallery_high_priority_hardening_test.php`, `tests/admin_side_panel_created_gallery_refresh_test.mjs`, and `tests/admin_side_panel_gallery_refresh_test.mjs` for the added Appearance subsection and current cache-busting imports.
- Added `ACCESSIBILITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `.github/SECURITY.md`, issue templates under `.github/ISSUE_TEMPLATE/`, and `.github/PULL_REQUEST_TEMPLATE.md`; updated `README.md` with the project guidance links.
- Synchronized all four administrator manual editions and rebuilt their PDFs for Version 0.118.

### User Impact

#### For visitors

- The ellipsis on a gallery card opens its public description, date, and all tag links in a compact panel. The visible tag preview remains available on first paint, and the disclosure still works without JavaScript.
- The panel can be dismissed by clicking elsewhere or pressing Escape when JavaScript is available. Its motion follows the browser's reduced-motion preference.

#### For administrators

- Theme > Appearance > Animations controls how quickly the public gallery information panel and Admin side panel open and close. Each setting supports 0–800 ms and can be reset to its default.
- Saving Theme settings returns the administrator to the tab and Appearance subsection they were using.
- Contributors can use the repository's new issue templates and accessibility, contribution, conduct, and security guidance.

## Version 0.117.1

Version 0.117.1 fixes first-paint tag disclosure in public gallery headers and gallery cards. The configured visible tag limit, overflow control, and optional row cap now come from server-rendered markup and CSS, keeping card geometry stable before JavaScript runs.

### Highlights

#### Public gallery tags

- Rendered the configured number of gallery and contained tags on the server, with a native disclosure control for the remaining tags.
- Kept tag expansion available without JavaScript and preserved tag links in the expanded collection.
- Applied the optional tag row cap from the initial response and kept tag pills on a single line with truncation for long names.
- Preserved compatibility handling for older button-based tag markup while current server-rendered roots remain untouched by browser layout initialization.

### Technical Details

#### Backend and frontend

- Updated `app/controllers/public_gallery_page.php` to prepare tag-list view models and pass the existing Theme display settings to the view.
- Updated `app/views/public_gallery_pages.php` and `app/views/public_tags.php` to render the initial visible tags, inline native disclosure, and server-configured row-height limit.
- Updated `public/assets/gallery-modules/hero-tags.js` to leave current native disclosure markup unchanged and retain legacy markup support.
- Added native disclosure, tag truncation, and stable row-cap styling to `public/assets/styles/public-shared.css`; updated gallery and public-gallery entrypoint cache versions.

#### Database

- Added no migration or setting. Tag assignments and existing Theme preferences remain in their current storage.

#### Tests and documentation

- Added `tests/gallery_tags_browser_test.mjs`, `tests/fixtures/gallery_tags.html`, and `tests/support/gallery_tags_render_fixture.php` for first-paint and card-geometry coverage.
- Updated `tests/admin_panel_lifecycle_browser_test.mjs`, `tests/hero_tag_theme_model_test.php`, and `scripts/audit_registry.php` for the new browser fixture and server-first rendering contract.
- Updated the architecture description and synchronized all four manual edition versions.

### User Impact

#### For visitors

- Gallery and contained tags show their configured initial set immediately, while the `[...]` disclosure opens the remaining linked tags without a JavaScript-dependent initial layout shift.

#### For administrators

- Existing Theme settings for visible tag count, display-all mode, and row limits continue to control public tag presentation.
## Version 0.117

Version 0.117 refines the gallery editor into a more compact, task-focused workspace and improves SimBrief description drafting for new and existing galleries. The update preserves the existing gallery storage, access rules, and optional flight-map behavior.

### Highlights

#### Compact gallery editor

- Reorganized the gallery overview and settings into clearer Identity, API, Access, Display, and Media work areas, with a persistent save action in the side panel.
- Added contextual help and compact advanced disclosures so infrequent controls remain available without crowding routine editing.
- Grouped route-map editing with SimBrief generation and improved gallery-date suggestions and controls.
- Kept direct-page form submission available when JavaScript is disabled.

#### SimBrief description drafts

- Added a single Pilot ID or pilot-name field that selects the matching SimBrief lookup automatically.
- Generated an editable description in the selected content language and populated available maintained-language description fields when localization storage is ready.
- Kept generated descriptions as private temporary drafts until the administrator saves the gallery; saved OFP and route data are attached after the gallery save.
- Preserved the ordinary description and manual route controls when SimBrief is unavailable or a request fails.

### Technical Details

#### Backend and frontend

- Added localized draft construction to `app/services/simbrief_descriptions.php` and integrated the draft lifecycle with the gallery editor save workflow.
- Updated `app/controllers/admin_simbrief.php`, `app/controllers/admin_galleries_edit_page/`, and `app/views/admin_gallery_forms.php` for compact editor data and SimBrief draft controls.
- Added `public/assets/gallery-modules/admin-gallery-grid-controls.js` and split gallery editor presentation styles into focused access, API, display, and media assets.
- Updated the Admin side-panel and gallery-editor modules to keep dynamically loaded editor actions working in place.

#### Database and compatibility

- Added no database migration; gallery descriptions, translations, SimBrief OFP data, and route maps continue to use their existing storage.
- Kept optional localization and flight-map behavior bounded by their existing feature and schema readiness checks.
- Kept the independent Windows uploader version and installer unchanged.

#### Tests and documentation

- Updated gallery-editor layout, localization, SimBrief draft, and side-panel lifecycle regression coverage.
- Updated the English, Czech, German, and Swedish manuals and rebuilt their PDFs for Version 0.117.

### User Impact

#### For visitors

- Public gallery behavior and visitor access rules are unchanged.

#### For administrators

- Common gallery settings are easier to find and edit in the compact workspace.
- SimBrief drafts can be reviewed and edited in the gallery's language fields before saving, with flight data attached after the gallery save succeeds.

## Version 0.116

Version 0.116 streamlines the physical gallery image editor with in-place photo controls, clearer selection and preview tools, and sorting by either filename or EXIF capture date. Existing gallery and photo authorization, server-side mutations, and database schema remain in place.

### Highlights

#### Gallery image editor

- Added a compact, responsive photo list with accessible thumbnail previews and row selection, including Shift-click range selection.
- Added direct row actions for photo visibility, gallery title-picture selection, editing, and deletion.
- Added filename and EXIF capture-date sorting; photos without a capture date remain at the end, and saved ordering continues to use the existing server route.
- Added a filename visibility preference for the current gallery editor and an optional browser-wide default.
- Grouped thumbnail maintenance controls to keep the main image workflow focused.

### Technical Details

#### Backend and frontend

- Added `public/assets/gallery-modules/admin-gallery-images.js` for dynamically mounted editor controls, keyboard and pointer previews, selection, and filename preferences.
- Updated `app/controllers/admin_galleries_edit_page/tab_images.php` and `app/views/admin_gallery_edit_tabs.php` to prepare and render compact image rows, capture dates, authorized preview thumbnails, and row actions.
- Updated `app/controllers/admin_images_bulk.php` to route explicit per-photo visibility, cover, and deletion actions through the existing authenticated and ownership-checked mutation pipeline.
- Updated image reordering, Admin side-panel handling, gallery-card rendering, and `public/assets/styles/side-panel.css` for the new workflow and in-place panel updates.

#### Database and compatibility

- Added no database migration; the editor uses existing image, visibility, ordering, and cover data.
- Preserved the existing direct-page form behavior when JavaScript is unavailable and the existing image authorization rules.
- Kept the independent Windows uploader version and installer unchanged.

#### Tests

- Updated Admin side-panel delegation and gallery-refresh fixtures for dynamically rendered image controls and panel lifecycle behavior.
- Updated frontend operational-policy, gallery-card visibility, Smart Gallery hardening, mutation hardening, and browser workflow support coverage.

### User Impact

#### For visitors

- Public gallery presentation and visitor workflows are unchanged.

#### For administrators

- Photo ordering and common row actions are easier to use within the gallery editor, including when the editor is opened in the Admin side panel.

## Version 0.115.1

Version 0.115.1 fixes the source-documentation checks introduced in 0.115 and updates GitHub Actions to supported Node.js runtimes. It documents existing PHP and JavaScript contracts more precisely, adds bounded checks for changed Bash and PowerShell functions, and removes obsolete action-runtime warnings. Gallery behavior and the independent Windows uploader version remain unchanged.

### Highlights

#### Reliable source checks and CI

- Fixed the CI documentation gate for the gallery-card renderer and hero-tag browser callbacks.
- Added changed-function documentation checks for ordinary brace-bodied Bash and PowerShell functions without executing the inspected scripts.
- Updated GitHub Actions to native Node.js 24 action runtimes while retaining Node.js 22 for the project's JavaScript tests.
- Disabled automatic package-manager caching in `actions/setup-node` because this repository has no Node package build.

### Technical Details

#### Backend and developer tooling

- Added `scripts/source_contracts/scripts.php` and integrated it with `scripts/check_source_documentation.php`, `scripts/source_contracts/changes.php`, and `scripts/source_contracts/php.php`.
- Required meaningful preceding comments or comment-based help for new or changed supported script functions, and compared existing function bodies through stable fingerprints.
- Refused unsupported or dynamic script forms with an explicit blocked result instead of reporting unchecked source as passing.
- Updated the documentation for `render_gallery_card()` in `app/controllers/public_gallery_cards.php` and the existing deployment-script exclusion helpers.

#### Frontend

- Completed callback and return-type JSDoc in `public/assets/gallery-modules/hero-tags.js` without changing browser behavior.

#### Database and compatibility

- Retained the existing database schema and migration set; this patch adds no migration.
- Retained installation-owned `custom.css` protection and WinApp 0.3.2 without rebuilding its installer.

#### CI and documentation

- Updated `.github/workflows/gallery-workflows.yml` to `actions/checkout@v7.0.1`, `actions/setup-node@v7.0.0`, and `actions/upload-artifact@v7.0.1` in both database jobs.
- Updated `TESTING.md` and the source ownership map for the bounded script checks.
- Updated the English, Czech, German, and Swedish manual editions and PDFs to 0.115.1.

### Tests

#### Source-contract regression coverage

- Added `tests/source_contract_scripts_test.php` for Bash and PowerShell declarations, comments, lexical boundaries, fingerprints, and unsupported dynamic forms.
- Covered strings, here-documents, here-strings, inline declarations, subshell bodies, and explicit refusal of unsafe or unsupported constructs.

### User Impact

#### For visitors

- Preserved the public gallery, gallery-card, and hero-tag behavior from 0.115.

#### For administrators and maintainers

- Fixed release verification failures caused by incomplete source contracts and removed deprecated Node.js action-runtime warnings from CI.
- Preserved existing administration workflows, stored settings, and the Windows uploader.

## Version 0.115

Version 0.115 brings a broad administration refresh: a clearer gallery tree, reviewed feature changes across entire branches, narrow password controls, integrated upload settings and mobile connections, and redesigned appearance, language, maintenance and diagnostic workspaces. It also corrects gallery-card orientation terminology with a compatibility migration, preserves installation-owned custom stylesheets more reliably, reduces redundant updater and navigation-data work, and strengthens PHP, JavaScript and Python source contracts. The independent Windows uploader remains at 0.3.2; its build now produces an installer and matching update metadata together in a version directory.

### Highlights

#### Administration workspace and navigation

- Redesigned the dashboard and its Overview, Galleries and maintenance surfaces with clearer hierarchy, compact summaries, field icons and contextual actions.
- Improved responsive layouts and the distinction between section navigation, operational state and actions that affect stored data.
- Preserved separately deferred Overview totals, gallery inventory and maintenance content so opening the dashboard does not eagerly perform every operation.
- Fixed unwanted Overview anchor jumps when activating or refreshing dashboard sections.
- Updated dynamically mounted tabs and panel fragments so their actions remain usable after an in-place refresh.
- Included optional presentation-schema health in the dashboard's action-required decision alongside security and destructive-mutation health.
- Kept unavailable optional storage visible as an actionable health condition rather than allowing an otherwise healthy dashboard summary to hide it.
- Refreshed the shared Admin chrome and control styling, and updated the setup wizard's integration with the redesigned workspaces.

#### Gallery tree, inventory and branch summaries

- Redesigned the gallery manager around the physical parent/child hierarchy, with expandable branches, feature indicators, direct actions and clearer current-row context.
- Added separate direct-image counts, descendant-image counts and subgallery counts, making a branch's contents easier to understand without opening every descendant.
- Added on, off and mixed feature summaries for branches rather than reporting only the root gallery's own preference.
- Prepared summaries from the existing ordered inventory and accumulated descendant values in a reverse pass.
- Reused already fetched ancestor rows when resolving inherited GPS/map preferences instead of repeatedly fetching each parent.
- Improved gallery tree interaction across fragment replacements and retained contextual navigation to the existing editors.
- Kept physical galleries and Smart Galleries distinct and retained their existing ownership and authorization rules.

#### Reviewed feature changes across gallery branches

- Added local staging of Maps, File names, Voting and Picture Game intentions from the gallery inventory.
- Applied each intention to the selected root and descendants, retaining chronological order when selected branches overlap.
- Added an exact server-prepared preview showing affected galleries, changed fields, current/proposed effective states, inherited map values and coupled side effects.
- Added explicit review and application controls; activating a feature indicator stages an intention without immediately changing stored preferences.
- Added draft discard and review cancellation, both of which preserve stored gallery state.
- Disabled application when the reviewed plan contains no stored preference changes.
- Counted replacing an inherited Maps preference with an explicit value as a change even when its current effective On/Off state matches.
- Preserved Picture Game's dependency on voting: enabling the game also enables voting, and disabling voting also disables the game.
- Checked canonical global capabilities before optional schema discovery and refused a requested feature when its effective capability is disabled.
- Rechecked hierarchy, settings, dependencies and the reviewed fingerprint under writer and database locks before transactional application.
- Refused stale plans after intervening gallery, hierarchy, revision or inherited-default changes instead of silently overwriting newer work.
- Updated gallery revisions/public content through existing mutation owners and returned affected IDs through the shared completion envelope.
- Reported sidecar or presentation refresh trouble as a warning after a committed change rather than falsely reporting that persistence failed.
- Kept staged intentions attached to the stable workspace when its owned fragment is rendered again.

#### Quick current-gallery password and visibility controls

- Added a narrow password action for one current gallery from the gallery workspace.
- Added entry controls for installing/replacing an own password and a corresponding removal action.
- Checked the submitted edit revision through the existing gallery editor concurrency owner before changing the password.
- Changed only password hash and access mode, preserving visibility, listing, share-token authority and unrelated display preferences.
- Preserved token-only protection when an own password is removed from a gallery that still has a validating share token.
- Preserved ancestor protection; removing a current gallery password does not remove protection inherited from an ancestor.
- Returned bounded row state with ID, localized visibility/access labels, own-password status and revision.
- Kept plaintext passwords and stored password/token hashes out of response state and discarded incidental output from credential-bearing requests.
- Updated inline visibility mutations to return the same acknowledged gallery state so inventory controls reflect the saved result.
- Preserved authentication, CSRF protection, canonical mutation completion and ordinary POST/redirect compatibility.

#### Gallery creation and public home

- Redesigned creation into content and location/visibility groups, with secondary settings under a disclosure.
- Kept title, description, tags and source language readily available, and retained optional SimBrief drafts and remembered defaults.
- Generated independent description-field IDs so labels remain correct when multiple creation fragments are mounted.
- Improved root-gallery creation on the public home for authenticated administrators.
- Added a first-gallery prompt only after confirming both the physical catalog and stored Smart Gallery definitions are empty, including disabled/private definitions; an empty visible listing alone no longer implies a new installation.
- Kept creation controls and catalog emptiness checks out of anonymous visitor rendering.
- Retained the side-panel creation/upload pipeline and refreshed affected home/gallery fragments after completion.
- Updated creation titles, submit labels and contextual links to match their surface.

#### Integrated uploads and mobile WebDAV in Settings

- Moved ordinary legacy upload preferences into Settings > Uploads while retaining original setters and direct routes.
- Added central editing of source format, automatic rename, browser-assisted availability, worker defaults/maxima/hard cap, ZIP batching and thumbnail source chunks.
- Submitted coupled worker and batching values together through the canonical browser-upload normalizer.
- Presented preferred ZIP targets and thumbnail chunks in MB while retaining byte-valued storage.
- Added `admin_legacy_upload_navigation_enabled`, defaulting to hidden legacy Upload photos, Upload settings and Mobile uploads links.
- Preserved legacy routes and ingestion services when menu links are hidden; the preference controls discovery.
- Added an embedded mobile connection workspace with its own fragment refresh.
- Prepared mobile connection state for central Settings and the original direct page.
- Skipped mobile inventory reads when the effective upload capability is disabled.
- Routed integrated mobile connection mutations through canonical JSON/AJAX completion.
- Preserved a newly created one-time password even when subsequent inventory refresh fails, avoiding loss of its only display.
- Reported refresh trouble separately from successful persistence and kept the saved connection recoverable.
- Updated Settings discovery, upload navigation and dynamic workspace handlers.

#### Appearance editing and live previews

- Reworked Appearance control groups and coordinated previews for site name, width, card orientation and tag-page grid.
- Added a resizable control/preview split with pointer and keyboard operation.
- Bounded resizing by both pane minimum widths and retained stacked presentation when horizontal resizing is unavailable.
- Remembered the split locally for the browser/path, separately from persistent public Theme settings.
- Refreshed theme form interactions, draft state, range/number synchronization and dynamically rendered previews.
- Added dedicated responsive styling for Appearance, Branding & Media, Layout, Language and Custom CSS.
- Preserved saved Theme values and canonical owners while reorganizing presentation.

#### Branding & Media and Layout

- Grouped banner, separator, favicon and background tools in a compact Site images workspace.
- Added clearer removal, background optimization and maintenance controls.
- Clarified that gallery fallback modes use each gallery's cover/collage choices and do not select/upload the global background.
- Retained separator limits, favicon cropping, original backgrounds and optimized derivatives.
- Simplified Layout explanations for shortcuts, card details, home grids, lightbox defaults and per-gallery grid reset.
- Corrected card previews and labels: vertical places photo above description; horizontal places it beside description.
- Kept titles, dates, tags and shortened Markdown-capable descriptions in prepared cards.
- Preserved permanent `progressive` and `responsive` renderers and existing access/no-JavaScript behavior.

#### Language settings and translation maintenance

- Split Language into Settings, Design, Editor and Diagnostics subtabs with dedicated styling.
- Added explicit pack edit links and opened Editor after supported pack selection, import/save or validation error.
- Paired selector-design controls with their own language panel, avoiding cross-form preview reads.
- Made the selector sample show only languages offered by the current draft.
- Marked the public draft default active when offered, otherwise using the first offered language.
- Cached language/default signatures separately from design to avoid unnecessary preview rebuilding.
- Preserved independent Admin language, public default and per-visitor override semantics.
- Updated English, Czech, German and Swedish JSON catalogs and PHP compatibility dictionaries together.

#### Gallery-card orientation compatibility

- Added `database/migrations/202610020001_gallery_description_layout_semantics.php` to preserve existing appearance after correcting orientation semantics.
- Converted explicit historical values in galleries, global Theme, tag-page settings and Smart Gallery presentation.
- Kept nullable inheritance distinct from explicit preferences.
- Preserved the old effective global default for established installations without an explicit saved value.
- Kept corrected vertical default for fresh installations without prior gallery/user/presentation evidence.
- Added installation `gallery_description_layout_semantics_version` and document `description_layout_semantics_version` markers.
- Carried document semantics through `gallery.json`, gallery migration metadata and Trash snapshots.
- Converted legacy imported/restored documents at existing boundaries so old data retains intended appearance.
- Made database conversion transactional and replay-safe; independent document markers prevent double swapping after interruption.
- Advanced gallery revisions for converted explicit values and refreshed public content revision.
- Preserved historical public CSS class hooks through an explicit canonical-to-historical orientation mapping so existing custom skins retain their interpretation.

#### Public cards, tags and search

- Extended shared tag disclosure to gallery cards, including vertical cards.
- Preserved all tags in server-rendered HTML; limits, expansion/collapse and scrolling remain browser enhancements.
- Adjusted responsive measurement/scrolling to each rendered card/hero owner.
- Applied the Theme tag sort order to card tags and initialized disclosure for dynamically inserted DOM content.
- Defaulted home search on when `public_home_search_enabled` has never been stored.
- Respected explicitly saved search OFF and the effective `public_search` capability.
- Updated Content/Display controls with public presentation and corrected orientation help.
- Fixed the dedicated GPS-override reset so an absent global-default checkbox does not overwrite the saved global preference.
- Returned direct/non-JavaScript Content actions to Maintenance > Content after URL rewrite, search, GPS, crawler-safety and path-regeneration operations.
- Updated public imports so deployed browsers receive tag behavior changes.

#### Custom stylesheet preservation and recovery

- Described installed CSS from the actual file instead of inferring contents/presence from a preset marker.
- Kept active CSS when no replacement is selected and gave a successful upload precedence over presets.
- Refused invalid selections/uploads without silently removing installed CSS.
- Required verified `app_settings` storage before replacing/resetting active CSS.
- Staged beside `public/assets/custom.css`, verified copied bytes with SHA-256 and preserved file permissions.
- Retained the prior stylesheet while activating replacement and persisting its marker.
- Rolled back on marker persistence failure and restored removed CSS when reset persistence failed.
- Distinguished retained recovery copies from failed first-install cleanup and exposed bounded guidance.
- Preserved unrelated Theme preferences during CSS save/replacement/reset.
- Excluded installation-owned `public/assets/custom.css` from both deployment helpers while retaining deployable `custom_css/` presets.

#### Updates workspace and remote budget

- Refreshed installed/latest versions, release selection, complete notes and API diagnostics together.
- Added in-place note-viewer handling synchronized after update operations.
- Read cached/bundled notes without GitHub requests during page rendering or version selection.
- Treated the installed package's own note entry as authoritative over older remote cache contents.
- Fetched missing pending-release notes only within explicit discovery's remaining budget.
- Stopped probing after a valid preferred stable branch; retained alternate branch compatibility fallback.
- Serialized explicit cross-tab discovery through a nonblocking lock.
- Throttled automatic Updates-page metadata checks to at most hourly.
- Released the PHP session before async GitHub I/O so other requests can continue.
- Reconciled installed status locally after activation instead of repeating remote discovery.
- Replaced stale check metadata while retaining a known newer release for rollback/beta cases.
- Added clear running, paused, failed, cancelled and completed job titles.
- Paused old background-job resumption when auto updates or installer capability is disabled.
- Retained resumable activation, retry, rollback and shared completion owners.

#### Navigation Data and flight maps

- Moved refresh to AJAX/background handling on dashboard, panel and dedicated page.
- Prepared a shared source/import snapshot rather than independent status blocks.
- Added weekly freshness for automatic OurAirports import and one-hour failure backoff.
- Serialized import ownership with a nonblocking lock and kept explicit manual refresh.
- Refused a downloaded snapshot with no valid airport rows or no valid navaid rows, preserving the previous navigation dataset.
- Released the PHP session for asynchronous imports and allowed an already-running atomic import to finish after the browser disconnects.
- Updated progress/panel handlers and owned-fragment refresh after background execution.
- Batched upserts into at most 200 rows per statement instead of one statement per point.
- Preserved the transaction around replacement and stale-source deletion.
- Returned bounded AJAX errors with generated reference and exception class in the diagnostic event.
- Preserved flight-map/navigation schema ownership and account/credential policy.

#### Trash, Smart Galleries, telemetry, logs and maintenance

- Refreshed Trash summaries and restore/permanent-delete confirmation presentation.
- Prepared remaining retention days and purge availability through the service-owned entry projection.
- Retained live-data overlap checks for broken entries before allowing purge.
- Preserved bounded emptying, authorization and recoverable gallery-level deletion.
- Refreshed Smart Gallery actions/listing while retaining relationship safety and result ownership.
- Localized rule groups as All, Any and Exclude while retaining stored `AND`, `OR` and `NOT` operators.
- Numbered rule conditions, labeled property/comparison/value fields and added empty-group and No value needed guidance.
- Escaped catalog labels, preserved missing referenced IDs in rule editing and mounted the builder on replacement fragments.
- Added slug/placement summaries and an Open link only for enabled public Smart Gallery definitions.
- Improved navdata, telemetry and log-maintenance hierarchy, icons, disclosures and responsive groups.
- Added dedicated log/telemetry styles and improved archive details/controls.
- Preserved collection preferences, retention and verified archive behavior.
- Updated maintenance and dynamic operation controls while retaining the shared refresh coordinator.

#### Windows installer and paired metadata

- Changed local build output to place installer and metadata together under `winapp/dist/<winapp-version>/`.
- Generated JSON from completed installer filename, independent version, size and SHA-256.
- Prepared both files before publication and restored the prior EXE if JSON replacement failed.
- Preserved older version directories, historical root artifacts and unrelated files.
- Kept build cleanup, bundled SimConnect verification and copied-helper native/version smoke before Inno Setup.
- Kept uploader at `0.3.2`; changed build output without adding an uploader runtime feature.
- Verified the changed builder with a successful build and retained its output as local qualification evidence.
- Reused the established byte-identical 0.3.2 installer/JSON for distribution so duplicate same-version assets across CMS releases cannot conflict on size or SHA-256.
- Updated the native smoke fixture's version-directory lookup.
- Documented attaching both matching files and retained manual 0.3.1-to-0.3.2 transition guidance.

### Technical Details

#### Backend owners and routes

- Added authenticated/CSRF-protected POST routes `admin_gallery_features_plan` and `admin_gallery_features_apply` in `app/controllers/admin_gallery_features.php`.
- Added `admin_gallery_password` in `app/controllers/admin_gallery_quick_access.php` with JSON and ordinary form completion.
- Added `app/services/gallery_feature_plans.php` and `app/models/gallery_feature_plans.php` for semantic normalization, snapshot, preview and transactional application.
- Bounded plans to 256 ordered intentions and checked JSON payload size before parsing.
- Added `app/services/gallery_editor_quick_access.php`, reusing editor revision, persistence and sidecar ownership.
- Added `app/models/gallery_description_layout_migration.php` and `app/services/gallery_description_layout_compatibility.php` for separate persistence/conversion orchestration.
- Added `app/services/flight_maps/navdata_update.php` through its module entrypoint for freshness/backoff/import policy.
- Updated layer loaders, dispatcher, dashboard/gallery inventory, lookup, Smart Gallery and inline mutation owners.
- Updated mobile, Settings, Theme, navdata and updater HTTP surfaces and prepared view models.
- Kept SQL/PDO in models, domain/filesystem orchestration in services, request authority in controllers and markup in views.

#### Database and migration recoverability

- Added one timestamped data/metadata migration, `202610020001_gallery_description_layout_semantics.php`; added no table or column.
- Reused `app_settings`, `galleries`, `smart_galleries` and `gallery_trash_entries`.
- Read required database inputs, including gallery revisions, before changing sidecars.
- Preflighted stored JSON and orientation-bearing sidecars before the first filesystem mutation.
- Traversed verified physical gallery/Trash roots, excluding linked and derivative/internal directories.
- Refused escaped, changed, unreadable or invalid orientation documents rather than silently completing partial conversion.
- Preserved original bytes until same-directory replacement was prepared.
- Used independent document markers and an atomic database checkpoint; failures leave migration retryable without double conversion.
- Preserved canonical migration ordering and schema-cache invalidation.
- Stored legacy-navigation preference in `app_settings` and reused upload/WebDAV storage.

#### Schema, security and diagnostics

- Required `available` gallery feature/revision storage; refused confirmed `missing` and `unknown` without a legacy write fallback.
- Required conclusive GPS, voting/game dependencies only for requested/coupled features.
- Refused effectively `disabled` features before unnecessary optional probes.
- Required verified access/revision authority before own-password changes; preserved stored access state on refusal.
- Required `available` settings storage before CSS replacement/reset, refusing `missing`/`unknown` before active-file mutation.
- Retained distinct mobile issuance and narrower revocation schema policies.
- Avoided treating omitted/inaccessible public content as proof of an empty catalog.
- Updated dashboard action aggregation without adding a competing security/presentation registry.
- Retained named presentation groups for GPS/EXIF, flight maps/navdata, voting, Picture Game, lightbox, OpenAI, AI metadata, SimBrief persistence, navigation accounts/cache, telemetry and reports.
- Preserved optional omission only through established read policy and conclusive schema for writes.
- Kept visibility, password/share-token, NSFW and media preflight at canonical access boundaries.
- Kept new credential/feature/CSS transport state bounded and free of SQL, database exceptions, stored credentials/tokens and private paths.
- Preserved secrets outside GitHub requests and source reports.

#### Frontend and in-place completion

- Added `public/assets/gallery-modules/admin-gallery-features.js`, `admin-gallery-quick-access.js`, `admin-update-notes.js` and `theme-appearance-resizer.js`.
- Updated gallery tree, dashboard, Settings, side panel, interaction policy, tabs, operations, setup, navdata, Smart Gallery, Trash and updater handlers.
- Updated Theme form, language-selector designer and shared hero-tag modules.
- Updated cache-busting imports in `public/assets/gallery.js` and `public/assets/public-gallery.js`.
- Retained canonical `ok`, `message`, typed `mutation`, stable `entity_ids`, affected `contexts`, optional `panel` and `fallback`.
- Passed full successful envelopes to `public/assets/gallery-modules/admin-mutation-completion.js`.
- Retained shared stale-read suppression, postconditions and refresh retries rather than adding workflow-specific pipelines.
- Covered dynamically replaced controls and panel URL/open-state persistence.
- Added dedicated gallery creation, logs, Smart Gallery, telemetry and Theme CSS, with updated dashboard/list/maintenance/Settings/Updates/public styles.
- Retained framework-free modules and existing direct-page/server fallbacks; staged feature planning and embedded quick controls use JavaScript.

#### Source contracts, Python policy and CI

- Extended meaningful declaration summaries, typed/described parameters and return contracts across PHP, JavaScript and Python/PYW.
- Checked missing/extra/duplicate parameters, missing type/description, duplicate returns and definite type mismatch.
- Required legal native PHP parameter/return declarations and Python annotations, including `-> None`.
- Supported typed Google-style Python docstrings and existing tagged contracts; retained braced JSDoc.
- Added isolated batched Python AST parsing without importing examined modules, executing code or evaluating annotations.
- Covered classes, nested/async functions, positional/keyword-only/variadic parameters and class/static methods; excluded lambdas lacking native documentation syntax.
- Added immutable value-free source reports with safe locations.
- Kept unchanged documentation debt advisory while enforcing new/material declarations and documentation regressions.
- Added `PHP_GALLERY_SOURCE_BASE`/`--base`, retaining local HEAD comparison.
- Fetched full CI history and used PR target, prior push head or manual first parent as comparison.
- Blocked missing runtime/history, invalid source and incomplete/unsupported changed parsing.
- Parsed current PHP source while comparing historical source lexically, allowing repairs of older runtime-incompatible syntax.
- Added `scripts/check_python_import_policy.php`, `scripts/source_contracts/python.php` and `scripts/source_contracts/python_scan.py`.
- Prohibited whole-tree `from __future__ import annotations`, including aliases, combined and multiline imports, without legacy exemption.
- Excluded comments, strings, `concurrent.futures` and unrelated future imports from prohibition.
- Registered policies with the central audit and uploaded JSON documentation/import reports in CI.
- Added no Python requirement to normal shared-hosted PHP requests.

#### Regression tests and fixtures

- Added subtree aggregation, feature-plan normalization/model/transaction and own-password contracts.
- Added browser coverage for trees, staged review/application, quick access, creation and empty public home.
- Added Content/Display, creation rendering, search-default and orientation/replay contracts.
- Added mobile Settings, coupled upload values and legacy-menu tests, including one-time password retention after failed inventory.
- Added CSS keep/selection/upload/staging/persistence rollback and recovery-copy tests.
- Added Appearance, Custom CSS, Language, Layout and Media rendering/browser fixtures.
- Added navdata freshness/backoff/UI and updater metadata-budget contracts.
- Added Trash retention/purge projection and confirmation coverage.
- Extended dashboard health, Settings, setup, side-panel lifecycle, mutation refresh and filesystem ownership.
- Extended Smart Gallery, operational policy, revisions, translations and resumable updater coverage.
- Added declaration type/docstring/import fixtures for valid/invalid source, non-execution and Git comparison.
- Extended audit registration and installer tests for exact paired metadata, publication rollback, first-build cleanup and older/unrelated artifact preservation.
- Retained central release orchestration; deterministic fixtures do not establish installed-Windows/UAC/simulator or live publication acceptance.

#### Documentation and artifacts

- Updated permanent architecture, schema, code map, Settings inventory and testing guidance.
- Aligned EN/CZ/DE/SV manual editions/dates and workflow/compatibility instructions with CMS `0.115`.
- Rebuilt matching tracked PDFs after final source edits and checked compiler diagnostics.
- Updated registered markers and `release-metadata.json` for `v_0.115`.
- Refreshed `app/core-manifest.json` after final sources/documents/artifacts.
- Kept audit/qualification evidence in ignored cache and publication as a separate maintainer action.

### User Impact

#### For visitors

- Improved gallery-card tag disclosure and consistent orientation terminology.
- Enabled search only where no preference exists and the canonical capability permits it.
- Preserved saved search settings, migrated gallery appearance, access/NSFW rules, authorized media and no-JavaScript navigation.
- Restricted creation/password/feature controls to authenticated administrators.

#### For administrators

- Made hierarchy, descendant contents and mixed features visible in one workspace.
- Added exact review for subtree changes and stale-preview refusal.
- Added quick own-password editing with normal editor concurrency protections.
- Consolidated upload/mobile settings and allowed restoration of legacy links.
- Improved Theme, language, maintenance and diagnostic navigation and dynamic controls.
- Preserved newly issued mobile passwords across inventory-read failures; required saving the one-time value before leaving.
- Required normal migration for orientation compatibility and recommended backing up database, gallery and Trash stores together.
- Kept interrupted conversion replayable after resolving its storage/document problem.
- Preserved installation-owned CSS on deploy and unrelated saves, retaining evidence when rollback cannot finish.
- Reduced remote/import repetition and left other requests available during async checks.
- Kept uploader version independent and paired installer/JSON in its version folder.
- Kept live acceptance and post-publication updater smoke separate from preparation and automated fixtures.

### Detailed changed-file inventory

This inventory records every implementation path changed from `v_0.114.2` to the release branch before marker preparation. The regenerated manifest and release-document edits are described above.

#### Database, tooling, CI and companion

- Updated or added `.github/workflows/gallery-workflows.yml`.
- Updated or added `TESTING.md`.
- Updated or added `database/migrations/202610020001_gallery_description_layout_semantics.php`.
- Updated or added `scripts/audit.php`.
- Updated or added `scripts/audit_registry.php`.
- Updated or added `scripts/check_python_import_policy.php`.
- Updated or added `scripts/check_source_documentation.php`.
- Updated or added `scripts/deploy.ps1`.
- Updated or added `scripts/deploy.sh`.
- Updated or added `scripts/source_contracts/changes.php`.
- Updated or added `scripts/source_contracts/javascript.php`.
- Updated or added `scripts/source_contracts/php.php`.
- Updated or added `scripts/source_contracts/python.php`.
- Updated or added `scripts/source_contracts/python_scan.py`.
- Updated or added `winapp/README.md`.
- Updated or added `winapp/build_installer.py`.

#### Routing and HTTP controllers

- Updated or added `app/bootstrap/dispatch.php`.
- Updated or added `app/controllers.php`.
- Updated or added `app/controllers/admin_dashboard.php`.
- Updated or added `app/controllers/admin_galleries_bulk.php`.
- Updated or added `app/controllers/admin_galleries_discovery.php`.
- Updated or added `app/controllers/admin_galleries_edit_page/tab_display.php`.
- Updated or added `app/controllers/admin_gallery_features.php`.
- Updated or added `app/controllers/admin_gallery_quick_access.php`.
- Updated or added `app/controllers/admin_logs.php`.
- Updated or added `app/controllers/admin_public_inline.php`.
- Updated or added `app/controllers/admin_settings.php`.
- Updated or added `app/controllers/admin_theme_actions.php`.
- Updated or added `app/controllers/admin_theme_appearance.php`.
- Updated or added `app/controllers/admin_theme_custom_css.php`.
- Updated or added `app/controllers/admin_theme_language.php`.
- Updated or added `app/controllers/admin_theme_layout.php`.
- Updated or added `app/controllers/admin_theme_media.php`.
- Updated or added `app/controllers/admin_trash.php`.
- Updated or added `app/controllers/mobile_webdav.php`.
- Updated or added `app/controllers/navigation_data.php`.
- Updated or added `app/controllers/public_gallery_cards.php`.
- Updated or added `app/controllers/public_gallery_home.php`.
- Updated or added `app/controllers/shared_layout.php`.
- Updated or added `app/controllers/smart_galleries.php`.
- Updated or added `app/controllers/updates.php`.

#### Views and maintained catalogs

- Updated or added `app/lang/cs.json`.
- Updated or added `app/lang/cs.php`.
- Updated or added `app/lang/de.json`.
- Updated or added `app/lang/de.php`.
- Updated or added `app/lang/en.json`.
- Updated or added `app/lang/en.php`.
- Updated or added `app/lang/sv.json`.
- Updated or added `app/lang/sv.php`.
- Updated or added `app/views/admin_chrome.php`.
- Updated or added `app/views/admin_dashboard.php`.
- Updated or added `app/views/admin_dashboard_sections.php`.
- Updated or added `app/views/admin_gallery_discovery.php`.
- Updated or added `app/views/admin_gallery_forms.php`.
- Updated or added `app/views/admin_language_settings.php`.
- Updated or added `app/views/admin_logs.php`.
- Updated or added `app/views/admin_settings.php`.
- Updated or added `app/views/admin_telemetry.php`.
- Updated or added `app/views/admin_theme.php`.
- Updated or added `app/views/admin_trash.php`.
- Updated or added `app/views/admin_ui.php`.
- Updated or added `app/views/admin_updates.php`.
- Updated or added `app/views/layout.php`.
- Updated or added `app/views/mobile_webdav.php`.
- Updated or added `app/views/navigation_data.php`.
- Updated or added `app/views/public_gallery_cards.php`.
- Updated or added `app/views/public_gallery_pages.php`.
- Updated or added `app/views/public_tags.php`.
- Updated or added `app/views/smart_galleries.php`.

#### Persistence models

- Updated or added `app/models.php`.
- Updated or added `app/models/admin_dashboard.php`.
- Updated or added `app/models/flight_maps.php`.
- Updated or added `app/models/galleries.php`.
- Updated or added `app/models/gallery_description_layout_migration.php`.
- Updated or added `app/models/gallery_feature_plans.php`.
- Updated or added `app/models/smart_galleries.php`.

#### Domain services

- Updated or added `app/services.php`.
- Updated or added `app/services/admin_dashboard.php`.
- Updated or added `app/services/admin_settings_registry.php`.
- Updated or added `app/services/custom_css.php`.
- Updated or added `app/services/flight_maps.php`.
- Updated or added `app/services/flight_maps/navdata_update.php`.
- Updated or added `app/services/gallery_description_layout.php`.
- Updated or added `app/services/gallery_description_layout_compatibility.php`.
- Updated or added `app/services/gallery_editor_quick_access.php`.
- Updated or added `app/services/gallery_feature_plans.php`.
- Updated or added `app/services/gallery_lookup.php`.
- Updated or added `app/services/gallery_migration.php`.
- Updated or added `app/services/gallery_migration/metadata.php`.
- Updated or added `app/services/gallery_migration/target_setup.php`.
- Updated or added `app/services/gallery_sidecars.php`.
- Updated or added `app/services/gallery_trash.php`.
- Updated or added `app/services/public_search.php`.
- Updated or added `app/services/updates_jobs/activation.php`.
- Updated or added `app/services/updates_patch_notes.php`.
- Updated or added `app/services/updates_remote.php`.
- Updated or added `app/services/updates_status.php`.

#### Browser modules and styles

- Updated or added `public/assets/gallery-modules/admin-dashboard-workspace.js`.
- Updated or added `public/assets/gallery-modules/admin-gallery-features.js`.
- Updated or added `public/assets/gallery-modules/admin-gallery-list.js`.
- Updated or added `public/assets/gallery-modules/admin-gallery-quick-access.js`.
- Updated or added `public/assets/gallery-modules/admin-interaction-policy.js`.
- Updated or added `public/assets/gallery-modules/admin-language-selector-design.js`.
- Updated or added `public/assets/gallery-modules/admin-navdata-panel.js`.
- Updated or added `public/assets/gallery-modules/admin-navdata-update.js`.
- Updated or added `public/assets/gallery-modules/admin-operations.js`.
- Updated or added `public/assets/gallery-modules/admin-settings-workspace.js`.
- Updated or added `public/assets/gallery-modules/admin-setup-wizard.js`.
- Updated or added `public/assets/gallery-modules/admin-side-panel.js`.
- Updated or added `public/assets/gallery-modules/admin-smart-galleries.js`.
- Updated or added `public/assets/gallery-modules/admin-tabs.js`.
- Updated or added `public/assets/gallery-modules/admin-trash.js`.
- Updated or added `public/assets/gallery-modules/admin-update-jobs.js`.
- Updated or added `public/assets/gallery-modules/admin-update-notes.js`.
- Updated or added `public/assets/gallery-modules/hero-tags.js`.
- Updated or added `public/assets/gallery-modules/theme-appearance-resizer.js`.
- Updated or added `public/assets/gallery-modules/theme-form.js`.
- Updated or added `public/assets/gallery.js`.
- Updated or added `public/assets/public-gallery.js`.
- Updated or added `public/assets/styles/admin-dashboard.css`.
- Updated or added `public/assets/styles/admin-gallery-create.css`.
- Updated or added `public/assets/styles/admin-gallery-list.css`.
- Updated or added `public/assets/styles/admin-logs.css`.
- Updated or added `public/assets/styles/admin-maintenance-center.css`.
- Updated or added `public/assets/styles/admin-settings.css`.
- Updated or added `public/assets/styles/admin-smart-galleries.css`.
- Updated or added `public/assets/styles/admin-telemetry.css`.
- Updated or added `public/assets/styles/admin-theme-custom-css.css`.
- Updated or added `public/assets/styles/admin-theme-editor.css`.
- Updated or added `public/assets/styles/admin-theme-language.css`.
- Updated or added `public/assets/styles/admin-theme-layout.css`.
- Updated or added `public/assets/styles/admin-theme-media.css`.
- Updated or added `public/assets/styles/admin-update.css`.
- Updated or added `public/assets/styles/public-shared.css`.
- Updated or added `public/assets/styles/public.css`.
- Updated or added `public/assets/styles/utilities.css`.

#### Regression tests and isolated fixtures

- Updated or added `tests/admin_content_display_test.php`.
- Updated or added `tests/admin_dashboard_fragment_controller_test.php`.
- Updated or added `tests/admin_filesystem_ownership_test.php`.
- Updated or added `tests/admin_gallery_features_browser_test.mjs`.
- Updated or added `tests/admin_gallery_quick_access_browser_test.mjs`.
- Updated or added `tests/admin_gallery_quick_access_test.php`.
- Updated or added `tests/admin_gallery_subtree_summaries_test.php`.
- Updated or added `tests/admin_gallery_tree_browser_test.mjs`.
- Updated or added `tests/admin_maintenance_health_test.php`.
- Updated or added `tests/admin_navigation_data_ui_test.php`.
- Updated or added `tests/admin_panel_lifecycle_browser_test.mjs`.
- Updated or added `tests/admin_settings_controller_test.php`.
- Updated or added `tests/admin_settings_registry_test.php`.
- Updated or added `tests/admin_setup_wizard_rendering_test.php`.
- Updated or added `tests/admin_side_panel_created_gallery_refresh_test.mjs`.
- Updated or added `tests/admin_side_panel_gallery_refresh_test.mjs`.
- Updated or added `tests/admin_smart_galleries_browser_test.mjs`.
- Updated or added `tests/admin_smart_galleries_ui_test.php`.
- Updated or added `tests/admin_trash_confirmation_browser_test.mjs`.
- Updated or added `tests/admin_trash_rendering_test.php`.
- Updated or added `tests/admin_updates_ui_test.php`.
- Updated or added `tests/admin_upload_workspace_browser_test.mjs`.
- Updated or added `tests/audit_runner_test.php`.
- Updated or added `tests/custom_css_preservation_test.php`.
- Updated or added `tests/fixtures/admin_dashboard_workspace.html`.
- Updated or added `tests/fixtures/admin_gallery_features.html`.
- Updated or added `tests/fixtures/admin_gallery_quick_access.html`.
- Updated or added `tests/fixtures/admin_gallery_tree.html`.
- Updated or added `tests/fixtures/admin_smart_galleries.html`.
- Updated or added `tests/fixtures/admin_trash_confirmation.html`.
- Updated or added `tests/fixtures/admin_update_jobs.html`.
- Updated or added `tests/fixtures/admin_upload_workspace.html`.
- Updated or added `tests/fixtures/gallery_creation.html`.
- Updated or added `tests/fixtures/public_home_creation.html`.
- Updated or added `tests/fixtures/theme_appearance.html`.
- Updated or added `tests/fixtures/theme_custom_css.html`.
- Updated or added `tests/fixtures/theme_language.html`.
- Updated or added `tests/fixtures/theme_layout.html`.
- Updated or added `tests/fixtures/theme_media.html`.
- Updated or added `tests/frontend_operational_policy_test.mjs`.
- Updated or added `tests/gallery_creation_browser_test.mjs`.
- Updated or added `tests/gallery_creation_rendering_test.php`.
- Updated or added `tests/gallery_description_layout_compatibility_test.php`.
- Updated or added `tests/gallery_feature_plan_model_test.php`.
- Updated or added `tests/gallery_feature_plans_test.php`.
- Updated or added `tests/gallery_revision_runtime_updates_test.php`.
- Updated or added `tests/gallery_trash_admin_entries_test.php`.
- Updated or added `tests/legacy_upload_navigation_test.php`.
- Updated or added `tests/mobile_upload_settings_integration_test.php`.
- Updated or added `tests/navdata_background_refresh_test.php`.
- Updated or added `tests/public_home_creation_browser_test.mjs`.
- Updated or added `tests/public_home_creation_ui_test.php`.
- Updated or added `tests/public_search_default_policy_test.php`.
- Updated or added `tests/python_import_policy_test.php`.
- Updated or added `tests/smart_gallery_high_priority_hardening_test.php`.
- Updated or added `tests/source_contract_changes_test.php`.
- Updated or added `tests/source_type_documentation_test.php`.
- Updated or added `tests/stage4_mutation_hardening_contract_test.php`.
- Updated or added `tests/support/admin_gallery_tree_render_fixture.php`.
- Updated or added `tests/support/gallery_creation_render_fixture.php`.
- Updated or added `tests/support/language_design_defaults_fixture.php`.
- Updated or added `tests/support/public_card_layout_fixture.php`.
- Updated or added `tests/support/theme_appearance_fixture.php`.
- Updated or added `tests/theme_appearance_browser_test.mjs`.
- Updated or added `tests/theme_appearance_rendering_test.php`.
- Updated or added `tests/theme_custom_css_browser_test.mjs`.
- Updated or added `tests/theme_custom_css_rendering_test.php`.
- Updated or added `tests/theme_language_browser_test.mjs`.
- Updated or added `tests/theme_language_rendering_test.php`.
- Updated or added `tests/theme_layout_browser_test.mjs`.
- Updated or added `tests/theme_layout_rendering_test.php`.
- Updated or added `tests/theme_media_browser_test.mjs`.
- Updated or added `tests/theme_media_rendering_test.php`.
- Updated or added `tests/translation_catalog_consistency_test.php`.
- Updated or added `tests/updater_metadata_budget_test.php`.
- Updated or added `tests/updater_resumable_state_machine_test.php`.
- Updated or added `tests/uploads_settings_merge_test.php`.
- Updated or added `winapp/tests/test_build_installer.py`.
- Updated or added `winapp/tests/test_update_helper.py`.

## Version 0.114.2

Version 0.114.2 includes Windows uploader 0.3.2 with repaired native Windows DLL loading in the frozen update helper and a build-time check of the copied helper. Users upgrading from 0.3.1 should manually launch the verified 0.3.2 installer, because their running older application still copies its older helper before replacement.

### Highlights

#### Windows updater native libraries

- Fixed a collision between the bundled `VERSION` text file and extensionless Windows version-library loading on case-insensitive systems.
- Loaded `version.dll`, `kernel32.dll` and `shell32.dll` explicitly through the Windows System32 search policy.
- Added a read-only smoke check of a copied frozen helper before installer compilation, refusing packaging on native API failure or mismatched PE version.
- Documented the manual upgrade path from 0.3.1 and retained its original installer/integrity metadata for diagnosis.

### Technical Details

#### Windows companion and build

- Updated `winapp/uploader/update_helper.py` with explicit DLL extensions and `LOAD_LIBRARY_SEARCH_SYSTEM32` for version, process and installation APIs.
- Added `--self-update-smoke` dispatch in `winapp/gallery_watch_upload.pyw` before configuration, logging or GUI startup; checked the frozen executable version against bundled `VERSION` without installing, elevating or changing updater state.
- Updated `winapp/build_installer.py` to copy the built executable into an owned helper-smoke directory and run the native check before Inno Setup, preserving previous successful installers on build refusal.
- Kept the companion version independent at `0.3.2`; reused its completed installer and matching SHA-256 metadata.

#### Tests

- Extended `winapp/tests/test_update_helper.py` with the bundled VERSION collision and restricted native-library loading contracts.
- Added `winapp/tests/test_self_update_smoke.py` for matching/mismatched versions, native failures and early diagnostic dispatch.
- Extended `winapp/tests/test_build_installer.py` for copied-helper smoke ordering, isolated state and build failure cleanup.
- Updated `TESTING.md` to distinguish frozen API checks from live installed-app/UAC acceptance.

#### CMS backend, database and frontend

- Added no CMS route, setting, capability, migration, table or column.
- Preserved Admin workflows, public gallery rendering, media authorization and both thumbnail renderers.
- Preserved verified downloads, worker drain, update installation policy and existing SimConnect behavior.

#### Documentation and release artifacts

- Updated the Windows upgrade instructions in `README.md`, `winapp/README.md` and all four manual editions.
- Aligned all manual versions/dates with CMS `0.114.2` and rebuilt their PDFs.
- Updated registered release markers and `release-metadata.json` for `v_0.114.2` and refreshed `app/core-manifest.json`.

### User Impact

#### For administrators

- Improved future self-update helper behavior when launched from the corrected 0.3.2 application.
- Required manual installation of the verified 0.3.2 installer when upgrading from 0.3.1; an already copied old helper can still report its previous native-DLL error after replacement.
- Required no new CMS configuration or database migration.
- Kept live installed update handoff and simulator acceptance as target-PC checks; automated fixtures do not prove those workflows.

#### For visitors

- Preserved existing public gallery and authorized media behavior.

## Version 0.114.1

Version 0.114.1 makes Admin Settings more compact, defers dashboard totals and gallery lists until needed, and includes Windows uploader 0.3.1 with corrected SimConnect aircraft reply correlation.

### Highlights

#### Compact Admin Settings

- Grouped website address, site name, Admin/public languages and navigation in General, with advanced summaries collapsed.
- Added section loading and saving in place, a sticky save bar, changed-setting counts and draft revert.
- Preserved search, specialist destinations, stable section links and ordinary forms without JavaScript; mapped historical Website address links to General.
- Added explicit links after saved address or Admin-language changes and avoided rewriting an unchanged address.

#### Deferred dashboard

- Displayed the dashboard shell before loading Overview totals and loaded the Galleries list when requested.
- Added retry for failed section reads and preserved direct URLs and no-JavaScript navigation.
- Calculated Overview from indexed gallery/image metadata without cover resolution or filesystem scanning.

#### Windows uploader 0.3.1

- Fixed aircraft reply matching to use request and definition IDs without requiring a zero returned object ID.
- Added bounded receive metadata, dispatch sequence and specific packet-rejection diagnostics.
- Preserved primary failure reasons through path redaction and removed duplicate SimConnect activity markers.
- Preserved optional simulator coordinates, MSFS 2024 camera/aircraft fallback and verified self-update behavior.

### Technical Details

#### Backend

- Updated `app/controllers/admin_settings.php` and `app/services/admin_settings_registry.php` for compact section models and canonical JSON mutation responses.
- Added authenticated read-only `admin_dashboard_fragment` routing in `app/bootstrap/dispatch.php` and `app/controllers/admin_dashboard.php`, with private/no-store responses and early session release.
- Updated `app/models/admin_dashboard.php` and `app/services/admin_dashboard.php` for separately prepared shell, Overview and Galleries models.
- Preserved canonical visibility, security/schema policy, authentication and CSRF boundaries; added no capability or schema policy.

#### Frontend

- Added `public/assets/gallery-modules/admin-settings-workspace.js` and `public/assets/gallery-modules/admin-dashboard-workspace.js`, updated Settings search and cache-busting imports in `public/assets/gallery.js`.
- Updated Admin views/styles and all four interface language catalogs; preserved panel interaction and shared mutation completion.

#### Windows companion

- Updated `winapp/uploader/simconnect_location.py` and `winapp/gallery_watch_upload.pyw` for reply decoding and diagnostics.
- Validated three FLOAT64 coordinates following the 40-byte receive header, including nonzero object IDs, truncated packets and valid zero coordinates.
- Kept WinApp at its independent version `0.3.1`, with its matching installer and integrity metadata.
- Documented that the reported FS2020 timeout did not record its reply object ID; simulated tests do not establish a live fix on the affected PC.

#### Database and compatibility

- Added no migration, table or column and required no new CMS configuration.
- Preserved public media authorization, both thumbnail renderers and existing updater behavior.

#### Tests and documentation

- Added `tests/admin_settings_controller_test.php`, `tests/admin_dashboard_fragment_controller_test.php` and `tests/admin_dashboard_overview_totals_test.php`, with updated navigation/rendering contracts.
- Added registered Settings/dashboard browser fixtures and tests for deferred reads, in-place save, retry and panel lifecycle.
- Extended `winapp/tests/test_simconnect.py` and `winapp/tests/test_redesign.py` with independent wire packets and diagnostic-redaction checks.
- Updated permanent architecture, testing and Settings documentation, aligned all four manuals and rebuilt their PDFs.
- Updated release metadata and registered markers for `v_0.114.1`, removed the completed temporary SimConnect plan and refreshed `app/core-manifest.json`.

### User Impact

#### For administrators

- Improved navigation and readability of Settings and reduced unnecessary dashboard work.
- Preserved ordinary form workflows without JavaScript and existing setting ownership.
- Improved uploader diagnostics and corrected a confirmed SimConnect filter bug; required live FS2020/FS2024 acceptance on target Windows systems.

#### For visitors

- Preserved public gallery behavior and authorized media access.

## Version 0.114

Version 0.114 introduces verified self-updates for the independent Windows uploader 0.3.0. It discovers newer stable installers across CMS releases, verifies downloads and waits for background work to stop safely before handing installation to Windows.

### Highlights

#### Windows uploader updates

- Added automatic update checks at startup and at most hourly, with a persisted per-user attempt timestamp and a manual **Check for updates** action.
- Added a nonmodal **Update / Later** offer, actual download progress and download cancellation.
- Verified installer size and SHA-256 before installation; refused missing or conflicting integrity metadata for the highest newer version.
- Preserved saved settings and recoverable jobs while waiting up to 120 seconds for background workers to finish safely.
- Added an external installation helper, Windows UAC handoff, actual installer progress and restart as the original user after successful version verification.

### Technical Details

#### Windows companion

- Added `winapp/uploader/self_update.py` for bounded paginated GitHub discovery, numeric installer versions, HTTPS URL validation and verified streaming downloads.
- Accepted GitHub asset digests or matching same-release `winapp-update.json` metadata; accepted duplicate installers only when their size and SHA-256 agree.
- Added `winapp/uploader/update_ui.py` for scheduling, cancellation, progress and safe worker drain, and `winapp/uploader/update_helper.py` for independent ticket/download verification and exact installation-path process checks.
- Updated `winapp/gallery_watch_upload.pyw` to block new work during update handoff and `winapp/installer.iss` to refuse self-update while target processes remain, without force-closing workers.
- Updated standalone build/version metadata to WinApp `0.3.0`; kept CMS and companion versions independent.

#### Backend, database and frontend compatibility

- Added no CMS route, setting, capability, database migration, table or column.
- Preserved public gallery rendering, authorized media access and existing Admin workflows.
- Kept gallery credentials out of GitHub requests and preserved unrelated uploader copies and download files.
- Preserved manual installer launch for native Python/source runs and existing shutdown behavior for directly launched installers.
- Reopened the existing application after UAC cancellation; required a Windows restart when Setup returns restart-required status. Added no automatic rollback.

#### Tests

- Added `winapp/tests/test_self_update.py` for release selection, digest/URL limits, duplicate handling, download verification and cancellation.
- Added `winapp/tests/test_update_helper.py` and `winapp/tests/test_update_ui.py` for scheduling, worker drain, ticket ownership, installation handoff and progress.
- Added `winapp/tests/test_self_update_source_contracts.py` and extended `winapp/tests/test_build_installer.py` for updater source and installer contracts.
- Documented installed-Windows acceptance in `TESTING.md`; fake transports and source contracts do not replace live UAC, process shutdown, installation and simulator checks.

#### Documentation and release artifacts

- Updated `README.md`, `ARCHITECTURE.md`, `CODEMAP.md` and `winapp/README.md` with updater ownership, security and operational behavior.
- Aligned all four manual editions with CMS `0.114` and Windows uploader `0.3.0`, and rebuilt their PDFs.
- Updated registered release markers and `release-metadata.json` for `v_0.114` and refreshed `app/core-manifest.json`.

### User Impact

#### For administrators

- Added verified Windows uploader updates without a separate Python installation for standalone users.
- Required no new CMS configuration or database migration.
- Kept existing settings and jobs through upgrades; unsaved Settings fields must be saved before updating.
- Required publication of the uploader installer with trusted digest metadata before automatic discovery can offer it.

#### For visitors

- Preserved existing public gallery and media behavior.

## Version 0.113.2

Version 0.113.2 makes the Admin Updates page more compact and refreshes the installed version and release status in place when an update finishes. It preserves existing updater jobs, recovery controls and public gallery behavior.

### Highlights

#### Clearer update status

- Reorganized release status and actions into a compact, responsive workspace, with repository and GitHub API details in a collapsed diagnostics section.
- Refreshed the installed version, channel and update availability after a completed job without closing the Admin panel, changing the browser URL or reloading the page.
- Hid obsolete stable-update controls after completion and added an explicit status-refresh retry when the passive read fails.
- Kept progress synchronized between Status and Advanced tools.

### Technical Details

#### Backend

- Updated `app/controllers/updates.php` with a prepared release view model and the authenticated, private/no-store `update_status_fragment` response.
- Compared cached discovery with the version currently installed on disk; the completion refresh reads local metadata without a new GitHub check.
- Preserved installer capability gating, access checks, CSRF-protected mutations and durable update/recovery semantics.

#### Frontend

- Updated `app/views/admin_updates.php` and `public/assets/styles/admin-update.css` for compact release presentation and responsive layout.
- Updated `public/assets/gallery-modules/admin-update-jobs.js` with delegated status retry, duplicate-request suppression and protection against detached or superseded job fragments.
- Updated the module cache key in `public/assets/gallery.js` and added status-refresh messages in English, Czech, German and Swedish.

#### Database and compatibility

- Added no migration, table, column, capability or setting.
- Preserved ordinary POST fallback for browsers without JavaScript and existing stable/beta installation and rollback workflows.
- Kept WinApp at its independent version `0.2.0`; changed no companion runtime, dependency or installer behavior.

#### Tests

- Added `tests/admin_updates_ui_test.php` for compact markup, passive status refresh, installed-version comparison and frontend wiring contracts.
- Extended `tests/admin_update_jobs_browser_test.mjs` and its fixture for completion refresh, failure/retry, synchronized progress and unchanged URL/open-panel behavior.
- Updated `tests/admin_panel_lifecycle_browser_test.mjs` and registered updater browser coverage in `scripts/audit_registry.php`.

#### Documentation and release artifacts

- Updated registered release markers and metadata for `v_0.113.2`.
- Updated all four manual editions with the completion/status-refresh workflow and rebuilt their PDFs.
- Refreshed `app/core-manifest.json`.

### User Impact

#### For administrators

- Improved readability of update status and kept completed installation information current without manual page reloads.
- Added a local status-refresh retry without repeating installation or consuming another GitHub discovery request.
- Required no new configuration or database migration.

#### For visitors

- Preserved public gallery rendering and authorized media access.

## Version 0.113.1

Version 0.113.1 fixes the Windows installer dependency regression test so it runs on headless Linux CI workers without Tkinter. It preserves the gallery and WinApp runtime behavior introduced in Version 0.113.

### Highlights

#### Portable installer regression coverage

- Fixed the five negative dependency cases that previously failed when Tkinter was unavailable on the CI host.
- Kept validation of unsupported or missing build packages independent of the test machine's GUI runtime and installed build tools.

### Technical Details

#### Tests

- Updated `winapp/tests/test_build_installer.py` to provide an import-only Tkinter stub for negative package checks.
- Preserved the expected `SystemExit` and `PackageNotFoundError` outcomes, with package refusal before Tcl creation or imports of actual installer build dependencies.
- Preserved real installer dependency checks; changed test isolation only.

#### Backend, database and frontend

- Added no application feature, route, capability, setting, database migration, table or column.
- Preserved existing access policies, cooperative gallery behavior, thumbnail ownership and browser assets.
- Kept the independent WinApp version at `0.2.0`; added no companion runtime or installer behavior change.

#### Documentation and release artifacts

- Updated registered release markers and `release-metadata.json` for `v_0.113.1`.
- Updated the English, Czech, German and Swedish manual editions and rebuilt their PDFs.
- Refreshed `app/core-manifest.json` for the release.

### User Impact

#### For administrators

- Required no new configuration or database migration beyond the existing Version 0.113 upgrade requirements.
- Improved reproducibility of installer dependency regression checks on headless CI workers; hosted CI confirmation remains separate from local verification.

#### For visitors

- Preserved public gallery behavior, shared photograph pages and authorized media access.

## Version 0.113

Version 0.113 adds opt-in cooperative galleries across independently operated HTTPS installations, with explicit approval by every participant and authorized shared previews. It also strengthens thumbnail ownership and delivers WinApp 0.2.0 with automatic Microsoft Flight Simulator location selection and safer installer shutdown.

### Highlights

#### Cooperative galleries

- Added Friendly galleries and Album collaborations under Advanced Settings, with resumable two-way pairing and independent credentials for each peer.
- Added public album reference codes, immutable proposals, local approval, email invitations, bounded delivery and verification, withdrawal and separately approved successor groups.
- Added a shared photograph page with source attribution, pagination, progressive loading, short-lived derivative links and no-JavaScript navigation.
- Kept cooperation disabled by default. Turning it off preserves stored identities, friendships, proposals and preferences; friendship alone grants no album access.
- Limited export to currently public, listed, password-free, non-NSFW albums and their authorized existing JPEG/WebP thumbnails and previews. Originals, protected audiences and remote writes remain unsupported.

#### Thumbnail ownership and Windows uploads

- Added complete source-path identities, including extensions, for new photographs while preserving existing thumbnail names and metadata without bulk regeneration.
- Refused ambiguous derivative ownership, including collisions with private or NSFW siblings; preserved verified derivative bytes during copies and clones and invalidated shared cache artifacts before ownership changes.
- Added automatic MSFS 2024 camera-position selection with bounded aircraft fallback, and MSFS 2020 aircraft-position support through one SimConnect transport in WinApp 0.2.0.
- Added bundled-runtime validation and installer application shutdown handling. Simulator location remains optional; unavailable coordinates do not block photograph uploads.

### Technical Details

#### Backend and security

- Added cooperative models, services, controllers and prepared views, including `app/services/cooperative_galleries.php`, `app/services/cooperative_pairing.php`, `app/services/cooperative_proposals.php` and `app/services/cooperative_content.php`.
- Registered `cooperative_galleries` in the canonical capability and Settings registries. Disabled paths remain dormant without optional storage or network work.
- Added encrypted outbound credentials, hashed inbound secrets, expiring challenges, generation binding and nonce/revision checked reservations that reject replay, stale responses and concurrent ownership conflicts.
- Added `app/services/outbound_http.php` with paired HTTPS origins, fixed protocol routes, public-address DNS pinning, no redirects and bounded responses/timeouts; reused configured mail through `app/services/configured_mail.php`.
- Required verified available cooperative storage for authority and writes. Confirmed missing or unknown required schema refuses pairing, consent, activation and export; credential revocation uses its narrower verified requirements.
- Preflighted ingestion and thumbnail identity/metadata storage before target files are committed. Unknown schema refuses mutation; prepared uploads and migration jobs remain recoverable. Existing identity-version-zero derivatives retain only verified legacy compatibility.
- Rechecked album/ancestor visibility, passwords, NSFW policy, image ownership, current consent, peer generation, membership revision and lease at content reads. Stripped derivative metadata in memory within an 8 MiB input limit and refused malformed or animated containers.
- Added private/no-store, noindex and referrer protection, bounded safe errors and credential redaction. Diagnostics do not expose raw SQL, database exceptions, credentials, bearer capabilities or private paths.
- Added `scripts/cooperative_renew.php` for explicit per-group renewal; ordinary shared-page use can advance bounded verification on demand without a scheduler.

#### Database

- Added `database/migrations/202609270001_cooperative_galleries_foundations.php` for `cooperative_albums`, `cooperative_identity`, `cooperative_peers` and `cooperative_groups`.
- Added `database/migrations/202609270002_cooperative_pairing.php` for resumable `cooperative_pairings` invitations and retry state.
- Added `database/migrations/202609300001_thumbnail_source_identity.php` for `images.thumbnail_source_identity_version` and `idx_images_gallery_filename`. Existing rows retain version 0; subsequent inserts receive version 1. The migration does not rename, regenerate or delete existing derivatives.

#### Frontend and Windows companion

- Added `public/assets/cooperative-gallery.js`, `public/assets/cooperative-gallery.css` and `public/assets/gallery-modules/admin-cooperative-galleries.js`, with updated browser cache keys.
- Integrated persistent Admin actions with dynamic in-place panels and the canonical mutation completion envelope, retaining POST fallback and explicit consent.
- Added English, Czech, German and Swedish interface strings.
- Added `winapp/uploader/simconnect_location.py`, packet/exception correlation, position-source diagnostics and validation of the single packaged x64 SimConnect runtime in `winapp/build_installer.py`.
- Updated all four product manual editions and permanent architecture, schema, testing, source-map and Settings documentation; removed completed temporary implementation plans.

#### Tests

- Added isolated multi-installation pairing, proposal, activation, maintenance, metadata, catalog and derivative-byte fixtures, plus Admin panel and public cooperative browser contracts.
- Added thumbnail identity, ownership, bounded indexed lookup, copy and clone regressions, including a 10,000-row lookup fixture.
- Added outbound transport and SimConnect/installer regression coverage; retained both supported public thumbnail renderers.
- Updated CI workflow policy while preserving explicit coverage gaps for unavailable browser or deployment environments. Isolated fixtures do not qualify real independent HTTPS peers, production SMTP or live simulator behavior.

### User Impact

#### For administrators

- Added explicitly approved shared galleries without transferring ownership of albums or originals. Every participant must pair directly and approve the exact membership and scopes.
- Required normal database migrations before using the new storage. Existing photographs and settings remain intact; mass thumbnail rebuilding is unnecessary.
- Added independent withdrawal and friendship revocation. Local changes block subsequent reads immediately; unreachable peers remain bounded by the maximum 120-second lease. Already downloaded pixels cannot be recalled.
- Added optional simulator coordinates in WinApp 0.2.0. Live MSFS 2020/2024 and installer upgrade acceptance still require testing on the target Windows system.

#### For visitors

- Added attributed shared photographs from consenting public albums, with ordinary links and pagination available without JavaScript.
- Preserved ordinary galleries, protected media access, originals and both supported thumbnail renderers.

## Version 0.112

Version 0.112 adds complete Czech, German and Swedish editions of the PHP Gallery administrator and developer manual. Each edition is supplied as a searchable PDF and an editable LaTeX source alongside the English reference.

### Highlights

#### Localized product documentation

- Added Czech documentation in `docs/PHP_Gallery_Manual_CZ.tex` and `docs/PHP_Gallery_Manual_CZ.pdf`.
- Added German documentation in `docs/PHP_Gallery_Manual_DE.tex` and `docs/PHP_Gallery_Manual_DE.pdf`.
- Added Swedish documentation in `docs/PHP_Gallery_Manual_SV.tex` and `docs/PHP_Gallery_Manual_SV.pdf`.
- Preserved the installation, administration, Setup Wizard, architecture, security, maintenance and development coverage of the English reference, with localized contents and indexes.
- Updated all four manual editions to Version 0.112 and added direct documentation links to the README.

### Technical Details

#### Documentation and packaging

- Added language-specific LaTeX typography, hyphenation and table layout for the translated manuals.
- Rebuilt all four PDF editions with resolved contents, index entries, bookmarks and internal references.
- Updated registered release markers and `release-metadata.json`, and regenerated `app/core-manifest.json` for the release.

#### Backend, database and frontend

- Added no application feature, route, setting, database migration, table or column.
- Preserved the runtime behavior, schema policies, access checks and browser assets of Version 0.111; changed only the runtime release marker.
- Kept manual-language selection independent of the administrator and public interface language settings.

#### Tests

- Added no new regression suite for this documentation-only release; retained qualification through the central release audit.
- Reviewed PDF edition metadata, reference resolution and rendered manual pages during preparation.

### User Impact

#### For administrators

- Added offline product documentation in Czech, German and Swedish, with editable sources for maintaining each edition.
- Required no configuration change or database migration for this release.

#### For visitors

- Preserved public gallery behavior and existing interface-language selection.

## Version 0.111

Version 0.111 adds a guided administrator Setup Wizard. Administrators can stage supported global settings, preview appearance changes, skip individual choices or whole sections, and review all proposed changes before explicitly applying them.

### Highlights

#### Guided configuration

- Added a launch/resume entry in Admin Settings and eight guided sections derived from the canonical Settings registry.
- Added short subsections, collapsed Advanced and Expert groups, localized explanations and examples, and a change-first final review with optional unchanged-setting disclosure.
- Added per-setting and section skips, Back/Next navigation, draft cancellation/restart, and a usable no-JavaScript path.
- Added live Theme preview for supported appearance, layout, card, grid, GPS-pin, hero-tag and media choices without saving during navigation.
- Added safe staged upload, telemetry, thumbnail warm-up, SEO guard, Gallery Trash, automatic-update and Scheduled Maintenance preferences.
- Kept credentials, uploaded assets, raw CSS, API-key lifecycle, filesystem relocation and destructive maintenance as informational items inside the wizard.

### Technical Details

#### Backend and configuration

- Added the `admin_setup_wizard` route, `app/controllers/admin_setup_wizard.php`, `app/services/admin_setup_wizard.php` and its catalog, draft, apply and preference parts, and `app/models/admin_setup_wizard.php`.
- Bound drafts to the authenticated administrator and validated CSRF, revision and explicit final approval at the controller boundary.
- Reused canonical domain normalizers and setters; rejected unsupported identifiers, invalid values, unavailable capabilities and stale originals before saving.
- Added separate stable row-lock domains for application and telemetry settings, sibling preference locks and grouped saves for Trash and Scheduled Maintenance, and reversible `base_url` persistence with compensation on transaction failure.
- Required positively available settings storage for writes and verified telemetry storage for telemetry edits. Missing or unknown required storage refuses apply; disabled capability-owned settings remain unavailable. Existing specialized owners retain their schema safeguards.
- Shared bounded Theme layout persistence through `app/services/theme_layout_settings.php` and retained public-content revision updates.
- Logged stable setting identifiers only; kept secrets and raw exception details out of draft summaries and wizard audit events.

#### Database

- Added no migration, table or column. Reused `app_settings` and existing `telemetry_settings` storage; kept `base_url` exclusively in local `config.php`.

#### Frontend and translations

- Added `app/views/admin_setup_wizard.php`, `public/assets/gallery-modules/admin-setup-wizard.js` and `public/assets/styles/admin-setup-wizard.css`.
- Reused the existing Theme preview renderer and browser hooks, and refreshed browser import cache keys.
- Kept subsection switching within the current form and preserved staged values when inclusion controls are toggled.
- Kept ordinary Settings section URLs fragment-free so the wizard launcher stays visible after navigation or save.
- Added English, Czech, German and Swedish wizard labels, descriptions and examples.

#### Documentation

- Documented the guided workflow, supported scope, conflict/schema behavior and canonical owners in the README, architecture, code map, testing guide, Settings inventory and administrator manual.
- Replaced the temporary implementation roadmap with permanent documentation and updated the manual edition to Version 0.111.

#### Tests

- Added deterministic wizard catalog, draft, apply, transaction-model, rendering and browser-module contracts.
- Covered approval/revision rejection, conflicts, rollback and URL compensation, secret redaction, registry coverage, localization, staged controls, subsections and summary disclosure.
- Added reversible website-address and shared Theme-layout contracts, and extended upload, telemetry and Settings navigation coverage.

### User Impact

#### For administrators

- Added a guided alternative to editing supported global settings one page at a time, with persistence only after final review and approval.
- Preserved skipped values, per-gallery overrides and existing specialized editors; unsupported specialist operations remain informational within the draft.
- Required no additional installation step or database migration for the wizard itself.

#### For visitors

- Preserved the existing public gallery workflow. Supported global choices take effect after an administrator approves and saves the draft.

## Version 0.110

Version 0.110 adds an administrator setting for correcting the gallery's public installation address after setup. The website URL is saved directly in local configuration, so an incorrectly detected hosting subdirectory can be corrected without editing the file manually.

### Highlights

#### Website address in Admin Settings

- Added **Settings > Website address** with a dedicated website URL field and section-scoped save.
- Added validation for absolute HTTP/HTTPS addresses, with an optional real installation subdirectory and removal of trailing slashes.
- Redirected successful saves to the Settings section at the newly configured address.

### Technical Details

#### Backend and configuration

- Added `app/services/site_url.php` for URL validation and safe updates of the top-level literal `base_url` in `config.php`.
- Preserved unrelated configuration values, formatting, and comments through token-based source editing.
- Serialized saves with a configuration lock, wrote a temporary replacement with the existing file permissions, checked for intervening source changes, and invalidated OPcache after replacement.
- Refused missing or unwritable configuration, duplicate or computed `base_url` expressions, credentials, query parameters, fragments, and malformed URLs.
- Registered the setting in `app/services/admin_settings_registry.php` and delegated persistence from `app/controllers/admin_settings.php` through the existing administrator authentication and CSRF boundary.
- Added `config.php.lock` to `.gitignore` as local runtime state.

#### Database

- Added no migration, table, column, index, or database setting. The website address remains owned exclusively by `config.php`.

#### Frontend and translations

- Added the Website address section to the existing Settings hub, using its search, accessible form controls, and normal no-JavaScript submission.
- Added Czech, English, German, and Swedish labels and help text with a generic example address.

#### Documentation

- Updated the Settings inventory, code map, testing guidance, and administrator manual to describe the configuration owner and save requirements.
- Updated the release markers and rebuilt the manual for Version 0.110.

### Tests

- Added `tests/site_url_config_test.php` covering URL normalization and rejection, unrelated-content preservation, literal configuration variants, and refusal of unsafe rewrites.
- Covered actual configuration persistence in disposable files without accessing the installation's configuration or database.
- Extended the Settings registry contract for the Website address section.

### User Impact

#### For administrators

- Administrators can correct the public gallery address from Settings after installation, including removal of an incorrectly detected subdirectory.
- Saving requires write access to `config.php` and its containing directory. Custom computed configuration remains editable manually.

#### For visitors

- Generated gallery links use the corrected configured address on subsequent requests.

## Version 0.109

Version 0.109 adds a distributable Windows uploader installer. The companion app can now be built as a single bundled executable and packaged through the installed Inno Setup compiler, so end users can install it without managing Python or its runtime dependencies.

### Highlights

#### Windows uploader installer

- Added a standalone `PHPGalleryUploader-0.1.0-Setup.exe` build for the Windows companion app.
- Added a per-machine installer that requests administrator approval and defaults to `Program Files\PHP Gallery Uploader`.
- Added Start Menu and optional desktop shortcuts, a normal uninstaller, and preservation of existing uploader settings and durable jobs in `%APPDATA%\PHPGalleryUploader`.

### Technical Details

#### Build and packaging

- Added `winapp/build.bat` and `winapp/build_installer.py` to create a temporary isolated Python build environment, install the pinned build requirements, build a PyInstaller one-file windowed executable, run an application smoke check, and invoke the installed Inno Setup `ISCC.exe` compiler.
- Added `winapp/installer.iss` with the independent WinApp version `0.1.0`; the CMS release version remains `0.109`.
- Added `winapp/VERSION` and `winapp/requirements-build.txt`. Temporary virtual environments, caches, intermediate executables, and installer staging files are removed after each build, including failed builds where cleanup is possible.
- Added local Git exclude rules for generated `build`, `dist`, staging, virtual-environment, and installer output directories. The generated installer is intentionally not part of the repository release commit.

#### Runtime compatibility

- Bundled Python, Tkinter, Pillow, pystray, tray assets, and `SimConnect.dll` into the application executable.
- Updated dependency-repair controls so a frozen application reports bundled dependency status instead of attempting to run `pip` against itself. Optional Transformers/PyTorch installation remains available only to the source-based Python runtime.
- Added the WinApp version to the desktop window title while preserving existing configuration paths, upload routes, API-key handling, and `--once` compatibility.

#### Database

- Added no database migration, table, column, index, or stored-data change.

### Tests

- Added `winapp/tests/test_build_installer.py` covering atomic installer publication, cleanup after each build-tool failure, compiler validation, and invalid-version rejection.
- Extended `winapp/tests/test_redesign.py` to verify that frozen dependency actions never spawn a package installer.
- The WinApp regression suite passes all 41 tests. The installer was built locally with Inno Setup 7 and its versioned output passed the standalone `--help` smoke check.

### User Impact

#### For Windows uploader users

- Users can install the uploader through a conventional elevated Windows installer and launch it from the Start Menu or optional desktop shortcut without installing Python separately.
- Existing uploader settings, API keys, upload state, logs, and recoverable jobs remain in the user profile across installer upgrades.

#### For gallery administrators and visitors

- No PHP Gallery web route, gallery access rule, media authorization policy, public page, administrator workflow, or visitor-facing feature changed.

## Version 0.108.2

Version 0.108.2 streamlines release preparation so deterministic metadata and integrity checks are completed before the long qualification audit. It also prevents a newly prepared release from starting with incomplete release metadata.

### Highlights

#### Predictable release preparation

- Completed new `release-metadata.json` entries with a timestamp, readable release label, and `v_<version>` tag during the first preparation command.
- Added a cheap release preflight before the long audit for release consistency, manifest freshness, and working-tree/staged whitespace.
- Documented the release-input freeze, single final audit path, clean package source selection, and package inspection sequence.

### Technical Details

#### Release tooling

- Updated `scripts/release_lib.php` so metadata preparation defaults a missing timestamp to the current time while preserving complete existing entries on repeat runs.
- Updated `scripts/prepare_release.php` to print the complete preflight sequence after mechanical preparation.
- Refreshed `app/core-manifest.json` after the release tooling changes.

#### Documentation

- Updated `RELEASE.md`, `README.md`, and `docs/RELEASE_QUALIFICATION.md` with the gated release gameplan.
- Updated the application, testing guide, database documentation, architecture example, and manual edition markers to Version 0.108.2.

### Tests

- Added regression coverage for first-run release metadata creation and idempotent repeat preparation in `tests/release_tooling_test.php`.
- Passed the complete central audit with PHP, Node, WinApp, syntax, browser, manifest, and contract checks.

### User Impact

#### For maintainers

- Release preparation now exposes incomplete metadata and other deterministic failures before expensive suites run, reducing avoidable release iterations.

#### For administrators and visitors

- No application runtime, database schema, public route, gallery behavior, or visitor-facing UI changes were introduced.
## Version 0.108.1

Version 0.108.1 fixes server-side thumbnail generation on PHP 8.5 and stabilizes the release workflow checks for the Version 0.108 gallery editor. Thumbnail sizes, quality, formats, and public presentation remain unchanged.

### Highlights

#### PHP 8.5 thumbnail compatibility

- Replaced deprecated `imagedestroy()` calls in `app/services/thumbnail_generation.php` with normal GD object release. This prevents deprecation output from disrupting a JSON response during image processing.

#### Reliable release checks

- Updated the isolated browser journey to use the existing name-only gallery creation form and verify that a new gallery opens in the editor as Unpublished.
- Allowed more time for disposable Chromium to start on a busy CI runner. When the panel lifecycle fixture fails, the compact audit report now identifies a bounded startup reason or assertion number.

### Technical Details

#### Backend and compatibility

- Kept the JPEG and WebP resize algorithms, EXIF orientation handling, thumbnail policy, and failure results unchanged while removing five deprecated GD cleanup calls.
- Added no database migration, setting, route, public asset, or configuration requirement. PHP 8.1 remains the compatibility minimum.

#### CI and tests

- Corrected a PHP 8.1-incompatible return type in `tests/legacy_download_cache_health_test.php` and removed deprecated cleanup calls from isolated test fixtures.
- Updated `tests/support/gallery_workflow_browser.js` for the Version 0.108 create panel. Extended the shared Chromium fixture startup deadline and its bounded failure reporting in `scripts/audit.php`.
- Verified the complete local audit and all four isolated GitHub Actions jobs across PHP 8.1, 8.3, and 8.5 with MySQL and MariaDB where applicable.

### User Impact

#### For administrators

- Server-side thumbnail and image-processing responses remain usable on PHP 8.5 without GD deprecation warnings appearing in the response.

#### For visitors

- Gallery images and thumbnails retain their existing presentation and access rules.

## Version 0.108

Version 0.108 streamlines gallery creation around a short, name-first Admin panel and turns the resulting gallery editor into the main place for details. It adds optional personal defaults for SimBrief and source language, supports an editable SimBrief preview before a gallery exists, and makes Identity, API, Access and Display easier to scan without removing their saved settings. It also repairs drawer tab selection and the persistent Save gallery bar so these controls remain usable in the live right-side panel.

### Highlights

#### Name-first gallery creation

- Changed the `+` controls on public gallery heroes and cards, and **Create gallery here** in the editor, to open the `admin_new_gallery` workflow for the selected parent. The right-side first step asks for a gallery title only; parent and operation identity travel as hidden form context.
- Kept the existing bounded, accessible title completion on that single title field, including keyboard and pointer acceptance. A suggestion remains advisory and does not decide title uniqueness or submit the form.
- Created the new gallery as Unpublished and opened its complete editor inside the already mounted panel after a successful enhanced submission. The public page URL stays unchanged while the normal server-rendered mutation coordinator refreshes affected gallery context.
- Kept a direct Admin create page with additional initial fields and **More options**, plus the separate create-and-upload form for administrators who need to upload files immediately. Normal POST/redirect remains the no-JavaScript and direct-page fallback.
- Preserved submitted non-secret fields and the chosen parent after a validation failure; the direct form opens its advanced controls when it is re-rendered with submitted input.

#### Personal defaults and source metadata

- Added a per-administrator choice to remember a SimBrief identifier and the current title/description language for future galleries. Saved values appear pre-filled in a later editor with a quiet **Pre-filled from your saved defaults** note; the administrator can still edit each value before saving.
- Combined the visible SimBrief Pilot ID and pilot-name inputs into **Pilot ID or name**. An all-digit value maps to Pilot ID; other nonempty text maps to pilot name. Remembering one visible value clears the older alternate slot so a later prefill does not present an ambiguous identity.
- Kept date/date range, source language, tags and SimBrief in the ordinary Identity flow. The language selector uses the existing language flag assets and places its Remember checkbox beside the choice; **Other languages** remains available as a disclosure.
- Allowed the extended direct Admin create form to save tags and source-language selection with the new gallery. The service checks required tag and localization storage before the gallery mutation instead of silently dropping selected data when optional schema is unavailable.
- Validated selected defaults before creating or editing a gallery. A failed preference write after a successful gallery mutation produces a clear warning without falsely reporting that the gallery was rolled back.

#### SimBrief preview before creation

- Added an authenticated SimBrief import path for a gallery that has not yet been created. It fetches the latest OFP, prepares an editable Markdown description draft and returns an opaque reference to private draft data.
- Bound each draft to the current administrator and PHP session, capped the stored JSON to 8 MiB, and expired it after 30 minutes. Editing the identifier clears an earlier draft reference; an in-flight response is rejected if its identifier or destination description changed while the request was pending.
- Attached a valid previewed OFP and any available route-map data only after the gallery exists. Optional OFP/route attachment failures are reported as warnings on the completed create response. The existing SimBrief route-map schema policy continues to allow description generation without falsely claiming an unavailable route write.
- Replaced the pre-create text-overwrite browser dialog with an inline second-action label when a description already exists. Existing-gallery import retains its established replacement confirmation.

#### Compact gallery editor

- Put the API tab immediately after Identity and kept tab selection scoped to the currently injected editor. A public page containing another editor fragment no longer diverts the drawer's API, Access or other tab to a duplicate ID elsewhere in the document.
- Measured the actual panel header height for sticky tabs, including wrapped titles. Kept **Save gallery** fixed and reachable at the bottom of the gallery editor while scrolling the settings tabs. The save action still uses the shared gallery form and its existing server validation.
- Made Identity's description help available from a keyboard-accessible `?` disclosure, kept dates among the everyday fields, and moved AI gallery text, URL/folder/parent adjustments and other less frequent controls into **Advanced gallery settings**.
- Compressed SimBrief to one identifier row with nearby Remember and Generate actions. The longer explanation remains available through `?`; imported data and the current save flow are unchanged.
- Reduced API's first view to a copyable upload endpoint, key label and Generate action. Added copy feedback for the one-time generated API key, displayed the active-key list only when keys exist, and moved AI metadata regeneration, gallery migration and the global API manager under **Advanced API tools**. API keys remain gallery-scoped and raw generated keys are shown only once.
- Placed visibility and password controls in a short Access layout, kept share-link controls close together, and exposed the NSFW flag as a small checkbox. Longer access and hosting explanations are available on demand.
- Moved Display grid to the top of Display. Put ordinary voting and filename controls in a compact row, and placed Picture Game, EXIF/GPS, card layout, contained-picture badge, lightbox mode, responsive thumbnail bounds and flight-map text inside a closed **Advanced display settings** group. Thumbnail bounds and route text have their own expandable subsections.
- Preserved the existing field names, option values, authorization, CSRF checks, direct-page fallback and Admin mutation envelopes while changing the presentation. English, Czech, German and Swedish catalogs include the new controls and copy feedback.

### Technical Details

#### Backend and service ownership

- Updated `app/controllers/admin_galleries_discovery.php` to normalize new create fields, validate a supplied SimBrief draft and any selected remembered defaults before calling gallery creation, preserve parent context on error, and return a canonical `gallery.create` response that targets the editor panel.
- Updated `app/controllers/admin_galleries_edit_page/post_actions.php` to expand the single visible SimBrief identifier, validate a requested default before the gallery save, and persist selected defaults only after the gallery save succeeds. A preference persistence failure is appended to the successful gallery notice.
- Added `app/models/gallery_creation_preferences.php` for the user-owned SQL row and `app/services/gallery_creation_preferences.php` for schema readiness, value validation and selective updates. Registered both through the existing model/service entry points.
- Added `app/services/simbrief_description_drafts.php` for bounded private OFP references and connected it through `app/controllers/admin_simbrief.php`. Updated `app/services/simbrief_descriptions.php` with the compact-identifier adapter; legacy two-field callers remain supported.
- Updated `app/services/gallery_sidecars.php` to preflight explicitly requested tags and content localization, then persist them after the gallery row is available. Updated `app/controllers/public_gallery_cards.php` so both hero and card add controls use the focused creation route.
- Kept existing actor-bound operation-key replay protection, gallery mutation and upload ownership, and the canonical Admin completion coordinator. A repeated completed request with the same operation key returns its original result; an optional post-create attachment warning is part of that result rather than a second create attempt.

#### Database and upgrade behavior

- Added migration `database/migrations/202609250001_gallery_creation_preferences.php`. It creates `user_gallery_creation_preferences` with one `user_id` primary-key row per administrator; `simbrief_pilot_id` (`VARCHAR(32)`), `simbrief_pilot_name` (`VARCHAR(80)`), `content_language` (`VARCHAR(16)`), and `created_at`/`updated_at` are the stored fields. The foreign key to `users.id` uses `ON DELETE CASCADE`.
- Used ordinary idempotent `CREATE TABLE IF NOT EXISTS`, InnoDB and `utf8mb4`; no trigger, stored routine, global database setting, new application secret or new `config.php` key is required. Existing gallery, image, translation, OFP and access rows are not rewritten by this migration.
- Treated confirmed available preference storage as the write-ready state. Confirmed missing or unknown storage yields empty optional read defaults but refuses an explicit Remember mutation before gallery creation/editing. There is no separate feature-disabled switch for this user preference table; SimBrief and content localization retain their established capability gates.
- Kept SimBrief drafts under the private application `cache/` tree, outside public media paths. A missing or unknown optional flight-route schema may omit route persistence while preserving the independent draft/description result; it cannot authorize or misreport a route write.

#### Frontend and browser lifecycle

- Updated `app/views/admin_gallery_forms.php`, `app/views/admin_gallery_edit_tabs.php`, `app/views/admin_gallery_edit_components.php` and `app/views/upload_automation.php` for the focused create panel, saved-default indicators, native disclosures, compact controls and copyable API fields. Updated `public/assets/styles/side-panel.css` and `public/assets/styles/admin-cinematic.css` to retain legible narrow layouts, sticky tabs and the viewport-anchored save bar.
- Added `public/assets/gallery-modules/admin-gallery-compact-editor.js` for delegated language-flag updates and clipboard copy feedback in newly injected fragments. The code uses Clipboard API when available and a selectable-field fallback when it is not.
- Updated `public/assets/gallery-modules/admin-simbrief-description.js` for the compact identifier, draft reference and stale-response protection. Updated `public/assets/gallery-modules/admin-tabs.js` and `admin-side-panel.js` for panel-local tab targets, measured sticky offset and dynamically replaced create/editor fragments.
- Changed versioned imports in `public/assets/gallery.js` and `public/assets/gallery-modules/admin-operations.js` so deployed browsers load the revised panel, tab and SimBrief handlers instead of a previously cached module.

### Tests

#### Automated coverage

- Added `tests/gallery_creation_preferences_test.php` for per-user defaults, validation, missing/unknown storage, name-only panel markup and visible prefill indicators.
- Added `tests/gallery_editor_creation_defaults_save_test.php` for validation-before-save ordering, refusal before a gallery mutation when preference storage is unavailable, and preference persistence only after a successful gallery save.
- Added `tests/public_gallery_create_entrypoint_test.php` for the focused hero/card `+` route and retained parent/workflow context.
- Added `tests/simbrief_create_draft_test.php` for draft owner, session, token-path and expiry boundaries; extended `tests/simbrief_description_model_test.php` for the single-field identifier adapter.
- Added `tests/gallery_display_editor_layout_test.php` for grid-first presentation, closed Advanced sections and retention of submitted Display controls. Extended `tests/gallery_edit_concurrency_test.php` so real editor rendering catches incomplete tab/save output.
- Extended the registered Admin panel lifecycle and gallery-refresh browser fixtures for clicked tab hit targets, sticky header and Save gallery visibility after scrolling, fragment replacement, API copy handling and unchanged public URL. Existing replay, schema, translation, MVC, mutation-envelope and syntax contracts remain in the central release audit.

### User Impact

#### For administrators

- A gallery can be started from its parent with one name, then completed in the open editor. The new gallery begins Unpublished, so the administrator can fill in metadata and access choices before publishing.
- Personal SimBrief and language defaults reduce repeated entry while remaining editable and explicitly marked as pre-filled. Apply the pending migration through the normal Admin migration action before using Remember; ordinary name-only creation remains available without this optional table.
- SimBrief can prepare a description before creation, while the final OFP/route attachment occurs after the gallery exists. Failure messages distinguish a completed gallery from an optional attachment or preference-save problem.
- Common Identity, API, Access and Display controls require less scrolling. Contextual help and Advanced sections retain the less frequent controls, and the Save gallery action remains reachable while switching settings tabs.

#### For visitors

- Visitors see the published title, date, description, tags, language fallback and display choices saved by the administrator through the usual access rules. A newly created Unpublished gallery does not enter normal public listings until it is published.
- Public gallery browsing, protected media authorization and viewer account permissions do not gain any new route or credential from administrator creation defaults or SimBrief drafts.

## Version 0.107

Version 0.107 adds gallery-scoped thumbnail regeneration with optional descendant coverage and makes browser generation the default for deliberate thumbnail rebuilds. It also improves physical image moves in the Metadata Organizer and the diagnostics available when a move needs attention.

### Highlights

#### Gallery thumbnail regeneration

- Added **Create all thumbnails** to a gallery's Images editor. The action targets that gallery, with an optional **Include subgalleries** checkbox for its descendants.
- Selected browser-side thumbnail rebuilding by default in both the gallery editor and the Maintenance thumbnail card. Administrators can uncheck it to use server generation. Browser generation remains available only when its configured support is enabled.
- Reused the existing bounded browser rebuild workflow: the server sends source ZIP chunks, the browser prepares derivatives, and the server validates and installs uploaded batches.

#### Metadata Organizer image moves

- Moved original files physically with `rename()` after source and destination checks, then verified the destination against the journaled size and SHA-256 identity before changing database ownership.
- Improved safe on-screen failure reasons and pending-move health entries. Trusted Admin logs retain bounded private file and exception context for diagnosis; database exception messages remain withheld.
- Normalized legacy gallery folder separators during journal path checks and kept unresolved moves available for explicit reconciliation.

### Technical Details

#### Backend

- Updated `app/controllers/admin_thumbnails.php`, `app/services/browser_thumbnail_rebuild.php`, and `app/services/thumbnail_generation.php` to keep thumbnail work inside the selected gallery scope and its requested descendants.
- Updated `app/services/gallery_image_move_journal.php` and `app/services/gallery_mutations.php` for physical moves, verified recovery, and structured failure diagnostics.
- Updated `app/services/admin_dashboard/maintenance_health.php` to show allowlisted move failure categories without exposing private paths.

#### Database and compatibility

- Added no migration, table, column, setting key, or configuration requirement. Existing thumbnail metadata and image-move journal storage continue to be used.
- Kept the direct-page and non-JavaScript thumbnail submission path available; browser rebuilding depends on the existing browser-upload capability.

#### Frontend and administration

- Updated `app/views/admin_gallery_edit_components.php` and `app/views/admin_dashboard_sections.php` to default the browser choice on and expose the gallery branch option.
- Updated `public/assets/gallery-modules/admin-browser-thumbnail-rebuild.js`, `public/assets/gallery-modules/admin-thumbnail-progress.js`, `public/assets/gallery-modules/admin-operations.js`, and the `public/assets/gallery.js` import revision for the in-place Admin workflow.
- Updated English, Czech, German, and Swedish strings in `app/lang/`.

### Tests

#### Automated coverage

- Extended gallery-editor panel, thumbnail scope, maintenance-health, and image-move regression contracts in `tests/`.
- The central release audit covers PHP and JavaScript regression, source contracts, syntax, release consistency, manifest freshness, and available browser integration.

### User Impact

#### For administrators

- Administrators can repair one gallery's thumbnails without sweeping unrelated galleries and can include its descendants when needed.
- Browser rebuilding is the initial choice for gallery and all-gallery regeneration; server generation remains selectable.
- Organizer failures provide a safer reason on screen and more useful private diagnostic context for recovery.

#### For visitors

- Visitors can see repaired gallery thumbnails after the administrator completes regeneration. Public access and source-image rules are unchanged.

## Version 0.106.1

Version 0.106.1 is a focused Maintenance Center reliability release. It fixes a production-only failure where an unavailable legacy download-artifact cache could stop the entire central maintenance job during the `downloads.cache` phase, even though the other independent cache owners were healthy. The corrected workflow isolates each cache owner, preserves completed cleanup, reports a bounded warning, and continues safely without exposing hosting paths or raw exception details.

### Highlights

#### Independent cache cleanup checkpoints

- Split Maintenance Center download/cache work into three explicit server-owned operations: download-manifest cleanup, legacy download-artifact cleanup, and generated-ZIP cache cleanup.
- Limited each browser execution step to one cache owner so shared-hosting request time remains bounded and persisted progress identifies the exact current sub-operation.
- Preserved repeated bounded manifest-cache slices while `scan_truncated` reports more work, with the existing ten-slice ceiling and an explicit continuation warning.
- Preserved files and rows already removed by a completed owner when a later independent cache owner is unavailable.

#### Production failure isolation

- Changed an unavailable or failing optional cache owner from a terminal Maintenance Center job failure into a localized warning and skipped sub-operation.
- Allowed unrelated Maintenance Center tasks to continue after a hosting-specific legacy ZIP fallback/cache failure.
- Kept unexpected required-task failures terminal while enriching their lifecycle event with safe machine-readable classification.
- Bumped the Maintenance Center registry revision to `2026-09-20.2`, causing plans analyzed under the earlier execution contract to be rejected as stale and analyzed again.

#### Privacy-safe diagnostics

- Added the bounded cache operation, exception class, stable error code, status, and elapsed milliseconds to Maintenance Center lifecycle diagnostics.
- Accepted only strict machine-readable reason codes from application exceptions and classified ordinary PDO, filesystem iterator, JSON, runtime, PHP, and unknown failures into stable categories.
- Continued to exclude raw exception messages, SQL, database exceptions, credentials, tokens, private filesystem paths, and stack traces from browser state and Admin lifecycle logs.
- Added complete English, Czech, German, and Swedish runtime messages for individual cache slices, skipped owners, truncation limits, and successful completion.

### Technical Details

#### Backend

- Updated `app/services/maintenance_center.php` with `maintenance_center_exception_diagnostic()` and the new registry revision.
- Updated `app/services/maintenance_center/execution.php` with `maintenance_center_run_download_cleanup_operation()`, explicit operation checkpoints, per-owner diagnostics, stable warning keys, and bounded terminal-failure context.
- Reused the existing download-manifest, legacy download-artifact, and generated-ZIP cleanup services without moving their filesystem or persistence responsibilities into the central orchestrator.
- Kept progress monotonic across repeated manifest slices and across the three independent owners.

#### Database and compatibility

- Added no database migration, table, column, index, trigger, stored routine, configuration key, or server-privilege requirement.
- Reused the `maintenance_jobs` state introduced in Version 0.106. Existing installations need only the normal application-file update; they do not need to rerun an already applied migration.
- Stored the current operation index and bounded diagnostics inside the existing job state. A fresh analysis creates a plan with the new registry revision.
- Preserved the existing schema policy: confirmed missing Maintenance Center storage requires the Version 0.106 migration, unknown storage refuses central mutation, and available storage follows the corrected workflow.

#### Frontend and administration

- Required no new route, page, browser module, stylesheet, or client-side setting.
- Returned existing Maintenance Center status envelopes with a more precise `current_subtask`, warning code, activity description, and persisted operation progress.
- Kept the Admin page in place throughout execution and preserved pause, resume, cancellation, reload, and multi-tab serialization behavior.

#### Documentation and release integrity

- Updated the architecture, testing guide, administrator manual, runtime version markers, release metadata, and updater-managed manifest for Version 0.106.1.
- Removed the temporary implementation and production-hotfix record after transferring its durable behavior, safety, and verification information into permanent documentation and these release notes.

### Tests

#### Automated coverage

- Extended `tests/maintenance_center_test.php` to verify strict reason-code handling, generic exception classification, raw-message redaction, cache-owner failure isolation, explicit operation order, bounded lifecycle logging, current-subtask reporting, and localized key parity.
- Retained the central contracts for strict MVC ownership, declaration documentation, Admin mutation envelopes, source headers, runtime hardening, PHP/JavaScript syntax, manifest freshness, and release consistency.
- Qualified the release against the disposable migrated MariaDB and isolated Chromium workflow rather than claiming source-only coverage for production database behavior.

### User Impact

#### For administrators

- Maintenance Center no longer stops the entire job merely because the hosting environment does not support or cannot access the optional legacy download-artifact cache.
- The affected cache operation is shown as skipped with a stable reason, while healthy cache owners and later maintenance tasks continue.
- Open or ready plans from Version 0.106 must be analyzed again because their registry revision is intentionally stale. Completed work is not rolled back.
- No manual SQL, migration, command-line repair, new credential, or database-server configuration is required.

#### For visitors

- Public galleries, media authorization, downloads, authentication, rendering, and cache-serving behavior are unchanged.
- The fix affects only administrator-triggered Maintenance Center cleanup orchestration and its bounded diagnostics.

## Version 0.106

Version 0.106 introduces the Admin Maintenance Center: a durable, browser-driven workflow that analyzes an installation, presents a reviewable maintenance plan, executes selected work in bounded checkpoints, and verifies the result. It coordinates existing specialist maintenance owners without replacing their safety policies, keeps public gallery traffic available, and is designed to resume cleanly across reloads, disconnected browsers, and shared-hosting request limits.

### Highlights

#### Unified Analyze, Review, Execute, and Verify workflow

- Added **Maintenance > Maintenance Center** as the central entry point for routine application care.
- Added a read-only Analyze phase that inspects maintenance needs and records only its own resumable job checkpoint.
- Added a Review phase with server-defined required, optional, and default tasks. Optional deep media verification remains unselected unless an administrator explicitly chooses it.
- Added bounded Execute and Verify phases with weighted progress, recent activity, warnings, skips, errors, before/after evidence, pause, resume, and cooperative cancellation.
- Added a dashboard summary for idle, resumable, running, and last-completed maintenance states, including clear ownership when another administrator controls the active job.

#### Durable and replay-safe maintenance jobs

- Persisted every central job so analysis and execution survive page reloads, closed browser tabs, and interrupted requests without restarting completed work.
- Bound each reviewed plan to the application version, applied migration revision, Maintenance Center registry revision, and SHA-256 plan content. Stale plans are rejected and must be analyzed again.
- Added a unique durable central mutation claim for running or paused jobs and a short-lived per-job database advisory lock so two browser tabs cannot advance the same checkpoint concurrently.
- Made automatic Site Maintenance yield before its first mutation slice while a central job owns the mutation claim; read-only public gallery traffic remains available.
- Added atomic database-operation checkpoints so a lost response after `ANALYZE TABLE` or `OPTIMIZE TABLE` cannot blindly replay the same table.

#### Existing subsystem ownership preserved

- Reused the established telemetry rollup and retention, Admin-log archival, Gallery Trash reconciliation and expiry purge, security/viewer retention, download/cache cleanup, thumbnail-orphan metadata cleanup, logical database cleanup, and physical database-maintenance owners.
- Kept generic central maintenance from applying migrations, running schema repair, installing updates, deleting archive ZIPs, emptying Gallery Trash indiscriminately, regenerating media, or performing speculative orphan cleanup.
- Revalidated task availability and database-table eligibility immediately before execution. Protected, unknown, unclassified, oversized, or newly ineligible tables are skipped rather than optimized.
- Limited physical database maintenance to one server-selected eligible table per browser step; cancellation takes effect between bounded operations and never claims to roll back already committed work.

#### Localized and transparent administration

- Added complete English, Czech, German, and Swedish Maintenance Center interfaces and runtime status messages.
- Added explicit, bounded outcome categories for rows and files removed, tables analyzed or optimized, skipped work, warnings, errors, and physical storage differences only when before/after inventory supports the claim.
- Kept logged and browser-visible errors bounded to safe task, state, and operator guidance; raw SQL, database exceptions, credentials, tokens, private paths, and stack traces are not exposed.

### Technical Details

#### Backend and strict MVC ownership

- Added `app/models/maintenance_center.php` for Maintenance Center persistence, job claims, checkpoints, and bounded history queries; SQL remains model-owned.
- Added `app/services/maintenance_center.php` with focused modules under `app/services/maintenance_center/` for registry policy, read-only analysis, bounded execution, verification, status models, and lifecycle logging.
- Added `app/controllers/admin_maintenance_center.php` for authenticated Admin routes, POST plus CSRF mutation boundaries, normalized input, canonical Admin mutation envelopes, and no-store JSON responses.
- Added `app/views/admin_maintenance_center.php` and integrated the prepared view model with Admin navigation and dashboard presentation.
- Updated existing telemetry, Admin-log archive, site-maintenance, database-maintenance, and thumbnail-metadata owners only where bounded central orchestration required an adapter or checkpoint contract.

#### Database and upgrade behavior

- Added migration `database/migrations/202609200004_maintenance_center.php` and the `maintenance_jobs` table for durable plan/state JSON, plan hashes and revisions, progress, cancellation, bounded failure evidence, history timestamps, and a nullable unique central mutation claim.
- Kept the migration compatible with the ordinary Gallery migration action. It requires normal table-creation authority but no trigger, stored function, stored procedure, `SUPER` privilege, or global database-server setting.
- Treated confirmed missing Maintenance Center storage as an upgrade-required state and refused to start a job until migrations are applied. An unknown inspection or persistence state also refuses mutation rather than guessing that execution is safe.
- Kept feature-disabled specialist subsystems unavailable within the central plan without probing or re-enabling them unnecessarily. Maintenance Center itself is core Admin functionality rather than a second feature toggle.
- Retained completed, failed, and cancelled job history for 90 days and removed old terminal rows in bounded batches. Running and paused jobs are never removed by history retention.

#### Frontend

- Added `public/assets/gallery-modules/admin-maintenance-center.js` for dynamic Analyze, Review, Execute, Verify, pause, resume, cancel, polling, and persisted reload behavior without full-page navigation.
- Added `public/assets/styles/admin-maintenance-center.css` for the dedicated plan, progress, activity, warning, report, and responsive layouts.
- Updated `public/assets/gallery.js` so deployed browsers load the new module with the current cache-busting revision.
- Preserved the current Admin URL during in-place actions and kept dynamically rendered controls under delegated event handling.

#### Documentation and release integrity

- Updated `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `TESTING.md`, and the administrator manual with the permanent orchestration, schema, safety, recovery, and acceptance contracts.
- Removed the temporary Maintenance Center implementation record after transferring its durable information into permanent documentation.
- Refreshed the updater-managed manifest and Version 0.106 release metadata after the final source and documentation edits.

### Tests

#### Automated coverage

- Added `tests/maintenance_center_test.php` for registry keys and dependencies, deterministic task order, state transitions, optional selection, weighted monotonic progress, migration schema, persistence and lock contracts, read-only Analyze boundaries, stale-plan revisions, one-table physical database steps, current eligibility rechecks, cancellation evidence, automatic-maintenance conflict protection, authenticated POST/CSRF routes, canonical JSON mutation envelopes, browser continuation, navigation, dashboard integration, and translation parity.
- Registered the new PHP and browser-facing source contracts with the central audit and retained strict MVC, declaration documentation, source-header, syntax, runtime-hardening, and Admin mutation checks.
- Documented disposable MariaDB acceptance for a real `OPTIMIZE TABLE` rebuild and interruption testing; source-level contracts alone do not claim that a hosting engine physically reclaimed storage.

### User Impact

#### For administrators

- Routine maintenance can now be analyzed, reviewed, run, paused, resumed, cancelled, and verified from one Admin page without keeping a single long browser request open.
- Existing specialist maintenance pages remain available for targeted diagnostics and repair. The central workflow does not silently broaden permissions or turn optional features on.
- After upgrading, administrators apply the single pending migration through the normal Gallery migration page, then open **Maintenance > Maintenance Center** and run Analyze. No command line, manual SQL, new credential, or database-server configuration is required.
- A completed report distinguishes confirmed work from skipped, unavailable, interrupted, or uncertain outcomes instead of presenting partial maintenance as complete.

#### For visitors and hosting environments

- Public gallery, media, authentication, download, and presentation behavior is unchanged.
- Maintenance is divided into bounded browser requests for shared-hosting compatibility. Individual database engine operations can still take time and cannot be interrupted mid-statement, so large or unsafe tables are excluded from ordinary central execution.
- Existing access controls, schema inspection policy, protected-table rules, subsystem locks, and non-destructive feature-disabled behavior remain authoritative.

## Version 0.105.1

Version 0.105.1 is a focused telemetry reliability and observability release built on the Version 0.105 administration hardening. It repairs retention scheduling, archival checkpoints, database observations, traffic classification, page-load timing, decoded-cache accounting, and Admin telemetry presentation without changing gallery access rules or requiring a database migration. The release is designed for ordinary shared hosting: maintenance remains bounded and cooperative, failures are explicit, and no raw visitor identifiers or database exception text are exposed.

### Highlights

#### Independent telemetry retention and recovery

- Added an independent telemetry maintenance worker with its own non-blocking database lock, schedule, time budget, bounded deletion batches, retry interval, and durable job evidence.
- Scheduled telemetry retention before thumbnail maintenance so a busy thumbnail worker, a thumbnail lock, or an unrelated maintenance phase cannot silently prevent telemetry cleanup.
- Added interruption-aware daily archival checkpoints. A completed day is upserted before its checkpoint advances; malformed or unavailable checkpoints refuse replay and hourly deletion instead of restarting from an unsafe boundary.
- Reconciled abandoned started jobs only after exclusive lock admission and distinguished a completed bounded slice from an empty historical backlog through the `has_more` result.
- Preserved non-destructive behavior when telemetry is disabled and kept retention ages separate from the observed count of overdue rows.

#### Correct telemetry measurements

- Fixed activation-origin SQL construction and distinguished query failure from an empty result.
- Allowed both daily-consistency query families through the profiler allowlist and corrected affected-row semantics so metadata statements and failed executions are not counted as affected data.
- Added positive page-load timing collection after the page-load event, including collectors loaded after the document is already complete. Invalid, non-finite, zero, or superseded samples are excluded rather than rewritten as zero.
- Corrected decoded-cache accounting for preview and full-source phases. Only a resolved usable cached image produces a hit; failed preloads that fall back to a fresh load produce one miss, and stale navigation cannot attribute a late result to a newer image.
- Preserved the historical high-open session as annotated evidence, including its share of the selected cohort; the repair does not heuristically delete or reinterpret it.

#### Privacy-safe traffic and media reporting

- Separated all traffic, non-bot desktop/tablet/phone, bot-classified, and unclassified segments so unknown classification is not presented as a person or a bot.
- Added bounded server-side media device/browser/OS buckets without storing the raw user agent and without retroactively assigning historical unknown media to people or bots.
- Corrected Complete Overview and standalone exports to use canonical page/photo event counts while retaining legacy session, duration, bounce, and cohort semantics with explicit labels.
- Added query fingerprints ranked by count and failures, observed database coverage, first/last hourly buckets, and bounded report diagnostics without exposing SQL, credentials, raw IPs, session identifiers, or private paths.

#### Better Admin maintenance visibility

- Added bounded telemetry diagnostic and maintenance services under the existing Controller-Service-Model boundary.
- Added localized English, Czech, German, and Swedish messages for maintenance outcomes, unavailable states, retention evidence, overdue counts, traffic segments, and repair diagnostics.
- Added explicit POST plus CSRF handling for the Admin telemetry maintenance action; no mutable GET maintenance link or new server token is required.
- Preserved the distinction between configured retention, observed overdue data, completed work, interrupted work, failed work, and remaining work.

### Technical Details

#### Backend and maintenance ownership

- Added `app/services/telemetry_diagnostics.php` and `app/models/telemetry_diagnostics.php` for bounded database-observation and report aggregation ownership.
- Added `app/services/telemetry_maintenance.php` and `app/models/telemetry_maintenance.php` for lock admission, retention planning, checkpoint validation, deletion, archival, job lifecycle, and interruption recovery.
- Refined `app/services/telemetry.php`, `app/services/telemetry_privacy.php`, and `app/services/telemetry_rollup.php` so collection, privacy classification, daily rollup, retention, and reporting remain separate responsibilities.
- Updated `app/controllers/admin_telemetry.php` and `app/views/admin_telemetry.php` to prepare and render view-model data without moving SQL or domain policy into presentation code.
- Updated `app/services/site_maintenance.php` to invoke the telemetry slice independently before thumbnail maintenance while retaining bounded execution and lock ownership.
- Updated `scripts/telemetry_maintenance.php` as a bounded CLI entry point. A failed slice exits nonzero; `has_more: true` requires another scheduled invocation.

#### Database and compatibility

- Added no migration, table, column, index, stored procedure, trigger, or production configuration requirement.
- Reused the existing telemetry tables and `telemetry_job_runs` lifecycle. Raw events are removed only according to configured retention and a valid exclusive archival checkpoint.
- Refused destructive retention when retention settings, checkpoint dates, schema inspection, or required reads are missing or unknown. There is no unsafe default that turns an inspection failure into deletion.
- Kept telemetry master OFF non-destructive. Presentation/reporting may show an unavailable or empty state, while mutation-sensitive retention still requires conclusive schema and policy readiness.
- Kept the existing collector compatibility copy byte-identical to `usage.js` and refreshed the effective asset revisions for the lightbox and telemetry collectors.

#### Frontend and localized Admin behavior

- Updated `public/assets/telemetry.js` and `public/assets/usage.js` for positive finalized navigation timing and late collector initialization.
- Updated `public/assets/gallery-modules/lightbox.js` so decoded-cache observations remain scoped to the current navigation owner and image phase.
- Added bounded cache-accounting, navigation-timing, privacy-bucket, query, maintenance, and repair semantics contracts under `tests/`.
- Updated all four language catalogs together so new telemetry statuses and failure summaries have consistent fallback behavior.

#### Configuration and policy

- Added documented defaults in `app/configuration_defaults.php` for telemetry maintenance time budget, deletion batch size, deletion batch count, rollup-day limit, retry interval, and normal completion interval.
- These values are cooperative ceilings and scheduling defaults, not database query cancellation guarantees. InnoDB filesystem allocation is not promised to shrink immediately after row deletion.
- Preserved centralized policy ownership and bounded output rules; diagnostics do not disclose raw SQL, PDO messages, raw user agents, IP addresses, credentials, tokens, or private filesystem paths.

#### Permanent documentation

- Added and retained `docs/TELEMETRY_0105_REPAIR.md` as the technical reference for maintenance, measurement, privacy, deployment, and acceptance boundaries.
- Updated the former temporary telemetry checkpoint into permanent documentation before removing the temporary file from the release tree.
- Updated the manual, database/runtime markers, testing guide, architecture references, source manifest, and localized catalogs for Version 0.105.1.

### Tests

#### Deterministic regression coverage

- Added `tests/telemetry_maintenance_recovery_test.php` for lock/throttle behavior, completed-day checkpoint advancement, interrupted job reconciliation, late-event refresh, bounded deletion, `has_more`, and telemetry-disabled paths.
- Added `tests/telemetry_repair_queries_test.php` and `tests/telemetry_repair_semantics_test.php` for SQL construction, profiler admission, canonical counts, affected-row semantics, schema-readiness refusal, traffic segmentation, privacy buckets, and anomalous-session reporting.
- Added `tests/telemetry_cache_accounting_test.mjs` and `tests/telemetry_navigation_timing_test.mjs` for cache-hit/miss ownership, stale navigation suppression, late collector loading, invalid timing values, and finalized positive measurements.
- Extended feature-policy, audit-remediation, image-observability, and traffic-segment contracts and registered the new fixtures in `scripts/audit_registry.php`.

#### Verification boundary

- The central audit remains the authoritative verification interface. The source handoff audit recorded no executed regression failures, but its environment was blocked by unavailable SQLite, ZIP, GD, browser, and live database prerequisites.
- Production query-volume reduction, live MariaDB maintenance behavior, real browser acceptance, and hosting-side ZIP-cache permissions remain operational acceptance work; this release does not claim those checks were performed locally.

### User Impact

#### For administrators

- Telemetry maintenance now provides clearer progress, remaining-work, interrupted-work, and failure information. A successful bounded slice may still report more work, and regular cron/maintenance invocations remain necessary.
- Admin reports show canonical page/photo activity, explicit legacy session semantics, four privacy-safe traffic segments, observed overdue rows, query coverage, and decoded-cache phase details.
- Run `php scripts/telemetry_maintenance.php` or the existing scheduled maintenance entry point to process another bounded slice. No manual SQL deletion, new credential, or live configuration migration is required.
- Existing retention settings continue to control deletion. A disabled telemetry master remains non-destructive.

#### For visitors

- No public gallery route, media authorization rule, gallery access rule, thumbnail renderer, lightbox permission, or visitor-facing URL changes in this patch.
- Page-load and media observations are more accurate and remain privacy-bounded; raw visitor identifiers and raw user agents are not introduced.

#### Limitations and upgrade notes

- Version 0.105.1 contains no schema migration and does not require a special database privilege or server-variable change.
- Telemetry cleanup is cooperative and bounded. It must be invoked regularly, and a `has_more` result is expected when historical work exceeds one slice.
- The supplied source-level audit is not a substitute for isolated MariaDB integration, real browser/device acceptance, production query measurement, or hosting permission investigation.

## Version 0.105

Version 0.105 is a substantial reliability, safety, and maintainability release for gallery administration. It protects catalog and filesystem ownership during concurrent or interrupted work, makes gallery editing conflict-aware, makes create and classic-upload retries replay-safe, strengthens the Admin side-panel lifecycle, bounds image decoding and gallery lookup work, and expands operational diagnostics and release qualification. Public gallery features and URLs remain compatible; the main changes are safer behavior behind existing administrator workflows.

### Highlights

#### Gallery catalog and filesystem preservation

- Stopped ordinary gallery creation from deleting or silently replacing an existing catalog row when its expected folder is missing, displaced, or cannot be observed conclusively. The operation now preserves the existing identity and returns a typed conflict for explicit reconciliation.
- Added a durable image-move journal that records source/destination ownership, file identities, and the database commit marker before recovery decisions are made. Interrupted moves can be finished or compensated without inferring success from whether the browser received a response.
- Used exclusive file placement and positive identity checks so an existing destination is never overwritten by rename semantics. Unknown, changed, cross-filesystem, or otherwise unverifiable files are retained for operator review.
- Added bounded read-only pending-move health to System Health and Runtime Diagnostics, plus `scripts/reconcile_image_moves.php` for explicit inspection and recovery. Diagnostics omit private paths, file hashes, manifests, raw SQL, and database exception text.

#### Conflict-aware gallery editing

- Added an application-owned `galleries.edit_revision` value to every complete editor form. A save reserves the exact submitted revision before filesystem, sidecar, relationship, or metadata side effects begin.
- Added one shared gallery-writer ownership boundary around editing, scanning, uploads, WebDAV, Trash, image moves/copies, Media Renamer, migration installation, thumbnail-bound changes, covers, and other workflows that can observe or change mutable gallery state.
- Advanced the revision explicitly in every supported application model update. The migration uses an ordinary column alteration only: it creates no trigger or stored routine, changes no global database variable, and requires no `SUPER`-style privilege.
- Returned typed stale/busy conflicts without discarding entered non-secret fields. Administrators can compare the latest saved values, preserve their draft, and deliberately reapply changes instead of an older form silently overwriting newer work.
- Made interrupted migration replay safe when the revision column already exists but the migration ledger entry does not. Running migrations again from the Gallery administration page records the migration without requiring database-server administration.

#### Replay-safe create and classic upload

- Added 256-bit operation keys to empty-gallery creation, classic upload, and combined create/upload forms. Replaying the same authenticated actor, key, operation, and semantic payload returns the original bounded response and stable entity IDs.
- Kept intentional duplicates available: a freshly rendered operation key represents a new intent and retains the established suffix-selection behavior. Operation keys do not deduplicate titles or filenames and are not authentication, CSRF, or access tokens.
- Bound claims to the current administrator, operation kind, normalized payload, and streamed file identities. Authentication, authorization, CSRF, schema readiness, and result ownership are rechecked before a stored response is replayed.
- Preserved uncertain work as `pending` or `needs_reconciliation` instead of stealing an old claim or rerunning its target mutation. Added `scripts/reconcile_admin_operations.php` for bounded read-only inspection and separately authorized reconciliation.

#### Safer, steadier Admin side panels

- Added generation and cancellation ownership for dynamic side-panel loads so late responses cannot replace a newer panel or revive a closed workflow.
- Added bounded in-memory drafts for non-secret fields, an inline unsaved-work guard, native keyboard focus containment, and restoration of focus to the control that opened the panel.
- Kept persistent panel actions in place through the canonical JSON mutation envelope and shared completion coordinator. Successful actions keep the panel open, preserve the browser URL, avoid page reloads, refresh only owned fragments/contexts, and suppress stale or out-of-order responses.
- Kept dynamically replaced controls active through delegated event handling and acknowledged a saved gallery revision on the submitting form before delayed context refresh work.

#### Bounded ingestion and gallery selection

- Centralized raster admission and decode-memory policy for GD and optional Imagick paths. Classic uploads, prepared browser uploads, thumbnails, and related pipelines reject unsafe dimensions or budgets before committing target files.
- Clarified request-body and temporary-file ownership for Mobile WebDAV and Gallery Migration. Each workflow cleans up only files it allocated, while a recoverable staged body/package remains available when a late schema refusal prevents target mutation.
- Added bounded server-side gallery-picker search with a 30-result ceiling, selected-gallery context, stale-response suppression, and a no-JavaScript ID directory. Large installations no longer need to embed the complete gallery tree in every picker.
- Added fresh under-lock reads and stale-plan checks across scanning, renaming, Trash, copy/move, migration, and thumbnail-related workflows so earlier request snapshots cannot authorize later filesystem changes.

#### Operational clarity and maintainability

- Added shared runtime-support guidance for PHP compatibility versus maintained deployment branches, with the same bounded status used by the dashboard, System Health, Runtime Diagnostics, README, and installation guidance.
- Separated caller-owned session/job compartments for complete reports, discovery, duplicate detection, Google OAuth, and Viewer anti-automation work. Services receive explicit state instead of selecting unrelated session namespaces.
- Centralized newly introduced immutable operational limits in `app/policy_constants.php` and browser policy modules, with units, scope, consumers, rationale, and compatibility/security notes. Whole-tree inventories remain explicit about legacy findings rather than treating them as silently accepted debt.
- Enforced Rudolf Klusal source attribution, meaningful declaration documentation, and Controller-Service-Model ownership for changed first-party source. Added permanent architecture documents and source-contract gates without weakening the zero-violation strict MVC baseline.

### Technical Details

#### Backend and ownership boundaries

- Added `app/services/gallery_creation_safety.php` to distinguish healthy reusable storage from missing, conflicting, and unknown catalog ownership before creation.
- Added `app/models/gallery_image_move_journal.php` and `app/services/gallery_image_move_journal.php` for durable image-move state, connection locks, exact file identity validation, commit marking, recovery, and bounded diagnostics.
- Added `app/models/gallery_edit_concurrency.php` and `app/services/gallery_edit_concurrency.php` for revision reservation and the reentrant application writer lease. Controllers continue to own HTTP conflict responses; models own PDO/SQL; services own concurrency and filesystem policy.
- Added `app/models/admin_operation_keys.php` and `app/services/admin_operation_keys.php` for durable claim, replay, response projection, diagnostic, and reconciliation behavior. Exact primary-key shape is verified before a request can claim storage.
- Added `app/services/image_decode_policy.php` and `app/services/runtime_support.php` as shared policy owners rather than duplicating decode arithmetic or runtime advice in controllers and views.
- Extended `app/services/mutation_schema_policy.php` with `mutation.gallery_image_move_journal` and registered `mutation_gallery_edit` and `mutation_gallery_move` health through the shared Admin mutation-health model.

#### Database and upgrade behavior

- Added migration `202609200001_gallery_image_move_journal.php`. The new `gallery_image_move_journal` table stores a 32-character operation identity, source/destination gallery IDs, lifecycle state, same-transaction database-commit marker, private manifest JSON, safe error category, timestamps, and bounded pending/source/destination indexes. It intentionally has no cascading gallery foreign key that could erase recovery evidence.
- Added migration `202609200002_gallery_edit_revision.php`. It adds unsigned `galleries.edit_revision` with default `1` through one portable `ALTER TABLE` statement. It does not create triggers, routines, functions, events, or privileged server configuration.
- Added migration `202609200003_admin_operation_keys.php`. The `admin_operation_keys` ledger uses the composite primary key `(actor_id, key_hash)` and stores operation/payload/owner hashes, lifecycle state, the original bounded canonical response, and timestamps.
- Required confirmed `available` schema before gallery edits, durable image moves, or operation-key claims begin target work. Confirmed `missing` and inspection `unknown` both refuse these mutations before the first irreversible file/database effect; there is no unsafe execution fallback and no feature-disabled state for these mandatory integrity boundaries.
- Kept the migration runner as the documented bootstrap path. A confirmed missing migration ledger can be created by the migration workflow, and a duplicate-column replay for `edit_revision` can complete after an interrupted earlier attempt. Unknown metadata inspection still refuses mutation.
- Kept administrator-facing migration failures bounded and localized. Raw SQL, PDO messages, database credentials, tokens, manifests, and private filesystem paths are not exposed through System Health, Runtime Diagnostics, or browser error envelopes.
- Added no requirement to grant `TRIGGER`, `SUPER`, `ALL PRIVILEGES`, or authority to change `log_bin_trust_function_creators`. Existing installations upgrade through the Gallery page's normal migration action.

#### Frontend and interaction lifecycle

- Added `public/assets/gallery-modules/admin-operation-keys.js` for one-key-per-intent ownership across direct forms, dynamic panel forms, and retry/replay behavior.
- Added `public/assets/gallery-modules/admin-panel-lifecycle.js`, `admin-panel-drafts.js`, and `admin-panel-policy.js` for generations, cancellation, draft capture, focus handling, and bounded interaction state.
- Added `public/assets/gallery-modules/admin-interaction-policy.js` for documented browser-side retry, timing, and lifecycle constants without exposing server configuration or credentials.
- Updated `public/assets/gallery-modules/admin-side-panel.js` to use delegated dynamic handling, canonical mutation completion, and stale-response suppression across replaced fragments.
- Reworked `public/assets/gallery-modules/searchable-gallery-picker.js` around bounded server results, selected context, cancellation, and parent-frame integration; added server-rendered fallback behavior when JavaScript is unavailable.
- Refreshed the deployed `public/assets/gallery.js` import chain and relevant asset-version inputs so upgraded browsers do not retain older panel, picker, or operation-key handlers.

#### Image, upload, and temporary-file safety

- Validated dimensions, pixel counts, estimated decode memory, runtime limits, and supported format before raster decoding. Optional Imagick handling refuses unsafe input and falls back only where the documented policy permits it.
- Applied the same admission boundary to classic and prepared uploads, generated derivatives, and upload-pipeline tests rather than trusting MIME metadata or compressed file size alone.
- Made `app/controllers/mobile_webdav.php` own the inbound request stream while `app/services/mobile_webdav.php` owns only the staging file it allocates. Late missing/unknown schema keeps recoverable staged bytes instead of partially registering the upload.
- Added `app/services/gallery_migration/temporary_files.php` so request-owned transfer files have explicit allocation/release rules and migration jobs never delete caller-owned or resumable package state accidentally.

#### Diagnostics, documentation, and source contracts

- Added `docs/ADMIN_OPERATION_KEYS.md`, `docs/ADMIN_PANEL_LIFECYCLE.md`, `docs/GALLERY_CATALOG_RECONCILIATION.md`, `docs/GALLERY_EDIT_CONCURRENCY.md`, `docs/GALLERY_PICKER.md`, `docs/IMAGE_DECODE_POLICY.md`, `docs/IMAGE_MOVE_RECOVERY.md`, `docs/MOBILE_WEBDAV_BODY.md`, and `docs/RUNTIME_SUPPORT.md`.
- Added `docs/CODE_DOCUMENTATION.md`, `docs/FRONTEND_OPERATIONAL_POLICY.md`, and `docs/SOURCE_CONTRACT_INVENTORY.md` to define declaration, data-shape, source-header, policy-literal, and inventory expectations.
- Added `scripts/check_policy_constants.php` and `scripts/check_source_documentation.php`, integrated their changed-source gates into `scripts/audit.php`, and kept complete-tree inventories visible as advisory migration work rather than a hidden baseline.
- Expanded `scripts/check_mvc_boundaries.php` and source-reading helpers so split modules, generic helpers, request/session access, SQL/PDO ownership, and moved filesystem responsibilities remain covered without adding an MVC baseline exemption.

### Tests

#### Database, concurrency, and recovery

- Added disposable-MySQL coverage for exact edit revisions, simultaneous sessions, writer ownership, stale snapshots, explicit runtime revision increments, and pre-activation schema enforcement.
- Reproduced the interrupted upgrade case where `galleries.edit_revision` exists but `schema_migrations` does not yet contain the migration, then verified that rerunning the normal migration records it exactly once without trigger privileges.
- Added process-interruption tests for image moves before file work, during movement, and after the database commit marker. Recovery tests verify rollback/finalization, unchanged-file identity, no overwrite, and safe retention of ambiguous state.
- Added operation-key service, HTTP, browser, primary-key, diagnostics, and maintenance tests covering concurrent claims, exact replay, changed payloads, actor isolation, lost acknowledgements, bounded stored responses, and intentional fresh-key duplicates.

#### Browser, ingestion, and request lifecycle

- Added real Chromium panel-lifecycle coverage for focus containment/restoration, stale load suppression, unsaved drafts, dynamic replacement, URL stability, and continued interception without whole-page rebinding.
- Added PHP and browser gallery-picker tests with a 10,000-row fixture, bounded search/response work, selected context, parent integration, cancellation, no-JavaScript fallback, and stale-result refusal.
- Added image-decode policy, pipeline, upload-pipeline, and Imagick fallback tests for malformed metadata, overflow, memory budgets, supported formats, and cleanup before persistence.
- Added WebDAV body, Gallery Migration temporary-file, upload writer-ownership, report/discovery/duplicate/OAuth context, Viewer anti-automation context, and authenticated session-contention regressions.

#### Architecture and release assurance

- Expanded the central audit registry across PHP, Node, WinApp, Chromium, complete PHP/JavaScript syntax, strict MVC, mutation contracts, source headers, declaration documentation, policy constants, and source-boundary inventories.
- Added deterministic disposable MySQL and browser workflow coverage while reducing fixture database authority. The fixture does not require `TRIGGER`, `SUPER`, global-variable changes, or access to an existing installation.
- Preserved both progressive and responsive public thumbnail renderers, existing lightbox authorization/zoom/navigation contracts, no-JavaScript behavior, updater safety, and deployment exclusions.
- The pre-release full-profile integration run completed 234 PHP tests, 22 Node tests, 36 WinApp tests, five Chromium integration checks, 857 PHP syntax checks, and 105 JavaScript syntax checks with zero failures, skips, or blockers. Version 0.105 release qualification is performed separately against the final release files and manifest.

### User Impact

#### For administrators

- Existing create, edit, move, upload, WebDAV, migration, Trash, renaming, and side-panel workflows remain in their familiar locations, but now refuse stale or unverifiable state before destructive work instead of guessing.
- Retrying the same create/classic-upload intent after an ambiguous response returns the original outcome when it completed. Starting from a fresh form still permits an intentional duplicate.
- Concurrent gallery edits now produce a reviewable conflict instead of silently overwriting another save. Non-secret draft fields remain available; passwords and file inputs must be re-entered or reselected deliberately.
- Database migrations continue to run from the Gallery administration page on ordinary shared hosting. No database trigger privilege, `SUPER` access, global setting change, or separate DBA operation is required.
- System Health and Runtime Diagnostics provide bounded action-oriented status for revision storage, durable moves, runtime support, and unresolved operations without disclosing sensitive database or filesystem details.
- Apply all pending migrations before resuming gallery edits, image moves, or create/classic-upload work. Include the database, gallery tree, and persistent `data/` state in coordinated backup and recovery planning.

#### For visitors

- Public gallery URLs, access controls, translations, thumbnail renderer choices, lightbox behavior, downloads, maps, voting, and no-JavaScript navigation retain their established contracts.
- Visitors benefit indirectly from fewer inconsistent catalog/filesystem states, safer administrator concurrency, and more reliable post-save refreshes. Version 0.105 does not add a new visitor-facing feature or weaken media authorization.

#### Compatibility and operational limits

- PHP 8.1 remains the syntax compatibility minimum; deployments should use a maintained PHP branch as documented in `docs/RUNTIME_SUPPORT.md`.
- Image-move journaling covers image moves only; uploads, gallery-folder moves, deletion, and off-host recovery keep their own mutation/recovery owners.
- Operation keys provide replay safety, not a cross-system transaction. Ambiguous interrupted ingestion is retained for explicit reconciliation and is never guessed complete from a similar title, filename, or elapsed time.
- Source-policy and documentation inventories expose remaining legacy cleanup candidates. Their changed-source enforcement prevents new undocumented declarations or unexplained operational literals without claiming that every historic file was mechanically rewritten.

## Version 0.104.1

Version 0.104.1 is a broad reliability and maintenance release built around six areas: safer and more accessible gallery-title completion, bounded suggestion lookup for large collections, explicit lightbox preload ownership, repeatable recovery assurance, isolated end-to-end workflow testing, and release evidence tied to the exact files being reviewed. It improves the title-completion feature introduced in 0.104 without changing how galleries are stored or created, and adds substantial operational tooling without turning automated test success into a claim of production restore readiness.

### Highlights

#### More predictable title completion

- Fixed suffix calculation for Unicode text whose normalized representation has a different length from its stored spelling. Compatibility ligatures, fullwidth letters, and composed/decomposed accents now use a safe boundary in the original title instead of slicing that title at an unrelated normalized offset.
- Prevented incomplete grapheme matches from producing broken ghost text. Suggestions are omitted when the typed prefix ends inside a ligature, combining sequence, or other indivisible displayed character; ordinary typing remains available.
- Preserved input-method composition, modified keyboard shortcuts, selections, and editing in the middle of a title. Unmodified `Tab` or `ArrowRight` accepts a suggestion only at an eligible end-of-input caret; acceptance changes the title without submitting the form.
- Added two-stage `Escape` behavior in the Admin drawer: the first press dismisses a suggestion or pending completion, while a subsequent press can reach the surrounding panel's existing dismissal behavior.
- Added translated help, a stable accessible field name, and polite suggestion announcements in English, Czech, German, and Swedish. Newly injected or replaced create forms receive the same behavior through delegated handling.

#### Small suggestion responses instead of a complete embedded catalog

- Replaced the gallery-title catalog previously embedded in each create form with an authenticated, on-demand JSON lookup. Full-page and side-panel forms start with an empty candidate list rather than querying and serializing every title.
- Retained recent-sibling priority for the selected parent, followed by recent matches elsewhere when the sibling scope has been exhausted. The lookup returns at most eight candidates and does not expose gallery folder paths.
- Added bounded work, debounced requests, cancellation, timeouts, and stale-response rejection so an older input value, parent selection, or detached form cannot overwrite the current suggestion.
- Made the limits explicit: older matches may be omitted when a scan budget is reached, and an empty result is not proof that a title is unique. Suggestions remain optional; failed lookup does not disable normal title entry or gallery creation.

#### A single owner for nearby lightbox preloads

- Extracted the nearby-preview queue into `public/assets/gallery-modules/lightbox-preload-lifecycle.js`, giving queue generations, scheduling, deduplication, concurrency, cancellation, reset, and disposal one explicit owner.
- Preserved already-running preview work during a soft reset while allowing hard reset and disposal to cancel obsolete work. Late completions and scheduled callbacks cannot revive an abandoned queue or interfere with a reopened viewer.
- Kept foreground navigation, decoded-image cache, authorized media selection, active-image quality, zoom, slideshow, and viewer markup under their existing owners. This is a lifecycle improvement, not a second viewer or a new original-image prefetch policy.

#### Recovery assurance with honest limits

- Added `scripts/recovery.php` to inventory a recovery set and validate restored files in an explicitly isolated location, including original-file hash samples, release-manifest hashes, required components, and operator-supplied evidence.
- Added a synthetic drill covering intact, corrupted, and incomplete recovery sets, relationship evidence, and a recoverable Trash file round trip. A successful synthetic drill is clearly distinguished from restoring a real installation.
- Added permanent guidance for coordinated database/filesystem snapshots, off-host storage, restored-service isolation, backup age, recovery duration, and agreed recovery-time/data-loss objectives.
- Kept the tool read-only with respect to recovery source files. It does not execute a SQL dump, contact a backup provider, automatically restore production, or prove network isolation on the operator's behalf.

#### Real workflows in disposable installations

- Added generated application copies, migrated MySQL schemas, synthetic photographs, and real HTTP/browser journeys for critical Admin workflows. Existing local or production configuration, media, and databases are not test fixtures.
- Exercised create, edit, multipart upload, prepared-upload retry, visibility changes, media authorization, recoverable subtree deletion, and Trash restore with database, sidecar, original-file, relationship, and ledger postconditions.
- Added browser assertions that panel mutations leave the URL unchanged, keep the panel open, and continue working after dynamic fragment replacement. Coverage includes immediate double-clicks, stale editor responses, and mutations attempted after logout invalidates the session.
- Added independent MySQL 8.4 and MariaDB 11.4 CI service jobs with mandatory workflow coverage. Hosted execution remains separate from local verification; adding the workflow is not a claim that a hosted run has already succeeded.

#### Release approval attached to exact artifacts

- Added `scripts/release_qualification.php` with `init`, `check`, `record`, `record-audit`, and `render` commands. Records distinguish automated results from pending, passed, and failed manual checks for a specific version and content fingerprint.
- Bound release evidence to actual source and PDF bytes rather than a Git revision, timestamp, or unverified manifest alone. Changed inputs select a new pending record while preserving previous evidence as history.
- Added repeatable PDF page previews and explicit title, contents, index, changed-page, links/layout, browser-smoke, and post-publication checks. Rendering a page or passing an automated audit does not automatically approve a visual or operational review.

### Technical Details

#### Backend and request boundaries

- Added the `admin_gallery_title_completion` route in `app/bootstrap/dispatch.php`, its loader entry in `app/controllers.php`, and the controller in `app/controllers/admin_gallery_title_completion.php`.
- Kept strict responsibility boundaries: the controller owns authentication, GET/input validation, headers, and response status; `app/services/gallery_picker.php` owns normalization, budgets, and ranking; `app/models/galleries.php` owns parameterized persistence queries.
- Added a consistent JSON envelope with `ok`, `candidates`, `normalization`, and `truncated`. Successful searches return `200`; invalid input returns `400`; anonymous or viewer callers receive `401` rather than a login-page redirect; unsupported administrator methods return `405` with `Allow: GET`; lookup/authentication failures return a bounded `503` response.
- Applied `Cache-Control: private, no-store, max-age=0`, `X-Content-Type-Options: nosniff`, and `X-Robots-Tag: noindex, nofollow`. Candidate fields are limited to `id`, `parent_id`, `title`, and `created_at`; responses do not include paths, access tokens, or raw database exceptions.
- Validated UTF-8 queries against 255 code points and 1,024 bytes and accepted only representable nonnegative decimal parent IDs. Too-short or whitespace-only input returns an empty success without querying titles.

#### Query budgets and normalization

- Limited normalized candidate work to 1,024 titles overall and 512 siblings, using 512-row pages plus one lookahead row, at most three SQL page queries, at most 1,027 materialized rows, and a 16 KiB encoded response cap.
- Used exclusive `(created_at,id)` keyset continuation rather than `OFFSET`. Stopped before fallback when the sibling scope is truncated, because an unexamined sibling could outrank every non-sibling candidate.
- Applied NFKC followed by locale-independent lowercase when PHP `intl` and `mbstring` are available. Without either extension, limited matching to ASCII queries and titles rather than claiming unsafe Unicode equivalence.
- Retained a browser-side normalization/boundary check because PHP, ICU, and browser Unicode tables can differ. Browsers without `Intl.Segmenter` conservatively decline non-ASCII suffix mapping.
- Added no persisted normalized-title index and made no database-engine scan-time guarantee: `LIMIT` bounds returned rows, not every row the engine might inspect or sort.

#### Frontend integration and compatibility

- Updated `public/assets/gallery-modules/admin-gallery-title-completion.js` with per-input request state, a 180 ms debounce, a five-second timeout, same-origin/no-store fetches, bounded results, and generation checks covering input, parent, focus, and form lifetime.
- Updated `app/views/admin_gallery_forms.php` and `public/assets/styles/admin-gallery-title-completion.css` for discoverable completion help and accessible announcements. An explicit translated field label prevents nested help/live text from changing the input's accessible name.
- Replaced per-keystroke sorting of all matches with best-match selection over the small returned set. Preserved pointer acceptance, normal form validation, folder-name derivation, and server-authoritative creation.
- Refreshed the affected import chain in `public/assets/gallery.js`, `public/assets/public-gallery.js`, `public/assets/gallery-modules/admin-side-panel.js`, and `public/assets/gallery-modules/admin-operations.js`, and included the new preload module in layout asset-version inputs.
- Preserved both `progressive` and `responsive` thumbnail pipelines, public media authorization, the existing 100-400% zoom contract, immediate authorized active-original promotion above 100%, and no-JavaScript navigation and creation.

#### Recovery and fixture safety

- Split recovery CLI parsing, contracts, I/O, validation, and fixtures into `scripts/recovery/` behind the compatibility entry point. Rejected active-install overlap, broad or traversing paths, symlinks/junction aliases, hardlinks, unsafe JSON, and evidence-output overwrites.
- Added `scripts/gallery_workflow_run.php`, `scripts/gallery_workflow_mysql.php`, and `scripts/gallery_workflow_ci.php` with explicit `disposable-only` opt-in, literal loopback addresses, nondefault ports, dedicated credentials, and randomly generated database identities.
- Verified the private MySQL instance's actual data directory before account creation and before shutdown. Cleanup targets only owned generated schemas, marked temporary directories, and exact child-process handles.
- Added `--release` to the disposable launchers so release preparation can provision the fixture around one `php scripts/audit.php --profile=release` invocation. Existing `--audit` continues to select `full`; release mode does not first run a duplicate full audit.
- Kept fixture code in the source-checkout testing workflow; production packages continue to exclude `tests/`, and fixture launchers refuse to operate without their test support.

#### Audit and qualification internals

- Updated `scripts/audit.php`, `scripts/audit_lib.php`, and `scripts/audit_registry.php` to register the new fixtures, include available standalone Chromium coverage in `full` as well as `release`, and apply explicit workflow timeouts.
- Fixed a Windows child-process deadlock exposed by verbose Node failures: temporary file-backed stdout/stderr capture now lets process-status polling and hard timeouts continue independently of output volume.
- Added before/after source fingerprints to release reports. Missing identity blocks qualification; changed inputs invalidate release consistency instead of leaving a misleading green result.
- Split qualification fingerprinting, CLI handling, evidence records, report import, and previews into `scripts/release_qualification/`. Stored local evidence under ignored `cache/release-qualification/`, excluded runtime data and compiler intermediates, and refused linked source inputs.
- Required a complete central `release` report for audit attachment, including matching before/after fingerprints, suite registry, result schema, and timing. Preserved skips and refused stale, partial, `quick`, or `full` reports as release evidence.

#### Database, configuration, and upgrade behavior

- Added no migration, table, column, index, stored-data rewrite, production configuration key, or capability toggle. Existing gallery IDs, files, settings, visibility, passwords, share links, and Trash records retain their established meaning.
- Preserved existing schema-inspection, destructive-mutation preflight, and security-policy behavior; this release does not redefine `available`, `missing`, `unknown`, or feature-disabled policy.
- Preserved the distinction between unpublished galleries, which may remain directly addressable, and private/password-protected galleries, whose media access must be denied without authorization.
- Left general server-side replay protection for create and classic multipart upload unchanged. Browser double-click suppression and prepared-batch retry coverage do not make every repeated POST idempotent; replaying a completed create request can still create a suffixed copy.
- Prepared consistent 0.104.1 runtime, documentation, release-metadata, manual, and manifest versions. No commit, tag, package publication, or production migration is implied by release preparation.

#### Measurements and permanent documentation

- Added `scripts/benchmark_title_completion.php` and `scripts/benchmark_title_completion_browser.mjs` with synthetic 100-, 1,000-, and 10,000-title fixtures and documented measurement boundaries.
- Measured a 749-byte common-prefix JSON response at 10,000 synthetic titles against 1,957,789 bytes in the reconstructed former escaped catalog. The measured SQLite service/controller median was 2.057 ms for that case; a bounded miss took longer. These are not whole-page or production MySQL timings.
- Measured the old 10,000-title browser matcher at 0.7914 ms per lookup and the eight-candidate matcher at 0.0020 ms in the documented Chromium fixture. Excluded rendering, network latency, and debounce time from those CPU measurements.
- Documented that the preload extraction reduced the main module's source size but increased combined gzip size by approximately 1.2 KiB. No unmeasured startup improvement, phone performance result, or speculative lazy-loading benefit was claimed.
- Added `docs/TITLE_COMPLETION.md`, `docs/BROWSER_LIFECYCLE.md`, `docs/GALLERY_WORKFLOWS.md`, `docs/RECOVERY_ASSURANCE.md`, `docs/RECOVERY_OFF_HOST.md`, and `docs/RELEASE_QUALIFICATION.md`; updated architecture, testing, release, source-map, and administrator documentation.
- Preserved the original review and implementation evidence in `docs/GALLERY_IMPROVEMENT_HISTORY.md` instead of shipping the temporary root-level roadmap as current instructions.

### Tests

#### Completion and lifecycle regressions

- Expanded `tests/admin_gallery_title_completion_test.mjs` and added `tests/admin_gallery_title_completion_browser_test.mjs` with a real DOM fixture for Unicode boundaries, IME guards, modifiers, selected text, dismissal, pointer acceptance, parent changes, detached controls, and out-of-order replies.
- Added `tests/gallery_title_completion_service_test.php` and its fixture for actual model/service/controller behavior, input and response limits, authorization statuses, sibling/fallback ordering, normalization fallback, bounded query counts, and larger catalogs.
- Added `tests/lightbox_preload_lifecycle_test.mjs` with 12 runtime cases, including repeated teardown/reopen cycles, cancellation races, rejected and synchronously failing work, zero-valued timer handles, and concurrency accounting.
- Updated existing lightbox navigation, resource, cache, zoom-quality, map, Smart Gallery, and Admin panel contracts for the new lifecycle owner and deployed cache revisions.

#### Real workflows, recovery, and release evidence

- Added `tests/gallery_workflow_integration_test.php`, `tests/gallery_workflow_browser_test.php`, and `tests/gallery_workflow_safety_test.php` with isolated support fixtures and explicit refusal tests for unsafe connection identities and cleanup targets.
- Kept the existing real-MySQL concurrency test in the same provisioned central run. CI requires all three workflow/concurrency PASS records instead of accepting an absent or skipped integration test.
- Added `tests/recovery_assurance_test.php` for recovery contracts and safety, and `tests/release_qualification_test.php` for fingerprints, artifact changes, evidence states, audit binding, and preview behavior.
- Expanded `tests/audit_runner_test.php` with large simultaneous stdout/stderr output and a silent-child timeout regression; added release-profile forwarding checks to the fixture safety contract.
- Retained the central release audit as the authority for regression, complete PHP/JavaScript syntax, MVC boundaries, mutation contracts, hardening, browser fixtures, release consistency, manifest freshness, and Git whitespace. Automated coverage does not replace a real off-host restore, assistive-technology review, or post-publication updater smoke test.

### User Impact

#### For administrators

- Improved optional title reuse in both create-gallery surfaces without forcing a suggestion, changing existing galleries, or adding a new setting. Ordinary typing and submission remain available if JavaScript, Unicode support, or the suggestion endpoint is unavailable.
- Removed the complete title catalog from each create form and reduced browser matching work while making the deliberate older-match cutoff explicit. No database migration or manual conversion is required for an upgrade from 0.104.
- Added practical recovery and release-evidence tools for maintainers, with clear boundaries between checked files, tested workflows, actual operational recovery, and outstanding manual sign-off.
- Kept backups, recovery objectives, production query plans, physical-device/IME/screen-reader review, and hosted CI results as evidence that must be obtained in the relevant environment rather than inferred from local fixtures.

#### For visitors

- Improved the internal lifecycle of nearby lightbox preview work without changing gallery URLs, public access rules, the chosen thumbnail renderer, or the established viewer controls.
- Preserved password/private-gallery protection, authorized media and metadata, slideshow, fullscreen, zoom, and no-JavaScript access. The new title endpoint and operational tooling are not public browsing features.

## Version 0.104

Version 0.104 adds inline title completion to the Admin create-gallery workflow. Administrators can reuse established naming patterns more quickly while retaining full control of the submitted title, parent gallery, folder name, and all existing creation behavior.

### Highlights

#### Gallery title completion

- Added ghost-text suggestions while entering a title in either the full-page or right-side-panel create-gallery form.
- Preferred matching titles from the selected parent gallery, with newer siblings ranked first and matching titles elsewhere in the gallery tree retained as a fallback.
- Allowed administrators to accept a visible suggestion with `Tab`, `ArrowRight`, or a pointer while keeping ordinary typing, editing, `Escape`, and form navigation available.
- Recalculated suggestions when the selected parent changes and supported create-gallery forms injected dynamically into the Admin side panel.

### Technical Details

#### Backend

- Added `gallery_model_title_completion_rows()` in `app/models/galleries.php` to load compact title metadata through the model layer.
- Added `gallery_title_completion_candidates()` in `app/services/gallery_picker.php` to prepare presentation-safe candidate data for the create-gallery use case.
- Updated `app/controllers/admin_gallery_form_models.php` to include title candidates only for new physical-gallery forms.
- Added no database migrations, schema changes, routes, configuration keys, or stored-data rewrites.

#### Frontend

- Added `public/assets/gallery-modules/admin-gallery-title-completion.js` for Unicode-normalized prefix matching, sibling-aware ranking, delegated event handling, and keyboard or pointer acceptance.
- Added `public/assets/styles/admin-gallery-title-completion.css` for the inline ghost-text presentation.
- Updated `app/views/admin_gallery_forms.php` to render the same enhanced title control in full-page and side-panel forms while preserving the normal required `title` input contract.
- Updated `public/assets/gallery.js` and `app/views/layout.php` to load the cache-busted module and stylesheet on the existing Admin gallery surface.

#### Compatibility and integrity

- Kept gallery creation server-authoritative: ignoring a suggestion or using JavaScript-disabled forms continues to submit the ordinary title field through the existing creation workflow.
- Preserved parent selection, folder-name derivation, visibility, upload integration, CSRF/authentication checks, and direct-page fallback behavior.
- Regenerated `app/core-manifest.json` for the complete release tree.

### Tests

- Added `tests/admin_gallery_title_completion_test.mjs` for case-insensitive matching, sibling priority, newest-title preference, fallback matching, minimum input length, acceptance controls, dynamic delegation, entrypoint boot, and server-rendered candidate metadata.
- Registered the new Node contract in `scripts/audit_registry.php` so it runs through the authoritative release audit.
- Retained the release audit as the authority for PHP and JavaScript syntax, PHP/Node/WinApp regression suites, MVC boundaries, browser integration when available, release consistency, manifest freshness, and Git whitespace.

### User Impact

#### For administrators

- Repeated or sequential gallery names can be entered faster from either create-gallery surface without copying an older title manually.
- Suggestions remain optional and never alter existing galleries or create a gallery until the administrator submits the normal form.

#### For visitors

- No public-gallery behavior, URL, access policy, media authorization, or stored content changed.

## Version 0.103.1

Version 0.103.1 is a focused public-viewer maintenance release that keeps lightbox navigation responsive during rapid stepping, delayed metadata, preview failures, and decoded-image cache turnover. It also replaces the separate navigation spinner with consistent, accessible loading and recoverable-error feedback while preserving media authorization, zoom, slideshow, map, and no-JavaScript behavior.

### Highlights

#### Reliable lightbox navigation

- Made each navigation intent own its complete metadata, preview, transition, quality-promotion, and completion lifecycle.
- Prevented stale requests from clearing loading state, replacing the active photograph, or scheduling slideshow work after a newer navigation intent.
- Kept foreground navigation live when optional preload, cache telemetry, decoded-cache eviction, or image-source setup fails.
- Preserved nearby in-flight preview work during rapid stepping while discarding stale queued preload requests.

#### Loading and failure feedback

- Replaced the standalone center spinner with the existing lightbox progress surface for consistent navigation and full-quality loading feedback.
- Added localized `Loading image...` text in English, Czech, German, and Swedish, with matching `aria-busy` and live-announcement behavior.
- Added a recoverable image-load failure state that identifies the failed navigation without blocking the next, previous, close, or reopen action.
- Added bounded navigation ownership and failure details to administrator-only development diagnostics.

### Technical Details

#### Frontend

- Updated `public/assets/gallery-modules/lightbox.js` with explicit navigation transactions covering sparse metadata lookup, source selection, decoded presentation, terminal failure, quality promotion, and slideshow continuation.
- Scoped quality-transfer progress and finalization to the request that still owns the active source, preventing stale completions from changing current loading state.
- Hardened decoded-image cache eviction, preload concurrency accounting, optional telemetry, and synchronous or rejected image setup so optimization failures cannot strand the viewer.
- Updated `public/assets/styles/lightbox.css` to share one progress surface between navigation and quality promotion, including reduced-motion behavior and a recoverable error presentation.
- Refreshed the lightbox dependency revisions in `public/assets/gallery.js`, `public/assets/public-gallery.js`, and `public/assets/gallery-modules/admin-side-panel.js` so deployed browsers load the corrected module.

#### Backend and compatibility

- Added no database migrations, schema changes, configuration changes, route changes, or stored-data rewrites.
- Preserved both supported public thumbnail renderers, protected-media authorization, Smart Gallery metadata loading, map navigation, voting, slideshow, fullscreen, zoom, and no-JavaScript fallbacks.
- Regenerated `app/core-manifest.json` for the complete release tree.

### Tests

- Added `tests/lightbox_navigation_loading_regression_test.php` for shared loading-state ownership and translated progress behavior.
- Added `tests/lightbox_navigation_transaction_liveness_test.php` for metadata, presentation, failure, slideshow, and stale-request settlement.
- Added `tests/lightbox_cache_eviction_liveness_test.php` for cache eviction, telemetry isolation, synchronous preload failures, and foreground source setup.
- Expanded lightbox resource-lifecycle, slideshow-preload, zoom integration, zoom-quality, and map-marker contracts.
- Retained the central release audit as the authoritative verification for PHP and JavaScript syntax, regression suites, browser integration when available, manifest freshness, release consistency, MVC boundaries, and Git whitespace.

### User Impact

#### For visitors

- Rapid next/previous navigation no longer leaves the lightbox indefinitely loading when metadata, previews, cache work, or optional telemetry completes out of order or fails.
- Loading and failure states are clearer and accessible, and a failed photograph does not prevent continuing through the gallery.

#### For administrators

- Development diagnostics now expose bounded navigation transaction state to help investigate viewer-loading problems without logging private paths, raw media credentials, or authorization tokens.
- No migration, configuration update, or manual data conversion is required.

## Version 0.103.0

Version 0.103.0 extends Smart Galleries with secure source-gallery context, GPS/map presentation, and richer public lightbox metadata. The release preserves the existing physical-gallery ownership model and authorization boundaries while making provenance and geographic context available through the established MVC and browser workflows.

### Highlights

#### Smart Gallery source context

- Added source-gallery provenance to Smart Gallery presentation so visitors and administrators can understand which physical gallery owns each matching photograph.
- Added safe source-gallery navigation context to Smart Gallery cards and lightbox payloads without creating a second ownership relation.
- Preserved the canonical `images.gallery_id` relationship as the only source of physical-gallery ownership.

#### GPS and map presentation

- Added map-ready context for authorized Smart Gallery results with source-gallery GPS privacy enforcement.
- Added aggregate map context independent of the currently paginated card page.
- Preserved the rule that map data is exposed only for authorized matching images with valid GPS coordinates and source galleries that allow maps.

#### Public lightbox and presentation

- Extended public and Smart Gallery lightbox context with source-gallery and metadata information while preserving existing navigation and media authorization.
- Added responsive source/map presentation styling and localized labels in Czech, German, English, and Swedish.
- Improved URL, rewrite, SEO target, and public-context handling for safe lightbox navigation.

### Technical Details

#### Backend

- Updated `app/services/smart_galleries.php`, `app/models/smart_galleries.php`, and related controllers and views to batch source-gallery context and prepare bounded presentation data.
- Added request, dispatcher, early-runtime, feature-policy, EXIF, and SEO guard integration while keeping SQL and persistence ownership in the model layer.
- Preserved strict MVC ownership and avoided N+1 source-gallery lookups.
- Added no database migrations, new tables, columns, or destructive data rewrites.
- Missing or invalid optional context is omitted safely; authorization and GPS privacy checks remain mandatory before source or map data is exposed.

#### Frontend

- Updated `public/assets/gallery-modules/lightbox.js` for source-context and map-aware lightbox behavior.
- Updated `public/assets/styles/public-shared.css` for responsive context and map presentation.
- Updated public and Smart Gallery views, layout integration, URL handling, and localized strings without weakening no-JavaScript fallbacks or protected media routes.

#### Documentation and integrity

- Updated `docs/SMART_GALLERIES.md` with the permanent source-context, provenance, GPS, map, and authorization behavior.
- Removed the temporary implementation roadmap after incorporating its durable requirements into documentation and automated contracts.
- Regenerated `app/core-manifest.json` for the release tree.

### Tests

- Added `tests/smart_gallery_source_map_hardening_test.php` for provenance, authorization, GPS privacy, aggregate-map, and source-context safety.
- Expanded Smart Gallery presentation and public-contract coverage.
- Updated SEO lightbox target, URL rewrite, public asset-loading, and lightbox-related regression coverage.
- The release audit remains authoritative for PHP and Node regression suites, syntax validation, MVC boundaries, browser integration, manifest freshness, release consistency, and Git whitespace.

### User Impact

#### For visitors

- Smart Gallery results can provide clearer physical-source context and, where permitted, useful map/GPS context.
- Public lightbox navigation and metadata are richer while protected galleries, GPS privacy, authorization checks, and existing fallback behavior remain enforced.

#### For administrators

- Smart Gallery presentation more clearly connects dynamic results with their physical source galleries and supported map context.
- No schema migration or manual data conversion is required for this release.
## Version 0.102.0

Version 0.102.0 is a feature and hardening release that expands administrator telemetry and diagnostics, improves Smart Galleries presentation, strengthens public media authorization and download resilience, and makes strict MVC boundaries an enforced release requirement. It adds broad regression coverage while preserving existing routes, stored gallery data, and supported compatibility paths.

### Highlights

#### Telemetry and diagnostics

- Added richer Admin telemetry reports for traffic segments, daily rollups, photo-open behavior, query profiles, storage health, database observers, and consistency diagnostics.
- Improved telemetry dimension normalization, privacy handling, media-served accounting, and bounded diagnostic logging.
- Added actionable dashboard and gallery-report summaries for maintenance, compatibility, and data-quality conditions.

#### Smart Galleries presentation

- Improved Smart Gallery presentation controls, inherited versus overridden settings, safe thumbnail bounds, pagination limits, and capability-master status.
- Improved Admin side-panel refresh behavior for dynamically created and updated galleries.
- Updated responsive gallery rendering, localized administrator messages, and public Smart Gallery contract handling.

#### Public authorization and downloads

- Strengthened visibility and access enforcement for public search results, media, thumbnails, metadata, and downloads.
- Added explicit handling and diagnostics for unavailable legacy server-side ZIP fallback storage while keeping progressive downloads independent of that optional path.
- Preserved protected-gallery behavior, authorized media streaming, and lightbox quality/lifecycle behavior.

#### Strict MVC release enforcement

- Expanded the MVC boundary checker and made zero-violation MVC validation part of the central audit and release qualification.
- Added contract coverage for controller, service, model, and view ownership rules.

### Technical Details

#### Backend

- Updated telemetry, database-observer, site-maintenance, download-cache, thumbnail-compatibility, public-search, Smart Gallery, and gallery-report services and models.
- Kept SQL and persistence logic in models, reusable policy and orchestration in services, HTTP flow in controllers, and presentation in views.
- Preserved schema capability semantics: confirmed `available` enables normal behavior, confirmed `missing` uses only documented compatibility or bootstrap paths, `unknown` blocks security-sensitive and mutation-sensitive operations, and `disabled` suppresses only real configurable capabilities.
- Added no database migrations and made no destructive data rewrites.

#### Frontend

- Updated Admin telemetry and Smart Galleries views, localized strings, shared gallery styles, lightbox lifecycle behavior, telemetry assets, usage reporting, and cache-busted Admin modules.
- Kept Admin side-panel mutations and refreshes in the existing in-place workflow with direct-page compatibility fallbacks.

#### Documentation and release integrity

- Updated `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `TESTING.md`, `docs/SMART_GALLERIES.md`, and `docs/PHP_Gallery_Manual.tex` for the release behavior and enforced contracts.
- Refreshed `app/core-manifest.json` after the final source and documentation edits.
- Removed temporary remediation roadmaps after incorporating their lasting requirements into permanent documentation and automated contracts.

### Tests

- Added or expanded contracts for telemetry semantics, normalization, query plans, rollups, storage diagnostics, media and image observability, photo lifecycle, public authorization, download health, maintenance diagnostics, Smart Galleries presentation, side-panel refresh, lightbox lifecycle, thumbnail compatibility, and MVC boundaries.
- The release audit covers PHP and JavaScript syntax, PHP and Node regression suites, WinApp checks when available, mutation contracts, runtime hardening, MVC boundaries, manifest freshness, release consistency, browser integration when available, and Git whitespace validation.

### User Impact

#### For visitors

- Public search and media routes now apply stricter, centralized authorization checks, reducing the risk of exposing content from protected or unavailable galleries.
- Public media, thumbnails, downloads, and lightbox behavior remain available through their existing URLs and supported no-JavaScript fallbacks.

#### For administrators

- Telemetry and System Health provide more useful, bounded diagnostics for traffic, storage, query performance, media delivery, maintenance, and consistency issues.
- Smart Gallery presentation settings are clearer about inherited defaults and explicit overrides, with safer bounds and more reliable side-panel refreshes.
- Release audits now fail on new MVC ownership violations instead of allowing them to accumulate as baseline debt.

## Version 0.101.2

Version 0.101.2 is a focused maintenance release that makes the administrator Complete Report reliable for large image libraries and constrained shared-hosting environments. It reduces the number of browser requests, retries transient hosting failures, bounds high-cardinality report state, and returns the completed HTML export without retaining a second large copy in the server-side job session.

### Highlights

#### Complete Report reliability

- Increased the normal image-processing batch from 20 to 250 rows while retaining a bounded maximum of 500 rows.
- Added limited exponential-backoff retries for transient timeout, rate-limit, and server responses during browser-driven report generation.
- Prevented large EXIF and GPS value sets from growing the server-side report job without a fixed bound.
- Returned the completed HTML report directly to the browser, reducing peak session-storage and serialization pressure near the end of large reports.

### Technical Details

#### Backend

- Aligned the browser, service, and model batch limits so the database layer no longer silently reduces 250-row requests to 100 rows and multiplies the total request count.
- Limited high-cardinality image-summary groups and GPS clusters to 500 entries, grouped excess summary values into an `Other values` bucket, and bounded gallery identifiers retained per GPS cluster.
- Updated `app/controllers/admin_gallery_report.php` to stream the final self-contained HTML export with bounded completion metadata instead of embedding it in the JSON job state.
- Added no database migrations, schema changes, configuration changes, or stored-data rewrites.

#### Frontend

- Updated `public/assets/gallery-modules/admin-gallery-report.js` to process 250 image rows per request and retry transient HTTP `408`, `429`, `500`, `502`, `503`, and `504` failures up to three times after the initial attempt.
- Refreshed the Complete Report browser-module cache key in `public/assets/gallery.js` so deployed browsers load the corrected workflow.

### Tests

- Added `tests/admin_gallery_report_batching_test.php` to keep browser, service, and model batch limits aligned.
- Retained the central release audit as the authoritative verification for PHP and JavaScript syntax, regression coverage, release consistency, and manifest freshness.

### User Impact

#### For administrators

- Large Complete Reports now require substantially fewer requests and are less likely to fail late because of shared-hosting request, timeout, memory, or session-storage limits.
- The telemetry range selection continues to control only the telemetry portion of the report; image-database processing still covers the complete image library.

#### For visitors

- No public gallery behavior changed.

## Version 0.101.1

Version 0.101.1 is a small maintenance patch that fixes the Admin Trash listing warning when rendering recoverable gallery rows.

### Highlights

#### Admin Trash rendering

- Fixed the automatic-purge status display so Trash rows render without repeated `Undefined variable $autoPurgeActive` warnings.

### Technical Details

#### Backend

- Updated `app/views/admin_trash.php` to pass the automatic-purge state explicitly to each Trash row renderer.
- Added no database migrations or schema changes.

### User Impact

#### For administrators

- Admin Trash pages and side-panel fragments no longer emit PHP warnings while listing entries.

## Version 0.101

Version 0.101 is a major architectural and gallery-management release. It completes the repository-wide migration to strict MVC ownership, adds mixed photo and physical-gallery operations to the public Picture Manager, strengthens the Windows upload companion and automated release checks, and carries forward the complete schema needed by Smart Galleries, multilingual content, and viewer account lifecycle foundations. The release preserves existing public URLs, authorization rules, Admin side-panel behavior, both thumbnail renderers, no-JavaScript fallbacks, and updater integrity while substantially reducing cross-layer coupling.

### Highlights

#### Mixed-selection Picture Manager

- Expanded the logged-in public Picture Manager from photo-only selection to a mixed selection of direct photographs and direct physical subgalleries.
- Added visible selection controls to physical gallery cards while deliberately excluding Smart Gallery cards from file-based mixed-selection operations.
- Added a `Delete selected` action that can remove selected photographs and complete selected physical subgallery trees in one validated request.
- Routed selected physical-gallery deletion through the established recoverable gallery-trash workflow when Trash is enabled and available.
- Kept direct photograph deletion on the existing filesystem-backed image deletion service, including database, thumbnail, derivative, and cache cleanup behavior.
- Added creation of a real physical gallery from any combination of selected photographs and physical subgallery trees.
- Added a searchable parent-gallery picker so the new physical gallery can be placed under an explicitly selected parent rather than always becoming a child of the gallery currently being viewed.
- Copied selected photographs into the new gallery while retaining the originals in their source gallery.
- Copied selected physical subgallery trees as real folders and files, wrote current metadata sidecars before the copy, recreated gallery rows from the copied filesystem hierarchy, refreshed parent/public-path relationships, and rescanned copied photographs.
- Prevented recursive self-copy by refusing a destination that lies inside any selected gallery subtree.
- Prevented accidental folder merges by prevalidating every selected subtree and reserving each target root before recursive copying begins.
- Added rollback tracking before the first recursive copy so a failed or partial operation removes the newly created destination gallery and any partially copied roots where safe cleanup remains possible.
- Kept move and copy-to-existing-gallery operations explicitly photo-only. When a physical gallery is selected, the browser explains that those selections must be cleared before using photo move/copy.
- Kept physical gallery cards out of native drag operations, preserving drag-to-subgallery movement as a photo-only gesture.
- Allowed Picture Manager controls to appear in a physical gallery that contains subgalleries but no direct photographs.
- Updated selection counts, progress messages, confirmations, error messages, and completion notices to distinguish items, photographs, and physical gallery trees.

#### Strict repository-wide MVC architecture

- Completed the staged migration to the canonical `Bootstrap/Router -> Controller -> Service -> Model` dependency direction.
- Moved SQL, PDO access, schema-specific queries, row mapping, persistence transitions, and database cleanup into dedicated model modules.
- Kept reusable use cases, validation, policy, orchestration, filesystem/media work, and mutation coordination in services.
- Kept request globals, CSRF/authentication boundaries, route normalization, headers, cookies, response types, redirects, and status handling in bootstrap or controllers.
- Moved public and Admin HTML presentation into dedicated views supplied with prepared view-model data.
- Removed view access to request/session globals, SQL/persistence owners, and feature-policy/domain discovery calls.
- Removed service-owned headers, redirects, cookies, JSON/HTML output, and upward dependencies on controllers or views.
- Added explicit request-data and viewer-identity context adapters so request semantics cross layers without exposing transport globals to domain code.
- Added focused gallery/image bulk and editor mutation services so controllers no longer combine HTTP flow with persistence or filesystem orchestration.
- Retained compatibility entry points where existing callers depend on them, with those adapters delegating to the new canonical owners rather than duplicating behavior.
- Reordered and expanded the model, service, view, and controller loaders so isolated modules receive their dependencies in a deterministic downward-only order.
- Preserved public route contracts, Admin AJAX mutation envelopes, side-panel persistence, direct-page fallbacks, lightbox behavior, viewer account controls, thumbnail processing, upload workflows, and updater activation semantics through the refactor.

#### Enforced architecture rather than advisory conventions

- Added `scripts/check_mvc_boundaries.php`, a token-based source checker for direct database access, SQL literals, request globals, transport output, presentation leakage, forbidden upward dependencies, and filesystem mutation from views.
- Reduced `scripts/mvc_boundary_baseline.json` to an intentional zero-violation contract; Version 0.101 ships with no accepted legacy MVC exceptions.
- Registered strict MVC validation in the central quick, full, and release audit profiles.
- Added route-reference and loader-order checks so extracted modules cannot silently break dispatcher targets or dependency initialization.
- Updated `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `RELEASE.md`, and `TESTING.md` with the permanent ownership rules, module map, audit expectations, and release qualification requirements.
- Removed the completed temporary strict-MVC implementation roadmap from the release tree after incorporating its lasting rules into permanent documentation and automated contracts.

#### Upload automation and Windows companion resilience

- Hardened upload automation inventory handling with checksum indexing and durable normalized state.
- Improved the Windows watch uploader's persisted job history, discovery, media modeling, diagnostics, configuration handling, recovery paths, and installation/launcher integration.
- Preserved confirmed-success source deletion rules while making interrupted or recovered work easier to reconcile deterministically.
- Added Windows-side and PHP-side coverage for checksum indexing, inventory loading, simulated camera metadata, state persistence, and redesigned uploader behavior.

### Technical Details

#### Models and persistence

- Added `app/models.php` as the model-layer loader and introduced dedicated model modules for galleries, images, gallery mutations/order/trash, authentication and persistence, viewer accounts and tokens, collections and sharing, favourites, tags, Smart Galleries, telemetry, logs, diagnostics, maintenance, uploads, thumbnails, downloads, AI metadata, EXIF, flight maps, navigation, schema inspection, public paths, Picture Manager, and related subsystems.
- Extracted gallery and image persistence into `app/models/galleries.php`, `app/models/images.php`, `app/models/gallery_mutations.php`, `app/models/gallery_order.php`, and `app/models/gallery_trash.php`.
- Extracted administrator and viewer identity persistence into focused authentication, throttling, account, registration, lifecycle, token, rate-limit, security-event, collection, sharing, and favourite models.
- Extracted operational persistence for database maintenance, log archives, reports, telemetry, thumbnail metadata, upload automation, download signatures, duplicate-photo ledgers, Media Renamer state, and site maintenance.
- Added `app/models/schema_inspection.php` as the persistence owner used by the schema-inspection service without changing the established three-state policy surface.
- Preserved transaction boundaries, database compatibility behavior, row shapes, schema-cache invalidation, and security-sensitive fail-closed decisions while relocating their implementation.

#### Services and domain orchestration

- Added `app/services/gallery_bulk_mutations.php`, `app/services/gallery_editor_mutations.php`, `app/services/image_bulk_mutations.php`, `app/services/image_editor_mutations.php`, and `app/services/image_order.php` for reusable mutation use cases outside HTTP controllers.
- Added `app/services/auth_accounts.php` and `app/services/admin_diagnostic_logs.php` for reusable account and diagnostic orchestration.
- Refactored gallery discovery, reports, Trash, media renaming, thumbnail generation/maintenance, database maintenance, upload automation, Mobile WebDAV, Smart Galleries, viewer workflows, telemetry, tags, localization, downloads, and public path handling around their new persistence owners.
- Kept mutation preflight ahead of the first irreversible filesystem or database change and retained recoverability for prepared uploads, migrations, copies, deletions, derivatives, and updater staging when readiness cannot be proven.
- Preserved the narrower revocation policy for credentials: verified identity/revocation storage can still disable a credential even when unrelated issuance or authentication storage is unavailable.
- Preserved schema result semantics throughout the refactor: `available` permits the established operation; confirmed `missing` uses only an explicitly supported legacy or bootstrap path; `unknown` blocks security-sensitive and mutation-sensitive work; and configuration `disabled` suppresses only capabilities with a real switch.
- Preserved optional presentation/reporting behavior: unavailable optional reads may be omitted where policy allows, writes still require conclusive schema readiness, and disabled capabilities avoid unnecessary metadata probes.
- Kept System Health, Runtime Diagnostics, and refusal logs bounded to capability/operation identifiers, validated database object names, safe categories, and request correlation; raw SQL, PDO/database exceptions, credentials, tokens, secrets, and private filesystem paths remain excluded.

#### Controllers, request handling, and responses

- Added `app/request_data.php` and `app/bootstrap/viewer_identity_context.php` to normalize transport inputs and authenticated viewer context before domain services are called.
- Refactored public gallery, search, media, tags, SEO, downloads, votes, Smart Gallery, viewer, setup, upload, migration, update, and theme-asset controllers to own request/response flow without owning persistence or presentation.
- Refactored Admin authentication, dashboards, diagnostics, galleries, reports, logs, telemetry, themes, uploads, thumbnails, Trash, Media Renamer, duplicate-photo, feature, settings, and test-run controllers around prepared view models and service calls.
- Added controller modules for shared layout, public SEO, public gallery descriptions, and gallery form models where explicit transport/view-model ownership replaced mixed helper behavior.
- Registered the new `picture_manager_delete` route and added it to the canonical `picture_manager` capability-owned route list.
- Preserved authentication, CSRF, source ownership, destination validation, visibility/access enforcement, canonical mutation envelopes, status codes, and JSON/direct-page response compatibility.

#### Views and presentation

- Added `app/views.php` as the presentation-layer loader and introduced dedicated views for Admin authentication, dashboards, diagnostics, Features, gallery editing, reports, logs, telemetry, themes, updates, uploads, integrity, Media Renamer, tags, public inline tools, and render profiling.
- Added dedicated public views for shared pages, gallery cards and controls, lightbox markup, tags, pagination, downloads, Picture Game, Smart Galleries, viewer accounts, collections, favourites, lifecycle pages, voting, and HTTP/service-unavailable output.
- Moved formatting-only helpers into presentation-safe owners and supplied all views with controller-prepared URLs, labels, feature state, schema state, and result data.
- Preserved semantic server-rendered image markup, useful alternative text, public access gates, no-JavaScript navigation, and both permanent `progressive` and `responsive` thumbnail renderer pipelines.

#### Picture Manager backend and filesystem safety

- Updated `app/controllers/picture_manager.php` to normalize mixed selections, validate that submitted photographs and galleries belong directly to the displayed source gallery, accept an explicit `new_gallery_parent_id`, coordinate mixed deletion, and return structured JSON results.
- Updated `app/services/picture_manager.php` with physical gallery-ID normalization, direct-child ownership validation, destination-cycle protection, prevalidated subtree plans, collision checks, safe directory reservation/copy, sidecar synchronization, parent/public-path repair, image rescanning, and failure rollback.
- Reused `move_gallery_subtrees_to_trash()` for recoverable gallery-tree deletion, `delete_gallery_images()` for photograph deletion, `gallery_trash_copy_directory()` for path-safe subtree copies, and normal gallery discovery/indexing services for the copied result.
- Created new galleries with the selected parent's visibility, voting, filename-display, and count-badge defaults, while retaining the current gallery as the fallback parent for older clients that do not submit the new field.
- Ensured create-from-selection builds physical galleries and never creates or rewrites Smart Gallery rules.

#### Database

- Added migration `202608140001_smart_galleries.php` for persisted Smart Gallery rule definitions, public/private enablement and ordering state, plus private editorial image ratings and lookup indexes.
- Added migration `202608140002_smart_gallery_placement.php` for root, physical-gallery child, and unlisted Smart Gallery placement with an optional parent-gallery relationship.
- Added migration `202608140003_smart_gallery_multiple_placements.php` for the many-to-many `smart_gallery_placements` table and migration of existing single-parent placements.
- Added migration `202608150001_multilingual_content.php` for source-language markers and per-language gallery/image title and description tables with owner/language uniqueness and cascading cleanup.
- Added migration `202608170001_smart_gallery_presentation.php` for optional Smart Gallery presentation overrides.
- Added migration `202608170002_smart_gallery_attachment_ordering.php` for deterministic top/bottom placement areas and per-parent ordering.
- Added migration `202608180004_viewer_account_lifecycle_foundations.php` for staged viewer email-change requests using selectors, hashed verification tokens, account security-version binding, expiry/state indexes, and cascading account cleanup.
- Kept gallery/image and viewer-content data intact during migration; no Version 0.101 migration destructively rewrites existing photographs or gallery folders.

#### Frontend and localization

- Updated `public/assets/gallery-modules/picture-manager.js` to manage mixed card selection, submit both `image_ids[]` and `gallery_ids[]`, require the chosen parent for new galleries, perform mixed deletion, and keep photo-only actions disabled for gallery selections.
- Updated public gallery card/control/page markup so physical subgallery cards expose accessible selection controls and the page-level toolbar remains available for subgallery-only views.
- Updated `public/assets/styles/public.css` for mixed-selection gallery-card state, the delete action, parent selection, responsive creation fields, and the revised toolbar layout.
- Updated `public/assets/gallery.js` and `public/assets/gallery-modules/admin-side-panel.js` with the shared `20260914-picture-manager-mixed-v1` cache revision so deployed browsers load the new handler consistently.
- Updated English, Czech, German, and Swedish catalogs with mixed-selection actions, confirmation/progress/result messages, parent selection, physical-gallery wording, and photo-only move/copy guidance.
- Preserved delegated event handling for dynamically rendered content and retained the browser URL, public page, and side-panel interaction model.

#### Compatibility, packaging, and integrity

- Added `data/gallery-trash/` to `.gitignore` so recoverable runtime Trash contents cannot enter source control or release packages.
- Preserved historical controller/service/helper entry points where compatibility required them, while their implementations now delegate to canonical MVC owners.
- Preserved both supported public thumbnail renderers, lightbox zoom/original-quality behavior, public search, visibility controls, viewer authorization, Smart Gallery access, and direct no-JavaScript routes.
- Updated central audit registration, Admin mutation contracts, runtime-hardening checks, and release documentation for strict zero-baseline MVC qualification.
- Regenerated `app/core-manifest.json` after the final updater-managed source and documentation changes.

### Tests

- Added `tests/mvc_layer_contract_test.php` and staged contracts `tests/stage3_controller_presentation_boundary_test.php` through `tests/stage13_loader_dependency_boundary_test.php` to cover presentation extraction, gallery/image persistence, relational features, identity/authentication, media pipelines, operational persistence, HTTP transport, view request independence, helper responsibility, and loader order.
- Added `tests/admin_auth_mvc_boundary_test.php`, `tests/admin_media_renamer_mvc_boundary_test.php`, and `tests/public_gallery_mvc_boundary_test.php` for high-risk cross-layer surfaces.
- Added `tests/route_reference_integrity_test.php` to verify dispatcher and registered route targets after module extraction.
- Added `tests/source_header_author_test.php` to protect repository source-header ownership conventions.
- Added `tests/picture_manager_mixed_selection_test.php` to cover route/capability ownership, physical-card selection markup, mixed request payloads, direct-child validation, Trash-backed deletion, explicit parent placement, physical subtree copying, rollback ordering, filesystem re-indexing, Smart Gallery exclusion, photo-only move/copy behavior, drag restrictions, and cache-busting imports.
- Added `tests/upload_automation_checksum_indexing_test.php` and expanded upload inventory, simulated camera metadata, browser upload, and Windows companion regression coverage.
- Updated the broad PHP regression tree for the new model/service/controller/view owners without weakening its behavioral assertions.
- Updated JavaScript contracts for Admin mutation completion, side-panel delegation, gallery refresh, Picture Manager behavior, public search, downloads, uploads, lightbox/map lifecycle, and viewer favourites.
- Updated WinApp regression coverage in `winapp/tests/test_redesign.py` for configuration, discovery, diagnostics, durable state, upload recovery, and UI/controller integration.
- Updated `scripts/check_admin_mutation_contracts.php` and `scripts/audit_registry.php` so the central audit includes the new MVC and browser contracts.
- Qualified complete-tree PHP and JavaScript syntax, PHP/Node/WinApp regression suites, strict MVC boundaries, Admin mutation envelopes, runtime hardening, release consistency, manifest freshness, Git whitespace, and environment-available browser integration through the release audit profile.

### User Impact

#### For visitors

- Normal visitor behavior is unchanged: Picture Manager selection and mutations remain available only to authenticated administrators.
- Public gallery URLs, visibility and password rules, NSFW protection, media authorization, semantic image markup, lightbox navigation/zoom, public search, maps, voting, downloads, and no-JavaScript fallbacks retain their established behavior.
- The MVC refactor does not require visitors to migrate settings, change links, or learn a replacement interface.

#### For administrators

- Administrators can select photographs and physical subgalleries together from the public gallery view, delete them in one operation, or copy them into a new physical gallery under a chosen parent.
- Selected physical galleries are clearly separated from Smart Galleries and from photo-only move, copy, share, download, and drag operations.
- Failed physical subtree copies are validated and rolled back as early as possible instead of leaving an intentionally accepted partial gallery.
- Existing Admin side-panel, direct-page, and non-JavaScript fallbacks remain available.
- Installations upgrading across the included schema changes must run the normal migration workflow before using the affected Smart Gallery, multilingual-content, and viewer lifecycle storage.
- Deployments receive stronger automated protection against future MVC boundary regressions and stale route/loader references.

#### For maintainers and integrators

- Persistence now has explicit model owners, domain policy and filesystem workflows have explicit service owners, transport decisions remain in controllers, and markup remains in views.
- The zero-entry MVC baseline means new cross-layer violations fail qualification rather than being added as accepted migration debt.
- Existing compatibility functions remain callable, but new and materially refactored work must use the canonical layer owner and dependency direction.
- The Windows uploader retains its established workflow while using more durable checksum-indexed state and recovery behavior.

## Version 0.100

Version 0.100 is a feature release introducing progressive public search. The new staged, relevance-aware search experience returns useful results early, defers expensive work, and preserves the gallery’s existing access, visibility, localization, and routing boundaries. It also adds bounded Admin diagnostics and dedicated regression coverage for the complete search pipeline.

### Highlights

#### Progressive public search

- Added a public search workflow that progressively evaluates search stages and presents available results without waiting for every deferred operation to finish.
- Added relevance-aware matching, deterministic ranking, pagination, and safe handling of partial or deferred result sets.
- Preserved existing gallery visibility, media authorization, and SEO request-guard behavior throughout public search requests.

#### Admin search diagnostics

- Added an Admin diagnostics view for inspecting bounded search behavior and stage results.
- Integrated search diagnostics with the existing Admin diagnostics surface without exposing raw SQL, database exceptions, private paths, or protected content.

### Technical Details

#### Backend

- Added `app/models/public_search.php`, `app/models/public_search_diagnostics.php`, and `app/models/public_search_progressive.php` for search result modeling, diagnostics, staged matching, ranking, and deferred processing.
- Added `app/services/public_search_diagnostics.php`, `app/services/public_search_progressive.php`, and their deferred-processing part for bounded orchestration and diagnostic reporting.
- Added `app/controllers/public_search.php` and `app/controllers/admin_search_diagnostics.php`, plus `app/views/public_search.php` and `app/views/admin_search_diagnostics.php`.
- Registered the public search route and service/model/view dependencies through the existing bootstrap registries.

#### Database

- Added no migration, table, column, index, or data rewrite. Search uses the existing application data and preserves current schema compatibility behavior.

#### Frontend and localization

- Updated `public/assets/gallery-modules/public-home-search.js` for progressive request handling, deferred results, ranking/pagination presentation, and safe browser updates.
- Added `public/assets/gallery-modules/admin-search-diagnostics.js` for the Admin diagnostics workflow.
- Updated `public/assets/gallery.js`, `public/assets/public-gallery.js`, and `public/assets/styles/public.css` with the required cache-busting and search presentation changes.
- Updated the maintained English, Czech, German, and Swedish catalogs with public-search and diagnostics strings.

#### Compatibility and documentation

- Preserved the existing public gallery entry points and MVC boundaries while adding search-specific controllers and views.
- Documented the permanent progressive-search architecture and audit coverage in `AGENTS.md`, `ARCHITECTURE.md`, and `CODEMAP.md`.
- Regenerated `app/core-manifest.json` for all updater-managed changes.

### Tests

- Added `tests/public_search_diagnostics_stage0a_test.php`, `tests/public_search_mvc_boundaries_test.php`, `tests/public_search_progressive_stage2_test.php`, `tests/public_search_progressive_stage4_test.php`, `tests/public_search_progressive_stage5_test.php`, and `tests/public_search_progressive_stage7_test.php`.
- Added `tests/public_search_progressive_test.mjs` for browser-side progressive search behavior.
- Extended localization coverage and registered the new JavaScript regression suite in `scripts/audit_registry.php`.
- Covered staged matching, deferred work, result ordering, diagnostics boundaries, MVC separation, localization, and protected-data handling.

### User Impact

#### For visitors

- Public search returns useful matching results progressively, with relevance-aware ordering and pagination once the available stages complete.
- Existing gallery visibility, access restrictions, localization, and no-JavaScript-safe routing remain enforced.

#### For administrators

- Administrators can inspect bounded public-search diagnostics from the Admin diagnostics area.
- No database upgrade or configuration change is required.

## Version 0.99.1

Version 0.99.1 is a focused maintenance patch for the Version 0.99 public-card visibility workflow. It keeps gallery, subgallery, and picture visibility changes in place after Admin mutations, ensures newly created galleries receive the correct refresh treatment, and improves the visual presentation of the public visibility indicator without changing the database schema or public visitor permissions.

### Highlights

#### Reliable in-place Admin refresh

- Fixed the Admin side panel and public gallery cards so visibility and gallery-creation mutations refresh the affected card and panel state consistently.
- Preserved the canonical mutation envelope and existing no-JavaScript POST fallback while keeping JavaScript-enabled actions in place.
- Kept dynamically rendered cards and newly created galleries covered by delegated handlers after partial refreshes.

#### Clearer public visibility presentation

- Added the missing public-card styling needed for the visibility eye indicator and its state variants.
- Preserved the existing administrator-only visibility controls and prevented visibility interactions from changing normal picture-card lightbox behavior.

### Technical Details

#### Backend

- Updated `app/controllers/admin_galleries_edit_actions.php` to return the created-gallery context and refresh metadata required by the shared Admin mutation completion flow.
- Preserved authorization, CSRF, visibility-policy, and canonical mutation-envelope behavior.

#### Database

- Added no migration, table, column, index, or data rewrite. Existing schema compatibility behavior remains unchanged.

#### Frontend

- Updated `public/assets/gallery-modules/admin-side-panel.js` to process refreshed and newly created gallery-card state through the existing delegated in-place workflow.
- Updated `public/assets/gallery-modules/admin-operations.js` and `public/assets/gallery.js` cache-busting revisions so deployed browsers load the corrected handlers.
- Updated `public/assets/styles/public.css` for the public visibility eye presentation and related card-state styling.

#### Tests and integrity

- Extended `tests/admin_side_panel_created_gallery_refresh_test.mjs` and `tests/admin_side_panel_gallery_refresh_test.mjs` for created-gallery and refreshed-card behavior.
- Extended `tests/public_card_visibility_eye_test.php`, `tests/smart_gallery_high_priority_hardening_test.php`, and `tests/stage4_mutation_hardening_contract_test.php` for visibility, routing, and mutation-contract coverage.
- Regenerated `app/core-manifest.json` for the changed updater-managed files.

### Tests

- Covered canonical created-gallery refresh metadata, dynamic side-panel handling, public visibility eye markup and styling, Smart Gallery priority behavior, and Stage 4 mutation-hardening boundaries.
- Confirmed that the patch introduces no schema changes and leaves existing visitor access and lightbox behavior intact.

### User Impact

#### For administrators

- Visibility changes and newly created galleries remain synchronized between the public card and Admin side panel without manual navigation or reloads.
- Existing direct POST fallback behavior remains available when JavaScript is disabled.

#### For visitors

- No change to public permissions, gallery visibility rules, media access, or lightbox behavior.

## Version 0.99

Version 0.99 adds compact public-page visibility controls for administrators. Gallery, subgallery, and picture cards now expose a small eye menu beside the existing edit and trash shortcuts, letting administrators switch published, unpublished, and private state directly from the public gallery view while preserving the existing side-panel and no-JavaScript fallbacks.

### Highlights

#### Public card visibility controls

- Added an admin-only eye menu to public gallery, subgallery, and picture cards for changing visibility without opening the full editor.
- Exposed published, unpublished, and private choices with accessible labels and fixed icon geometry so the menu stays aligned across all three states.
- Kept the existing edit and trash actions in place and prevented picture-card visibility clicks from opening the public lightbox.

#### In-place administrator workflow

- Submitted visibility changes through the existing public inline Admin AJAX path when JavaScript is available.
- Preserved direct POST behavior for browsers without JavaScript or direct route use.
- Closed open visibility menus on Escape and outside clicks, including after dynamically refreshed card markup.

### Technical Details

#### Backend

- Updated `app/controllers/public_gallery_cards.php` and `app/controllers/public_gallery_page.php` to render shared visibility-menu markup for galleries and images.
- Updated `app/controllers/admin_public_inline.php` so gallery and image visibility changes return canonical mutation envelopes with typed `gallery.visibility` and `image.visibility` metadata.
- Preserved legacy image storage compatibility by mapping the canonical unpublished state to the existing `draft` image visibility value.
- Added no database migrations and made no schema changes in Version 0.99.

#### Frontend

- Updated `public/assets/gallery-modules/admin-side-panel.js` to intercept delegated public-card visibility forms, submit them through `fetch`, and hand the canonical response to the shared mutation completion coordinator.
- Refreshed the relevant `public/assets/gallery.js` and `public/assets/gallery-modules/admin-operations.js` cache-busting imports so deployed browsers load the new handler.
- Updated `public/assets/styles/public.css` for the card placement, submenu presentation, and aligned published/unpublished/private eye variants.
- Updated maintained language catalogs in `app/lang/en.json`, `app/lang/cs.json`, `app/lang/de.json`, and `app/lang/sv.json` for the new visibility action text.

#### Tests

- Added `tests/public_card_visibility_eye_test.php` to protect rendering, POST fallbacks, delegated browser handling, shared mutation completion, lightbox exclusion, and aligned icon markup.
- Extended Admin side-panel refresh, Smart Gallery hardening, Stage 4 mutation hardening, and mutation-contract checks for the new visibility path and updated cache keys.
- Refreshed `app/core-manifest.json` for the changed updater-managed files.

### User Impact

#### For visitors

- Visitor behavior is unchanged. The controls render only for signed-in administrators with inline administration enabled.
- Public lightbox behavior remains unchanged for normal picture-card clicks.

#### For administrators

- Administrators can publish, unpublish, or privatize galleries, subgalleries, and pictures directly from public gallery cards.
- Visibility updates complete in place when JavaScript is enabled and remain available through normal POST fallbacks otherwise.

## Version 0.98

Version 0.98 establishes a single, dependency-aware capability policy for optional PHP Gallery features. It aligns feature availability, route protection, Admin navigation, settings discovery, health reporting, and caller behavior around registered capability definitions while preserving existing setting owners and stored data.

### Highlights

#### Centralized capability policy

- Added the canonical capability registry in `app/services/feature_flags/`, covering definitions, storage adapters, effective-state policy, route requirements, and Admin presentation.
- Distinguished persisted configured preferences from effective runtime availability, including dependency-aware checks and explicit `all_of`/`any_of` route requirements.
- Preserved compatibility helpers such as `feature_flag_enabled()`, `set_feature_flag_enabled()`, and `feature_flag_for_route()` with their established configured-state semantics.
- Kept capability disablement non-destructive: existing galleries, files, translations, tags, Trash contents, review ledgers, and subordinate preferences remain stored and become available again when a capability is re-enabled.

#### Consistent feature integration

- Updated Admin dashboards, Settings, Features, gallery editing, reports, logs, maintenance, theme, test-run, and authentication surfaces to use the shared effective capability policy.
- Applied registered capability ownership to public gallery pages, gallery cards, controls, tags, search, upload automation, Smart Galleries, viewer accounts, Picture Game, OpenAI assistance, EXIF, lightbox settings, telemetry, and updater-related workflows.
- Added bounded Admin health and Runtime Diagnostics reporting for capability readiness without exposing raw SQL, database exceptions, credentials, tokens, or private filesystem paths.

### Technical Details

#### Backend

- Added `app/services/feature_flags/registry.php`, `policy.php`, `adapters.php`, `routes.php`, and `admin.php` as ordered implementation parts behind `app/services/feature_flags.php`.
- Registered canonical keys, labels, descriptions, dependencies, owned routes/prefixes, data-disable policies, behavior tags, storage owners, and specialized Settings destinations.
- Kept domain-owned master settings authoritative instead of creating shadow feature flags, and retained lazy schema inspection and existing migration behavior.
- Updated callers to gate optional actions and background/network work at the appropriate boundary while leaving shared pages available for core functionality.

#### Database

- Added no database migrations and made no schema changes in Version 0.98.
- Preserved existing storage ownership and persisted administrator preferences during upgrades and capability disablement.

#### Frontend and Admin

- Updated Admin navigation, settings discovery, dashboard/report sections, gallery editor controls, and runtime health presentation to hide or disable only capability-owned affordances whose effective policy is unavailable.
- Updated maintained language catalogs and Admin views for the new capability status and settings presentation.

#### Tests

- Added `tests/feature_policy_core_test.php`, `tests/feature_policy_adapters_test.php`, `tests/feature_policy_admin_test.php`, `tests/feature_policy_inventory_test.php`, and `tests/feature_policy_stage13_contract_test.php`.
- Extended feature-policy, Admin test-run, localization, Smart Gallery, updater, viewer-account, and security-operation coverage.
- Covered configured versus effective state, dependency handling, route ownership, storage adapters, Admin registration, compatibility wrappers, and disabled/available policy behavior.

### User Impact

#### For visitors

- Public routes and controls now follow the same effective capability policy as the corresponding server-side feature behavior.
- Core gallery pages remain usable when an optional capability is unavailable; only controls and operations owned by that capability are withheld.

#### For administrators

- Admin > Features and Settings provide a consistent view of configured and effective optional capability state, including dependencies and actionable health information.
- Disabling an optional capability preserves its data and preferences, and re-enabling it restores access to the retained state.

## Version 0.97.2

Version 0.97.2 is a focused SEO and canonicalization patch for the public gallery homepage. It prevents unsupported homepage query-string variants from remaining crawlable duplicate URLs while preserving existing routing and supported homepage options.

### Highlights

#### Canonical homepage query handling

- Redirected public homepage requests containing unsupported query parameters to the clean canonical homepage with HTTP 301.
- Removed bogus query parameters from the redirect target while retaining the supported `gallery_page`, `view_as`, and `lang` parameters.
- Kept normal homepage requests at HTTP 200 and left query-string behavior for gallery, media, download, authentication, Admin, and other routes unchanged.

### Technical Details

#### Backend

- Updated `app/services/seo_request_guard.php` to perform homepage-specific canonicalization in the existing centralized request guard.
- Reused the existing `public_base_url()` canonical host and scheme handling.
- Explicitly rebuilt the redirect query string so Apache or PHP cannot inherit the original unsupported query.

#### Database and frontend

- Added no database migrations, dependencies, visible page changes, or frontend changes.
- Preserved existing authorization, routing, canonical metadata, and non-homepage query parameters.

### Tests

- Extended `tests/public_home_clean_url_test.php` with source-contract checks for the permanent redirect, supported parameter allowlist, canonical base URL, and explicit query construction.

### User Impact

#### For visitors

- Clean homepage requests continue to render normally with HTTP 200.
- Crawler-discovered homepage URLs with arbitrary parameters now resolve permanently to the canonical homepage instead of producing duplicate 200 responses.

#### For administrators

- No administrator-facing behavior changed.

## Version 0.97.1

Version 0.97.1 is a focused public-lightbox maintenance patch. It makes the byte-accurate full-quality progress indicator consistent in normal and fullscreen viewing and keeps rapidly arriving download progress visually current.

### Highlights

#### Consistent full-quality progress feedback

- Fixed the full-quality download indicator so it appears consistently in normal lightbox, fullscreen, and mobile-fullscreen viewing.
- Kept the existing accessible loading status while replacing the obsolete normal-mode pill/ring styling with the same byte-progress bar used by fullscreen.
- Made rapid stream-progress updates repaint immediately instead of being delayed by repeated CSS width transitions.

### Technical Details

#### Frontend

- Updated `public/assets/gallery-modules/lightbox.js` to expose the shared byte-progress state in every lightbox mode and update the fill through a bounded `scaleX()` transform.
- Updated `public/assets/styles/lightbox.css` so the progress track owns a full-width fill and the loading selector is shared across normal and fullscreen modes; removed superseded pill/ring rules.
- Refreshed `app/core-manifest.json` for the changed lightbox module and stylesheet.

#### Backend and compatibility

- Added no database migrations, configuration changes, routes, or backend behavior.
- Preserved the existing quality-request cancellation, authorization, navigation, zoom, and fallback behavior.

### Tests

- Updated `tests/lightbox_zoom_quality_indicator_test.php` to cover the shared normal/fullscreen progress contract, transform-based fill updates, hidden-state lifecycle, accessibility attributes, and removal of obsolete spinner CSS.
- The existing lightbox zoom lifecycle, rendering, browser map/lightbox, and full release audit coverage remains applicable to the unchanged request and viewer lifecycle behavior.

### User Impact

#### For visitors

- Full-quality lightbox downloads now show the same useful byte and percentage progress whether the viewer is windowed, fullscreen, or mobile fullscreen.
- Progress bars remain responsive during fast downloads and do not interfere with image controls.

#### For administrators

- No administrator-facing behavior changed.

## Version 0.97

Version 0.97 adds a recoverable gallery trash bin for administrator-initiated gallery deletion. Deleted gallery trees leave the live public hierarchy immediately but remain restorable from Admin by default, while permanent and optional retention-based cleanup stay explicit, bounded, and fail-closed.

### Highlights

#### Recoverable gallery deletion

- Changed normal administrator gallery deletion to move the selected gallery and its complete descendant tree into protected persistent trash storage before removing its live database rows.
- Added **Maintenance > Trash**, where administrators can review deleted gallery counts, photo counts, stored size, lifecycle state, and retention status; restore one entry; permanently delete one entry; or empty the trash.
- Restored recoverable galleries to their original hierarchy and metadata while refusing to overwrite an occupied live path.
- Kept individual-photo deletion outside the gallery trash feature; it remains a permanent operation.

#### Explicit retention and recovery policy

- Enabled recoverable gallery deletion by default while keeping automatic purge disabled by default.
- Added independent 1–365 day retention and bounded 1–100 entry maintenance-batch settings, with defaults of 30 days and 25 entries.
- Preserved existing trash entries when Trash is disabled and returned only future gallery deletes to the legacy permanent path.
- Re-armed current recoverable entries with a fresh full retention window when automatic purge resumes after being inactive, preventing old entries from being destroyed immediately.
- Added conservative reconciliation for interrupted trash, restore, and purge operations; ambiguous entries remain visible as problems rather than being deleted speculatively.

### Technical Details

#### Backend

- Added `app/services/gallery_trash.php`, which owns protected storage paths, versioned restore snapshots, verified filesystem moves/copies, lifecycle claims, rollback, restoration, permanent purge, bounded emptying, automatic cleanup, and stale-operation reconciliation.
- Added `app/controllers/admin_trash.php` and `app/views/admin_trash.php` for authenticated, POST-only, CSRF-protected Trash settings and mutations. Enhanced actions return the canonical Admin mutation envelope; direct/no-JavaScript requests retain redirect fallback behavior.
- Routed Admin bulk deletion and public-page administrator gallery deletion through one policy snapshot: when Trash is enabled, confirmed missing or unknown trash schema blocks the operation before any permanent-delete path can run. Disabling Trash is the explicit compatibility choice that permits future legacy hard deletion.
- Registered `mutation.gallery_trash` with System Health and the three-state mutation schema policy. `available` enables the recoverable workflow; confirmed `missing` and `unknown` both stop it before the first irreversible target mutation.
- Integrated bounded reconciliation and optional expired-entry purge with scheduled Site Maintenance. Automatic purge selects only `trashed` entries past their deadline and runs only while both Trash and automatic purge are enabled.
- Kept the restore snapshot authoritative in the database and wrote a sanitized adjacent recovery manifest without password hashes, share credentials, or bearer tokens.

#### Database

- Added migration `database/migrations/202609070001_gallery_trash_bin.php`, creating `gallery_trash_entries` with durable token, original-path, snapshot, counts, lifecycle, retention, attribution, timestamp, and lookup-index fields. Trash rows intentionally do not reference `galleries`, because they must survive deletion of the live hierarchy they describe.
- Added migration `database/migrations/202609070002_gallery_trash_state_machine.php`, upgrading early development installations to extensible lifecycle states, `LONGTEXT` snapshots, snapshot versions, operation timestamps, bounded error categories, and original-path indexing.

#### Frontend, localization, and deployment

- Added `public/assets/gallery-modules/admin-trash.js` so restore, permanent purge, and multi-batch Empty Trash complete in place inside the Maintenance surface without changing the browser URL.
- Updated the Admin dashboard, gallery cards, bulk actions, and side-panel completion paths to expose trash state and keep canonical mutation coordination intact.
- Added Trash UI messages to the maintained English, Czech, German, and Swedish catalogs.
- Added `data/gallery-trash/.htaccess` to deny direct HTTP access. Deployment helpers include only that policy file and exclude runtime trash payloads from release/deployment archives.
- Updated `README.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `DATABASE.md`, `TESTING.md`, and the administrator manual with the permanent recovery, schema, storage, maintenance, and operational contracts.

### Tests

- Added `tests/gallery_trash_model_test.php` covering settings, retention, token/path validation, disjoint storage roots, real nested filesystem moves, SHA-256-verified cross-filesystem copy fallback, schema-before-mutation ordering, rollback and path guards, bounded maintenance purge, authenticated POST/CSRF routes, canonical mutation envelopes, migrations, and maintained translations.
- Updated dashboard-deferral, Admin side-panel/cache-revision, Smart Gallery import-edge, and Stage 4 mutation hardening contracts for the new modules and current browser cache revisions.
- Kept the new test registered in the central PHP regression tree; release qualification runs it through `php scripts/audit.php --profile=release` together with repository syntax, mutation-contract, migration, translation, manifest, browser, and packaging-related checks.

### User Impact

#### For administrators

- Accidental gallery deletion is recoverable by default from **Maintenance > Trash**, including nested galleries, files, and supported metadata.
- Permanent cleanup remains deliberate: administrators must explicitly purge entries, empty the trash, or opt into retention-based scheduled purge.
- Upgrading requires the two Version 0.97 migrations before enabled recoverable deletion can proceed; a missing or uninspectable trash schema is reported instead of silently deleting permanently.

#### For visitors

- A trashed gallery disappears from public gallery discovery and related public surfaces immediately, just as with permanent deletion.
- Restoring a gallery makes its reconstructed hierarchy available again under the normal visibility, access, NSFW, and media-authorization rules.

## Version 0.96.6

Version 0.96.6 is a small maintenance release with no database schema or migration changes. It fixes the fullscreen full-quality progress bar introduced in Version 0.96.5, whose fill element did not render or size correctly.

### Highlights

#### Fullscreen progress bar rendering fix

- Fixed the fullscreen/mobile-fullscreen full-quality progress bar so its fill indicator renders and sizes correctly while an original-quality photo downloads.

### Technical Details

#### Frontend

- Added `display: block;` to `.lightbox-quality-progress-fill` in `public/assets/styles/lightbox.css`. The fill element is a `<span>`, which is inline by default and does not apply an explicit `width`/`height`, so the bar's completion fill was not rendering as intended.
- Regenerated `app/core-manifest.json` for the changed stylesheet. The deployed browser entrypoint's cache-busting revision is computed from actual file bytes at request time, so no separate version marker needed updating for this change.

### Tests

- No test changes. The fix is a single CSS display-mode correction with no behavioral branching to cover beyond the existing Version 0.96.5 manual verification steps in `TESTING.md`.

### User Impact

#### For visitors

- The fullscreen/mobile-fullscreen full-quality progress bar now visibly fills as the photo downloads, instead of staying visually broken.

#### For administrators

- No Admin-facing change.

## Version 0.96.5

Version 0.96.5 is a public lightbox release with no database schema or migration changes. It adds a real byte-progress indicator for full-quality photo loading in fullscreen and mobile fullscreen lightbox, and replaces the previous zoom-to-original `<img src>` swap with the same tracked fetch-and-decode path already used for passive quality upgrades, so both paths report accurate progress and cancel cleanly.

### Highlights

#### Fullscreen full-quality progress bar

- Added a progress bar shown only in fullscreen and mobile fullscreen lightbox while the original-quality image downloads, with a percentage and a dynamically unit-scaled byte readout such as `8.4 MB / 22.7 MB`.
- Kept the existing compact loading pill/ring for normal (non-fullscreen) lightbox mode so the added metrics do not consume space on smaller cards.
- Unified the passive 100% quality-upgrade path and the deliberate pinch/scroll zoom-to-original path onto the same tracked download, so both now report real progress instead of only the zoom path swapping the image source directly.

### Technical Details

#### Frontend

- Added `primeLightboxImageCacheWithProgress()` in `public/assets/gallery-modules/lightbox.js`, which fetches the authorized image through the Fetch API and streams the response body with a reader so real loaded/total byte counts are available from `Content-Length`, then lets the existing decode helper reuse the now browser-cached URL.
- Added `loadTrackedDecodedLightboxImage()` combining that byte-tracked fetch with the existing decode helper, used by both the passive quality upgrade and the explicit zoom-to-original path.
- Replaced the previous zoom-to-original flow's direct `<img src>` assignment and `load`/`error` listener pair with the same tracked fetch-and-decode path used for passive upgrades, giving both paths one consistent original-image installation path.
- Added an `AbortController` per quality request so navigating, closing, or starting a newer quality request cancels the in-flight transfer instead of letting a stale download finish and affect the wrong photograph. Aborted transfers are no longer recorded as failed sources.
- Added `setLightboxQualityProgress()` and `formatLightboxQualityTransfer()` to drive the progress bar fill, percentage, and byte readout.
- Added the `.lightbox-quality-progress` markup and CSS in `public/assets/styles/lightbox.css`, restricted to fullscreen/mobile-fullscreen modes; excluded `is-fullscreen`/`is-mobile-fullscreen` from the existing corner spinner/backdrop selectors so the two indicators never overlap.
- Regenerated `app/core-manifest.json` for the changed lightbox module and stylesheet. The deployed browser entrypoint's cache-busting revision is computed from actual file bytes at request time, so no separate version marker needed updating for this change.

### Tests

- Updated `tests/lightbox_zoom_quality_indicator_test.php` and `tests/lightbox_zoom_quality_lifecycle_test.php`, which previously asserted the removed synchronous `<img src>` zoom-to-original assignment, to instead verify the tracked fetch-and-decode path: the download still starts synchronously with the zoom input before any decode/installation work, the fullscreen/mobile-fullscreen progress element toggles correctly and stays excluded from the normal-mode indicator selectors, and a superseded download is aborted rather than recorded as a failed source.
- Extended `TESTING.md`'s Public Lightbox Zoom Verification checklist with manual coverage for the fullscreen progress bar's percentage/byte readout, its absence in normal lightbox mode, and cancellation behavior when navigating, closing, or re-zooming during an in-flight full-quality transfer.

### User Impact

#### For visitors

- Fullscreen and mobile fullscreen lightbox now show real download progress while a sharper photo loads, instead of only a generic busy indicator.
- Rapidly navigating, zooming, or closing the lightbox during a full-quality download no longer leaves a stale transfer that could affect a later photograph.

#### For administrators

- No Admin-facing change.

## Version 0.96.4

Version 0.96.4 is a maintenance and maintainability release with no database schema or migration changes and no change to the public gallery feature set. It reorganizes the largest Admin and backend modules into focused, ordered part files while preserving their historical entry points, include contracts, runtime behavior, and updater integrity metadata. The release also adds source-level checks for module path resolution and makes the split-module architecture easier to review and maintain.

### Highlights

#### Maintainable application modules

- Split the large Admin gallery editor into named capability, controller, overview, post-action, and tab modules while keeping `app/controllers/admin_galleries_edit_page.php` as the stable entry point.
- Split gallery reporting, Admin test-run analysis, and Admin test-run storage into focused service parts without changing their existing service registration or callers.
- Split browser uploads, gallery migration, and updater jobs into ordered part files covering validation, transfer planning, persistence, recovery, activation, and lifecycle behavior.
- Preserved the existing module include contract so callers continue to load the original top-level service or controller path.

#### Path and review hardening

- Fixed project-root path resolution used by the newly split modules, preventing nested part files from resolving shared application paths relative to the wrong directory.
- Added a dedicated module-split review audit and documented the supported split-module structure for future contributors and automated agents.

### Technical Details

#### Backend

- Kept the original entry points `app/controllers/admin_galleries_edit_page.php`, `app/services/admin_gallery_report.php`, `app/services/admin_test_run_analysis.php`, `app/services/admin_test_runs.php`, `app/services/browser_uploads.php`, `app/services/gallery_migration.php`, and `app/services/updates_jobs.php` as module boundaries with ordered `require_once` lists.
- Added focused module parts under `app/controllers/admin_galleries_edit_page/` and `app/services/{admin_gallery_report,admin_test_run_analysis,admin_test_runs,browser_uploads,gallery_migration,updates_jobs}/`.
- Centralized source inspection support in `tests/support/module_source.php` and updated `scripts/check_admin_mutation_contracts.php` so contract checks read complete split modules rather than only their entry files.

#### Database

- Added no migration, table, column, index, or data rewrite.
- Preserved all existing schema-sensitive policies and compatibility behavior; the refactoring changes file organization and path resolution only.

#### Frontend and documentation

- Made no browser feature or public URL changes; existing Admin and visitor workflows continue to use the same controllers, services, templates, and assets.
- Updated `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, and `TESTING.md` with the split-module architecture, path-resolution expectations, and review/audit guidance.
- Regenerated `app/core-manifest.json` so all updater-managed application files have current integrity hashes.

### Tests

- Added `tests/module_split_path_resolution_test.php` to verify project-root resolution and stable entry-point loading across the split modules.
- Updated Admin localization, test-run, duplicate-photo, mutation-schema, presentation-schema, Smart Gallery, stage-hardening, updater, and version-audit contracts to inspect complete module sources where required.
- Added the module-split review audit and retained the existing regression coverage for upload, migration, updater, Admin mutation, and presentation-schema workflows.

### User Impact

#### For administrators

- Admin gallery editing, reporting, test diagnostics, uploads, gallery migration, and application update workflows retain their existing behavior and URLs while becoming easier to maintain and diagnose.
- No database upgrade or configuration change is required.

#### For visitors

- No public gallery, media, authentication, download, or presentation behavior changed in this maintenance release.

## Version 0.96.3

Version 0.96.3 is a maintenance release with no schema or migration changes. It fixes a stale Admin update-status display: after a stable update, beta install, or rollback job finished activating, the Admin update page briefly lost all cached GitHub update information and fell back to a "no cached data yet, use Force check" placeholder until an administrator forced a check or the next automatic hourly probe ran.

### Highlights

#### Immediate post-update status refresh

- Fixed the Admin update page so it reflects the newly activated version right after an update job completes, instead of requiring a manual Force check to leave the empty placeholder state.
- Kept the existing safety property that a just-installed version is never shown as still needing an update: the stale pre-update cache is still cleared before the fresh result is stored.

### Technical Details

#### Backend

- Updated `application_update_job_finalize()` in `app/services/updates_jobs.php` so it recomputes the GitHub update-check status against the now-active version and re-caches it immediately after deleting the stale pre-update cache entry.
- Wrapped the refresh in a non-fatal `try`/`catch`: `check_application_update()` already reports its own remote/parsing failures inside a diagnostic array rather than throwing, but the call is still guarded so a local failure while persisting the cached setting can never fail an otherwise-successful, already-activated update job.

### Tests

- Extended `tests/gallery_description_layout_compatibility_test.php` with deterministic first-rename refusal, bounded Windows recovery, unchanged non-Windows refusal, concurrent-edit preservation, read-only refusal and staging cleanup.
- Extended `tests/updater_resumable_state_machine_test.php` with source-level assertions confirming that finalization still clears the stale cache, immediately refreshes it with a fresh GitHub result for the activated version, and keeps that refresh guarded by the non-fatal `catch` block.

### User Impact

#### For administrators

- The Admin update page shows the correct "up to date" status immediately after a stable update, beta install, or rollback completes, without needing to click Force check.

#### For visitors

- No user-facing change.

## Version 0.96.2

Version 0.96.2 is a maintenance release with no schema, migration, or user-facing behavior changes. It adds deterministic release preparation and consistency tooling so version bumps and pre-release qualification no longer depend on a maintainer or agent manually touching every version marker by hand.

### Highlights

#### Release preparation and consistency tooling

- Added `scripts/prepare_release.php <version>` to apply only the registered mechanical current-version markers for a release: `app/bootstrap.php` `CMS_VERSION`, the `README.md`/`TESTING.md`/`DATABASE.md` current-version markers, the `ARCHITECTURE.md` `CMS_VERSION` example, `docs/PHP_Gallery_Manual.tex` version/edition date, and the `release-metadata.json` entry and `v_<version>` tag. It also scaffolds a new `PATCH_NOTES.md` section when the target version has no entry yet, and deliberately leaves editorial notes, manual compilation, manifest regeneration, Git history, and publication as explicit follow-up steps.
- Added `scripts/check_release.php` as a read-only checker that cross-verifies runtime, documentation, manual, release-metadata, patch-note, and core-manifest versions for agreement and flags a manual PDF older than its LaTeX source.
- Registered the checker as the `release-consistency` suite in `scripts/audit.php` and `scripts/audit_registry.php`, so `--profile=release` now verifies release consistency as part of the normal audit run instead of requiring a separate manual pass.
- Added `RELEASE.md` as the authoritative release-lifecycle playbook, and pointed `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `README.md`, and `docs/LATEX_BUILD.md` at it instead of duplicating the release checklist in each document.
- `AGENTS.md` now states explicitly that release preparation or a passing release audit must never by itself result in a release commit, tag, push, or publication unless the user asks for that action.

### Technical Details

#### Backend

- Added `scripts/release_lib.php`, centralizing reusable release operations: detecting the current `CMS_VERSION`, updating registered current-version markers, updating `docs/PHP_Gallery_Manual.tex` version/edition-date commands, upserting `release-metadata.json`, and scaffolding a new `PATCH_NOTES.md` entry.
- Added `scripts/prepare_release.php` and `scripts/check_release.php` as thin CLI entrypoints over `scripts/release_lib.php`.
- Updated `scripts/audit.php` and `scripts/audit_registry.php` to register and run `release-consistency` (`scripts/check_release.php --quiet`) as part of the `release` profile.
- Regenerated `app/core-manifest.json` after the source and documentation changes.

#### Documentation

- Added `RELEASE.md` describing the complete release lifecycle: scoping, mechanical preparation, release-note/documentation completion, manual build/inspection, standalone consistency checks, manifest regeneration, the authoritative release audit, package inspection, and commit/tag/publish authorization.
- Updated `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `README.md`, and `docs/LATEX_BUILD.md` to reference `RELEASE.md` as the single source of truth for the release workflow instead of repeating the checklist.

### Tests

- Added `tests/release_tooling_test.php` covering `scripts/release_lib.php` marker updates, release-metadata upsert behavior, and patch-note scaffolding.
- Extended `tests/audit_runner_test.php` to assert that the `release` profile includes the new `release-consistency` suite.

### User Impact

#### For administrators

- No visible change to the public site, Admin UI, or any stored data. This release exists to make future release preparation faster and less error-prone for maintainers.

#### For visitors

- No user-facing change.

## Version 0.96.1

Version 0.96.1 is a maintenance release with no schema, migration, or user-facing behavior changes. It introduces a central audit runner that consolidates the project's PHP, Node, and WinApp regression suites behind three selectable profiles, and hardens the local/ZIP deployment scripts' tests-folder opt-in prompt. Release verification for this version is a superset of the Version 0.96 checklist run through the new central runner.

### Highlights

#### Central test audit runner

- Added a single orchestration entrypoint that runs the complete PHP regression suite, the registered Node test suite, and the WinApp Python suite behind three profiles: `quick` (fast, Git-changed-file syntax checks plus the full PHP regression suite), `full` (complete deterministic source verification including the slow ZIP64 boundary fixture and full PHP/JavaScript syntax validation), and `release` (adds Chromium map integration, `app/core-manifest.json` freshness, and `git diff --check`).
- Replaced ad-hoc per-suite invocation with structured, persisted reporting: a compact `cache/test-audit/latest.md`, a machine-readable `cache/test-audit/latest.json`, and per-suite drill-down logs under `cache/test-audit/<run-id>/`.
- Kept `php tests/run.php` working as a thin compatibility wrapper that delegates to `php scripts/audit.php --suite=php-regression --no-report`.

#### Deployment tests-folder prompt

- Fixed the local/ZIP deployment scripts (`scripts/deploy.sh`, `scripts/deploy.ps1`) so the tests-folder inclusion choice is asked interactively for local-mode runs when not supplied on the command line, instead of silently defaulting to excluded.
- Kept the existing safeguard that refuses the tests-folder opt-in for FTP deployments.

### Technical Details

#### Backend

- Added `scripts/audit.php` as the central orchestrator: subprocess management, profile selection, verbose/quiet modes, and structured Markdown/JSON report generation.
- Added `scripts/audit_lib.php` with reusable audit utilities: registry loading, suite execution, result normalization, and subprocess output capture.
- Added `scripts/audit_registry.php` as the registry-driven source of truth for every registered Node test's execution arguments, environment requirements, and output-parsing rules, so Node coverage is no longer discovered through a blind `*_test.mjs` glob.
- Simplified `tests/run.php` to delegate to the central audit runner for backward compatibility.
- Fixed `winapp/tests/test_redesign.py` so its SimConnect regression correctly exercises the nonfatal missing-DLL fallback branch instead of assuming a deliberately invalid manual override means no usable SimConnect DLL exists.
- Updated `scripts/deploy.sh` and `scripts/deploy.ps1` to prompt for the tests-folder inclusion choice on local-mode runs when `--include-tests`/`-IncludeTests` is not explicitly provided.

#### Tests

- Added `tests/audit_runner_test.php` as a meta-test that prevents unregistered Node tests from silently escaping profile coverage and guards against profile drift.

#### Documentation

- Updated `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `README.md`, and `TESTING.md` to describe the central audit runner, its profiles, Python-discovery behavior, and the updated deployment tests-folder prompt as the canonical verification workflow.
- Regenerated `app/core-manifest.json` after the final source, test, version, and documentation changes.

### User Impact

#### For administrators

- No visible change to the public site, Admin UI, or any stored data. This release exists to make release verification faster and more reliable for maintainers.

#### For visitors

- No user-facing change.

## Version 0.96

Version 0.96 expands gallery-to-gallery migration into a resumable hierarchy transfer. Administrators can move one gallery or its complete descendant tree between compatible PHP Gallery installations, recreate the imported hierarchy safely below a selected receiving gallery, and transfer originals, existing thumbnails, gallery assets, and metadata in bounded ZIP packages that resume after interrupted connections.

### Highlights

#### Recursive gallery migration

- Added an **Include subgalleries** option, enabled by default, for both push and pull migration workflows.
- Recreated the selected source gallery as a new child below the receiving gallery and preserved the descendant hierarchy in deterministic parent-first order.
- Preserved supported gallery and image metadata, translations, flight-map data, originals, generated thumbnails, and gallery branding assets across the imported tree.
- Kept single-gallery transfer available by clearing the recursive option.

#### Bounded and resumable ZIP transfer

- Replaced one-asset browser transfer steps with deterministic ZIP packages sized to the receiving server's upload policy.
- Kept each original grouped with its existing thumbnails and stored photograph bytes without unnecessary recompression.
- Added target-side package status so interrupted or timed-out transfers can skip assets already installed before retrying.
- Refused completion until every manifest asset is present and retained durable gallery and image mappings for safe resume.

#### Transfer safety

- Kept exact application-version compatibility and gallery-scoped API-key authorization for manifests, packages, status, and completion.
- Validated recursive tree structure, package membership, stable ZIP entry names, file sizes, SHA-256 checksums, target paths, schema readiness, and imported asset ownership before mutation.
- Created imported roots as unpublished child galleries during preparation and applied final metadata only after the transfer completes.

### Technical Details

#### Backend

- Updated `app/services/gallery_migration.php` for recursive manifests, legacy single-gallery manifest normalization, deterministic package planning, source ZIP construction, target ZIP validation, durable source-to-target gallery mappings, and completeness-checked finalization.
- Updated `app/controllers/gallery_migration.php` with package send/receive actions for Admin push and pull workflows and recursive-scope handling for API requests.
- Registered the new package routes in `app/bootstrap/dispatch.php`, `app/early_runtime.php`, `app/services/feature_flags.php`, and `app/services/seo_request_guard.php` without widening unrelated public request contracts.
- Added no database migration, table, column, stored-data rewrite, or configuration requirement.

#### Frontend and localization

- Updated `public/assets/gallery-modules/admin-gallery-migration.js` to process receiver-defined package plans, query target status before retries, skip completed packages, and keep progress resumable.
- Updated the Admin migration form and English, Czech, German, and Swedish catalogs for recursive selection, ZIP-package progress, reconnect, retry, and validation messages.
- Updated the authenticated gallery entrypoint cache revision so deployed browsers load the new migration workflow.

#### Tests and documentation

- Extended `tests/gallery_migration_model_test.php` with behavioral coverage for recursive manifest normalization, cross-gallery asset identity, atomic image package grouping, deterministic package plans, soft and hard size bounds, and malformed tree/package rejection.
- Updated architecture, code-map, testing, README, database-version, and administrator-manual documentation for the recursive child-tree and bounded ZIP-package contracts.
- Regenerated `app/core-manifest.json` after the final source, test, version, and documentation changes.

### User Impact

#### For administrators

- Complete nested gallery structures can be transferred between same-version installations without rebuilding the hierarchy manually.
- Interrupted transfers can resume from target-confirmed package state instead of restarting completed work.
- The selected receiving gallery remains intact and becomes the parent of the newly imported root.

#### For visitors

- Imported galleries remain unpublished while transfer preparation is incomplete and become visible only according to their restored final visibility and normal access policy.

## Version 0.95.4

Version 0.95.4 restores focused regression coverage for the browser download and Smart Gallery contracts. The production download path remains unchanged; isolated test fixtures now provide the runtime and request helpers required to exercise it reliably, while the related capability and activation contracts stay aligned with the current implementation.

### Highlights

#### Download regression coverage

- Restored download controller, manifest, service, and Smart Gallery regression tests that were failing because isolated fixtures lacked production runtime and request helpers.
- Preserved the existing browser download activation behavior and verified its production contract without changing the user-facing download workflow.
- Kept Smart Gallery file-count and aggregate source-byte limits, trusted route parameters, and capability behavior covered by focused tests.

### Technical Details

#### Backend and test fixtures

- Documented the constructor contract in `app/services/download_capabilities.php`.
- Updated download and Smart Gallery test fixtures to provide the runtime-limit, request-method, and SEO response helpers used by production code.
- Added the missing controller and manifest assertions needed to exercise request validation and download response behavior.
- Made no migration, table, column, stored-data rewrite, configuration, or production authorization change.

#### Frontend

- Kept the browser download activation contract in `public/assets/gallery-modules/gallery-download.js` aligned with the existing production pipeline.

#### Tests and documentation

- Extended `tests/admin_settings_normalization_test.php`, `tests/browser_upload_settings_test.php`, `tests/gallery_download_controller_test.php`, `tests/gallery_download_manifest_test.php`, `tests/gallery_download_service_test.php`, `tests/smart_gallery_medium_hardening_test.php`, and `tests/smart_gallery_public_contract_test.php`.
- Updated the isolated dispatcher fixtures used by `tests/support/nsfw_policy_dispatch_fixture.php` and `tests/support/security_schema_policy_dispatch_fixture.php`.
- Regenerated `app/core-manifest.json` after the final source and test changes.

### User Impact

#### For visitors

- Browser-based gallery downloads continue to use the existing bounded, authorized workflow.
- Smart Gallery download limits and trusted route handling remain enforced.

#### For administrators

- No configuration or migration action is required; this patch improves release confidence and test reliability without changing the Admin download workflow.

## Version 0.95.3

Version 0.95.3 fixes map-marker navigation regressions introduced around Version 0.95.2. Public visitors can again open photographs outside the current pagination window in the active lightbox, previously loaded sparse photograph metadata remains available when its card is no longer rendered, and cancelled or superseded selections can no longer change the photograph or unexpectedly navigate away after the map or viewer closes.

### Highlights

#### Reliable map-to-lightbox navigation

- Fixed a false 404 that rejected the public lightbox metadata request used to resolve a map photograph outside the current pagination window.
- Preserved previously loaded sparse photograph metadata across map selections when lazy loading or pagination removes its card from the rendered page.
- Kept rapid repeated marker selections deterministic so only the latest active selection can load metadata or change the photograph.

#### Safe cancellation and fallback behavior

- Prevented cancelled, superseded, closed, or stale map selections from merging metadata, changing the current photograph, or navigating to another page.
- Kept map-target lookup cancellation independent from shared nearby metadata and preload requests.
- Preserved the canonical authorized photograph-page fallback for current selections whose enhanced lookup or viewer rendering genuinely fails.

### Technical Details

#### Backend

- Updated `app/services/seo_request_guard.php` to allow `target_image_id` only for the public `gallery_lightbox_data` request contract; unrelated public routes and unknown parameters remain rejected, while authenticated Admin handling remains unchanged.
- Added no migration, table, column, stored-data rewrite, configuration change, or new media authorization route.

#### Frontend

- Updated `public/assets/gallery-modules/lightbox.js` so its sparse metadata cache remains authoritative between explicit lifecycle resets instead of being rebuilt from the current DOM during map navigation.
- Added explicit per-selection ownership and cancellation through deferred click handling, target lookup, metadata merge, photo commit, and canonical-page fallback.
- Updated the matching cache revisions in `public/assets/gallery.js` and `public/assets/public-gallery.js` so authenticated and public visitors load the corrected lightbox path together.

#### Tests and documentation

- Added `tests/seo_lightbox_target_guard_test.php` for the real public/Admin request guard, supported-route acceptance, and rejection of unsupported parameters and routes.
- Added `tests/lightbox_map_navigation_test.mjs` to execute production lightbox functions across detached-cache reuse, repeated selection, cancellation, close/reopen, stale-response, failure-fallback, and shared-request isolation paths.
- Added `tests/lightbox_map_browser_test.mjs` and `tests/fixtures/lightbox_map_navigation.html` for headless Chromium coverage of the production lightbox module with controlled metadata responses and popup-shaped DOM interactions.
- Updated the established lightbox, zoom, Leaflet marker, architecture, and testing contracts for the corrected lifecycle and cache behavior.

### User Impact

#### For visitors

- **Open photo** from a gallery map reliably opens the intended photograph in context, including photographs beyond the currently rendered page and photographs whose metadata was loaded earlier.
- Closing the map or viewer, leaving fullscreen, or choosing a newer marker no longer allows an older pending selection to change the photograph or redirect the page.
- A genuine current lookup or rendering failure still reaches the normal authorized photograph page.

#### For administrators

- Public and authenticated gallery views use the same corrected map/lightbox lifecycle without weakening the stricter public request guard.
- Existing galleries require no migration or configuration change.

## Version 0.95.2

Version 0.95.2 makes gallery-map photo markers lead back into the photograph experience instead of exposing raw media or disrupting the active viewer. Marker actions now use canonical photo-page URLs as durable fallbacks, open matching photos directly in the existing lightbox when possible, load a bounded authorized metadata window for photos outside the current pagination page, and preserve the fullscreen split map while its photo pane changes.

### Highlights

#### Map markers open photographs in the viewer

- Changed **Open photo** links in gallery-map popups to select the matching photograph in the active lightbox instead of navigating to the raw media response.
- Added in-viewer navigation for map-selected photographs that are already present in the current sparse lightbox cache.
- Added bounded target-window loading so a marker can open a photograph from another pagination page without preloading the complete gallery.
- Kept the fullscreen split map mounted while changing the photograph pane, including rapid repeated marker selections.
- Closed ordinary body-level map overlays cleanly before revealing the selected photograph in the lightbox.

#### Canonical fallback and interaction safety

- Added canonical authorized photo-page URLs and gallery identifiers to photo-marker payloads.
- Retained normal page navigation as the fallback when JavaScript is unavailable, the viewer is not active, a marker belongs to another gallery, or enhanced target resolution fails.
- Removed an over-broad fullscreen anchor sizing rule that could place a Leaflet popup link over other popup controls.
- Preserved the existing gallery, visibility, password, share-link, NSFW, GPS-display, and media authorization boundaries.

### Technical Details

#### Backend

- Updated `app/services/exif.php` to emit `page_url` and `gallery_id` for photo map points and advanced the cached map-payload fingerprint version so existing payloads are invalidated.
- Updated `app/controllers/gallery_lightbox.php` to accept an optional positive `target_image_id`, resolve it through the current gallery's authorized lightbox ordering, and return a capped metadata window plus `target_index`.
- Reused the existing `find_image()`, `gallery_lightbox_image_position()`, and lightbox metadata services; no new media route or authorization path was introduced.

#### Frontend

- Updated `public/assets/gallery-modules/lightbox.js` to capture popup actions before Leaflet removes popup DOM, merge target metadata into the sparse viewer cache, and use the existing `openAt()` lifecycle.
- Kept fullscreen split-map state during successful marker navigation while retaining immediate photo swaps and existing navigation/quality-generation guards.
- Updated `public/assets/gallery.js` and `public/assets/public-gallery.js` cache-busting imports so deployed browsers load the new map/lightbox behavior.
- Updated `public/assets/styles/lightbox.css` so fullscreen dimensions remain scoped to the photo zoom surface and do not affect Leaflet popup anchors.

#### Database, compatibility, and tests

- Added no migration, table, column, stored-data rewrite, or configuration requirement.
- Kept map payload reads optional under the existing presentation-schema policy and kept all lightbox target lookup inside the established access-controlled endpoint.
- Extended the Leaflet/lightbox regression contract for canonical popup targets, bounded target-window lookup, split-map persistence, page fallback, cache-busting imports, and popup hit-testing.
- Updated the release documentation and rebuilt the administrator manual for Version 0.95.2.

### User Impact

#### For visitors

- **Open photo** from a gallery map now opens the intended photograph in context, including photographs beyond the currently visible page.
- Fullscreen visitors can choose several map markers without the split map closing or a rapid second click toggling the underlying photo stage.
- Direct and no-JavaScript use continues to reach the normal photograph page rather than a bare image response.

#### For administrators

- Existing galleries and GPS metadata work without migration or reconfiguration.
- Updated map payloads replace earlier cached payloads automatically through the new payload fingerprint.
- Access controls and public GPS-display policy remain unchanged.

## Version 0.95.1

Version 0.95.1 hardens public physical-gallery and Smart Gallery downloads through a complete seven-stage security and resource-control pipeline. The release keeps the existing browser ZIP experience and no-JavaScript fallback while making crawler-triggered requests cheap, requiring short-lived scoped authority for protected work, bounding server-side preparation, reusing immutable results safely, and preventing download metadata or bearer credentials from amplifying filesystem, CPU, memory, or logging costs.

### Highlights

#### Stage 1: observability and crawler hygiene

- Added structured, bounded diagnostics for legacy download preparation failures, including resource identity, stable failure stage and reason, request method, route, request correlation, bounded User-Agent and Referer data, and a keyed client-IP fingerprint.
- Prevented raw SQL, stack traces, filesystem paths, cookies, share/access tokens, arbitrary headers, and raw client addresses from entering download failure diagnostics.
- Added `X-Robots-Tag: noindex, nofollow` to physical and Smart Gallery download responses without applying it to ordinary gallery pages.
- Added explicit `robots.txt` exclusions for download initialization, legacy, manifest, and source routes.

#### Stage 2: stateless signed download capabilities

- Added versioned stateless HMAC-SHA-256 capabilities in `app/services/download_capabilities.php`.
- Bound every capability to a resource type, positive resource ID, purpose-specific scope, issue time, expiry time, and random nonce.
- Added strict length, envelope, base64url, signature, expiry, clock-skew, resource, and scope validation before resource traversal or archive work.
- Added purpose-separated key derivation from the dedicated `download_security.capability_secret`, with compatibility fallback to the existing stable application secret and then `setup_key`.
- Kept `progressive` and `legacy` authority separate so a browser capability cannot authorize server-side legacy ZIP construction.

#### Stage 3: header-based progressive browser downloads

- Changed the normal JavaScript download flow to POST to `download_gallery_start` or `download_smart_gallery_start` before requesting a manifest.
- Moved normal progressive bearer transport to `X-PHP-Gallery-Download-Capability`, keeping capability values out of ordinary browser URLs and access logs.
- Retained a bounded query-token compatibility path for older/manual clients and rejected requests that provide disagreeing header and query capabilities.
- Preserved private no-store caching and `Referrer-Policy: no-referrer` on capability-bearing progressive responses.
- Kept browser-side source `Content-Length` validation, source-version conflict handling, ZIP64 support, and local ZIP assembly.

#### Stage 4: safe legacy fallback boundary

- Made historic physical and Smart Gallery legacy GET and HEAD routes confirmation-only; they no longer enumerate manifests or construct ZIP archives.
- Replaced crawlable build-triggering controls with explicit POST forms containing only the resource ID and a short-lived `legacy` capability.
- Preserved JavaScript enhancement on the same form while keeping the bounded POST fallback available when JavaScript is disabled or unavailable.
- Rejected missing, malformed, expired, cross-resource, cross-type, and progressive capabilities before manifest enumeration or archive creation.
- Preserved configured legacy file and aggregate-byte limits while keeping the normal progressive browser path independent of the smaller server fallback ceiling.

#### Stage 5: single-flight and bounded build admission

- Added canonical content-only legacy build keys that exclude capabilities, request IDs, client identity, hosts, User-Agent values, and irrelevant query parameters.
- Added non-blocking per-build filesystem `flock()` locks so concurrent requests cannot duplicate the same expensive preparation.
- Added a shared global build-slot pool with centralized defaults of two concurrent legacy builds and a bounded five-second `Retry-After` response under pressure.
- Ensured completed artifact hits bypass build admission and that archive transfers are not intentionally bandwidth-throttled.
- Revalidated content identity after admission and relied on kernel lock release for crash recovery; stale lock files cannot become permanent leases.
- Added incremental Smart Gallery file-count and aggregate source-byte enforcement with safe centralized runtime defaults.

#### Stage 6: immutable managed legacy artifacts

- Added `app/services/download_artifact_cache.php` for immutable physical-gallery and Smart Gallery fallback artifacts.
- Published completed ZIPs only after successful archive creation, close, entry-count sanity validation, bounded metadata persistence, and atomic directory rename.
- Restricted artifact metadata to bounded resource/build identity, revision, timestamp, archive size, and expected entry count; capabilities, visitor identity, client identity, public URLs, and source paths are never persisted.
- Added serve leases and build coordination locks so active artifacts and builds are skipped by maintenance.
- Added managed capacity reservations, a 4 GiB default artifact budget, a 512 MiB free-space margin, controlled HTTP 507 refusal, and `download.legacy_cache_capacity_refused` logging.
- Added retention and cleanup policy for physical artifacts, dynamic Smart Gallery artifacts, abandoned partials, inactive reservations, and stale coordination state, limited to managed download-cache roots.

#### Stage 7: progressive manifest and source cost hardening

- Added revision-keyed capability-free manifest metadata caching in `app/services/download_manifest_cache.php`.
- Derived physical revisions from the authorized ordered image set and stable source identity, and Smart Gallery revisions from the bounded canonical result set.
- Kept current capability validation, resource lookup, visitor authorization, visibility, NSFW policy, and Smart Gallery membership evaluation ahead of every cache lookup.
- Stored only normalized ZIP names, image IDs, source versions, sizes, totals, resource identity, revision, and bounded timestamps; current-request URLs and capabilities are injected only into the response.
- Preserved cache-miss filesystem existence, containment, realpath, and size validation while avoiding repeated per-file work on cache hits.
- Added generated `mr` revision and `s` expected-size snapshots to source URLs and invalidated only an exact cache entry after matching image, version, and expected-size verification.
- Kept source delivery PHP-authorized through exact `Content-Length` and `readfile()` streaming because no portable protected internal redirect can be assumed on supported shared hosting.
- Added cheap method and parameter rejection before capability, database, filesystem, manifest, or archive work; progressive manifest/source routes accept GET only and reject HEAD with 405.
- Added credential-free manifest profiling, SQL/row/filesystem counters, elapsed and memory diagnostics, `Server-Timing`, and `X-PHP-Gallery-Manifest-Cache` response headers.
- Added bounded maintenance cleanup for expired/corrupt manifest metadata and partial files, isolated from media and unrelated cache trees.
- Updated the SEO request guard to accept `mr` and `s` on both source routes and to redact query strings from security logs, preventing compatibility capabilities and share tokens from being recorded.

### Technical Details

#### Backend and configuration

- Added centralized download limits to `app/configuration_defaults.php`, including manifest limits, capability bounds, legacy admission, artifact retention/capacity, and manifest-cache retention/cleanup settings.
- Updated `app/bootstrap/configuration.php` to merge deployment defaults without requiring existing `config.php` files to change.
- Updated `app/controllers/downloads.php`, `app/services/downloads.php`, `app/services/download_capabilities.php`, `app/services/download_artifact_cache.php`, and `app/services/download_manifest_cache.php` for the staged flow.
- Integrated both download-cache cleanup services into `app/services/site_maintenance.php`.
- Preserved existing gallery/password/share-link/visibility/NSFW/media authorization and added no database migration, table, column, or stored-data rewrite.

#### Frontend and routing

- Updated `public/assets/gallery-modules/gallery-download.js` and its cache-busting import chain for POST initialization, header capabilities, manifest validation, source-size validation, retry behavior, and ZIP assembly.
- Updated physical and Smart Gallery public controls to use explicit no-JavaScript-compatible POST forms.
- Registered separate initialization, legacy, manifest, and source routes in `app/bootstrap/dispatch.php`.
- Added download route crawler policy and source snapshot query validation in `app/services/seo_request_guard.php` and `app/controllers/public_media.php`.

#### Tests and documentation

- Retained focused service, manifest, controller, Smart Gallery, public-media, browser-client, ZIP, ZIP64, and source-boundary regression coverage under `tests/`.
- Documented the seven-stage acceptance criteria, threat model, capability semantics, GET safety invariant, progressive and legacy flows, concurrency limits, cache lifecycle, maintenance behavior, rollback safety, and manual verification in `TESTING.md`, `ARCHITECTURE.md`, `CODEMAP.md`, and `README.md` before retiring the temporary implementation plan.
- Regenerated `app/core-manifest.json` after final source, documentation, version, and patch-note edits.

### User Impact

#### For visitors

- Normal Download actions continue to assemble ZIP files in the browser with progress reporting and ZIP64 support.
- Public download links remain compatible with direct navigation and no-JavaScript fallback use, but crawler visits no longer trigger expensive server ZIP creation.
- Public and Smart Gallery access, visibility, NSFW, password, share-link, and source authorization behavior remains enforced.
- Changed source files produce a controlled retry response instead of silently creating a corrupt archive.

#### For administrators

- Repeated legacy requests reuse immutable artifacts and no longer duplicate expensive ZIP work.
- Concurrent server preparations are bounded and produce controlled retry or capacity responses instead of indefinite waiting.
- Download cache capacity, active leases, stale partials, and manifest-cache maintenance are handled automatically by scheduled maintenance.
- Existing installations require no configuration edit and no database migration for this release.

## Version 0.95

Version 0.95 fundamentally hardens every persistent mutation launched from the Admin right-side panel. Gallery, image, upload, metadata, thumbnail, migration, duplicate-review, tag, date, and Smart Gallery actions now share one typed server response and one browser completion coordinator. Successful work remains in place with the panel open and the browser URL unchanged, while authoritative server-rendered contexts are refreshed, checked against explicit postconditions, protected from stale/out-of-order responses, and retried only through one bounded policy. The release also closes related gallery-visibility, deletion-refresh, browser-upload, thumbnail compatibility, and Windows uploader defects discovered during staged qualification.

### Highlights

#### One mutation completion architecture

- Added a canonical Admin mutation success/error envelope containing `ok`, `message`, typed `mutation` metadata, stable `entity_ids`, optional `panel` refresh metadata, explicit affected `contexts`, typed observable `postcondition` data, and direct-page `fallback` metadata.
- Added one shared browser completion coordinator in `public/assets/gallery-modules/admin-mutation-completion.js` for public-context refresh, verification, retry, stale-response rejection, diagnostics, and fragment replacement.
- Kept server mutation services authoritative for authentication, authorization, CSRF, schema readiness, path safety, validation, database/filesystem persistence, and logging.
- Kept server-rendered HTML authoritative instead of creating a second client-side rendering model.
- Preserved normal POST/redirect behavior for direct-page and JavaScript-disabled use without allowing fallback metadata to navigate a successful enhanced side-panel workflow.

#### Persistent side-panel behavior

- Kept the right-side panel mounted and open after successful enhanced mutations.
- Preserved the visible browser URL byte-for-byte instead of assigning `window.location`, calling `window.location.reload()`, or rewriting history to conceal a canonical URL change.
- Added lifecycle-safe delegated form handling so controls injected by a panel refresh remain intercepted on the next mutation.
- Separated workflow-owned progress/editor fragments from authoritative public gallery/tag context refreshes.
- Reported a controlled synchronization failure when persistence succeeded but the visible result could not be verified, while clearly preserving the completed server mutation.

#### Verified and race-safe public refreshes

- Matched gallery and tag contexts by stable entity identity rather than relying only on URL string equality.
- Distinguished the visible browser URL from the authoritative render URL needed after gallery rename, folder/slug change, reparenting, or tag slug change.
- Added explicit source, destination, old-parent, new-parent, moved-gallery, root, physical-parent, and Smart Gallery context sets for multi-context mutations.
- Added server-rendered identity/state metadata for gallery heroes, gallery cards, root listings, tag pages, image grids, pagination, ordering, and Smart Gallery placement.
- Verified refreshed HTML before public DOM replacement instead of treating a successful fetch or fragment swap as proof that the mutation became visible.
- Added a monotonic operation generation, per-context ownership, active-fetch aborts, stale retry suppression, and stale panel-response rejection so older work cannot overwrite newer state.
- Centralized a bounded shared-hosting read-after-write retry sequence and used it only after persistence succeeded but a declared postcondition remained temporarily unobservable.
- Preserved applicable pagination, sorting, language, filtering, active Admin tab, panel scroll, and public-page scroll during refresh.

#### Gallery and image workflows

- Migrated empty gallery creation and create-with-upload completion to the canonical pipeline while preserving created gallery identity through aggregation.
- Migrated gallery save, title/metadata edits, folder/slug rename, path changes, reparenting, visibility changes, cover/title-picture changes, and enhanced gallery-card deletion.
- Used canonical render mode for identity-changing saves while leaving the visible browser URL unchanged and storing the new render source for later panel operations.
- Added pagination-safe `gallery_membership` verification using direct card presence/absence plus authoritative full-context counts.
- Added exact `gallery_visibility` verification for Published/Unpublished transitions instead of relying on an indirect timestamp proxy.
- Reflected the persisted visibility scalar immediately on an already visible child card while the authoritative parent refresh converges on shared hosting.
- Avoided remote favicon retrieval on unrelated gallery saves by refreshing cached external-link favicons only when persisted description content changed.
- Migrated bulk and single-row image deletion, move-to-existing, move-to-new-gallery, draft/public/private visibility, NSFW on/off, cover selection, thumbnail generation, and ordinary/browser-prepared upload completion.
- Fixed bulk visibility and NSFW AJAX branches so a successful database mutation cannot fall through to flash/redirect HTML when JSON was requested.
- Verified image absence, visibility, NSFW state, gallery-wide image revision, full counts, cover identity, and persisted reorder sequence as appropriate to each action.
- Kept gallery hero deletion as a normal navigation workflow because the deleted page has no valid same-URL context to refresh.
- Kept Picture Manager hard navigation as an explicit standalone public-toolbar exception rather than incorrectly treating it as a side-panel mutation.

#### Embedded and auxiliary workflows

- Made embedded scan/import panel-owned and added canonical JSON completion while retaining direct-page fallback behavior.
- Added canonical queued-work completion for Force AI metadata regeneration, refreshed the API editor fragment, and deliberately declared no immediate public context while future worker processing remains pending.
- Migrated Media Renamer public invalidation to the coordinator while preserving its tool-owned preview and result UI.
- Migrated Metadata Organizer batch completion, preserved source/destination counts and moved image IDs, and removed reload/reconstructed-URL fallbacks.
- Routed Duplicate Photo Detector deletion through authoritative gallery refresh instead of treating local element removal as final synchronization.
- Migrated Duplicate Photo Detector `ignore_pair`, `ignore_gallery`, and `clear_ledger` persistence to typed canonical envelopes while retaining detector-owned result rendering and excluding temporary scan progress from the persistent contract.
- Migrated image reorder integration to canonical completion and persisted-order verification.
- Migrated image editing to gallery-context completion with an `image_visibility` postcondition.
- Migrated tag editing to stable `tag_id` identity and canonical tag render URLs without changing the visible browser URL after a slug rename.
- Added canonical public photo-card deletion with `image_absent` verification.
- Migrated gallery-scoped Upload Automation API-key creation/revocation, Gallery Migration `target_pull`, browser-batched gallery thumbnail creation, and per-gallery EXIF date suggestion application.
- Kept Gallery Migration progress/log UI tool-owned while synchronizing accepted partial/final target mutations and refreshing the server-rendered editor before a later save can overwrite imported metadata.
- Migrated every persistent Smart Gallery side-panel action: create, update, placement update, detach, duplicate, and delete.
- Refreshed all previous/new root and physical parent contexts affected by Smart Gallery placement changes and verified authoritative presence, count, top/bottom placement, and order.

#### Browser upload and automation hardening

- Treated selected browser processing as an explicit execution choice: capability or worker-preparation failure now stops before persistence instead of silently switching to classic server processing.
- Reserved literal `fallback === true` for an empty create-gallery request with no selected files; canonical successful responses retain a `fallback` object only as direct-page metadata.
- Prevented successful browser create/upload completion from replaying the classic workflow, eliminating duplicate gallery and duplicate-photo creation.
- Required browser batches that request thumbnails to send `prepared_thumbnails_required=1` and validated the complete configured size/format matrix before originals are stored.
- Kept malformed manifests, missing required variants, MIME/signature mismatches, unknown formats/sizes, and unsupported thumbnail payloads as hard failures.
- Treated the configured browser ZIP batch size as a soft packing target, allowing one atomic original-plus-thumbnails package above that target to travel alone while retaining the effective PHP upload limit as a hard ceiling.
- Preserved stable image IDs, source ordering, batch/session metadata, progress events, created-gallery identity, contexts, postconditions, and fallback metadata through classic and browser-assisted upload aggregation.
- Accepted a complete JPEG+WebP prepared-thumbnail compatibility matrix from the Windows uploader: modern WebP-only servers keep required WebP variants and count valid extra JPEG variants as skipped, while legacy servers accept both formats.
- Preserved strict rejection for invalid/unknown formats, MIME mismatches, unsupported sizes, and malformed automation thumbnail uploads.

#### JSON, refresh, and lifecycle fixes

- Added canonical JSON authentication and CSRF failures for enhanced public/Admin mutation routes instead of returning login-page HTML or plain text.
- Posted server-rendered mutation forms to the active browser origin so valid host aliases or schemes retain the current Admin session.
- Isolated accidental displayed PHP output before public gallery-card deletion JSON, logged only bounded discarded-output context, and prevented secondary logging warnings from corrupting the response.
- Added a rejection boundary around asynchronous side-panel success reflection so a failed independent refresh cannot leave the drawer stuck at “Saving”.
- Preserved visible non-routing query state while excluding routing identity parameters from canonical render URLs.
- Added explicit browser no-cache request headers alongside no-store/cache-busting behavior for independent verification fetches.
- Initialized lightbox teardown state before early setup returns, preventing temporal-dead-zone errors when a parent view with no photo cards is refreshed repeatedly after child gallery create/delete operations.

#### Test and package handling

- Added an opt-in `--include-tests true` / `-IncludeTests true` mode for local source-review folders and ZIPs while keeping production packages test-free by default.
- Refused test inclusion for FTP deployment so development regression sources cannot be uploaded accidentally.
- Preserved existing exclusions for secrets, runtime data, caches, logs, `.git`, temporary files, gallery media, and macOS metadata.

### Technical Details

#### Stage 1: canonical contract and coordinator foundation

- Added `app/helpers_mutation.php` with shared JSON-request detection, success/error envelope construction, typed mutation descriptors, panel metadata, public context builders, and postcondition helpers.
- Added `admin_wants_json()` as the shared enhanced Admin mutation detector.
- Defined `render_mode=preserve_view` for stable-identity refreshes and `render_mode=canonical` for mutations whose old route no longer represents the persisted entity.
- Added `public/assets/gallery-modules/admin-mutation-completion.js` with envelope normalization, stable context matching, authoritative render URL resolution, cache-busted no-store requests, owned-fragment replacement, typed postcondition verification, bounded retry, synchronization diagnostics, and stale-response primitives.
- Adapted the existing gallery create/upload verified refresh to the shared coordinator while retaining temporary compatibility for not-yet-migrated workflows during the staged rollout.

#### Stage 2: core gallery and image migration

- Updated `app/controllers/admin_galleries_edit_actions.php`, `app/controllers/admin_galleries_discovery.php`, `app/controllers/admin_images_bulk.php`, `app/controllers/admin_uploads.php`, and related public gallery controllers to emit canonical mutation results.
- Added stable gallery revision, effective visibility, full membership count, image count/revision, cover identity, and image-state metadata to public render contexts.
- Preserved canonical completion metadata and all uploaded image IDs across classic one-file and browser batch aggregation.
- Delegated gallery saves, image bulk operations, and existing/new-gallery uploads from `public/assets/gallery-modules/admin-side-panel.js` to the coordinator.
- Preserved panel workflow/tab/scroll state while refreshing only affected editor/public fragments.

#### Stage 3: embedded and auxiliary migration

- Updated scan/import, Force AI regeneration, Media Renamer, Metadata Organizer, Duplicate Photo Detector, image reorder/edit, tag edit, public inline deletion, Upload Automation tokens, Gallery Migration, gallery thumbnails, EXIF date suggestions, and Smart Gallery actions to use canonical completion.
- Kept tool-specific previews, logs, progress, and compact fragments in their existing workflow modules while forwarding durable public invalidation through one auxiliary completion bridge.
- Added JSON-only expected auth, CSRF, validation, and mutation-failure responses before legacy HTML helpers on enhanced endpoints.
- Preserved direct-page forms and explicit standalone navigation exceptions.
- Advanced cache-busting imports through changed modules and their `admin-operations.js` / `gallery.js` entry chains.

#### Stage 4: postconditions, multi-context, race, and cache hardening

- Added pre-replacement verification for `gallery_membership`, `gallery_identity`, `gallery_updated_at`, `gallery_visibility`, image count/revision/state/order, cover identity, tag identity, and Smart Gallery presence/placement/order postconditions.
- Added operation generations, context claims, abort controllers, stale retry guards, and guarded panel refreshes.
- Classified request, HTTP, parse, abort, wrong-context, structurally stale, superseded, unverified, and exhausted-postcondition outcomes with bounded development diagnostics.
- Stripped query strings from diagnostic render URLs so CSRF, share, and private query values are not recorded.
- Represented thumbnail repair with explicit `postcondition: null` and reported it as refreshed but unverified rather than falsely verified.

#### Stage 5: strict enforcement and cleanup

- Removed the browser compatibility adapter that reconstructed completion semantics from legacy top-level fields.
- Made missing canonical completion metadata a contract error.
- Removed duplicate/create-specific/tool-specific public refresh helpers and hard reload/history-rewrite recovery paths superseded by the coordinator.
- Required classic upload and Metadata Organizer aggregation to preserve the server-authored canonical envelope.
- Added `scripts/check_admin_mutation_contracts.php` as a deployment-tree static guard for strict envelope consumption, stable ID survival, aggregation, delegated dynamic controls, centralized retry ownership, JSON return boundaries, visibility postconditions, current-origin form posting, and navigation/reload/history invariants.
- Added permanent rules and ownership documentation to `AGENTS.md`, `ARCHITECTURE.md`, `CODEMAP.md`, `README.md`, `TESTING.md`, and the administrator manual.
- Removed the temporary staged roadmap during final Version 0.95 release cleanup only after its completed reports were transferred into permanent release and product documentation.

#### Database and compatibility

- Added no migration, table, column, index, or stored-data rewrite in Version 0.95.
- Reused existing gallery/image/tag/Smart Gallery, upload, thumbnail, duplicate ledger, API credential, and migration services and their existing schema policies.
- Preserved PHP 8.1+ compatibility and the framework-free, dependency-free browser architecture.
- Preserved public gallery/password/share-link/visibility/NSFW/media authorization and existing direct-page/non-JavaScript behavior.

#### Tests

- Added `tests/mutation_response_contract_test.php` for canonical PHP envelope shape and response metadata.
- Added `tests/admin_mutation_completion_test.mjs` for normalization, stable identity, postconditions, retries, and stale completion behavior.
- Added `tests/admin_mutation_stage4_hardening_test.mjs` for pagination-safe membership, image ordering/revisions, Smart Gallery placement, pre-replacement stale rejection, supersession, aborts, panel race protection, wrong-context retries, explicit unverified contexts, and diagnostic redaction.
- Added `tests/admin_side_panel_delegation_test.mjs` for dynamic side-panel interception.
- Added `tests/stage3_auxiliary_mutation_contract_test.php` for auxiliary workflow envelope and event wiring.
- Added `tests/stage4_mutation_hardening_contract_test.php` for server-rendered verification metadata and workflow boundaries.
- Extended `tests/admin_side_panel_gallery_mutation_test.php`, `tests/admin_side_panel_gallery_refresh_test.mjs`, and `tests/admin_side_panel_created_gallery_refresh_test.mjs` for URL stability, panel persistence, canonical render sources, visibility, deletion, upload, refresh, and lifecycle behavior.
- Extended Duplicate Photo Detector, Smart Gallery, hero count, and deployment packaging contracts.
- Reconciled stale source-pattern expectations with the final strict browser-upload choice and final cache-busting import chain discovered by the complete source-tree suite.
- Added the two canonical-contract failure strings missing from the maintained English, Czech, German, and Swedish catalogs.
- Added and extended the 60-check `scripts/check_admin_mutation_contracts.php` repository guard.
- Retained complete PHP, Node, localization, migration, syntax, documentation, package, manifest, and manual browser qualification as release gates.

### User Impact

#### For visitors

- Public gallery, tag, image, cover, and Smart Gallery content now reflects completed administrator changes more reliably without stale fragments being accepted as current state.
- Existing access, visibility, NSFW, password, share-link, media authorization, pagination, and no-JavaScript behavior remain intact.

#### For administrators

- Persistent actions launched from the right-side panel stay in place, keep the panel open, preserve the current URL, and refresh the affected server-rendered content automatically.
- Renames, reparenting, image moves, visibility changes, Smart Gallery placement, and other multi-context operations update every relevant visible context without forcing navigation.
- A delayed shared-host read is handled by bounded verification retries; a genuine synchronization failure is reported without losing or misrepresenting the successful server change.
- Browser-assisted uploads no longer silently switch execution pipelines, replay completed creation, omit required prepared thumbnails, or reject a single valid image package merely because it exceeds the soft batch target.
- No database migration, manual metadata rebuild, thumbnail regeneration, or configuration change is required for the Version 0.95 upgrade.

## Version 0.94.8

Version 0.94.8 improves the embedded Admin gallery workflow by refreshing the background gallery in place after successful side-panel mutations, so newly created or edited galleries appear immediately without a manual page reload.

### Highlights

- Refreshed the affected background gallery after successful panel-created gallery mutations.
- Kept the Admin side panel open while updating the gallery content in place.
- Preserved the current URL and avoided full-page navigation or reloads.
- Added no migration and made no schema or stored-data change.

### Technical Details

#### Frontend

- Updated `public/assets/gallery-modules/admin-side-panel.js` and related gallery modules to coordinate successful mutation responses with the existing background gallery refresh path.
- Updated cache-busting imports for changed browser modules.

#### Backend

- Preserved the existing gallery mutation, authorization, CSRF, and response behavior while making the refreshed gallery fragment available to the panel workflow.

#### Tests

- Added `tests/admin_side_panel_created_gallery_refresh_test.mjs` for created-gallery refresh behavior.
- Added `tests/admin_side_panel_gallery_refresh_test.mjs` for in-place refresh, panel persistence, URL stability, and dynamic controls.
- Added `tests/admin_side_panel_gallery_mutation_test.php` for the server-side mutation contract.

### User Impact

#### For visitors and administrators

- Newly created galleries and edited gallery details become visible immediately in the background gallery after the operation succeeds.
- Users no longer need to manually reload the page to see the result.

## Version 0.94.7

Version 0.94.7 aligns opened-gallery picture counters with the public gallery-card count semantics, preventing signed-in viewers from seeing a broader visibility count in the gallery hero than on the card that opened it.

### Highlights

- Fixed opened-gallery hero counters to report the same public-only branch image total as gallery cards.
- Preserved the existing gallery access, visibility, and contained-picture badge policy.
- Added no migration and made no schema or stored-data change.

### Technical Details

#### Backend

- Updated `app/controllers/public_gallery_page.php` to pass the public-only count policy to `gallery_branch_image_count()` for the hero counter.
- Kept the count query conditional on the effective count-badge setting and inside the existing public render-profile span.

#### Tests

- Retained the gallery hero count and public visibility regression coverage for the corrected counter semantics.

### User Impact

#### For visitors

- Gallery cards and opened-gallery heroes now show the same exact image total, including when a viewer is signed in.

#### For administrators

- No configuration, migration, content rewrite, or thumbnail regeneration is required.

## Version 0.94.6

Version 0.94.6 keeps gallery image totals visible when visitors move from a gallery card into the opened gallery. The existing contained-picture badge setting now controls the same branch count in the gallery hero, with matching accessibility text, translations, and responsive presentation.

### Highlights

- Added the current gallery's branch image count to the opened-gallery hero when the effective contained-picture badge setting is enabled.
- Reused the existing Theme default and per-gallery override, so card and hero counts remain governed by one setting.
- Clarified Admin labels and help text to describe the shared gallery-card and opened-gallery behavior.
- Added localized hero count descriptions in English, Czech, German, and Swedish.
- Excluded macOS `.DS_Store` metadata from release packages produced by both deployment helpers.

### Technical Details

#### Backend

- Updated `app/controllers/public_gallery_page.php` to resolve the effective badge policy before counting and to use the canonical `gallery_branch_image_count()` service for the current gallery and accessible descendants.
- Wrapped branch counting in the existing public render-profile span and skipped the count query when the effective badge setting is disabled.
- Preserved the existing public-only, gallery access, visibility, and descendant-count semantics used by gallery cards.

#### Database

- Added no migration and made no schema or stored-data change in Version 0.94.6.
- Continued to use the existing Theme setting and optional per-gallery count-badge override without changing their stored values or fallback behavior.

#### Frontend and localization

- Added the in-flow `.gallery-hero-count-badge` presentation to the existing responsive hero action row without introducing a second viewer or browser module.
- Added translated accessible labels and explanatory hover text for all four maintained language catalogs.
- Updated Admin Theme, gallery creation, gallery editing, settings inventory, and administrator manual wording to cover gallery cards and opened-gallery heroes.

#### Release packaging

- Updated `scripts/deploy.sh` and `scripts/deploy.ps1` to exclude `.DS_Store` files wherever they occur in the packaged tree.
- Kept application files, the compiled manual, patch notes, release metadata, migrations, and the integrity manifest in the release archive.

#### Tests

- Added `tests/gallery_hero_count_badge_test.php` to cover the effective visibility policy, canonical branch-count call, profiling boundary, accessible markup, maintained translations, and responsive CSS contract.
- Extended `tests/deploy_app_packaging_test.php` to enforce `.DS_Store` exclusion in both deployment helpers.
- Retained the complete regression, localization, PHP syntax, documentation, packaging, and integrity-manifest checks as release gates.

### User Impact

#### For visitors

- Visitors can see the total image count for the opened gallery and its accessible subgalleries directly in the hero panel.
- Galleries whose effective contained-picture badge setting is hidden continue to show no card or hero count.

#### For administrators

- The existing Theme default and per-gallery Show/Hide/Inherit controls now apply consistently to gallery cards and opened-gallery heroes.
- No migration, content rewrite, thumbnail regeneration, or configuration change is required.

## Version 0.94.5

Version 0.94.5 is a focused maintenance release that aligns the supported PHP runtime, protected cached-favicon delivery, and updater server-policy enforcement. It adds the reconciliation path needed to keep policy-managed files coherent across installs without changing gallery content or public media behavior.

### Highlights

- Raise the documented and installer-enforced minimum PHP version to 8.1.
- Serve cached link favicons through the protected asset route in every document-root and gallery-storage layout.
- Reconcile updater server-policy files during maintenance and update preparation, with a migration-backed repair path for existing installations.
- Preserve resumable updater, rollback, activation, gallery authorization, and cache behavior.

### Technical Details

#### Runtime and asset access

- Documentation, bootstrap checks, setup diagnostics, and migration validation now agree on PHP 8.1 as the minimum supported runtime.
- Cached favicon URLs no longer expose the galleries storage tree directly; the existing protected asset controller handles repository-root, `public/`, and custom storage deployments consistently.

#### Updater policy reconciliation

- Added the updater server-policy reconciliation service and migration used to detect and repair managed policy files before activation.
- Kept policy inspection and filesystem changes bounded and resumable, with existing rollback and recovery controls intact.
- No gallery media rewrite, thumbnail regeneration, or content migration is performed.

#### Tests and documentation

- Updated the administrator manual, release metadata, testing guide, and integrity manifest for 0.94.5.
- Release verification covers PHP/runtime checks, favicon access boundaries, updater reconciliation, migration consistency, packaging, and the complete regression suite.

### User Impact

#### For visitors

- Cached external-link favicons continue to load through a protected, cacheable route without exposing internal gallery paths.
- Gallery pages, downloads, thumbnails, and media authorization remain unchanged.

#### For administrators

- Hosts must provide PHP 8.1 or newer.
- Existing installations can reconcile updater policy files safely without altering gallery content.

## Version 0.94.4

Version 0.94.4 is a focused presentation fix for progressive gallery downloads. Download progress separators now render as a real middle dot instead of mojibake, and the corrected browser module is loaded through refreshed cache-busting identifiers.

### Highlights

- Replaced corrupted download-dialog separator characters with an explicit Unicode middle-dot escape that renders consistently across browsers and page encodings.
- Refreshed the public and Admin gallery download module cache-busting identifiers so deployed browsers cannot retain the malformed asset.
- Preserved download authorization, ZIP assembly, progress accounting, cancellation, retry, ZIP64 support, Smart Gallery checks, and server-side fallback behavior.

### Technical Details

- Changed only the download progress/summary presentation strings and their module version markers; no archive format, route, permission, or stored-data behavior changed.
- Regenerated `app/core-manifest.json` after the asset update.
- Added the release-specific documentation and retained the complete 0.94.3 progressive-download feature description below.

### Tests

- Re-ran the complete PHP regression suite, declaration documentation audit, download controller/service/manifest contracts, browser ZIP and ZIP64 tests, and JavaScript syntax checks.

## Version 0.94.3

Version 0.94.3 adds secure progressive ZIP downloads for public galleries and Smart Galleries. Modern browsers can assemble an archive locally from a bounded private manifest while each original remains independently authorized by the server. Direct and no-JavaScript requests retain the existing bounded server-side ZIP fallback.

### Highlights

- Added progressive browser downloads for physical galleries and Smart Galleries with progress reporting, cancellation, retry, duplicate-name handling, ZIP64 output, and memory-aware fallback behavior.
- Added private manifest and per-file streaming routes that never treat an image ID as proof of gallery membership.
- Preserved the existing direct-download path for no-JavaScript clients and legacy browsers, with explicit source-count and cumulative-byte limits before archive creation.
- Kept downloads behind the global feature flag and Smart Gallery presentation override; disabled features do not expose or serve download routes.

### Technical Details

#### Authorization and request boundaries

- Smart Gallery manifests use the canonical current published rule set and visitor visibility checks, then repeat membership and media authorization for every streamed source.
- Physical-gallery and Smart Gallery downloads retain gallery access, password/share-link, NSFW, image visibility, media-version, path-containment, and source-file checks.
- Manifest and file responses are private and non-cacheable; failures return bounded localized responses without leaking filesystem paths, database details, or raw exceptions.

#### Archive assembly and compatibility

- The browser receives only authorized source descriptors and streams originals one at a time into a local ZIP, avoiding a long-running server archive request for modern clients.
- ZIP64 support handles large archives while bounded file-count, source-size, memory, and duplicate-path rules keep resource use predictable.
- Server-side fallback archives remain available for direct links and no-JavaScript requests, including the existing cached/locked archive behavior where applicable.
- No database migration or stored-schema change is introduced.

#### Frontend and release assets

- Added localized download-dialog controls with native `<dialog>` behavior where supported, accessible status/progress messaging, cancellation, retry, and cache-busted module loading.
- Updated public gallery and Smart Gallery markup, routing, dispatch, feature flags, SEO guards, and the integrity manifest.

#### Tests

- Added and ran focused controller, service, manifest, browser-client, ZIP, ZIP64, authorization, fallback, and Smart Gallery download regressions.
- Existing thumbnail, access-control, Smart Gallery, updater, packaging, localization, and full-suite contracts remain part of release verification.

### User Impact

#### For visitors

- Authorized visitors can download large physical or Smart Gallery result sets with visible progress and cancellation in modern browsers.
- Downloading never grants access beyond the gallery and image permissions already enforced by the normal public routes.

#### For administrators

- The global download feature switch and per-Smart-Gallery download setting continue to control availability.
- Existing direct-download behavior remains available, and no migration or content rewrite is required.

## Version 0.94.2

Version 0.94.2 fixes a deterministic thumbnail format and metadata consistency bug. Public pages could combine historical `valid` JPEG metadata with the active modern policy and advertise `.jpg` candidates whose generated files no longer existed. Modern mode now remains strictly WebP-only at every shared thumbnail boundary, while legacy mode continues to support JPEG plus WebP compatibility derivatives.

### Highlights

- Modern is still the default and requests only WebP derivatives; stale metadata or historical JPEG files cannot override that setting.
- Legacy remains opt-in and continues to advertise and serve valid JPEG and WebP compatibility derivatives.
- Old cached `.jpg` thumbnail requests in modern mode are rejected without JPEG generation, setting changes, or policy bypasses.

### Technical Details

#### Thumbnail policy and public data

- Applied the canonical requested-format policy to public media manifests, generic thumbnail bundles, candidate-size selection, thumbnail sources, HTML, browser rebuild/warmup paths, and upload automation.
- Made the optimized public manifest query policy-aware so modern mode never loads historical JPEG variants into public bundles.
- Preserved stable revision URLs and the existing batched metadata architecture; ordinary public rendering performs no new per-candidate filesystem discovery or repair.

#### Metadata lifecycle and maintenance

- Legacy JPEG cleanup now removes or invalidates matching metadata even when the generated JPEG file is already absent, preventing `valid` rows from describing missing derivatives.
- Generation and publication ordering keeps metadata `valid` only after a derivative is successfully published.
- A JPEG generation failure in legacy mode does not invalidate a successful WebP derivative; WebP failure in modern mode does not trigger an automatic JPEG fallback.
- No database migration or original-photo deletion is introduced.

#### Tests

- Added focused consistency coverage for default modern behavior, stale metadata, historical JPEG files, legacy cleanup with present or missing files, independent format failures, old JPEG URLs, public manifests, progressive markup, responsive markup, authorization, and size bounds.
- Extended compatibility and public thumbnail markup contracts to enforce WebP-only modern output and preserve explicit legacy JPEG plus WebP behavior.

### User Impact

#### For visitors

- Modern galleries no longer emit generated JPEG thumbnail URLs that resolve to the Gallery's HTML 404 page.
- Existing WebP thumbnail URLs, revision-based caching, authorization, progressive rendering, and responsive rendering remain intact.

#### For administrators

- The default remains modern/WebP-only. Selecting legacy compatibility mode remains the explicit way to enable JPEG thumbnails.
- Maintenance and regeneration can repair stale legacy JPEG state without touching original photographs or WebP derivatives.

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
