# PHP Gallery Code Map

This file maps features to source files. It is optimized for fast maintenance and AI-assisted code changes.

## How to Use This Map

1. Find the feature area below.
2. Open the listed controller first when the change is request/response related.
3. Open the listed service first when the change is business logic, storage, validation or shared behavior.
4. Open the listed migration only to understand schema history. Add a new migration for changes.
5. Open public assets only for browser-side behavior or styling.

## Core Runtime

| Area | Files |
| --- | --- |
| Bootstrap coordinator and version constants | `app/bootstrap.php` |
| Configuration bootstrap | `app/bootstrap/configuration.php` |
| Request and security-header lifecycle | `app/bootstrap/request.php` |
| Session lifecycle | `app/bootstrap/session.php` |
| Public-path and query routing | `app/bootstrap/routing.php` |
| Scheduled-maintenance request hooks | `app/bootstrap/maintenance.php` |
| Route table and controller dispatch | `app/bootstrap/dispatch.php` |
| Controller loader | `app/controllers.php` |
| Service loader | `app/services.php` |
| View loader | `app/views.php` |
| Database connection | `app/database.php` |
| Migration runner | `app/migrations.php` |
| Migration definition validation | `app/migration_definitions.php` |
| Migration data repairs | `app/migration_repairs.php` |
| Security/session/CSRF helpers | `app/security.php` |
| General helpers | `app/helpers.php` |
| Integrity manifest logic | `app/integrity.php`, `app/core-manifest.json`, `scripts/generate_manifest.php` |

## Public Gallery Pages

| Task | Primary files | Secondary files |
| --- | --- | --- |
| Home page gallery listing | `app/controllers/public_gallery.php` | `app/services/gallery_display.php`, `app/services/gallery_lookup.php`, `app/services/pagination.php` |
| Gallery detail page | `app/controllers/public_gallery.php` | `app/views/layout.php`, `app/views/gallery_descriptions.php`, `app/services/gallery_display.php` |
| Breadcrumbs | `app/controllers/public_gallery.php` | `app/services/gallery_lookup.php`, `app/services/public_paths.php` |
| Gallery cards | `app/controllers/public_gallery.php` | `app/services/gallery_count_badges.php`, `app/services/gallery_dates.php`, `app/services/gallery_grid.php` |
| Gallery date ranges | `app/services/gallery_dates.php` | Manual start/end validation, public date formatting and EXIF-derived suggestion aggregation. |
| Image grid | `app/controllers/public_gallery.php` | `app/services/public_thumbnail_rendering.php`, `app/services/thumbnail_html.php`, `app/services/thumbnail_bundles.php`, `app/services/public_gallery_media_manifest.php` |
| Selected-gallery thumbnail renderer setting | `app/services/public_thumbnail_rendering.php`, `app/controllers/admin_theme.php` | Stable key `public_thumbnail_rendering_mode`; allowed values `responsive` and `progressive`; responsive is the safe default. |
| Progressive browser activation | `public/assets/gallery-modules/progressive-thumbnail-renderer.js`, `public/assets/gallery-modules/progressive-thumbnail-upgrade.js` | Conditional from `public/assets/public-gallery.js` and `public/assets/gallery.js`; near-viewport observer, two-slot scheduler, measured candidate selection, decode-before-swap. |
| Public thumbnail placeholder/layout CSS | `public/assets/styles/utilities.css` | Existing stable image-slot background and no-flicker rules are shared; intrinsic image dimensions are emitted by `thumbnail_html.php`. |
| Lightbox JSON | `app/controllers/gallery_lightbox.php` | `app/services/lightbox_metadata.php` |
| Lightbox browsing modes | `app/services/gallery_lightbox_mode.php` | Theme default plus per-gallery override resolution for single-image, picture-strip, and 3D-carousel modes. |
| Lightbox image zoom | `public/assets/gallery-modules/lightbox.js`, `public/assets/gallery-modules/lightbox-zoom-model.js` | Centered growing-frame geometry, cursor/pinch anchoring, pan and immediate active-original promotion. Server controls: `app/controllers/public_gallery_lightbox.php`; authorized quality candidates: `app/services/thumbnail_bundles.php`, `app/controllers/gallery_lightbox.php`; desktop/mobile styles and fullscreen clipping/HUD stacking: `public/assets/styles/lightbox.css`, `public/assets/styles/mobile-gallery.css`; dependency cache revision: `app/helpers_page_rendering.php`; focused contracts: `tests/lightbox_zoom_*` and `tests/lightbox_zoom_model_test.mjs`. |
| Public tags | `app/controllers/public_tags.php` | `app/services/tags.php`, `app/services/tag_metadata.php` |
| Gallery hero tags | `app/controllers/public_gallery.php`, `app/services/tag_metadata.php` | Full-width server-rendered tag groups with usage/alphabetical sorting and browser disclosure. |
| Picture game | `app/controllers/picture_game.php` | `app/services/picture_game.php` |
| Voting | `app/controllers/votes.php` | `app/services/votes.php`, `app/services/picture_game.php` |

## Public Search

| Task | Files |
| --- | --- |
| Search settings | `app/services/public_search.php`, `app/controllers/admin_dashboard.php`, `app/controllers/admin_theme.php` |
| Search bar rendering | `app/controllers/public_gallery.php` |
| Search endpoint | `app/controllers/public_gallery.php`, handler `cms_public_search` |
| Search model | `app/services/public_search.php` |
| Browser-side search behavior | `public/assets/gallery.js` |

## Media, Thumbnails and Assets

| Task | Primary files | Notes |
| --- | --- | --- |
| Serve original media | `app/controllers/public_media.php` | Use access checks before streaming. |
| Serve thumbnail | `app/controllers/public_media.php` | Calls thumbnail source helpers. |
| Thumbnail URL/path helpers | `app/services/thumbnail_sources.php` | Central location for generated thumbnail paths. |
| Thumbnail generation | `app/services/thumbnail_generation.php` | GD/Imagick handling. |
| Thumbnail responsive HTML | `app/services/thumbnail_html.php`, `app/services/thumbnail_bundles.php` | Used by public pages. |
| Thumbnail maintenance | `app/services/thumbnail_maintenance.php`, `app/controllers/admin_thumbnails.php` | Admin create/delete/rebuild status. |
| Scheduled site maintenance | `app/services/site_maintenance.php`, `app/controllers/site_maintenance.php`, `scripts/site_maintenance.php` | Cron-safe daily maintenance, request-triggered hidden runner, UTC maintenance window, chained safe web slices, per-image persisted thumbnail checks, metadata refresh and cleanup tasks. |
| Thumbnail size bounds | `app/services/thumbnail_bounds.php` | Global/gallery/image min/max sizes. |
| WebP behavior | `app/services/thumbnail_formats.php` | Format decisions. |
| Gallery covers | `app/services/gallery_covers.php`, `app/controllers/public_media.php` | Cover image serving and metadata. |
| Gallery backgrounds | `app/services/gallery_backgrounds.php`, `app/controllers/theme_assets.php` | Site and gallery background assets. |
| Branding assets | `app/services/gallery_branding.php`, `app/controllers/public_media.php`, `app/controllers/theme_assets.php` | Banner, logo, separator and favicon routes. |

## Admin Authentication and Account

| Task | Files |
| --- | --- |
| Login/logout | `app/controllers/admin_auth.php`, `app/security.php` |
| Durable login | `app/services/auth_persistence.php`, migration `202605310001_admin_persistent_auth_and_google_login.php` |
| Login throttling | `app/services/auth_throttle.php`, migration `202605110001_auth_rate_limits.php` |
| Password reset | `app/controllers/admin_auth.php`, migration `202605060004_password_reset_tokens.php` |
| Admin account page | `app/controllers/admin_auth.php` |
| Google OAuth config and token handling | `app/services/google_auth.php` |
| Google login routes | `app/controllers/admin_auth.php`, handlers `cms_admin_google_start`, `cms_admin_google_callback` |

## Centralized Admin Settings

| Path | Responsibility |
| --- | --- |
| `app/controllers/admin_settings.php` | Admin-authenticated Settings hub controller, per-section validation, delegated saves, flash/redirect handling and safe audit metadata. |
| `app/services/admin_settings_registry.php`, `app/views/admin_settings.php`, `public/assets/gallery-modules/admin-settings-search.js` | Canonical Settings ownership plus complete global-control discovery, Spotlight-style local search, keyboard navigation, section activation, and specialist deep links. |
| `app/services/admin_settings_registry.php` | Stable section taxonomy, setting ownership metadata, current/default/source resolution, central-edit whitelist, canonical normalizers/save delegation and deep-link helpers. |
| `app/views/admin_settings.php` | Accessible Settings overview, tab/section navigation, scoped fieldsets, error summary, current-source labels, redacted summaries and specialized-page links. |
| `app/views/admin_chrome.php` | Persistent Admin navigation entry for Settings. |
| `public/assets/gallery-modules/admin-tabs.js` | Shared Admin tab behavior; Settings opts into href-history mode so query and hash remain synchronized. |
| `public/assets/styles/admin.css` | Responsive Settings tab strip and field/summary layout. |
| `docs/ADMIN_SETTINGS_INVENTORY.md` | Canonical ownership, defaults, fallbacks, migration and sensitivity inventory. |
| `tests/admin_settings_*_test.php` | Registry, normalization, navigation and rendering/accessibility contracts. |

Future global settings should be registered summary-only first. Enable central editing only after the entry can call the same service normalizer and setter as its specialized owner. Never register per-gallery/per-image values, raw secrets, file editors or destructive actions as generic centrally editable keys.

## Admin Dashboard and Maintenance

| Task | Primary files | Notes |
| --- | --- | --- |
| Dashboard route | `app/controllers/admin_dashboard.php`, handler `cms_admin` | Main admin landing route. |
| Dashboard model | `app/services/admin_dashboard.php` | Prepares counters, gallery rows, warnings, and maintenance status without view queries. |
| Admin shell/chrome | `app/views/admin_chrome.php` | Admin navigation, top-level tabs, and reusable nested subtabs. |
| Dashboard view helpers | `app/views/admin_dashboard.php`, `app/views/admin_dashboard_sections.php` | Overview metrics, gallery table rendering, and grouped maintenance subtab sections. |
| Gallery date suggestions entry | `app/controllers/admin_gallery_dates.php`, `app/services/gallery_dates.php` | Global and scoped branch review plus the focused `admin_gallery_date_suggestion` apply endpoint. |
| URL rewrite settings | `app/controllers/admin_dashboard.php`, `app/services/app_settings.php` | Clean URL compatibility controls rendered under Content and display maintenance. |
| Public search settings card | `app/controllers/admin_dashboard.php`, `app/services/public_search.php` | Global public search toggle rendered under Content and display maintenance. |
| EXIF/GPS display defaults | `app/controllers/admin_dashboard.php`, `app/services/admin_dashboard.php`, `app/views/admin_dashboard.php`, `app/views/admin_dashboard_sections.php`, `app/services/exif.php` | Global default-enabled policy, reset-all override action and dashboard maintenance card. |
| Favorite gallery and main-page shortcuts | `app/controllers/admin_theme.php`, `app/services/favorite_galleries.php`, `app/views/layout.php` | Theme-admin selection for top navigation shortcuts. |
| Dev mode | `app/controllers/admin_dashboard.php`, `app/services/app_settings.php` | Viewer diagnostics toggle rendered under System health maintenance. |
| Migrations from admin | `app/controllers/admin_dashboard.php`, `app/migrations.php` | Pending migration action rendered only when needed. |
| Integrity checks | `app/controllers/admin_integrity.php`, `app/integrity.php` | Core-file and deployment health checks. |
| Database inspection and maintenance | `app/services/database_maintenance.php`, `app/controllers/admin_database_maintenance.php`, `app/views/admin_database_maintenance.php`, `public/assets/styles/admin-dashboard.css`, `database/migrations/202607250001_database_maintenance_schema_repair.php` | Explicit full-schema audit, scoped production/test SQL-reference evidence, cached JSON report, high-confidence bounded cleanup with transactional row-identifier audit, conditional schema repair, selected `ANALYZE TABLE`, and separately confirmed selected `OPTIMIZE TABLE`. |

## Gallery Administration

| Task | Primary files | Notes |
| --- | --- | --- |
| Discover filesystem galleries | `app/controllers/admin_galleries_discovery.php`, `app/services/admin_gallery_discovery.php`, `public/assets/gallery-modules/admin-refresh-progress.js` | Runs browser-driven folder discovery in Ajax batches, renders review rows with plain-language photo counts, destination previews, possible-duplicate warnings, and action controls for import, move, or delete. |
| Resolve discovered folders | `app/controllers/admin_galleries_discovery.php`, `app/services/gallery_mutations.php`, `app/services/admin_gallery_discovery.php`, `public/assets/gallery-modules/admin-thumbnail-progress.js` | Imports selected folders in place, moves photo files into a selected existing gallery, or deletes selected unmanaged folders. Suppresses exact, case-folded, realpath, and same-title sibling duplicates and skips thumbnail follow-up jobs when no images were scanned. |
| Create gallery | `app/controllers/admin_galleries_discovery.php` | Includes side-panel handling. |
| Edit gallery | `app/controllers/admin_galleries_edit.php` | Large feature surface, including manual date ranges and the embedded reusable EXIF suggestion component. |
| Gallery form rendering | `app/views/admin_gallery_forms.php`, `app/controllers/admin_gallery_renderers.php` | Shared form fragments, manual date range inputs, per-gallery EXIF suggestion controls and select lists. The suggestion component carries endpoint, gallery id and CSRF data so it works in full-page and side-panel admin contexts. |
| Bulk gallery operations | `app/controllers/admin_galleries_bulk.php` | Bulk delete/rename/move/regenerate paths plus EXIF/GPS force on, force off and inherit-default actions. |
| Reorder hierarchy | `app/controllers/admin_galleries_reorder.php` | Admin and public order. |
| Gallery mutations | `app/services/gallery_mutations.php` | Create/update/delete/move service logic. |
| Gallery lookup | `app/services/gallery_lookup.php` | Read model and tree queries. |
| Gallery paths and slugs | `app/services/gallery_paths.php`, `app/services/public_paths.php` | Clean URLs and filesystem path helpers. |
| Gallery sidecars | `app/services/gallery_sidecars.php` | Metadata sidecar persistence. |
| Gallery display settings | `app/services/gallery_display.php`, `app/services/gallery_grid.php`, `app/services/gallery_description_layout.php`, `app/services/gallery_count_badges.php`, `app/services/gallery_dates.php`, `app/services/exif.php` | Public rendering behavior, including date range display and effective EXIF/GPS display inheritance. |
| EXIF-derived gallery date suggestions | `app/controllers/admin_gallery_dates.php`, `app/services/gallery_dates.php`, `app/views/admin_gallery_forms.php`, `public/assets/gallery-modules/admin-gallery-date-suggestion.js` | Aggregates `images.exif_taken_at` across each gallery branch, supports scoped branch reviews through `gallery_id`, lets admins approve, edit, or ignore suggested ranges, and applies the current gallery suggestion through the shared focused endpoint with AJAX or POST fallback. |
| Duplicate Photo Detector | `app/controllers/admin_duplicate_photos.php`, `app/services/duplicate_photo_detector.php`, `app/services/duplicate_photo_ledger.php`, `app/views/admin_duplicate_photos.php`, `public/assets/gallery-modules/admin-duplicate-photo-detector.js`, `public/assets/styles/admin-duplicate-photo-detector.css`, `app/services/gallery_mutations.php`, `app/controllers/admin_galleries_edit.php`, `public/assets/gallery-modules/admin-side-panel.js`, `database/migrations/202608080001_duplicate_photo_ledger.php`, `tests/duplicate_photo_detector_test.php`, `tests/duplicate_photo_ledger_test.php` | Selected-gallery-branch or explicit all-gallery scan using stored SHA-256 and normalized EXIF metadata, rendered as deterministic left/right pair comparisons. Gallery/photo paths use existing public URL helpers. Per-admin ledger rules suppress one canonical pair or one exact gallery ID, with parent/child galleries independent; **Clear ledger** resets only that administrator's rules. Scan, ledger, clear, and single-click delete actions use the existing side-panel AJAX pipeline without reload/navigation; POST remains fallback-only. |

## Image Administration

| Task | Files |
| --- | --- |
| Upload images and upload settings | `app/controllers/admin_uploads.php`, `app/views/admin_upload_settings.php`, `app/services/uploads.php`, `app/services/browser_uploads.php`, `public/assets/gallery-modules/admin-side-panel.js`, `public/assets/gallery-modules/admin-browser-upload.js`, `public/assets/gallery-modules/browser-image-worker.js` |
| Thumbnail maintenance and browser rebuild | `app/controllers/admin_thumbnails.php`, `app/services/thumbnail_generation.php`, `app/services/thumbnail_sources.php`, `app/services/browser_thumbnail_rebuild.php`, `public/assets/gallery-modules/admin-thumbnail-progress.js`, `public/assets/gallery-modules/admin-browser-thumbnail-rebuild.js`, `public/assets/gallery-modules/browser-image-worker.js` |
| Scan images from filesystem | `app/controllers/admin_galleries_edit.php`, `app/services/image_scanning.php` |
| Bulk image actions | `app/controllers/admin_images_bulk.php` |
| Reorder images | `app/controllers/admin_images_reorder.php` |
| Edit image metadata | `app/controllers/admin_public_inline.php`, handler `cms_admin_edit_image` |
| Copy/move/share selected public-view images | `app/controllers/picture_manager.php`, `app/controllers/downloads.php`, `app/services/picture_manager.php`, `app/services/downloads.php`, `public/assets/gallery-modules/picture-manager.js` |
| DNG support | `app/services/dng_derivatives.php`, `app/services/uploads.php` |
| EXIF extraction and GPS display policy | `app/services/exif.php`, `app/controllers/exif.php`, `app/controllers/admin_dashboard.php`, `app/controllers/admin_galleries_edit.php` | Default-enabled public GPS display with nullable per-gallery inherit, force on and force off overrides. |
| EXIF capture-date reuse | `app/services/gallery_dates.php`, `app/controllers/admin_gallery_dates.php` | Uses scanned original image EXIF dates to suggest gallery date ranges. |

## Tags

Public tag-page grid and card overrides are implemented by app/controllers/public_tags.php with app/services/pagination.php and app/services/gallery_description_layout.php.

| Task | Files |
| --- | --- |
| Tag creation and syncing | `app/services/tag_metadata.php`, `app/services/tags.php` |
| Admin tag management | `app/controllers/admin_tags.php` |
| Public tag pages | `app/controllers/public_tags.php` |
| Tag suggestions | `app/services/tag_metadata.php`, `app/controllers/admin_gallery_renderers.php` |
| Gallery/image tag relations | Tables `gallery_tags`, `image_tags` |
| Hero tag usage sorting | `app/services/tag_metadata.php`, `app/controllers/public_gallery.php` |
| Hero tag disclosure and row-based scrolling | `public/assets/gallery-modules/hero-tags.js`, `public/assets/styles/public-shared.css`, `public/assets/styles/admin-layout.css`, `public/assets/styles/admin.css` |

## Theme, Layout and Branding

The Gallery tags Theme subsection is rendered by app/controllers/admin_theme.php and persisted through app_settings; public resolution is shared with the public tag controller through the pagination and description-layout services.

| Task | Files |
| --- | --- |
| Admin theme page | `app/controllers/admin_theme.php` |
| Public thumbnail renderer control | `app/controllers/admin_theme.php`, `app/services/public_thumbnail_rendering.php`, `app/lang/en.php`, `app/lang/cs.php`, `app/lang/en.json`, `app/lang/cs.json` |
| Theme settings and CSS variables | `app/services/theme.php` |
| Gallery hero tag Theme controls | `app/controllers/admin_theme.php`, `app/services/theme.php`, `public/assets/gallery-modules/theme-form.js`, `public/assets/styles/admin-theme-editor.css` |
| Dynamic theme CSS | `app/controllers/theme_assets.php`, handler `cms_theme_css` |
| Custom CSS presets | `app/services/custom_css.php`, `custom_css/*.css` |
| Favicon | `app/services/favicon.php`, `app/controllers/theme_assets.php` |
| Gallery branding | `app/services/gallery_branding.php`, `app/controllers/public_gallery.php`, `app/controllers/public_media.php` |
| Lightbox browsing-mode resolution | `app/services/gallery_lightbox_mode.php`, `app/controllers/admin_theme.php`, `app/controllers/admin_galleries_edit.php`, `app/controllers/public_gallery.php` |
| Picture-strip and 3D-carousel lightbox UI | `public/assets/gallery-modules/lightbox.js`, `public/assets/styles/lightbox.css`, `public/assets/styles/mobile-gallery.css` |
| Public/admin styling | `public/assets/styles.css`, `public/assets/custom.css` |
| Browser UI behavior | `public/assets/gallery.js`, `public/assets/public-gallery.js`, `public/assets/gallery-modules/hero-tags.js`, `public/assets/gallery-modules/admin-gallery-date-suggestion.js`, `public/assets/gallery-modules/admin-refresh-progress.js` |

## Access, Sharing and Downloads

| Task | Files |
| --- | --- |
| Gallery password access | `app/services/gallery_access.php`, `app/controllers/public_gallery.php` |
| Share links | `app/services/gallery_access.php`, `app/controllers/public_gallery.php` |
| Download signatures | `app/services/download_signatures.php` |
| ZIP generation | `app/services/downloads.php`, `app/controllers/downloads.php`, including gallery, all-gallery and selected-photo fallback archives |
| Archive cache table | `zip_archives` |

## AI and Metadata Generation

| Task | Files |
| --- | --- |
| Local/worker image metadata queue | `app/services/ai_image_analysis.php`, `app/controllers/upload_automation.php` |
| AI metadata display panel | `app/controllers/admin_public_inline.php` |
| OpenAI account settings | `app/services/openai_text_assist.php`, `app/controllers/admin_auth.php` |
| OpenAI generation endpoint | `app/controllers/admin_openai_text_assist.php` |
| Bulk image descriptions | `app/controllers/admin_openai_text_assist.php`, `app/services/openai_text_assist.php` |
| User settings table | `user_openai_text_settings` |
| AI metadata tables | `image_ai_metadata`, `image_ai_analysis_jobs` |

## SimBrief, Flight Maps and Navigation Data

| Task | Files |
| --- | --- |
| SimBrief OFP fetch and parsing | `app/services/simbrief_descriptions.php` |
| SimBrief admin endpoint | `app/controllers/admin_simbrief.php` |
| SimBrief UI | `app/views/simbrief_descriptions.php` |
| Flight map persistence | `app/services/flight_maps.php`, table `gallery_flight_maps` |
| EXIF and map data endpoint | `app/controllers/exif.php`, `app/services/exif.php` |
| Navigation lookup | `app/services/navigation_data.php`, `app/controllers/navigation_data.php` |
| Navigation admin UI | `app/views/navigation_data.php` |
| Bundled navdata | `data/navdata/local_nav_points.csv` |
| Navigation provider accounts | `navigation_data_accounts` |
| Navigation cache | `navigation_data_cache` |

## Gallery Migration and API Export/Import

| Task | Files |
| --- | --- |
| Admin migration UI | `app/controllers/gallery_migration.php`, `app/views/admin_gallery_migration.php` |
| Migration service model | `app/services/gallery_migration.php` |
| Manifest endpoint | Handler `cms_gallery_migration_manifest` |
| Asset endpoint | Handler `cms_gallery_migration_asset` |
| Receive manifest | Handler `cms_gallery_migration_receive_manifest` |
| Receive asset | Handler `cms_gallery_migration_receive_asset` |
| Receive completion | Handler `cms_gallery_migration_receive_complete` |
| Receive status | Handler `cms_gallery_migration_receive_status` |

## Upload Automation

| Task | Files |
| --- | --- |
| Admin token management | `app/controllers/upload_automation.php`, handler `cms_admin_upload_automation_token` |
| Upload API | `app/controllers/upload_automation.php`, handler `cms_upload_automation_upload` |
| Token model | `app/services/upload_automation.php`, table `gallery_upload_tokens` |
| Windows watcher | `winapp/gallery_watch_upload.pyw` |
| Windows watcher installer | `winapp/install.bat`, `winapp/run_gallery_watcher.bat` |

## Telemetry and Observability

| Task | Files |
| --- | --- |
| Public event ingestion | `app/controllers/telemetry.php`, handler `cms_telemetry_ingest` |
| Admin telemetry dashboard | `app/controllers/admin_telemetry.php` |
| Telemetry recording | `app/services/telemetry.php` |
| Privacy bucketing/hashing | `app/services/telemetry_privacy.php` |
| Telemetry settings | `app/services/telemetry_settings.php` |
| Rollups and purge | `app/services/telemetry_rollup.php` |
| DB query metrics | `app/services/database_observer.php` |
| Browser scripts | `public/assets/telemetry.js`, `public/assets/usage.js` |
| Tables | `telemetry_settings`, `telemetry_sessions`, `telemetry_events`, `telemetry_hourly_metrics`, `telemetry_daily_metrics`, `telemetry_db_query_metrics`, `telemetry_job_runs` |

## Logs

| Task | Files |
| --- | --- |
| Write structured admin events | `app/services/logs.php` |
| Display logs | `app/controllers/admin_logs.php` |
| Update log status | `app/controllers/admin_logs.php`, handler `cms_admin_log_update` |
| Export logs | `app/controllers/admin_logs.php`, handlers `cms_admin_log_export`, `cms_admin_logs_export_zip` |
| Archive and retain logs | `app/services/admin_log_archives.php`, `app/controllers/admin_logs.php`, `app/controllers/site_maintenance.php` |
| Archive protection | `data/admin-log-archives/.htaccess` |
| Table | `admin_logs` |

## Updates and GitHub Integration

| Task | Files | Notes |
| --- | --- | --- |
| GitHub API helper/cache | `app/services/github.php` | Shared GitHub metadata/content transport. |
| Update status/service router | `app/services/updates.php`, `app/services/updates_status.php`, `app/services/updates_remote.php` | Bounded release discovery and updater service composition. |
| Resumable update state machine | `app/services/updates_jobs.php` | Durable jobs, bounded download/archive/extract/verify/stage/backup checkpoints, Range/If-Range resume, worker locks, pre-activation cancellation, changed-file activation, migration continuation, rollback and cleanup. |
| Update install/background entry points | `app/services/updates_install.php`, `scripts/application_update.php` | Legacy API starters plus request-triggered/cron continuation; discovery and package work are split across invocations. |
| Admin update UI | `app/controllers/updates.php`, `public/assets/gallery-modules/admin-update-jobs.js` | Authenticated CSRF-protected start/continue/retry/cancel/rollback controls with delegated in-panel progress refresh and non-JavaScript POST fallback. |
| Patch notes | `PATCH_NOTES.md` | Release-note source, not modified as part of updater hardening. |
| Release metadata | `release-metadata.json` | Version/channel metadata consumed during discovery. |
| Updater safety regression tests | `tests/updater_safety_model_test.php`, `tests/updater_resumable_state_machine_test.php` | State, interruption, locking, package, rollback, migration, synchronized Status/Advanced progress UI, deterministic non-retryable manifest failures, and redaction contracts. |

## Language and Translations

| Task | Files |
| --- | --- |
| Language bootstrap, discovery, fallback, diagnostics | `app/services/translations.php` |
| Canonical English JSON pack | `app/lang/en.json` |
| Complete maintained JSON packs | `app/lang/en.json`, `app/lang/cs.json`, `app/lang/de.json`, `app/lang/sv.json` |
| Dormant future-language skeletons | `app/lang/no.json`, `app/lang/is.json`, `app/lang/da.json`, `app/lang/fr.json`, `app/lang/it.json`, `app/lang/es.json` |
| PHP compatibility fallbacks | `app/lang/en.php`, `app/lang/cs.php`, `app/lang/de.php`, `app/lang/sv.php` |
| Admin/public default language selectors and pack editor | `app/controllers/admin_theme.php` |
| Reusable viewer-language settings panel | `app/views/admin_language_settings.php`, reused by `app/controllers/admin_theme_language.php` and `app/views/admin_settings.php` |
| Public language and viewer-selector centralized settings/search | `app/services/admin_settings_registry.php`, `app/controllers/admin_settings.php` |
| Per-viewer public flag/native-name selector and override policy | `app/services/translations.php`, `app/views/layout.php`, `public/assets/styles/public.css`, `public/assets/flags/*.svg` |
| Bundled flag artwork license | `public/assets/flags/LICENSE.flag-icons.md` |
| Browser translation payload | `app/views/layout.php`, `app/controllers/theme_assets.php` |
| Catalog and viewer-preference regression coverage | `tests/translation_catalog_consistency_test.php`, `tests/public_language_preference_test.php` |

English is the canonical default and fallback. English, Czech, German, and Swedish are the only selectable languages and their JSON catalogs remain key-for-key complete. Public visitors can override the site default from the shared header and reset to the site default; the preference is local to their browser. Dormant future-language skeletons may contain validated subsets of English keys, but file discovery alone does not make them selectable.

| Multilingual content model | `app/services/content_localization.php`, `database/migrations/202608150001_multilingual_content.php` |
| Multilingual Admin editor | `app/views/admin_gallery_forms.php`, `app/controllers/admin_galleries_edit_actions.php`, `app/controllers/admin_public_inline.php`, `public/assets/styles/side-panel.css` |
| Localized public rendering/search | `app/controllers/public_gallery_page.php`, `app/controllers/gallery_lightbox.php`, `app/services/public_search.php`, `app/views/seo.php` |
| Optional translation drafts | `app/services/openai_text_assist.php`, `public/assets/gallery-modules/admin-openai-text-assist.js` |
| Multilingual tests | `tests/content_localization_model_test.php`, `tests/admin_content_localization_test.php`, `tests/public_content_localization_test.php` |

## Database and Migrations

| Task | Files |
| --- | --- |
| DB connection | `app/database.php` |
| Migration runner | `app/migrations.php` |
| Schema helpers | `app/services/database_helpers.php` |
| Three-state schema inspection | `app/services/schema_inspection.php`, `tests/schema_inspection_model_test.php` for structured table/column/index and column-definition results, production metadata-query definitions, request cache/reset, safe diagnostics, aggregate feature state, registration contract, and isolated available/missing/unknown coverage. `app/migrations.php` is the cache-invalidation boundary after schema DDL and repair callbacks. |
| Security/public policy conversion | `app/services/gallery_access.php`, `app/bootstrap/dispatch.php`, `app/controllers/http_helpers.php`, `app/services/public_paths.php`, `app/services/favorite_galleries.php`, `app/services/gallery_sidecars.php`, `app/controllers/public_gallery_controls.php` for gallery access, visibility vocabulary, NSFW, share-token, sitemap/listing/sidecar, media/metadata, and protected-download fail-closed policy. `tests/gallery_access_schema_policy_test.php`, `tests/support/security_schema_policy_dispatch_fixture.php`, `tests/nsfw_schema_policy_test.php`, and `tests/service_unavailable_response_test.php` cover current/legacy/partial/unknown states, handler preflight, response formats, and redaction. |
| Authentication schema conversion | `app/services/auth_persistence.php`, `app/services/google_auth.php`, `app/controllers/admin_auth.php`, `app/security.php` for persistent login, `users.email`, password reset, external identity links, account editing, and safe session continuity. `tests/auth_schema_policy_test.php` covers available/missing/unknown policy, query caching, disabled persistent login, and bounded logs. |
| Security System Health | `app/services/admin_dashboard.php`, `app/views/admin_dashboard.php`, `app/views/admin_dashboard_sections.php`, `app/controllers/admin_diagnostics.php` for `gallery_access`, `gallery_visibility`, `gallery_share_token`, `nsfw_guard`, `auth_persistent_login`, `auth_password_reset`, and `auth_external_identity`. `tests/security_schema_system_health_test.php` and `tests/admin_nsfw_system_health_test.php` protect the four-state bounded model and Runtime Diagnostics integration. |
| Mutation schema policy | `app/services/mutation_schema_policy.php` defines the Phase 10 capability registry, fail-closed assertions, optional-dependency compatibility checks, narrow credential-revocation capabilities, bounded `database.mutation_schema_refused` diagnostics, and the shared `MutationSchemaUnavailableException`. `tests/mutation_schema_policy_test.php` protects state semantics, request-cache budgets, preflight ordering, health registration, updater package requirements, and removal of legacy boolean probes from converted mutation paths. |
| Gallery destructive mutations | `app/services/gallery_mutations.php`, `app/services/picture_manager.php` preflight gallery/image ownership, path, hash, ordering, tag-propagation, and dependency cleanup before deletion, move, or copy. Confirmed absent optional dependency tables/columns may be skipped; unknown dependencies stop the mutation. |
| Duplicate Photo Detector ledger schema | `app/services/duplicate_photo_ledger.php`, `app/controllers/admin_duplicate_photos.php` distinguish missing ledger migration from unknown inspection state and refuse per-admin ignore/reset writes on unknown schema. |
| Upload ingestion schema | `app/services/uploads.php`, `app/services/browser_uploads.php` require current gallery/image registration schema plus conclusive thumbnail write-shape inspection before source files are moved or prepared ZIP entries are written into a gallery. |
| Upload automation schema | `app/services/upload_automation.php`, `app/controllers/upload_automation.php` require the complete `gallery_upload_tokens` shape for issuance/authentication/usage and a smaller verified revocation shape for disabling an existing API key. |
| Gallery migration schema | `app/services/gallery_migration.php` preflights target gallery/image schema, optional imported metadata columns, thumbnail write compatibility, asset installation, and job completion so an inspection outage pauses the resumable job before target mutation. |
| Mobile WebDAV schema | `app/services/mobile_webdav.php`, `app/controllers/mobile_webdav.php` separate full credential/authentication readiness from narrow credential deletion and verify upload-ingestion schema before PUT storage crosses into a gallery. |
| Thumbnail mutation schema | `app/services/thumbnail_metadata.php`, `app/services/thumbnail_generation.php`, `app/services/thumbnail_maintenance.php` preserve confirmed table-absent file-only compatibility while refusing unknown metadata/write-shape state before generation, variant deletion, repair, or bulk derivative deletion. |
| Database maintenance mutation schema | `app/services/database_maintenance.php` requires conclusive `schema_migrations` inspection before cleanup/repair. Confirmed absence may enter the migration bootstrap path; unknown state refuses mutation. |
| Update activation schema | `app/services/updates_install.php`, `app/services/updates_filesystem.php` allow staging/download first, then require conclusive schema inspection immediately before active-file copy. Update snapshots must contain `schema_inspection.php` and `mutation_schema_policy.php`. |
| Mutation System Health | `app/services/admin_dashboard.php`, `app/views/admin_dashboard.php`, `app/views/admin_dashboard_sections.php`, `app/controllers/admin_diagnostics.php` register and render the ten Phase 10 mutation capabilities through the same bounded Admin-health model used by Phase 9. Missing and unknown states raise the Maintenance/System Health Action signal. |
| Presentation/reporting schema policy | `app/services/presentation_schema_policy.php` defines the fifteen Phase 11 capability resolvers, safe read degradation, write/known assertions, bounded `database.presentation_schema_degraded` logging, affected-object normalization, and the `PresentationSchemaUnavailableException` boundary. |
| GPS and flight-map presentation | `app/services/exif.php`, `app/services/flight_maps.php`, `app/services/gallery_metadata_organizer.php`, `app/controllers/admin_dashboard.php`, `app/controllers/admin_galleries_edit_actions.php` use structured GPS/EXIF, narrow capture-date organizer readiness, nullable override, route-map, and local navdata capabilities. |
| Voting and Picture Game | `app/services/picture_game.php`, `app/services/gallery_sidecars.php`, `app/controllers/votes.php`, `app/controllers/picture_game.php`, `app/controllers/admin_galleries_bulk.php` separate optional rendering from writes that record votes, displayed pairs, gallery feature state, and voting-enabled gallery creation/import. |
| Lightbox presentation override | `app/services/gallery_lightbox_mode.php`, `app/services/gallery_sidecars.php`, `app/services/gallery_metadata_organizer.php`, `app/controllers/admin_galleries_edit_actions.php` verify the override column and supported stored vocabulary before explicit or inherited persistence while safe reads may fall back to the global/default mode. |
| OpenAI and local AI presentation schema | `app/services/openai_text_assist.php`, `app/services/ai_image_analysis.php`, `app/controllers/upload_automation.php` distinguish core OpenAI settings, optional image input, and AI metadata/queue storage; worker schema failures use bounded responses instead of raw exceptions. |
| SimBrief and navigation integration | `app/services/simbrief_descriptions.php`, `app/services/navigation_data.php` keep remote/session behavior independent from optional persistent route/account/cache storage while refusing unknown account persistence and using a narrow verified disconnect capability. |
| Telemetry presentation/reporting | `app/services/telemetry.php`, `app/services/telemetry_settings.php`, `app/services/telemetry_rollup.php`, `app/controllers/admin_telemetry.php` distinguish safe read omission, verified settings writes, complete report shape, and maintenance refusal on unknown schema. |
| Complete Admin Gallery Report | `app/services/admin_gallery_report.php` uses structured named-object inspection for known optional sections, retains a justified dynamic `information_schema.TABLES` inventory query, and suppresses raw database exception text. |
| Presentation System Health | `app/services/admin_dashboard.php`, `app/views/admin_dashboard_sections.php`, `app/controllers/admin_diagnostics.php` expose the same fifteen lazy Phase 11 capability resolvers as `available`, `missing`, `unknown`, or feature-flag `disabled`. |
| Migration files | `database/migrations/*.php` |
| Schema documentation | `DATABASE.md` |

`app/services/database_helpers.php` continues to expose compatibility boolean
table and column checks for narrow legacy/read callers, but those helpers are not
policy evidence when confirmed absence must be distinguished from inspection
failure. `app/services/schema_inspection.php` provides the audited `available`,
`missing`, and `unknown` API, request-local cache, safe diagnostics, feature
aggregation, trusted column-definition token inspection, and column-nullability
inspection. `app/migrations.php` invalidates the request cache after
schema-changing statements and successful repair callbacks so a single migration
process can inspect, modify, and re-inspect safely.

Phase 10 mutation callers use `app/services/mutation_schema_policy.php` instead
of the legacy boolean schema helpers. Every converted destructive or ingestion
workflow has a named aggregate status and performs its conclusive preflight before
the first irreversible target mutation. Confirmed absence may select only a
documented compatibility or migration-bootstrap path. Unknown metadata state is
never treated as absence. `admin_mutation_schema_health_statuses()` exposes those
same ten capabilities to System Health and Runtime Diagnostics.

For gallery access, do not treat feature-level `missing` as legacy by itself.
`gallery_access_schema_is_confirmed_legacy()` requires all five access columns to
be confirmed absent; partial migration and unknown state fail closed. For
authentication, distinguish optional durable conveniences from the primary PHP
session: confirmed missing remember-token storage preserves session login, while
unknown storage never authorizes token issue/use. Share-token revocation is the
security-tightening exception and may clear the verified validating hash even
when the optional encrypted display-token column is unavailable.

Phase 11 optional presentation/reporting callers use
`app/services/presentation_schema_policy.php`. The policy registry owns named
status aggregates for GPS/EXIF maps, nullable GPS overrides, flight maps and local
navdata, voting, Picture Game, lightbox overrides, OpenAI text/image-input
settings, local AI image-analysis storage, SimBrief route-map persistence,
navigation cache/account storage, telemetry reporting, and the Complete Admin
Gallery Report. Read-only presentation wrappers may omit a feature when doing so is
safe; state-changing callers use explicit write/known assertions instead of the
boolean wrapper.

`presentation_schema_health_definitions()` is intentionally lazy. It stores
feature resolvers plus feature-flag identifiers.
`admin_presentation_schema_health_statuses()` resolves only enabled capabilities,
so a disabled optional feature reports `disabled` without metadata queries. The
resolved objects reuse the same request cache as all other schema policy. The
Complete Admin Gallery Report is the one intentional direct-schema-query exception:
`admin_gallery_report_exact_database_table_counts()` queries
`information_schema.TABLES` because it must enumerate every current base table
dynamically, not test one known table identity.

## Deployment and Tooling

| Task | Files |
| --- | --- |
| Linux deploy | `deploy.sh`, `scripts/deploy.sh` |
| Windows deploy | `deploy.bat`, `scripts/deploy.ps1` |
| Manual migration CLI | `scripts/migrate.php` |
| Admin creation | `scripts/create_admin.php` |
| Manifest generation | `scripts/generate_manifest.php` |
| Telemetry maintenance CLI | `scripts/telemetry_maintenance.php` |
| Site maintenance CLI | `scripts/site_maintenance.php` |

## Test Files

| Test | Purpose |
| --- | --- |
| `tests/presentation_schema_policy_test.php` | Final Phase 11 optional presentation/reporting state policy, lazy health registry, query-budget, redaction, write-boundary, source-audit, and diagnostics contracts. |
| `tests/admin_log_severity_filter_test.php` | Log filtering behavior. |
| `tests/admin_log_scaling_test.php` | Indexed grouped browsing, bounded exports, and retention contracts. |
| `tests/admin_log_archive_maintenance_test.php` | Protected day archives, resumable archive state, row-count safety, and archive cleanup behavior. |
| `tests/gallery_branding_model_test.php` | Gallery branding model. |
| `tests/gallery_dates_model_test.php` | Gallery date range normalization and renderer behavior. |
| `tests/duplicate_photo_detector_test.php` | Duplicate checksum/EXIF matching, pair expansion and ledger filtering, selected-gallery branch/global scope, deterministic ordering, linked-context rendering, delete/ledger scope validation, detector-job pruning, and AJAX side-panel integration contracts. |
| `tests/duplicate_photo_ledger_test.php` | Canonical pair keys, pair/gallery suppression semantics, parent/child gallery independence, migration foreign keys, parameterized ledger persistence, and protection against image/gallery mutations. |
| `tests/gallery_migration_model_test.php` | Migration model behavior. |
| `tests/gallery_visibility_model_test.php` | Gallery visibility compatibility. |
| `tests/openai_text_assist_model_test.php` | OpenAI settings/model behavior. |
| `tests/simbrief_description_model_test.php` | SimBrief description model behavior. |
| `tests/upload_automation_sim_camera_metadata_test.php` | Upload automation camera metadata behavior. |
| `tests/url_rewrite_settings_test.php` | URL rewrite settings behavior. |
| `tests/migration_consistency_test.php` | Migration definitions, preflight validation, and obsolete-version compatibility. |
| `tests/migration_legacy_runner_compatibility_test.php` | Compatibility with the former SQL-only migration runner. |
| `tests/updater_safety_model_test.php` | Required update files, staged-update assumptions, and cleanup safety. |
| `tests/public_thumbnail_rendering_model_test.php` | Renderer normalization, persistence dispatch, and responsive/progressive loading policies. |
| `tests/public_thumbnail_markup_test.php` | Responsive/progressive server markup, bounds, missing variants, warm-up/media fallback, intrinsic dimensions, and NSFW gate ordering. |
| `tests/progressive_thumbnail_renderer_test.mjs` | Browser-independent candidate parsing/selection, DPR calculation, queue deduplication, priority, and concurrency bounds. |

## Common Change Recipes

### Add an admin setting

1. Identify the existing canonical service normalizer/setter and whether the setting is global, per-gallery, sensitive, file-backed or destructive.
2. Add or update the specialized page first.
3. Register the global setting in `app/services/admin_settings_registry.php` as summary-only, with a stable ID and specialized route.
4. Enable `central_editable` only when central save can delegate to the exact same service boundary and preserve all feature/schema guards and side effects.
5. Add English, Czech, German, and Swedish PHP/JSON translation keys, navigation/rendering tests, and update `docs/ADMIN_SETTINGS_INVENTORY.md`.


### Add a database column

1. Create a new migration file with the next timestamp prefix.
2. Use `ALTER TABLE ... ADD COLUMN ...` and indexes where needed.
3. Add compatibility checks through `db_column_exists()` only if the code may run before migrations complete.
4. Do not edit historical migrations.
5. Update `DATABASE.md`.

### Add a public JSON endpoint

1. Add route mapping in `cms_run()`.
2. Add controller handler under `app/controllers/`.
3. Put query or mutation logic in `app/services/`.
4. Return a stable JSON shape.
5. Check visibility and access before exposing records.

### Add image metadata

1. Add DB migration if persistent fields are required.
2. Add scan or generation logic in a service.
3. Add admin rendering in image edit or relevant panel.
4. Add search integration only through `public_search.php`.
5. Avoid modifying original source files unless explicitly required.

### Add theme UI

1. Update `app/controllers/admin_theme.php`.
2. Add service helpers in `app/services/theme.php`.
3. Apply CSS through dynamic variables or `public/assets/styles.css`.
4. Use asset routes for uploaded theme images.
5. Update `ARCHITECTURE.md` if a new theme concept is introduced.

### Smart Galleries

- `database/migrations/202608140001_smart_galleries.php`: definition storage, ratings, and focused indexes.
- `app/services/smart_galleries.php`: schema capability, rule validation/compiler, summaries, CRUD, access intersection, pagination queries, search conversion, and rating writer.
- `app/controllers/smart_galleries.php`: Admin management/preview and published route.
- `public/assets/gallery-modules/admin-smart-galleries.js`: nested visual rule editor.
- `tests/smart_gallery_rules_test.php`: validation, nesting, version, injection, and compiler contracts.
