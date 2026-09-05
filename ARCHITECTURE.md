# PHP Gallery Architecture

PHP Gallery is a plain-PHP, filesystem-backed photo gallery CMS designed for normal shared hosting. It avoids frameworks and build steps while still keeping the application organized into controllers, services, views, migrations, and public assets.

This document is intended to help future maintainers and AI coding agents understand the application without rediscovering the same structure from source every time.

## Current Application Version

The runtime version is defined in `app/bootstrap.php`:

```php
const CMS_VERSION = '0.96';
```

Update-related code uses:

```php
const CMS_GITHUB_REPOSITORY = 'klusik/PHP_gallery';
const CMS_UPDATE_BRANCHES = ['main', 'master'];
```

## Core Design Principles

1. **Plain PHP first**
   The application deliberately uses direct PHP modules instead of Laravel, Symfony, or another framework. This keeps deployment simple and makes the app viable on cheap hosting.

2. **Filesystem-backed gallery content**
   Gallery folders and image files live on disk. The database stores metadata, visibility, tags, generated slugs, access rules, voting state, AI metadata, telemetry, and operational state.

3. **Controllers handle HTTP, services handle behavior**
   Controller files under `app/controllers/` read requests, validate permissions, process forms or JSON actions, and render or redirect. Service files under `app/services/` contain reusable business logic.

4. **Database migrations are append-only and prevalidated**
   Schema changes are stored as sequential PHP migration files in `database/migrations/`. Migrations are prevalidated as a complete pending set, applied in filename order, and recorded in `schema_migrations` only after SQL statements and optional repair callbacks succeed.

5. **Shared hosting compatibility**
   Runtime dependencies are intentionally small. The app expects PHP, PDO MySQL, Apache-style rewrite support when available, and writable directories for galleries, cache, uploads, thumbnails, and generated assets.

6. **Defensive updates**
   Release archives are checked for required runtime files, staged and size-verified before activation, and cleaned up only after replacement succeeds. Cleanup is limited to known nested project artifacts so valid application modules are preserved.

7. **Feature isolation**
   Recent features are usually introduced as focused controller and service files instead of expanding old monolithic files.

## Canonical Admin Mutation Completion Pipeline

Persistent mutations launched from the Admin right-side panel use one response and completion architecture. Server mutation logic remains authoritative for authentication, authorization, CSRF, validation, schema/path safety, and persistence. Successful AJAX mutations describe completion through helpers in `app/helpers_mutation.php` rather than through workflow-specific redirect conventions.

The canonical success envelope contains:

- `ok` and `message` for the operation result;
- a typed `mutation` descriptor with `type`, `entity`, `action`, and stable `entity_ids`;
- optional `panel` metadata naming the mounted workflow and its authoritative refresh URL;
- explicit affected public `contexts`, identified by stable gallery/tag identity, each with a server render URL, render mode, and an observable `postcondition` where meaningful;
- `fallback` metadata, normally a redirect URL, used only by direct-page/non-JavaScript behavior.

Controllers may append workflow-specific fields for progress displays or editor targeting, but browser completion semantics must not be reconstructed from those fields. Batch clients such as classic upload, browser-prepared upload, and Metadata Organizer must preserve the canonical envelope and aggregate stable affected IDs without discarding contexts or postconditions. Expected AJAX failures use the corresponding bounded error envelope and must not fall through into HTML redirects.

Browser-assisted upload treats the checked browser-processing control as an explicit execution choice whenever media files are selected. Client capability/preparation failures stop before persistence instead of silently switching thumbnail generation to PHP. The literal boolean `fallback === true` is therefore reserved for an empty create-gallery submission where there are no files to prepare; successful canonical mutation envelopes still carry `fallback` as an object containing direct-page metadata. Browser batches that request thumbnails also declare that prepared thumbnails are required, and PHP validates the complete configured size/format matrix in the temporary ZIP before storing originals. This prevents later background warmup from becoming an accidental server-side substitute for a browser-selected upload path. Once browser upload has started server-side persistence, a successful canonical fallback object must never be interpreted as permission to replay the classic create/upload workflow, because that would create a second independent gallery and upload the selected files twice.

`public/assets/gallery-modules/admin-mutation-completion.js` owns completion after persistence. It matches affected contexts by stable identity, selects the authoritative server-render source independently from the visible browser URL, fetches with `cache: 'no-store'` and cache busting, verifies the declared postcondition before replacing public markup, performs the single bounded retry policy for stale reads, and suppresses aborted or out-of-order panel/public responses by operation generation. Server-rendered HTML remains the rendering authority. A synchronization failure therefore reports that the server mutation succeeded but the visible view could not be verified; it does not undo or misreport persistence.

`public/assets/gallery-modules/admin-side-panel.js` owns drawer lifecycle, dynamic form interception, workflow progress, and panel refresh callbacks. It must not hard reload, rewrite browser history, or navigate on an enhanced success path. Direct-page redirects remain independent fallback behavior. Dynamic panel forms use delegated or lifecycle-safe handlers because panel fragments may be replaced with `innerHTML`. Common source-contract regressions are checked by `php scripts/check_admin_mutation_contracts.php`.

Public fragment replacement may also re-run `setupGalleryLightbox()` on parent gallery-list views that currently contain no lightbox-capable photo cards. Such a setup instance is still registered for teardown even when it returns early. Every state value referenced by its cleanup callback must therefore be initialized before cleanup registration and before the no-overlay/no-cards early return. This lifecycle invariant prevents successful create/delete mutations from being followed by a JavaScript temporal-dead-zone exception during the next fragment refresh.

## Runtime Entry Points

### Public request entry

Root-level `index.php` delegates to `public/index.php`, and `public/index.php` loads the application bootstrap.

Typical request flow:

```text
browser request
  -> index.php or public/index.php
  -> app/early_runtime.php
  -> app/bootstrap.php
  -> cms_run()
  -> cms_route_from_request()
  -> route handler from cms_run() route table
  -> controller function
  -> service functions
  -> view helpers or JSON/file response
```

`app/early_runtime.php` is intentionally dependency-free and executes before the normal bootstrap. It installs bounded uncaught/fatal error semantics and checks the updater activation marker. While active files are being published, ordinary new requests fail closed with a private, non-cacheable `503`; authenticated updater recovery/status requests remain available. Once PHP can no longer change committed headers or streamed bytes, the handler does not append an HTML error body. Production must disable `display_errors`, because host-level warning/fatal display can occur before application error formatting.

### Installer and recovery entry points

Root-level operational files:

| File | Purpose |
| --- | --- |
| `install.php` | Browser installer before `config.php` exists. |
| `setup-gallery.php` | Setup helper for initial deployment. |
| `reset.php` | Emergency recovery endpoint. |
| `config.example.php` | Example config used by tooling and first setup. |
| `config.php` | Local generated config, not expected in deploy ZIPs unless intentionally bundled. |

## Bootstrap Responsibilities

`app/bootstrap.php` is the thin runtime coordinator. Focused modules under `app/bootstrap/` own configuration loading, request preparation, session startup, routing, maintenance scheduling, and dispatch while preserving the original entrypoint contract. The coordinator does the following:

1. Defines application constants.
2. Requires core files in a fixed order.
3. Locates and loads `config.php`, falling back to `config.example.php` for tooling.
4. Starts the admin session with durable cookie settings.
5. Resolves query-string or pretty URL routing.
6. Boots translations for the current request.
7. Sends security headers.
8. Runs lightweight automatic update checks when enabled.
9. Dispatches the request to a controller function from the route table.

Loaded core files:

```text
app/helpers.php
app/database.php
app/security.php
app/migrations.php
app/services.php
app/views.php
app/integrity.php
app/controllers.php
```


## Localization Model

### Multilingual gallery and photo content

Interface catalogs and user-authored content localization are separate systems. `app/services/translations.php` remains the authority for maintained languages (`en`, `cs`, `de`, `sv`) and viewer preference. `app/services/content_localization.php` uses only that set for optional gallery/image title and description overlays.

The base `galleries.title`, `galleries.description`, `images.title`, and `images.description` fields remain source content for compatibility. Nullable `content_language` columns classify that source without assuming English. `gallery_translations` and `image_translations` store one optional row per owner/language. Resolution is field-independent, so a translated title can use a source-description fallback.

Public controllers localize only after access, visibility, NSFW, and pagination policy selects safe rows. Batch loading prevents per-card queries. Translations never affect slugs, paths, ordering, filenames, access policy, or media authorization. Admin forms keep source fields visible and put other maintained languages behind a disclosure. Configured OpenAI assistance inserts review-only drafts and never auto-saves.

`app/services/translations.php` owns language normalization, pack discovery, request bootstrap, Admin/public language persistence, JSON editing support, key fallback, interpolation, and missing-key diagnostics.

English (`en`) is the canonical source, configured default, and runtime fallback. English, Czech (`cs`), German (`de`), and Swedish (`sv`) are the maintained selectable languages and their JSON catalogs are kept key-for-key complete. Other `app/lang` JSON skeletons may remain in the repository for future work, but pack discovery does not grant selectability. The selectable-language allowlist is intentionally limited to `en`, `cs`, `de`, and `sv`; missing keys still resolve from English defensively.

The two UI language contexts are intentionally separate. Admin routes resolve `translation_admin_language()` from the Admin session and Admin language cookie. Public routes resolve the saved `public_language` application setting. The default-enabled `public_language_selector_enabled` setting determines whether visitors may override that site-wide default, while `public_language_selector_languages` stores the ordered maintained-language subset offered to viewers. Missing settings preserve the historical behavior: the feature and all four maintained languages are enabled. At least one viewer language is required on write; malformed or empty persisted subsets defensively fall back to all maintained languages.

When the viewer feature is enabled, a permitted `?lang=<code>` request override is remembered in that viewer's public-language browser cookie. It is a browser-local viewer preference, not an account field or site-wide language mutation; the request session mirrors the cookie only while serving that viewer. The shared public header renders the configured no-JavaScript language choices and stable presentation metadata. Its locally bundled SVG flags under `public/assets/flags/` are decorative visual cues; native language names remain the accessible labels. Same-page links preserve route query state while replacing only `lang`. `?lang=default` clears both public persistence layers before returning to the site-wide default. When the feature is disabled, the switcher is omitted, query/cookie/session viewer overrides are ignored, and public requests use `public_language`. The cacheable `browser_i18n` asset also carries `lang`, but bootstrap explicitly treats that as an asset cache key rather than a viewer preference mutation. `translation_bootstrap_request()` records the request context and `translation_active_language()` selects the appropriate language without coupling Admin and public preferences.

The maintained catalog format is `app/lang/<code>.json`. `translation_load_language()` prefers JSON and loads a legacy PHP dictionary only when the JSON file is absent. The `en.php`, `cs.php`, `de.php`, and `sv.php` dictionaries therefore exist only for compatibility and are not the canonical catalogs.

Server-side `t()` lookup order is active JSON/PHP pack, configured default English pack, provided inline English fallback, then the key itself. Browser modules receive the same semantics through `view_cms_browser_i18n_strings()`, which merges default English strings with the active pack before emitting the cacheable browser i18n asset. Placeholder interpolation uses stable `{name}` tokens, and translated values must preserve the same placeholder names as English.

The Theme > Language page intersects detected packs with `translation_supported_languages()` before building the `cms_language`, `public_language`, and pack-editor selectors. This prevents dormant future packs from becoming selectable just because a file exists. `app/views/admin_language_settings.php` owns the reusable viewer-selector panel rendered beside those choices and again in Settings > General. Both surfaces use the same translation-service normalization and persistence. Viewer-language filtering never removes a maintained catalog from Admin selection, the site-default selector, pack editing, or diagnostics. The page reports coverage relative to English. JSON pack edit/import/export stays on that specialized page for the four supported languages. Missing-key diagnostics are collected for authenticated administrators and can be cleared from the same UI.

Selector appearance is stored as one normalized `public_language_selector_design` JSON setting. `app/services/translations.php` owns the five complete preset defaults, numeric bounds, enum/color validation (including the explicit `transparent` color value), backward-compatible fallback, CSS-property serialization, and persistence. Global display choices and per-preset overrides coexist in that model so resetting one preset cannot mutate another. Settings > General submits a marked basic subset which the registry merges into the current normalized design, preserving detailed overrides; Theme > Language owns the compact complete editor. The Admin preview reads the canonical defaults embedded by the reusable PHP panel; delegated browser handlers update production-shaped markup and perform unsaved all/preset/field resets. Both public renderers consume the same normalized model, and malformed state falls back to Classic with flags and codes visible.

## Routing Model

Routing is intentionally simple. `cms_route_from_request()` converts the incoming request into a page name and parameter list. `cms_run()` maps the page name to a controller function.

Two routing styles are supported.

### Query-string routing

Always supported:

```text
/index.php?page=gallery&slug=example-gallery
/index.php?page=admin_edit_gallery&id=123
/index.php?page=thumb&id=456&size=640
```

### Pretty URL routing

Supported when Apache rewrite rules are enabled:

```text
/gallery/example-gallery/
/tag/travel/
/download/example-gallery.zip
```

Pretty URL generation is controlled by URL rewrite settings in `app/services/app_settings.php` and admin UI code in `app/controllers/admin_dashboard.php`.

## Main Route Groups

The definitive route table and request dispatch live in `app/bootstrap/dispatch.php`, with path interpretation in `app/bootstrap/routing.php`. `cms_run()` remains the stable coordinator called by the public entrypoint. Important groups are listed here for orientation.

### Public gallery routes

| Page | Handler | Responsibility |
| --- | --- | --- |
| `home` | `cms_home` | Public landing page with top-level galleries. |
| `gallery` | `cms_gallery` | Public gallery page with images, subgalleries, tags, maps, search, voting, and inline admin controls. |
| `tag` | `cms_tag` | Public tag page and tag-filtered gallery listing. |
| `public_search` | `cms_public_search` | JSON search endpoint for public gallery search bars. |
| `gallery_lightbox_data` | `cms_gallery_lightbox_data` | JSON payload for lightbox navigation and metadata. |
| `gallery_map_data` | `cms_gallery_map_data` | JSON map data from EXIF GPS and flight paths. EXIF GPS output follows the global default-enabled display setting plus nullable per-gallery overrides. |
| `picture_game` | `cms_picture_game` | Side-by-side image comparison game. |
| `vote` | `cms_vote` | Image voting endpoint. |

### Media and asset routes

| Page | Handler | Responsibility |
| --- | --- | --- |
| `media` | `cms_media` | Streams original media with access checks. |
| `thumb` | `cms_thumb` | Streams generated thumbnails. |
| `public_media` | `cms_public_media` | Legacy public media endpoint. |
| `public_thumb` | `cms_public_thumb` | Legacy public thumbnail endpoint. |
| `gallery_cover_asset` | `cms_gallery_cover_asset` | Streams gallery cover images. |
| `gallery_branding_asset` | `cms_gallery_branding_asset` | Streams gallery-specific branding images. |
| `theme_background_asset` | `cms_theme_background_asset` | Streams site/theme background assets. |
| `theme_branding_asset` | `cms_theme_branding_asset` | Streams site-wide branding assets. |
| `favicon_asset` | `cms_favicon_asset` | Streams configured favicon asset. |
| `theme_css` | `cms_theme_css` | Dynamic CSS endpoint generated from theme settings. |
| `robots` | `cms_robots_txt` | Robots file for crawlers. |
| `sitemap` | `cms_sitemap_xml` | XML sitemap with gallery and image URLs. |

### Access and download routes

| Page | Handler | Responsibility |
| --- | --- | --- |
| `gallery_access` | `cms_gallery_access` | Password form and validation for protected galleries. |
| `share` | `cms_share` | Share-token based access. |
| `download_gallery` | `cms_download_gallery` | Generates or serves gallery ZIP archive. |
| `picture_manager_download_selection` | `cms_picture_manager_download_selection` | Generates a transient ZIP archive for selected public-view Picture manager photos when native sharing is unavailable. |
| `download_all` | `cms_download_all` | Generates or serves full accessible archive. |

### Admin routes

| Page | Handler | Responsibility |
| --- | --- | --- |
| `admin` | `cms_admin` | Admin dashboard. |
| `admin_settings` | `cms_admin_settings` | Central global Settings overview and safe delegated edits. |
| `admin_login` | `cms_admin_login` | Admin login. |
| `admin_logout` | `cms_admin_logout` | Logout and token cleanup. |
| `admin_forgot_password` | `cms_admin_forgot_password` | Password reset request. |
| `admin_reset_password` | `cms_admin_reset_password` | Password reset completion. |
| `admin_google_start` | `cms_admin_google_start` | Google OAuth start. |
| `admin_google_callback` | `cms_admin_google_callback` | Google OAuth callback. |
| `admin_account` | `cms_admin_account` | Admin profile, email, password, Google linking, OpenAI settings. |
| `admin_theme` | `cms_admin_theme` | Theme, branding, layout, language and public UI settings. |
| `admin_discover` | `cms_admin_discover` | Filesystem gallery discovery. |
| `admin_import` | `cms_admin_import` | Imports discovered galleries into DB. |
| `admin_new_gallery` | `cms_admin_new_gallery` | Creates a new gallery folder and record. |
| `admin_edit_gallery` | `cms_admin_edit_gallery` | Edits gallery metadata and advanced settings, including manual date ranges and the embedded per-gallery EXIF date suggestion component. |
| `admin_gallery_dates` | `cms_admin_gallery_dates` | Reviews and applies editable EXIF-derived date-range suggestions globally or scoped to one gallery branch. |
| `admin_gallery_date_suggestion` | `cms_admin_gallery_date_suggestion` | Focused POST endpoint used by the reusable per-gallery EXIF suggestion component for AJAX and no-JavaScript fallback apply actions. |
| `admin_bulk_galleries` | `cms_admin_bulk_galleries` | Bulk gallery actions. |
| `admin_reorder_galleries` | `cms_admin_reorder_galleries` | Admin hierarchy/order changes. |
| `admin_reorder_public_galleries` | `cms_admin_reorder_public_galleries` | Public ordering changes. |
| `admin_upload` | `cms_admin_upload` | Upload images into existing or new galleries. |
| `admin_upload_settings` | `cms_admin_upload_settings` | Dedicated upload settings page for normal upload preferences and browser-pipeline limits. |
| `admin_upload_browser_batch` | `cms_admin_upload_browser_batch` | Browser admin-only endpoint that accepts browser-prepared store-only ZIP upload batches and places originals/thumbnails into the normal gallery structure. |
| `admin_thumbnail_browser_source_chunk` | `cms_admin_thumbnail_browser_source_chunk` | Browser admin-only endpoint that streams store-only ZIP source chunks containing original images for browser-side thumbnail rebuilds. |
| `admin_thumbnail_browser_upload_batch` | `cms_admin_thumbnail_browser_upload_batch` | Browser admin-only endpoint that accepts browser-prepared thumbnail ZIP batches and stores derivatives in canonical thumbnail locations. |
| `admin_bulk_images` | `cms_admin_bulk_images` | Bulk image operations. |
| `admin_reorder_images` | `cms_admin_reorder_images` | Image sort order changes. |
| `admin_edit_image` | `cms_admin_edit_image` | Image metadata, tags, AI metadata panel. |
| `admin_tags` | `cms_admin_tags` | Tag CRUD and tag metadata. |
| `admin_thumbnails` related routes | Multiple handlers | Thumbnail generation, deletion and notices. |
| `admin_integrity` | `cms_admin_integrity` | Filesystem and DB consistency check. |
| `admin_logs` related routes | Multiple handlers | Audit log view, update and export. |
| `admin_telemetry` related routes | Multiple handlers | Usage metrics, privacy settings and exports. |
| `admin_update` | `cms_admin_update` | Update checks and patch notes. |
| `admin_run_migrations` | `cms_admin_run_migrations` | Browser-triggered migration runner. |
| `admin_devmode` | `cms_admin_devmode` | Development diagnostics. |
| `admin_exif_gps_settings` | `cms_admin_exif_gps_settings` | Saves the global EXIF/GPS display default and can reset all per-gallery overrides. |

### Centralized Admin Settings ownership

`app/services/admin_settings_registry.php` is the ownership and discovery registry for the central Settings hub. Stable section IDs are `general`, `appearance`, `content`, `media`, `uploads`, `privacy`, and `advanced`. Registry entries describe canonical keys, current/default resolvers, validation metadata, sensitivity, migration readiness and specialized destinations. Discovery-only entries index every global specialist control and registered feature flag without rendering hundreds of duplicate cards. The registry does not replace the domain services that own normalization or persistence.

`app/controllers/admin_settings.php` accepts only registry-whitelisted centrally editable IDs. POST requests require Admin authentication and CSRF validation, retain field-level errors, and save through `admin_settings_save_editable_value()`. That function delegates to the same focused setters used elsewhere, including `set_site_name()`, `translation_set_public_language()`, `translation_save_public_language_selector_settings()`, `set_url_rewrite_enabled()`, `set_public_home_search_enabled()`, `public_thumbnail_rendering_mode_save()`, `set_exif_gps_default_enabled()`, and `set_dev_mode_enabled()`. Unknown IDs and specialized-only entries are rejected. No generic submitted key can be written directly to `app_settings`.

The central page starts from a read-only ownership model and enables editing only for the narrow safe set above. Complex Theme persistence, upload pipeline settings, telemetry retention, Account/Google/OpenAI credentials, raw CSS, uploaded branding, language packs, API keys, database repair/migrations and destructive maintenance stay at their existing mutation boundaries. Sensitive values are resolved to status labels before rendering and are never placed into central HTML or logs.

Tag-page presentation keeps its existing inheritance rules: `tag_page_gallery_grid_columns` and `tag_page_gallery_grid_rows` fall back to global pagination dimensions, while `tag_page_gallery_description_layout` falls back to the global Theme card layout. Hero-tag controls are distinct settings. Per-gallery description layout, lightbox and EXIF/GPS overrides remain per-gallery.

The navigation contract is implemented by `admin_settings_url()` and `admin_settings_section_id()`. Central links contain both `section=<stable-id>` and `#settings-<stable-id>`. The existing Admin tab module has an opt-in `data-admin-tabs-url-mode="href"` mode for this page so JavaScript activation updates the complete href in browser history rather than changing only the hash. Existing tabs retain their original hash-only behavior. Normal links remain the JavaScript-disabled fallback.

The complete source audit and setting inventory is maintained in `docs/ADMIN_SETTINGS_INVENTORY.md`. The central page itself requires no database migration. Optional existing schemas, for example telemetry or per-gallery EXIF/GPS overrides, continue to gate only the features that already depend on them.

### Integration and automation routes

| Page | Handler | Responsibility |
| --- | --- | --- |
| `upload_automation_upload` | `cms_upload_automation_upload` | External watcher upload API. |
| `admin_upload_automation_token` | `cms_admin_upload_automation_token` | Token management for upload automation. |
| `admin_api_manager` | `cms_admin_api_manager` | API/export/import manager entry. |
| `gallery_migration_*` | Multiple handlers | Recursive manifests, bounded ZIP-package transfer, receive status, resume, and completion for gallery migration. |
| `admin_gallery_migration` | `cms_admin_gallery_migration` | Admin UI for gallery migration. |
| `admin_openai_text_assist` | `cms_admin_openai_text_assist` | OpenAI text and image-description helper endpoint. |
| `admin_simbrief_description` | `cms_admin_simbrief_description` | SimBrief-derived gallery description generation. |
| `navdata_lookup` | `cms_navdata_lookup` | Navigation waypoint lookup. |
| `admin_navdata` | `cms_admin_navdata` | Navigation data admin UI. |
| `admin_update_navdata` | `cms_admin_update_navdata` | Local navigation data refresh. |

## Directory Layout

```text
.
  index.php
  public/
    index.php
    assets/
      styles.css
      gallery.js
      telemetry.js
      usage.js
      custom.css
  app/
    bootstrap.php
    database.php
    migrations.php
    security.php
    helpers.php
    views.php
    services.php
    controllers.php
    integrity.php
    lang/
    views/
    services/
    controllers/
  database/
    migrations/
  galleries/
  cache/
  custom_css/
  data/
    navdata/
  scripts/
  tests/
  winapp/
```

## Controller Layer

Controllers are loaded through `app/controllers.php`. The loader preserves the legacy include contract while allowing the actual controllers to live in focused files.

Controller conventions:

1. Public route handlers use `cms_*` function names.
2. Admin handlers validate authentication and CSRF before mutating state.
3. JSON endpoints should use small response helpers and return a stable `success` or `error` shape.
4. File-serving controllers must call the access-checking service layer before streaming originals or thumbnails.
5. Rendering-heavy admin controllers often delegate form fragments to helper functions in the same controller or `app/views/`.

Important controller files:

| File | Main responsibility |
| --- | --- |
| `app/controllers/public_gallery.php` | Public home, gallery rendering, public search UI, breadcrumbs, gallery cards, admin preview controls. |
| `app/controllers/public_media.php` | Original media, thumbnail, sitemap, robots and gallery asset streaming. |
| `app/controllers/gallery_lightbox.php` | JSON lightbox payloads. |
| `app/controllers/exif.php` | Map data endpoint. |
| `app/controllers/public_tags.php` | Public tag pages and tag list rendering. |
| `app/controllers/admin_auth.php` | Login, logout, password reset, Google login start/callback and account management. |
| `app/controllers/admin_dashboard.php` | Dashboard, dev mode, migrations, rewrite/public search settings, EXIF/GPS defaults, gallery date suggestion entry point and navdata maintenance card. |
| `app/controllers/admin_theme.php` | Theme and branding settings form. |
| `app/controllers/admin_galleries*.php` | Gallery discovery, creation, edit, reorder, bulk actions and scan actions. |
| `app/controllers/admin_gallery_dates.php` | EXIF-derived gallery date-range suggestion review and apply workflow. |
| `app/controllers/admin_images*.php` | Bulk image operations and reorder actions. |
| `app/controllers/admin_public_inline.php` | Inline public-page editing for logged-in admins. |
| `app/controllers/admin_uploads.php` | Upload UI, standard server-side upload processing, dedicated upload settings page orchestration and browser-prepared ZIP batch endpoint. |
| `app/controllers/admin_thumbnails.php` | Thumbnail creation, deletion, maintenance and notices. |
| `app/controllers/site_maintenance.php` | Token-protected web cron endpoint and Admin settings for resumable scheduled maintenance. |
| `app/controllers/admin_tags.php` | Admin tag management. |
| `app/controllers/admin_logs.php` | Audit log list, filters, updates and exports. |
| `app/controllers/admin_telemetry.php` | Telemetry dashboard, settings, exports and maintenance. |
| `app/controllers/gallery_migration.php` | Pull/push gallery migration API and admin UI. |
| `app/controllers/upload_automation.php` | Token-authenticated upload automation and AI worker API. |
| `app/controllers/navigation_data.php` | Navigation data account, lookup and maintenance endpoints. |
| `app/controllers/admin_openai_text_assist.php` | OpenAI text generation and bulk image description endpoint. |
| `app/controllers/admin_simbrief.php` | SimBrief OFP and gallery description endpoint. |

## Service Layer

Services are loaded through `app/services.php` in a deliberate dependency order.

Key service families:

| Family | Files | Responsibility |
| --- | --- | --- |
| Settings | `app_settings.php`, `theme.php`, `favorite_galleries.php`, `custom_css.php`, `translations.php` | DB-backed settings, theme defaults, favorite gallery/main-page shortcuts, CSS variables, language packs. |
| Gallery model | `gallery_lookup.php`, `gallery_mutations.php`, `gallery_paths.php`, `gallery_display.php`, `gallery_grid.php`, `gallery_dates.php`, `gallery_count_badges.php`, `gallery_description_layout.php` | Gallery queries, edits, URLs, manual date ranges, EXIF-derived date suggestions, display inheritance and presentation options. |
| Gallery assets | `gallery_covers.php`, `gallery_backgrounds.php`, `gallery_branding.php`, `favicon.php` | Cover, background, banner, logo, separator and favicon handling. |
| Images | `image_scanning.php`, `uploads.php`, `browser_uploads.php`, `dng_derivatives.php`, `picture_manager.php` | Image discovery, metadata scan, standard upload, browser client-prepared ZIP ingestion, copy/move, public-view selection sharing and DNG helper logic. |
| Thumbnails | `thumbnails.php`, `thumbnail_sources.php`, `thumbnail_generation.php`, `thumbnail_bundles.php`, `thumbnail_formats.php`, `thumbnail_html.php`, `thumbnail_bounds.php`, `thumbnail_maintenance.php`, `public_thumbnail_rendering.php` | Thumbnail pathing, static serving, generation, quality bounds, responsive/progressive server markup, and selected-gallery renderer policy. |
| Access | `gallery_access.php`, `auth_persistence.php`, `auth_throttle.php`, `google_auth.php`, `download_signatures.php` | Protected gallery access, admin sessions, durable login, Google linking, download signatures. |
| Tags | `tags.php`, `tag_metadata.php` | Tag CRUD, slugs, entity linking and weighted suggestions. |
| Search | `public_search.php`, `lightbox_metadata.php` | Public search across galleries, images, tags and AI metadata. |
| Maps and aviation | `exif.php`, `flight_maps.php`, `navigation_data.php`, `simbrief_descriptions.php` | EXIF GPS, default-enabled EXIF/GPS display policy with per-gallery overrides, flight route maps, waypoint lookup and SimBrief OFP processing. |
| AI | `ai_image_analysis.php`, `openai_text_assist.php` | Local AI metadata queue, OpenAI text/image-description integration. |
| Telemetry | `telemetry.php`, `telemetry_privacy.php`, `telemetry_settings.php`, `telemetry_rollup.php`, `database_observer.php` | Anonymous usage events, media serving metrics, privacy bucketing and rollups. |
| Admin operations | `admin_dashboard.php`, `admin_render_profiler.php`, `logs.php`, `admin_log_archives.php`, `updates.php`, `github.php`, `gallery_migration.php`, `site_maintenance.php` | Dashboard model, diagnostics, grouped and exportable audit logs, protected day archives, GitHub update checks, API migration and resumable scheduled maintenance. |

## View Layer

`app/views.php` loads shared rendering helpers and view modules. The application does not use a template engine. HTML is emitted by PHP functions.

Important view files:

| File | Purpose |
| --- | --- |
| `app/views/layout.php` | Page layout, shared chrome, and favorite shortcut rendering. |
| `app/views/admin_chrome.php` | Admin navigation and shared admin page shell. |
| `app/views/admin_dashboard.php` | Dashboard page composition, top-level Admin tabs, and gallery table rendering. |
| `app/views/admin_dashboard_sections.php` | Reusable dashboard overview and grouped maintenance subtab sections. |

The main Admin dashboard keeps its initial request bounded: the Maintenance tab is rendered through the authenticated `admin_dashboard_maintenance` JSON endpoint only when the tab is first activated. This keeps database usage metadata, navigation-data status, maintenance state, and related optional-schema checks out of ordinary gallery navigation. The server-rendered placeholder remains safe without JavaScript and links to the dedicated maintenance details page as a fallback.
| `app/views/admin_gallery_forms.php` | Gallery admin form sections. |
| `app/views/gallery_descriptions.php` | Gallery description rendering helpers. |
| `app/views/navigation_data.php` | Navigation data admin rendering. |
| `app/views/simbrief_descriptions.php` | SimBrief UI helpers. |
| `app/views/seo.php` | SEO-related rendering helpers. |

## Database Layer

`app/database.php` exposes a single function:

```php
function db(): PDO
```

The PDO instance is cached per request. It uses configuration from `cms_config()['database']` and enables:

```php
PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
```

Use `db()` everywhere. Do not instantiate separate PDO connections in feature code.

Optional schema checks live in `app/services/database_helpers.php`:

```php
db_table_exists(string $table): bool
db_column_exists(string $table, string $column): bool
```

Those helpers are used by compatibility-sensitive services and older installs that may not yet have all migrations.

### Schema readiness inspection reliability

The legacy boolean helpers remain for narrow compatibility code, but they are not
policy evidence at a boundary that must distinguish confirmed absence from an
inspection failure. The completed reliability architecture uses
`app/services/schema_inspection.php` for structured observation and three
separate policy layers according to risk: security/access policy,
`app/services/mutation_schema_policy.php` for destructive and ingestion work, and
`app/services/presentation_schema_policy.php` for optional presentation/reporting
features. The implementation keeps schema observation separate from feature policy:

- confirmed missing objects may use a migration-required response or a proven
  compatibility path;
- unknown access, privacy, authentication, ingestion, and destructive-mutation
  state must stop safely;
- optional presentation may use a safe display fallback when omission cannot
  weaken authorization or authorize a write;
- Admin diagnostics should preserve partial results while reporting inspection
  failure separately from pending migrations;
- request-local caching retains structured state and must be reset after DDL
  when migration validation occurs in the same process.

The service validates identifiers and executes bound queries against
`information_schema.TABLES`, `COLUMNS`, and `STATISTICS`. It also exposes cached
column-definition token and nullability inspection for compatibility decisions such
as historical visibility vocabulary and nullable per-gallery presentation overrides. Query failures produce
bounded `unknown` results with a safe SQLSTATE or `inspection_failed` category;
raw exception messages never enter the result. Feature aggregation preserves
individual requirements and applies `unknown > missing > available` precedence.
The pure `schema_inspection_query_definition()` boundary owns the production SQL
and bound-parameter mapping, allowing focused tests to verify active-database
scoping without opening a live connection. The same tests protect registration
order, cache identity, cache reset, predicate exclusivity, and diagnostic
redaction.
The first caller conversion is the security-sensitive NSFW Guard. Its feature
status aggregates `galleries.nsfw_enabled` and `images.nsfw_enabled`. A complete
schema preserves the established inherited-gallery and image-level policy. A
confirmed pre-feature schema retains the historical compatibility path because
that schema could not store NSFW flags. An `unknown` inspection result raises a
dedicated policy exception before protected gallery, media, thumbnail,
lightbox, map, or metadata output can continue.

`app/bootstrap/dispatch.php` catches the shared `PublicSchemaPolicyUnavailableException`
boundary and delegates to a focused response helper. Feature-specific exceptions
carry only bounded policy metadata: a validated capability key such as
`gallery_access`, `gallery_visibility`, or `nsfw_guard`, a normalized schema
state, and a stable error code. Human pages receive minimal translated HTML,
structured endpoints receive JSON, and media or crawler endpoints receive
plain text. Every representation uses HTTP 503, private no-store caching, a
retry hint, and an optional safe request identifier. Normal layouts are skipped
to avoid recursively requesting protected gallery metadata.

Before writing the response, the helper records a bounded security event through
the existing Admin log service. NSFW keeps its established
`security.nsfw_schema_inspection_unavailable` event key; the converted Phase 9
policies use `security.public_schema_inspection_unavailable`. A genuine metadata
inspection failure is logged as `unknown` / `inspection_failed`, while a
confirmed partially applied gallery-access migration is logged as
`missing` / `partial_schema`. Context is otherwise restricted to the validated
feature key, normalized route, response representation, and safe request
correlation. Raw SQL, database exception text, credentials, DSNs, session values,
CSRF values, passwords, and access/reset/share tokens are excluded. Admin logging
is best-effort and already isolates storage failures, so an unavailable or
partially migrated log table cannot replace or delay the public 503 response.

Admin System Health reports available, confirmed missing, and unknown states
separately. Gallery and image controls distinguish migration guidance from an
inspection outage, while explicit NSFW form and bulk mutations are refused
unless the complete schema is verified. The shared inspection service still
owns observation only; NSFW access and mutation decisions remain in the feature
service and controller layers.

`admin_schema_health_model()` is the shared Admin interpretation boundary.
`admin_nsfw_schema_health_model()` remains a focused compatibility wrapper. The
generic model contains state, validated affected table/column identities, safe
suggested-check keys, and a request identifier only for unknown state. It also
supports a configuration-disabled state for feature policies where a real opt-out
exists. NSFW Guard is core access policy and currently has no feature flag.

`admin_security_schema_health_statuses()` registers all converted security and
authentication capabilities. The deferred System Health card and
`admin_diagnostics` consume this same model.
Missing or unknown state adds an Action badge to both the main Maintenance tab
and nested System Health tab. Available and intentionally disabled states are
non-warning health outcomes. Runtime Diagnostics includes the state in its
visible panel and copyable report; unknown state provides connection, selected-
database, and schema-permission guidance plus the request reference. Raw
exception diagnostics and unvalidated object names never enter the model.

### NSFW pilot validation before wider conversion

Phase 8 reviewed the complete NSFW pilot before allowing another feature to
adopt the three-state model. The result contract is intentionally retained
without renaming. Object inspections expose `state`, `object_type`, `table`,
`object`, `error_code`, and `diagnostic`; feature aggregates expose `state`,
`feature`, and `requirements`. Policy callers normally use
`schema_inspection_is_available()`, `schema_inspection_is_missing()`, and
`schema_inspection_is_unknown()` rather than indexing `state` repeatedly. The
Admin health normalizer is the deliberate exception because it transforms
individual requirements into a bounded diagnostic model.

The request-local cache also defines the query budget. NSFW Guard requires two
columns, so the first capability check performs at most two
`information_schema.COLUMNS` queries in one PHP request. Repeated calls through
gallery inheritance checks, image policy checks, readiness predicates, and
other NSFW helpers reuse those two structured results. A later HTTP request gets
a fresh cache by design.

Migration execution is a cache invalidation boundary. `run_migrations()` clears
request-local schema inspection state after bootstrapping `schema_migrations`,
after successful or safely duplicate-replayed schema DDL (`CREATE`, `ALTER`,
`DROP`, `RENAME`), and after successful migration `after` repair callbacks.
This ensures a migration callback or same-process post-migration validator
cannot reuse a capability answer observed before the schema change. Data-only
migration statements do not clear the schema cache because they cannot change a
table, column, or index capability.

The Phase 8 review accepted the public and Admin presentation split unchanged.
Public human routes use translated minimal HTML, structured routes use JSON, and
media/crawler routes use plain text, all with HTTP 503 and the same redaction
boundary. Admin health continues to distinguish `missing` from `unknown`.
Logging remains best-effort and bounded to the stable security event and safe
context. No repository-wide caller conversion begins until these pilot
contracts and the full regression suite remain green.

### Phase 9 security and authentication capability conversion

Phase 9 extends the validated pilot contract across the security boundary while
keeping schema observation separate from authorization policy. The conversion is
intentionally capability-based rather than a repository-wide replacement of every
legacy boolean helper.

The converted capability map is:

| Capability | Structured status | Required schema | Missing policy | Unknown policy |
|---|---|---|---|---|
| Gallery access | `gallery_access_schema_status()` | `galleries.access_mode`, `access_listing`, `access_password_hash`, `access_token_hash`, `access_token_expires_at` | Legacy compatibility only when all five are confirmed absent | Fail closed before public output or access mutation |
| Gallery visibility | `gallery_visibility_schema_status()` | `galleries.visibility` plus verified `unpublished` enum support | Historical `draft` storage mapping | Refuse public policy/storage guess |
| Gallery share token | `gallery_access_share_token_schema_status()` | `galleries.access_share_token` | Generation/use unavailable; core hash revocation remains possible | Generation/use unavailable; core hash revocation remains possible |
| Persistent login | `auth_persistent_login_schema_status()` | `admin_remember_tokens` | Keep ordinary PHP-session login; no durable token | Refuse token issue/use, clear/refuse browser token safely |
| Admin email | `auth_user_email_schema_status()` | `users.email` | Preserve username-only login and account compatibility | Do not reinterpret as absent; recovery-email operations unavailable |
| Password reset | `auth_password_reset_schema_status()` | `password_reset_tokens` and `users.email` | Require migration before reset issue/consume | Refuse reset workflow as operationally unavailable |
| External identity | `google_auth_schema_status()` | `user_google_accounts` | Explicit migration/unavailable state | Refuse linked-account lookup, login, link, and disconnect |

#### Gallery access and visibility rules

`gallery_access_schema_is_confirmed_legacy()` is deliberately stricter than a
feature-level `missing` result. Every required access column must be confirmed
absent before the old unprotected/listed compatibility model may be used. If one
column exists and another does not, the installation is considered partially
migrated. Public policy fails closed because existing rows may already carry a
password mode, unlisted flag, or token hash that would be lost by substituting
legacy defaults.

Visibility compatibility is based on the column definition rather than a broad
query failure catch. `schema_inspection_column_definition_contains()` first
verifies the column and then checks whether its trusted definition contains the
canonical `unpublished` token. A successful negative answer proves the historical
`draft` vocabulary. An inspection failure is `unknown` and may not choose either
vocabulary on the application's behalf.

Sensitive public routes run the three access/privacy preflights in the dispatcher
before calling the route controller: visibility, gallery access, and NSFW Guard.
The protected set includes gallery/home/tag/share flows, media and thumbnail
routes, lightbox/map/search metadata, sitemap output, branding/cover assets,
voting/game flows that can reveal gallery state, and gallery downloads. This
prevents partial HTML, JSON, archive data, or image bytes from being emitted before
the policy decision is known. Service-layer callers such as sitemap/listing,
favorite galleries, and gallery sidecars also assert the capability where they
choose between current and historical schema shapes.

`cms_public_schema_unavailable()` is the generic public boundary. It keeps the
Phase 8 route representations, HTTP 503 status, no-store/retry/noindex behavior,
translation, and request correlation. `public_schema_log_unavailable()` emits the
stable generic event `security.public_schema_inspection_unavailable` for converted
capabilities, while the established NSFW event name is preserved for NSFW Guard.
No raw exception message crosses this boundary.

#### Authentication rules

Authentication preserves the distinction between the primary PHP session and
optional database-backed conveniences. A confirmed missing
`admin_remember_tokens` table disables only persistent browser login. Password or
Google authentication can still establish the ordinary session. Unknown metadata
state does not authorize token issuance or use. Restore/revoke paths remove or
ignore the cookie safely, and login/account flows catch the bounded schema
exception so the successfully established session is not discarded merely because
remember-login persistence is temporarily unavailable.

`users.email` has its own capability because older installations can still support
username/password authentication without recovery email. Password reset is a
separate aggregate capability requiring both `users.email` and
`password_reset_tokens`. Identity lookup may use email only after the column is
verified. Unknown email metadata is never reported as an invalid username/password
attempt, and password-reset request/consume flows report an operational schema
problem instead of pretending the token/account is invalid.

Google OAuth configuration readiness and identity-link schema readiness are
separate. Client configuration can be complete while `user_google_accounts` is
missing or unknown. Linked-account operations therefore use
`google_auth_schema_operation_available()` rather than a single boolean readiness
check. Password login remains independent. Callback logs record bounded operation
and exception class/category information only, not OAuth errors, client secrets,
tokens, DSNs, or raw database exception text.

#### Share-token revocation exception

Token generation and use are authorization-producing operations and require verified
storage. Revocation is security-tightening and follows a narrower rule. Once the
core access columns containing `access_token_hash` and expiry are verified, the
application always permits clearing that validating hash. If the optional encrypted
`access_share_token` display column is available it is cleared too. If that optional
column is missing or unknown, clearing the hash still invalidates every external
copy. This asymmetry prevents an inspection problem from making an existing share
link impossible to revoke.

#### System Health and diagnostics

`admin_security_schema_health_statuses()` normalizes all Phase 9 capability states
through `admin_schema_health_model()`. Missing and unknown states raise the Admin
Maintenance/System Health action signal. A configuration-disabled persistent-login
or Google integration may be shown as `disabled` where the capability is genuinely
not in use. Runtime Diagnostics uses the same complete capability set and places
only bounded object names, safe suggested-check identifiers, and optional request
references in its visible and copyable report.

The Phase 9 tests exercise current, confirmed legacy, partial migration, and unknown
states; real dispatcher refusal before protected handlers; route-specific response
formats; bounded log redaction; authentication compatibility; request-cache reuse;
and the full System Health registry. Phase 10 destructive/ingestion conversion is
not part of this architecture change.

### Phase 10 destructive and ingestion mutation policy

Phase 10 applies the same three-state inspection vocabulary to operations that can
delete, move, import, generate, repair, revoke, or replace persistent state. The
policy layer is `app/services/mutation_schema_policy.php`. It does not discover
schema independently. It composes results from `schema_inspection.php`, applies
mutation-specific compatibility rules, emits bounded refusal diagnostics, and
raises `MutationSchemaUnavailableException` before an unsafe mutation begins.

The default decision is intentionally stricter than read-only presentation:

| State | Mutation decision |
|---|---|
| `available` | Preserve the existing mutation behavior. |
| `missing` | Require the migration, or enter only an explicitly proven compatibility/bootstrap path. |
| `unknown` | Refuse or pause before irreversible target state is changed. |

The converted capability map is:

| Capability | Structured status | Main requirements | Confirmed missing policy | Unknown policy |
|---|---|---|---|---|
| Gallery/image deletion | `gallery_deletion_schema_status()` | Core `galleries` ownership/path columns and `images.id/gallery_id` | Core requirement blocks deletion; optional dependency cleanup may skip only verified absent legacy tables/columns | Refuse before filesystem or row deletion |
| Gallery/image move or copy | `gallery_move_schema_status()` | Gallery/image path hashes, parent/cover ownership and ordering columns | Require current core migration; optional image-tag propagation may skip only verified absence | Refuse before path/file/ownership mutation |
| Duplicate review ledger | `duplicate_photo_ledger_schema_status()` | `duplicate_photo_ledger_pairs` and `duplicate_photo_ledger_galleries` with user/entity/timestamp fields | Show migration-required ledger state, no ledger mutation | Refuse ledger mutation and show temporary schema failure |
| Upload ingestion | `upload_ingestion_schema_status()` | Core gallery target and image registration columns | Require current ingestion schema | Refuse before uploaded source is moved into gallery |
| Upload automation | `upload_automation_schema_status()` | Full `gallery_upload_tokens` issuance/authentication schema | Require migration for issue/auth/use | Refuse issue/auth/use; narrow verified revocation remains independently available |
| Gallery migration | `gallery_migration_schema_status()` | Core target gallery/image identity and path columns | Require core migration; confirmed absent optional metadata is omitted | Pause resumable job before target mutation |
| Mobile WebDAV | `mobile_webdav_schema_status()` | Full `mobile_webdav_upload_tokens` authentication schema | Require migration for create/auth/use | Refuse create/auth/PUT; narrow verified deletion remains independently available |
| Thumbnail metadata mutation | `thumbnail_metadata_mutation_schema_status()` | Core `image_thumbnail_variants` metadata columns | Confirmed absent table preserves documented file-only derivative compatibility | Refuse generation/repair/deletion before derivative file changes |
| Database maintenance | `database_maintenance_mutation_schema_status()` | Inspectability of `schema_migrations` | Confirmed absence may enter the migration/repair bootstrap path | Refuse cleanup/repair planning that would mutate state |
| Update activation | `application_update_activation_schema_status()` | Inspectability of `schema_migrations` | Confirmed absence is allowed because migration bootstrap follows activation | Refuse before active application files are replaced |

#### Preflight ordering and recoverability

The important Phase 10 boundary is not merely whether a schema check exists, but
**where it runs**. Each converted workflow performs its required inspection before
the first target-side irreversible change:

- classic upload and prepared browser ZIP upload verify image registration plus
  thumbnail-write compatibility before moving/writing originals into the gallery;
- WebDAV verifies credential storage and ingestion schema before consuming a PUT
  into the gallery target;
- gallery migration verifies target schema before job preparation, metadata writes,
  original registration, thumbnail installation, and completion. The resumable job
  record remains available when a schema check refuses progress;
- thumbnail generation preflights the core metadata table **and optional write-shape
  columns** before creating derivative directories or files. This prevents a later
  optional-column inspection failure from occurring only after a derivative was
  already replaced;
- gallery deletion and move/copy inspect core ownership/path schema before database
  or filesystem mutation, then inspect optional cleanup dependencies explicitly;
- database maintenance requires conclusive inspection before live cleanup or repair;
- updater installers may download and extract staging content first, but call
  `application_update_assert_activation_schema_known()` immediately before
  `application_update_copy_files()` touches the active installation.

This ordering is what makes a refusal recoverable. Uploaded files can remain in PHP
temporary storage or the prepared ZIP, resumable migration job state remains on
disk, source gallery files remain untouched, credentials remain unchanged, and an
update archive can remain staged without partially replacing the running program.

#### Optional compatibility dependencies

`mutation_schema_optional_table_column_available()`,
`mutation_schema_optional_table_columns_available()`, and
`mutation_schema_optional_column_available()` encode the only acceptable legacy
skip rule: a successful metadata query must prove the dependency is absent. An
inspection exception is never converted to `false`. It is normalized to `unknown`
and aborts the mutation. This removes the destructive ambiguity of the legacy
`db_table_exists()` and `db_column_exists()` wrappers, which still return boolean
`false` for both absence and inspection failure and therefore remain unsuitable for
new mutation policy.

Thumbnail metadata has an additional compatibility preflight in
`thumbnail_metadata_preflight_write_schema()`. If the complete metadata table is
confirmed absent, file-only derivative behavior remains available. If the table is
present, optional variant columns (`derivative_version`, `gallery_id`,
`thumbnail_rel_path`) and optional source-geometry fields on `images` are inspected
before file mutation. Confirmed missing fields are omitted from the write shape;
unknown fields stop the write.

#### Credential revocation uses a narrower capability

Issuing or trusting an upload credential requires the complete authentication
schema. Revocation is security-tightening, so it uses the smallest verified column
set needed to disable/delete the credential:

- `upload_automation_revocation_schema_status()` needs token identity, gallery
  ownership, `active`, and `revoked_at`, not `token_hash` or descriptive fields;
- `mobile_webdav_revocation_schema_status()` needs the row `id` required for the
  existing delete operation.

This deliberately allows an administrator to shut off a credential even if an
unrelated column is missing. An inspection failure of the revocation columns still
refuses the mutation.

#### Mutation diagnostics and System Health

`database.mutation_schema_refused` is the shared bounded refusal event. Context is
limited to the validated feature identifier, normalized `missing`/`unknown` state,
stable operation identifier, and validated affected table/column names. Raw PDO
messages, SQL, credentials, API keys, WebDAV passwords, filesystem paths, migration
source paths, and updater staging paths never belong in this event.

`admin_mutation_schema_health_statuses()` registers the ten Phase 10 capabilities
through the same `admin_schema_health_model()` used by Phase 9. Dashboard Maintenance
and Runtime Diagnostics therefore report the exact shared state. Missing or unknown
capabilities raise the System Health Action signal. Unknown state may expose a safe
request reference and bounded checks for database connectivity, selected database,
and metadata-inspection permission.

`tests/mutation_schema_policy_test.php` protects state precedence, optional
compatibility, narrow revocation, query-cache budgets, removal of legacy boolean
helpers from the converted mutation paths, health/diagnostics registration,
preflight ordering, and updater source-package requirements. Optional
presentation/reporting policy remains separate because safe read omission and
destructive mutation refusal are different decisions.

### Phase 11 optional presentation and reporting policy

`app/services/presentation_schema_policy.php` completes the three-state conversion
for optional UI, reporting, maps, AI assistance, navigation integrations, and
telemetry. It composes the same structured inspection results as the security and
mutation layers but applies a different read policy:

| State | Optional read/render | Dependent write |
|---|---|---|
| `available` | Render or query normally. | Preserve the existing write. |
| `missing` | Omit the optional feature or show migration guidance. | Refuse when the write requires the feature; an audited legacy path may intentionally omit optional persistence. |
| `unknown` | The core page may continue only when omission cannot expose protected data or weaken authorization. Log bounded degraded state and surface it in System Health. | Never treat as absence. Refuse the write, or skip only an incidental optional side effect while leaving the primary non-database operation independent. |
| `disabled` | System Health reports the configured feature as disabled without probing its schema. | The disabled feature does not initiate its write workflow. |

The named Phase 11 capability registry covers:

- GPS/EXIF metadata and nullable per-gallery GPS override storage;
- flight-route persistence and local flight-map navigation data;
- image voting and Picture Game storage;
- lightbox browsing-mode overrides, including definition-token validation for the
  supported mode vocabulary;
- OpenAI text-assistance settings and the optional image-input setting column;
- local AI image-analysis metadata and queue tables;
- SimBrief route-map persistence independently of remote OFP/draft generation;
- navigation-data cache and persistent navigation account/token storage, plus a
  narrow account-deletion capability for disconnect/revocation;
- telemetry settings and the complete telemetry reporting/rollup table set;
- the Complete Admin Gallery Report dependency set.

Metadata-organizer capture-date grouping uses a narrow internal structured capability
for `images.exif_taken_at`. It intentionally does not add a sixteenth System Health
card because it is a derivative EXIF consumer rather than an independently configured
feature.

#### Read degradation versus write authorization

Read-only helpers such as `exif_gps_schema_ready()`, `flight_map_schema_ready()`,
`gallery_lightbox_browsing_mode_schema_ready()`, navigation-cache readers, and AI
presentation checks now delegate to `presentation_schema_render_available()`. A
confirmed absent optional feature is omitted. An inspection failure is also omitted
from the base page only when that omission is safe, and
`database.presentation_schema_degraded` records a bounded feature/state/operation
entry. The log contains validated database-object names only and never raw PDO
messages, SQL, DSNs, credentials, OAuth/API tokens, cookies, reset/share tokens,
CSRF values, or filesystem paths.

State-changing entry points do not rely on those boolean presentation wrappers.
Voting and Picture Game writes, voting-enabled gallery creation/import, explicit or
inherited lightbox override persistence during gallery creation/import/metadata
organization, per-gallery presentation setting changes, OpenAI profile settings, AI
queue state transitions, navigation account persistence, telemetry
settings/maintenance, flight-map persistence, and related mutations use
`presentation_schema_assert_write_available()` or the narrower
`presentation_schema_assert_known()` compatibility boundary. Picture Game pair
selection is treated as a write because rendering a new pair records the displayed
images before the visitor votes.

Two intentional split-workflow cases preserve useful primary behavior without
claiming a database write succeeded. Navigraph/OAuth can keep session-only state on
a schema positively confirmed to predate persistent account storage, but an
`unknown` account schema cannot be treated as legacy. SimBrief may still fetch and
render a description draft when route-map persistence is missing or temporarily
uninspectable; only the optional route-map database write is omitted.

#### Reporting and telemetry

The Complete Admin Gallery Report now uses structured inspection for every known
optional table/column dependency and emits generic unavailable text instead of raw
database exception messages. Its direct query of `information_schema.TABLES` is
intentional: the report must enumerate the current database's complete set of base
tables dynamically, so a named-object existence helper cannot replace that query.
The Picture Game report groups `winner_image_id` into completed selections versus
displayed-without-selection rows instead of referencing a nonexistent `vote`
column.

Telemetry distinguishes the settings table from the complete report/maintenance
shape. Dashboard/export reads require the reporting capability. Setting saves
require verified settings storage. Daily rollup and retention purge may no-op for a
confirmed pre-telemetry schema, but an `unknown` reporting schema raises the Phase
11 policy boundary instead of silently reporting successful maintenance.

#### Lazy System Health and cache budget

`presentation_schema_health_definitions()` stores callable resolvers, not eager
status arrays. `admin_presentation_schema_health_statuses()` evaluates the relevant
feature flag first. A disabled optional feature therefore reports `disabled` with
zero metadata queries. Enabled features invoke only their resolver and reuse the
request-local object cache shared by all other policies. The voting capability, for
example, requires exactly ten first-use metadata probes and no additional probes
when checked repeatedly in the same request. Shared flight-map requirements are
likewise cached when both flight maps and SimBrief health are displayed.

System Health and Runtime Diagnostics consume the same fifteen capability entries.
`available`, `missing`, `unknown`, and configuration `disabled` are preserved as
distinct operator states. Unknown entries expose only bounded affected object names,
safe suggested checks, and request correlation.

`tests/presentation_schema_policy_test.php` protects state precedence, safe read
degradation, write refusal, bounded logging, the voting query budget, zero-query
lazy registry construction, exact capability registration, converted-source audits,
Admin report dynamic-inventory justification, AI worker exception redaction, and
Runtime Diagnostics integration. With this conversion, the schema-inspection
reliability roadmap is complete and durable behavior is owned by the permanent
architecture/database/testing documentation rather than a temporary phase plan.

## Migration System

`app/migrations.php` implements the migration runner.

Migration rules:

1. Files are read from `database/migrations/*.php`.
2. Files are sorted lexically, so timestamp prefixes define execution order.
3. Each migration returns a validated definition containing `statements` and may provide an `after` repair callback. Legacy files may still return a plain SQL statement list.
4. The complete pending definition set is loaded and validated before execution starts.
5. Each SQL statement is executed by `apply_migration_statement()`.
6. The optional `after` callback runs before the migration is recorded.
7. The migration is recorded in `schema_migrations` only after all statements and repair callbacks complete.
8. Duplicate DDL errors are treated as safe replays for common MySQL/MariaDB object-exists cases.

The migration table is created automatically:

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(64) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL
)
```

Use migrations for all schema changes. Do not alter tables from controller code.

## Database Maintenance Architecture

Database maintenance is an explicit Admin workflow, not a background dashboard query. The implementation is split across:

- `app/services/database_maintenance.php` for information_schema normalization, migration and code audits, table policies, candidate classification, resumable cleanup, schema-repair readiness, and selected physical operations.
- `app/controllers/admin_database_maintenance.php` for Admin authentication, POST-only routing, CSRF validation, confirmation phrases, flash messages, logging, and redirects.
- `app/views/admin_database_maintenance.php` for the cached audit, candidate reasons and confidence, table-specific policies, thumbnail distribution, and selected-table forms.
- `database/migrations/202607250001_database_maintenance_schema_repair.php` plus `app/migration_repairs.php` for conditional legacy schema repair.

The inspection pipeline reads `information_schema.TABLES`, `COLUMNS`, `STATISTICS`, `KEY_COLUMN_USAGE`, and `REFERENTIAL_CONSTRAINTS`, then correlates active objects with every migration source and code reference. Broad lexical matches are retained for investigation, while schema-removal evidence is restricted to production SQL literals that contain the table and column in the same statement fragment. Test references are tracked separately. The result is stored as structured JSON under `cache/` and is never regenerated during ordinary dashboard rendering.

Logical cleanup is allow-listed. Each rule has an explicit table, category, confidence, reason, count query, deterministic identifier query, bounded delete query, survivor policy when applicable, and `filesystem_effects = false`. Cleanup state is persisted in `app_settings` so one web request executes at most one bounded rule batch and retries remain idempotent. Unknown tables and tables without proven ownership semantics are disabled by default. Logical cleanup, schema repair, and `OPTIMIZE TABLE` each expose a first-class dry-run path that executes no deletion, DDL, or table operation.

For live cleanup, the selected row identities and deletion execute in one transaction. Before commit, the service writes an immutable row to `database_maintenance_audit_log` containing the operation, rule, reason, identity columns, every removed identifier, and affected count. Identifier-count mismatch or audit insertion failure raises an error and rolls back the deletion. The ordinary Admin log receives only a secondary summary.

Schema repair and data cleanup are independent. DDL uses a new timestamped migration and checks each object before alteration. The Admin repair action refuses to invoke the general migration runner while unrelated migrations are pending. MySQL and MariaDB DDL auto-commit is treated explicitly, so irreversible DDL is not wrapped into the same opaque request as row deletion.

`ANALYZE TABLE` and `OPTIMIZE TABLE` are selected-table actions. `ANALYZE` refreshes optimizer metadata. `OPTIMIZE` is separately confirmed, may rebuild or lock a table, and is never run by inspection, cleanup, normal page loads, or migrations.

## Settings System

Public tag-page presentation is stored in app_settings. The pagination service resolves the dedicated grid columns and rows with global pagination as the compatibility fallback, while the description-layout service resolves the dedicated vertical or horizontal card design with the global Theme card layout as fallback. The public tag controller applies these values only to tag-result pages. The Edit tags contextual link carries the Appearance subsection target so the Theme page opens Gallery tags directly.

DB-backed settings live in `app_settings` and are accessed through `app/services/app_settings.php`.

Core helpers:

```php
app_setting(string $key, ?string $default = null): ?string
set_app_setting(string $key, ?string $value): void
delete_app_settings(array $keys): void
```

Settings are used for URL rewrites, site name, dev mode, collapsed admin state, public search, theme behavior, telemetry preferences and related runtime options.

`public_thumbnail_rendering_mode` is a scalar site setting owned by `app/services/public_thumbnail_rendering.php`. Its only machine values are `responsive` and `progressive`; missing, empty, unknown, malformed, or obsolete values normalize to `responsive`. Admin Theme persists the setting after the existing administrator and CSRF checks. No schema migration is required because the setting uses the existing `app_settings` key/value table.

Theme favorite shortcuts are stored as a JSON array in `theme_favorite_gallery_ids`. The array may contain numeric gallery IDs and the `home` token for the main gallery page. `app/services/favorite_galleries.php` normalizes the value, removes duplicates, validates that selected galleries still exist before saving, and resolves public header navigation items in configured order. Anonymous visitors only receive gallery shortcuts that remain public and listed; the main page shortcut is always safe to render.

Gallery hero tag presentation also uses the existing `app_settings` table and therefore needs no schema migration. `app/services/theme.php` owns normalization and defaults for `theme_hero_tag_visible_limit` (default 20, range 1 to 200), `theme_hero_tag_display_all` (default off, so progressive disclosure is active), `theme_hero_tag_scrollbar_enabled` (default on), `theme_hero_tag_scrollbar_rows` (default 5, range 1 to 12), and `theme_hero_tag_sort_mode` (`usage` or `alphabetical`, default `usage`). `app/controllers/admin_theme.php` persists the values after the existing Admin and CSRF checks and bumps `theme_public_content_revision` when this public rendering policy changes.

`app/services/tag_metadata.php` owns hero usage sorting. Usage is the sum of direct `gallery_tags` and `image_tags` assignments, restricted to tag IDs required by the current hero. The service sorts direct and contained tag groups independently, with usage descending and natural case-insensitive name ordering as the tie-break. `app/controllers/public_gallery.php` emits every tag in the HTML plus validated data attributes. `public/assets/gallery-modules/hero-tags.js` progressively hides tags above the initial limit, expands/collapses them without requests, and measures actual wrapped rows before applying a scrollbar. No-JavaScript rendering remains complete because the server never omits tags for this feature.

When adding a setting:

1. Add a named helper when the setting has validation or conversion rules.
2. Use `app_setting()` only for simple scalar settings.
3. Keep defaults in service functions, not in templates.
4. Save from admin controllers after CSRF validation.
5. Do not create new config.php values for mutable runtime options.

## Browser-Prepared Upload Path

The default upload form uses browser-side preparation when the browser pipeline is enabled and selected for the request. Selected files are prepared in the browser, including originals, responsive thumbnails, and client-read metadata, then batched into store-only ZIP archives using the admin ZIP size as a soft packing target and the admin maximum-images-per-batch cap. If one atomic image package (original plus its prepared thumbnails) is larger than the normal ZIP target, it is emitted as a singleton batch; the detected PHP upload limit remains the hard ceiling. Each batch posts to `admin_upload_browser_batch`. The server remains authoritative for CSRF validation, gallery ownership, ZIP validation, final filename selection, unpacking and thumbnail metadata registration. The per-upload checkbox is checked by default. Unchecking it before submission explicitly selects the normal server-side `admin_upload` path. When browser preparation remains selected and files are present, a browser capability or preparation failure stops before persistence instead of silently changing the selected execution path. The browser JSON endpoint also detects PHP-discarded multipart bodies and returns a JSON 413 response when PHP receives an empty request after upload limits are exceeded.

The browser implementation lives in `public/assets/gallery-modules/admin-browser-upload.js` and `public/assets/gallery-modules/browser-image-worker.js`. The server orchestration and guard logic live in `app/services/browser_uploads.php`; the dedicated settings view lives in `app/views/admin_upload_settings.php`. Controllers only handle HTTP validation, persistence orchestration, and response formatting.

When browser-assisted upload is selected, the file input also accepts user ZIP archives such as iCloud Photos exports. Archives are inspected and expanded entirely in a short-lived browser worker before the normal image-preparation pool runs; the original user ZIP is never posted to PHP. The parser reads the central directory, supports stored and Deflate entries, skips directories, hidden metadata, encrypted entries, unsupported compression and unsupported media, and verifies image signatures. It refuses traversal paths, multi-disk/ZIP64 structures, excessive entry counts, oversized entries, suspicious compression ratios, invalid boundaries, and excessive total expansion. Extracted supported images then follow exactly the same thumbnail, manifest, bounded-batch, server validation, access, and mutation-schema path as individually selected images. A ZIP selection cannot fall back to classic PHP upload.

The same browser settings also control the optional browser-assisted thumbnail rebuild path exposed from the admin maintenance thumbnail card. The normal server-side job remains the default. When enabled, the server streams originals as deterministic store-only source ZIP chunks; browser workers create thumbnails and upload prepared derivative batches. The server remains authoritative for image identity, source selection, thumbnail paths, payload validation, final writes, and metadata refresh. Bounded repair passes can revisit missing-thumbnail inventory after the main pass.

## Migration Compatibility and Repairs

`app/migration_definitions.php` normalizes both modern migration definitions and legacy plain SQL lists. `app/migration_repairs.php` contains reusable transactional repairs for data transformations that cannot be expressed safely as SQL alone. This compatibility layer matters during rolling or partial deployments: an older runner may directly `require` a migration file while a newer runner loads its definition first.

Repair migrations return an empty SQL list to the legacy runner and execute their callback while the legacy `$pdo` variable is in scope. The current runner executes the same callback through the validated `after` definition. A migration version is recorded only after the callback succeeds.

## Updater Safety

`app/services/updates_jobs.php` is the canonical installer engine for stable updates, beta installs, stable restores, clean reinstalls, rollback, Admin button requests, pure-PHP entry points, and automatic background updates. Legacy functions in `updates_install.php` now start a durable job instead of downloading, extracting, copying, migrating, and cleaning in one request.

### Durable state machine and checkpoints

Release jobs use the ordered stages `download -> archive_validate -> extract -> package_validate -> plan -> stage_files -> backup -> ready -> activate -> migrate -> finalize -> cleanup -> completed`. Job JSON, progress, attempts, timestamps, error reference, operation parameters, and checkpoint cursors are written atomically below `cache/updates/jobs/<job-id>/`. The `cache/` access rules deny direct web access. A small `active-job.json` pointer prevents a second update from starting while a resumable job exists, and `last-job.json` retains the last completed update for rollback controls.

Normal worker invocations use a wall-clock budget of at most 12 seconds and normally 7 to 8 seconds in Admin or CLI, with smaller request-triggered background slices. If PHP has a finite `max_execution_time`, the requested budget leaves a five-second reserve. Enabled request-time automatic discovery is throttled to once per hour per installation; GitHub rate-limit headers and server-provided backoff windows remain authoritative. Remote release-metadata discovery is separately bounded to approximately eight seconds and divides the remaining budget across unprobed stable branches; a discovery invocation that creates a background update does not also download package bytes. Remote patch-note refresh uses a five-second transport timeout and falls back to bundled notes. These are scheduling limits, not promises: reverse-proxy, FastCGI, web-server, browser, and hosting control-plane limits cannot be discovered reliably from PHP. The updater records whether `set_time_limit()` and `ignore_user_abort()` exist but never calls them for correctness.

The `download` stage streams the ZIP to disk and resumes with HTTP Range when supported. The first trusted archive URL is persisted for the job. Response `ETag` or `Last-Modified` validators are retained and resumed requests send `If-Range`; if a stable branch head changes and the origin returns a fresh `200`, the local partial archive is truncated before new bytes are accepted. Completion is checked against the known response size when available. `archive_validate` rejects unsafe absolute/traversal paths, symbolic links, more than 20,000 entries, files above 32 MiB, and more than 512 MiB total uncompressed data. Its central-directory scan checkpoints every 500 entries. `extract` writes one entry through a `.part` file and checkpoints the entry index. `package_validate` requires the complete PHP Gallery source-root contract, verifies every `app/core-manifest.json` hash in bounded batches using streaming hashes, checks version markers and the selected stable target floor, and rejects any installable release file missing from the manifest. A deterministic manifest/hash/version mismatch is marked non-retryable: the Admin must cancel it and choose a newly published build instead of repeatedly downloading identical invalid bytes.

The Admin renders synchronized durable job cards in both the Status and Advanced tools tabs. Browser updates fan each checkpoint result out to both copies, so beta, restore, and reinstall operations retain their progress surface in the tab where they started. Reopening Updates resumes a running job from its persisted checkpoint.

`plan` computes the complete managed-file and obsolete-path set without modifying the active installation. Normal planning enumerates updater-owned application roots only and prunes protected gallery, cache, custom-data, and hosting paths before recursion. It SHA-256 compares incoming files with active files and excludes byte-identical files from activation, which minimizes the later critical section. File-versus-directory conflicts and managed symbolic-link destinations are rejected before backup. `stage_files` copies and hashes only new or changed release files into the job's private `ready/` tree. `backup` snapshots file-level paths that can actually change into `rollback/original/`, records activation files that did not previously exist, and persists pre-update update-channel settings. An individual managed active file above 128 MiB is rejected instead of beginning an unbounded snapshot copy. Obsolete directories are not recursively copied as one potentially unbounded backup unit because their child files are already represented individually. Only after all three stages finish can `ready` pass.

### Activation critical section

`activate` is intentionally the only non-yielding stage. Its frozen plan contains only files proven to differ during preflight. It performs no network access, ZIP extraction, package-wide verification, rollback construction, or database migration. Each prepared file is copied to a sibling temporary file, hash-checked, and committed with `rename()`. Dependencies are committed before bootstrap and public entry points. On replay, a destination whose SHA-256 already equals the prepared file is treated as completed, so a killed activation can continue without rewriting work already committed. Obsolete managed paths are removed only after prepared replacements have been processed.

Immediately before publication, the updater atomically writes `cache/updates/activation.json`. The early runtime checks this marker before loading the mutable application graph and returns `503 Service Unavailable` with `Cache-Control: no-store` while activation is incomplete. Corrupt or incomplete marker state fails closed. The gate clears only after durable activation completion is recorded, while the authenticated updater recovery/status path remains reachable so an interrupted activation can continue.

A portable PHP application on ordinary shared hosting cannot atomically exchange an entire non-symlink directory tree. Therefore a process termination inside activation can still expose a short mixed-version tree. The rollback snapshot is already complete before that critical section begins, and the same activation stage is retry-safe, but eliminating this final window would require a different deployment architecture such as versioned release directories plus an atomic symlink/document-root switch, which many shared hosts do not provide.

### Migrations, rollback, and recovery

The updater calls `run_migrations_bounded(1)`. `schema_migrations` is the durable migration-file checkpoint. The complete pending definition set is validated before the first pending migration is applied, and only one migration file is attempted per updater checkpoint. The version row is written only after that migration's SQL and optional repair callback complete. If a process dies after an individual statement or callback succeeds but before the version row is recorded, the migration file can replay. Migration authors must therefore use duplicate-safe DDL, predicates/uniqueness for DML, and idempotent repair callbacks. The current migration corpus follows that contract.

File rollback is itself a resumable job. It uses the source job's durable pre-update snapshot as its prepared source, takes a new snapshot of the current files before restoring, and uses the same `plan -> stage_files -> backup -> ready -> activate -> migrate -> finalize -> cleanup` tail. The rollback migration stage is an explicit no-op because the application has no general reverse-migration framework. Administrators must treat migrations as forward-only and restore the database from an external backup when a release requires schema reversal.

Failures before `activate` leave active application files untouched. Retry of download/archive/extraction/package-validation failures discards untrusted package artifacts, validators, and extraction checkpoints and restarts from a fresh trusted archive URL. Failures after validation retain prepared release and rollback data and retry the failed checkpoint. A failed or running pre-activation job may be cancelled, which removes transient artifacts and frees the active-job slot without changing application files. Cancellation is forbidden after activation starts; post-activation recovery is resume or rollback. A failed post-activation job can be rolled back. Worker execution uses non-blocking per-job `flock()` plus a global start lock around ownership changes. A worker refuses to mutate a non-active job, retry/cancel cannot steal ownership from another active job, and completion clears `active-job.json` only if the pointer still names that completing job. Lock-file JSON is diagnostic only, so a terminated PHP process releases the real kernel lock automatically and stale metadata does not require unsafe lock deletion.

### Browser and background execution

`app/controllers/updates.php` uses the same engine for the normal Admin update button and recovery controls. `public/assets/gallery-modules/admin-update-jobs.js` uses delegated submit handling so dynamically inserted side-panel forms are intercepted too. It sends one authenticated CSRF-protected continuation request at a time, updates the job card in place, and automatically resumes a running job when the update UI is opened again. Ordinary POST/redirect forms remain the JavaScript-disabled fallback.

Automatic stable updates also create `trigger=background` jobs. Discovery and package processing are deliberately separate invocations, so a slow metadata probe cannot be followed by a second long package slice in the same request. Safe normal page requests may advance a small slice through `application_autoupdate_maybe_run()`. Caught background failures wait at least 60 seconds, then pass through `application_update_retry_job()` so package-stage failures discard untrusted partial artifacts before another worker attempt. `scripts/application_update.php` provides a bounded CLI cron runner for idle sites. Unattended background runners refuse to advance Admin/manual jobs, including beta installs. A true long-lived background worker is not assumed on shared hosting.

The standalone `setup-gallery.php` first-install bootstrap is intentionally outside this engine because the application, authentication layer, and updater services do not exist yet. It still downloads/extracts/copies in one bootstrap request, so a host-level timeout can require retrying the bootstrap or uploading the release manually. This does not endanger an existing installation because the bootstrap refuses to run once `config.php`, `cache/installed.lock`, or `cache/bootstrap-installed.lock` exists, but it remains a shared-hosting limitation distinct from application updates.

Cleanup does not infer that an unknown `app/` entry is invalid. Only known nested project-copy artifacts such as `app/app`, `app/public`, and `app/index.php` are targeted by the dedicated malformed-deployment cleanup, protecting legitimate top-level modules added by later releases.

## Security Model

Security code is centralized in `app/security.php` and access-focused services.

Expected patterns:

1. Admin state is session based.
2. Durable login uses selector/token cookies stored in `admin_remember_tokens`.
3. Password reset tokens are stored hashed.
4. Google login requires prior account linking in `user_google_accounts`.
5. Protected galleries use password hashes or share-token hashes.
6. CSRF checks are required for admin mutations.
7. Public media routes must verify gallery and image visibility before streaming.
8. Output HTML must be escaped through helper functions.
9. Hidden form fields are not trusted.
10. Uploads must validate file type and destination ownership server-side.

### Dormant viewer identity and security foundation

Migration `202608180001_viewer_security_foundations.php` and the `viewer_*` services prepare a separate future viewer identity domain without exposing any viewer route or changing existing administrator behavior. The boundary is explicit: `users`, `$_SESSION['user_id']`, `current_user()`, administrator persistent login, and `require_admin()` remain administrator-only; future viewer state uses `viewer_accounts`, `$_SESSION['viewer_auth']`, `viewer_sessions`, and `current_viewer()`. Viewer services fail closed while `viewer_accounts.enabled` is not explicitly `true`.

This separation is security-critical because `visitor_can_access_gallery()` intentionally treats a valid historical `current_user()` as a trusted administrator and grants broad gallery access. That rule is unchanged. `current_user()` never consults viewer tables/session state, and existing gallery/media authorization does not call `current_viewer()`. **Viewer authentication must never satisfy administrator authorization.**

Future favourites and collections store canonical `images.id` references only. They do not store gallery visibility, passwords, share capabilities, or permission snapshots. **A collection reference is not an authorization grant.** Every future read of a referenced image must still pass the canonical gallery/media authorization layer at request time. A future collection share token can grant access only to the collection container, never to the underlying image or gallery.

The foundation includes native password helpers, hashed opaque-token helpers, server-side revocable viewer sessions, selector/verifier remember-token storage, account security-version invalidation, structured viewer security events, explicit trusted-proxy client-IP handling, bounded viewer abuse-control storage, indexed cleanup, future quotas, collection-reference tables, collection share-token storage, and passkey public-credential schema. Existing administrator throttling, administrator reset tokens, CSRF behavior, share links, session naming/cookies, security headers, controllers, routes, JavaScript, and CSS are intentionally unchanged.

Phase 0.5 extends this dormant boundary with migration `202608180002_viewer_registration_foundations.php`, `app/services/viewer_registration.php`, and `app/services/viewer_mail.php`. Anonymous registration intent is staged in `viewer_registration_requests`, never directly in `viewer_accounts`. One binary-unique normalized email maps to one expiring staged row, and a separately locked `viewer_registration_state` counter imposes a hard configurable row cap under concurrency. `viewer_invitations` stores a high-entropy token hash, optional administrator-visible intended email, its HMAC authorization binding, and administrator creator id. Invitation claiming and verification confirmation use database row locks; link validation is deliberately non-consuming so mail scanners cannot complete verification by issuing a GET-like request.

Phase 0.5 still creates no viewer account, route, controller, page, JavaScript, CSS, or email. Verification inspection is scanner-safe/non-consuming, and explicit confirmation reaches only the ephemeral `email_verified` staging state. Future email delivery must first pass `viewer_mail_authorize_send()`, which reserves independent recipient/client/global budgets through the existing bounded viewer rate limiter. The viewer mail module intentionally contains no `mail()`, SMTP, provider API, queue, or worker integration, and the existing administrator password-reset mail path is unchanged.

Phase 0.6 adds only route-free authentication transitions. Explicit verification confirmation now also creates a short-lived HMAC-bound `viewer_registration_activation` PHP-session grant after rotating the PHP session id. `viewer_registration_activate_verified()` accepts no client request id, re-locks staging/invitation authority, serializes a hard durable-account cap through `viewer_account_state`, applies the 15-character native password policy, inserts exactly one active verified viewer account, and retires staging data without auto-login. `viewer_authenticate_password()` applies IP/subnet/identifier/global throttles before account lookup/password verification, uses generic public failures, optionally rehashes a successful password, and establishes only `viewer_auth`.

Viewer mutation authority has its own `viewer_csrf_token` namespace. Active viewer sessions and remember credentials are capped per account under account-row locking; successful remember restoration atomically rotates selector/verifier authority before establishing a normal viewer session and emits no cookie. Password-reset inspection is read-only, explicit reset authorization uses separate `viewer_password_reset` pre-auth state, and final reset locks account/token state, changes the password, increments `security_version`, consumes/invalidates reset tokens, and revokes sessions/remember credentials without automatic login. Suspend/disable/restore transitions revoke existing viewer authentication/reset/share authority and never resurrect old credentials.

Viewer authentication does not use the historical forwarded-header behavior of `request_is_https()`. A stricter viewer transport resolver accepts forwarded protocol only from an explicitly configured trusted proxy and only for explicitly enabled `trusted_proxy_protocol_headers`; `viewer_accounts.require_https` defaults true. Future viewer security links derive authority only from validated configured `base_url`, never request `HTTP_HOST`. Viewer/pre-auth session-state presence forces the existing no-store cache path without a viewer database lookup. Scheduled viewer expiry/retention cleanup continues while viewer capability is disabled, provided the viewer schema is confirmed available.

Phase 0.7 completes the remaining route-free account lifecycle boundary. `app/services/viewer_lifecycle.php` separates a short-lived `viewer_reauthentication` authority from ordinary `viewer_auth`, binds it to account, `security_version`, and the current server-side viewer-session row, and deliberately does not establish it during remember-token restoration. Internal password change rotates `security_version`, invalidates reset authority, revokes sessions/remember credentials, and requires normal login again. Verified email change is staged in `viewer_email_change_requests`: inspection is non-consuming, explicit token verification establishes separate short-lived server-side confirmation authority, and the final transaction re-locks both account and request before switching the binary-unique normalized email. Physical account deletion revokes cross-owned collection-share capabilities, relies on the existing ownership cascades for account data, preserves security events only through `ON DELETE SET NULL`, and reconciles the durable account counter inside the same transaction.

Phase 0.7 also adds a narrow content-security boundary in `app/services/viewer_content_foundations.php`. Future favourite/collection code must resolve source images from authoritative storage and call the existing gallery/share/password/NSFW rules through the explicit no-administrator-bypass path. Viewer identity is never media permission, and stored references never preserve a prior access decision. The same service defines plain-text validation for future viewer labels and a centralized, strictly bounded future content-quota contract. `tests/viewer_phase07_mysql_concurrency_test.php` is an optional real MySQL/MariaDB race harness; it uses independent processes/connections when configured and reports an explicit skip when `pdo_mysql` or the test DSN is absent.

Detailed invariants, threat mapping, password/session/reset/lifecycle state machines, trusted proxy/origin rules, personal-data inventory, registration staging, mail-abuse budgets, content-authorization rules, and deferred visible phases are documented in `docs/VIEWER_SECURITY_FOUNDATIONS.md`.

## Authentication and Google Login

Durable admin login is implemented by:

```text
app/services/auth_persistence.php
app/controllers/admin_auth.php
database/migrations/202605310001_admin_persistent_auth_and_google_login.php
```

Google login is prepared by:

```text
app/services/google_auth.php
app/controllers/admin_auth.php
user_google_accounts table
```

Google login is intentionally account-link based. A Google account should be linked to an existing admin account before it can authenticate that admin.

## Gallery Access Model

Gallery visibility and access are stored mostly on `galleries`:

| Field | Meaning |
| --- | --- |
| `visibility` | `unpublished`, `public`, or `private`. Some migration compatibility logic references older `draft`. |
| `access_mode` | `normal` or `password`. |
| `access_listing` | `listed` or `unlisted`. |
| `access_password_hash` | Password hash for protected galleries. |
| `access_share_token` | Legacy or display token field. |
| `access_token_hash` | Hash of share token used for lookup. |
| `access_token_expires_at` | Optional expiry. |

Use `app/services/gallery_access.php` for checks rather than repeating access logic.

## Public Search

Public search is implemented by:

```text
app/controllers/public_gallery.php
app/services/public_search.php
public/assets/gallery.js
```

Search supports minimum query length handling, context-limited search inside the current gallery branch, gallery matches, image matches, tags and compact text. AI-generated searchable text can participate through the metadata tables where available.

Admin setting routes:

```text
admin_public_search_settings
admin_theme
```

## Theme and Branding Model

Site-level theme logic is in:

```text
app/controllers/admin_theme.php
app/controllers/theme_assets.php
app/services/theme.php
app/services/tag_metadata.php
app/services/custom_css.php
app/services/favicon.php
public/assets/gallery-modules/hero-tags.js
public/assets/styles.css
custom_css/*.css
```

Gallery-level branding logic is in:

```text
app/services/gallery_branding.php
app/controllers/public_gallery.php
app/controllers/public_media.php
```

Supported branding concepts include:

1. Site background.
2. Site logo and separator assets.
3. Gallery banner, logo and separator assets.
4. Separator width, height and stretch behavior.
5. Page width mode and custom width.
6. Grid columns and rows.
7. Gallery description layout.
8. Count badge visibility.
9. Lightbox browsing mode.
10. Thumbnail size bounds.
11. Gallery hero tag disclosure, ordering and row-based scrolling.
12. Custom CSS presets.

## Lightbox Browsing Mode Model

The public lightbox supports three browsing modes:

1. `single`, the legacy focused single-image lightbox.
2. `picture_strip`, the picture-strip lightbox with a centered primary image and de-emphasized nearby images below it.
3. `3d_carousel`, a focused layered carousel where a small number of neighboring images sit behind the active photo on the left and right.

The older `strip` value is accepted as a legacy alias and normalized to `picture_strip` before new settings or sidecars are written.

Configuration is split across Theme defaults and per-gallery overrides:

```text
app/controllers/admin_theme.php
app/controllers/admin_galleries_edit.php
app/services/gallery_lightbox_mode.php
app/services/theme.php
app/services/gallery_sidecars.php
```

`theme_lightbox_browsing_mode()` reads the site-level default from `app_settings.theme_lightbox_browsing_mode`. `gallery_effective_lightbox_browsing_mode()` resolves the effective public mode by reading the nullable `galleries.lightbox_browsing_mode` override first, then falling back to the Theme default, then to `single`. A `NULL` gallery value means inherit, matching the existing description-layout and count-badge override model.

Public rendering emits the resolved value as `data-lightbox-browsing-mode` from `app/controllers/public_gallery.php`. Browser behavior is owned by `public/assets/gallery-modules/lightbox.js`; the visual treatment is owned by `public/assets/styles/lightbox.css` and mobile fallback rules in `public/assets/styles/mobile-gallery.css`. The JSON lightbox endpoint remains unchanged, so slideshow, fullscreen, map overlays, votes, admin inline editing and image ordering continue to use the existing metadata pipeline. Both enhanced modes share the same neighbor-selection logic in JavaScript, while CSS decides whether the neighbors render as a flat strip or as a layered 3D carousel.

The `picture_strip` and `3d_carousel` modes select adjacent images from already-rendered gallery cards and lazily request sparse lightbox metadata when a neighbor is missing. Neighbor previews are preloaded opportunistically and navigation remains index-based, so paginated or partially hydrated lightbox data degrades to the available nearby photos instead of failing. The carousel skips rendering a duplicate active thumbnail, because the main stage itself is the active state.

Gallery-map photo markers use the same viewer pipeline. Map payloads carry the canonical authorized photo-page URL plus image and gallery identifiers. When a selected image belongs to the active physical gallery but is outside the current pagination window, `gallery_lightbox_data` accepts `target_image_id`, resolves its position through the same authorized ordering, and returns a bounded window containing that image. The public SEO guard permits this parameter only on that endpoint. The browser merges that window into the existing sparse `cards` cache and opens the lightbox item without rebuilding metadata from DOM cards. Cache resets remain explicit setup, close, and authoritative order-change boundaries. A fullscreen split map remains mounted while only the photo pane changes; a body-level map overlay closes before the lightbox is revealed. Each popup selection owns an abort controller from click scheduling through target lookup and commit. A newer selection, ordinary viewer navigation, map/viewer closure, fullscreen exit, or component teardown cancels that intent; cancelled or superseded work cannot merge target metadata, change the photo, or navigate to a fallback. Target requests are separate from shared nearby-range requests, so superseding a selection does not cancel unrelated preloads. Cross-gallery, unavailable, or genuinely failed enhanced targets retain the canonical photo-page fallback while their selection is still current. A map marker never becomes a separate media-authorization route.

Persistence details:

```text
database/migrations/202606010001_gallery_lightbox_browsing_mode.php
database/migrations/202606010002_gallery_lightbox_browsing_mode_carousel.php
galleries.lightbox_browsing_mode ENUM('single','picture_strip','3d_carousel') NULL
gallery.json key: lightbox_browsing_mode
```

## Lightbox Client Zoom Model

`app/controllers/public_gallery_lightbox.php` renders one semantic zoom-control group in the normal toolbar and a mirror
for the existing fullscreen HUD. Both control the same server-rendered `data-lightbox-stage`; there is no parallel viewer.
The stage contains one `.lightbox-zoom-surface`, which is the fitted photograph frame, and the active
`data-lightbox-img`. At 100% the surface is explicitly centered on the fitted photograph. Above 100% its real CSS width and
height grow from those fitted dimensions, while a translation-only transform moves the enlarged frame for pan. The image
fills the surface instead of being independently compositor-scaled.

`public/assets/gallery-modules/lightbox-zoom-model.js` owns browser-independent constants and geometry. Scale is clamped to
`1..4`, discrete controls use `0.25`, and translation is bounded from the fitted 100% image dimensions, current scale,
viewport size, and current pan. `zoomLightboxStateAtPhotoAnchor()` preserves the exact fractional photograph point under a
pointer or pinch midpoint. It derives the current rendered rectangle from canonical model state, not from
`getBoundingClientRect()` during the 120 ms CSS transition, so rapid repeated zoom cannot mix an intermediate visual frame
with already-committed JavaScript state and drift toward a corner. `tests/lightbox_zoom_model_test.mjs` exercises bounds,
pan clamping, centered/fractional anchors, repeated off-center zoom through 400%, and quality-candidate math without a DOM.

`public/assets/gallery-modules/lightbox.js` owns DOM state, status synchronization, wheel input, pointer pan, pinch capture,
fullscreen remeasurement, and teardown. Wheel/trackpad input resolves the pointer against the current stage, while
keyboard/discrete controls use the remembered in-stage pointer only when one is valid and otherwise fall back to the image
center. `openAt()` is the reset boundary for direct opens, previous/next, picture-strip, 3D-carousel, lazy hydration,
mobile swipe, and slideshow advance. `close()` clears zoom/pointer state. Pure fullscreen entry/exit preserves scale and
reclamps translation. At 100%, the established one-finger horizontal swipe remains the navigation owner; above 100%, one
pointer pans and two touch pointers own pinch.

Normal lightbox and fullscreen intentionally clip differently. The ordinary lightbox allows the enlarged zoom surface to
extend beyond the original fitted stage so the photograph frame grows with zoom instead of behaving like a fixed window.
Fullscreen keeps the stage as the clipping viewport and computes both horizontal and vertical pan bounds from the fitted
100% photograph, which allows vertical inspection of wide images as soon as scale creates additional photograph area.
Fullscreen HUD and close controls have an explicit stacking order above the transformed zoom surface so hit testing does
not disappear while zoomed. Map, strip, carousel, voting, help, and navigation controls remain outside the photo zoom
target.

Progressive zoom quality reuses the authorized preview and browser-displayable media URLs already owned by the public
renderer. `lightbox_zoom_quality_candidates()` in `app/services/thumbnail_bundles.php` attaches bounded source dimensions
to those URLs, preserving aspect ratio and collapsing duplicate fallback URLs. Visible physical and Smart Gallery cards
emit the list in `data-lightbox-quality-sources`; `app/controllers/gallery_lightbox.php` emits the same `quality_sources`
model for lazy pagination. Neither path exposes a filesystem path or constructs a new original-file route, and the lazy
endpoint still performs gallery and per-image NSFW checks before returning media metadata.

At exactly 100%, passive quality evaluation computes demand from fitted CSS image width, bounded device density, and a 1.5
rendering-detail factor. This can promote a large ordinary desktop stage or high-DPI display while allowing smaller stages
to retain the generated preview. Deliberate zoom is stricter: the first button, keyboard, wheel, trackpad, or pinch action
that raises scale above 100% synchronously assigns the already-authorized `data-full-src` to the live
`data-lightbox-img` in that same input task. Pending preview transitions and passive quality work are invalidated first;
`srcset`/`sizes` cannot override the explicit source. The request does not wait for resize, fullscreen, a quality debounce,
a detached image decode, or an animation frame.

The live image keeps its existing DOM identity while the original transfers. Loading/error listeners are scoped to the
active image ID, navigation token, and quality token. Success keeps the current zoom surface geometry and clears the
loading state; failure restores the protected preview and leaves controls usable. Full originals are never eagerly queued
for neighboring photos. While a larger source is pending, `setLightboxQualityLoading()` applies `aria-busy`, translated
loading text, and the existing pointer-transparent activity indicator; navigation, close, teardown, success, and failure
all clear it.

`public/assets/styles/lightbox.css` owns the centered/growing zoom surface, normal-lightbox overflow, fullscreen clipping,
cursor states, HUD stacking, focus, and reduced-motion behavior. `public/assets/styles/mobile-gallery.css` keeps controls
inside the existing safe-area toolbar. Zoom has no database, URL, cookie, storage, or telemetry state. Existing
gallery/media, share, NSFW, and lazy-lightbox authorization remains authoritative, and the unenhanced server-rendered path
is unchanged.

The shared browser entrypoint revision is content-sensitive across its dependency graph. `asset_dependency_revision()`
hashes dependency bytes instead of taking only the greatest modification time, so a changed `lightbox.js` always produces
a new module URL even when another asset has a newer or future timestamp.

## Thumbnail Model

Thumbnails are generated and served through the thumbnail service family.

Important concepts:

1. Thumbnails are derived files, not source data.
2. Source images remain in gallery folders.
3. Generated thumbnails can be JPEG and sometimes WebP depending on source and PHP imaging support.
4. Thumbnail bounds can be configured globally, per gallery and per image.
5. Admin maintenance screens can generate or delete thumbnails. Gallery-scoped thumbnail rebuilds launched from an injected gallery-editor side panel remain browser-batched, but the final JSON batch also emits the canonical mutation envelope for that gallery. The affected context set includes the edited gallery plus its owning parent/root so a repaired cover thumbnail is refreshed whether the drawer was opened from inside the gallery or from its gallery card. `admin-thumbnail-progress.js` keeps ownership of progress markup and forwards only those affected public contexts to the shared side-panel completion coordinator. The direct-page POST/redirect path remains unchanged, and JSON authentication/CSRF failures stay JSON instead of falling through to the Admin login/plain-text response path.
6. Scheduled site maintenance calls the same thumbnail generation service in bounded cron-safe batches, records progress after each image, reuses valid existing thumbnails and only repairs missing, stale or invalid-ratio variants. Automatic maintenance runs only inside the configured UTC window and can chain safe web slices until the cycle completes or the window ends.
7. Public thumbnail cards always keep server-rendered semantic picture/img markup; renderer policy only changes how candidate URLs become active.

### Public Thumbnail Rendering Pipelines

`app/services/public_thumbnail_rendering.php` is the renderer-selection boundary for selected-gallery photo cards. It owns the setting key, allowed values, safe default, normalization, mode-specific initial loading policy, and final choice between `thumbnail_picture_html()` and `thumbnail_progressive_picture_html()`. `app/controllers/public_gallery.php` does not duplicate card rendering or perform renderer string comparisons. Access checks, URLs, lightbox attributes, votes, maps, tags, pagination, thumbnail bundles, and media-manifest preparation remain shared.

The **progressive** pipeline is the site default and fallback for selected-gallery photo cards. The **responsive** pipeline remains the permanent legacy option. `thumbnail_picture_html()` emits the complete available WebP/JPEG srcsets and the existing `sizes` hint during responsive server rendering, with the 300 px request as the preferred fallback. The browser therefore chooses the suitable bounded/generated candidate during HTML parsing. The responsive loading policy is unchanged: cards 1 and 2 are eager/high priority, cards 3 through 8 are eager/auto, and later cards are lazy/low. JavaScript is not required.

The **progressive** pipeline is permanent and is now the Admin-facing default. `thumbnail_progressive_picture_html()` emits a real small `src` and small-only active srcsets, plus larger bounded candidates in `data-progressive-srcset`. The first small card is eager/high, the second is eager/auto, and later small thumbnails are native lazy/low. Stored `display_width`/`display_height`, with legacy `width`/`height` fallback, are emitted as intrinsic dimensions without opening originals during rendering. Existing public thumbnail background styling provides a stable painted placeholder, and progressive code intentionally adds no required animation, so reduced-motion users do not receive extra motion.

Browser activation is conditional. `public/assets/public-gallery.js` dynamically imports `progressive-thumbnail-renderer.js` only when progressive markers exist on anonymous markup; logged-in public pages use the same conditional import from `gallery.js`, while Admin-only pages do not load it. Two IntersectionObservers distinguish visible cards from a 720 px near-viewport margin. Larger work is scheduled only after the small image loads, prefers `requestIdleCallback` with a bounded timeout, and falls back to a short timer. A single exported concurrency constant limits preload/decode work to 2. Visible queued cards are prioritized over merely near-visible cards, duplicate jobs collapse, disconnected nodes are removed, and ResizeObserver can reconsider only currently relevant cards.

`progressive-thumbnail-upgrade.js` measures the rendered card width, multiplies by device pixel ratio capped at 2, and selects the smallest adequate available candidate above the currently active width. It uses the native `Image` loader and `decode()` where available. Live `source/srcset` or `img/srcset` attributes change only after the replacement is ready. Failed upgrades leave the small image untouched. No `fetch()`, Blob URL, byte-progress mechanism, page reload, or navigation is involved. Teardown aborts late DOM mutations and disconnects observers before reinitialization.

The renderer setting currently applies only to selected-gallery photo cards. Subgallery covers, subgallery collage cells, home-page gallery cards, search/card contexts that reuse gallery covers, and Admin/maintenance thumbnails remain responsive intentionally. This avoids repaint waves in collage cells and keeps the primary feature boundary narrow.

Both pipelines consume the same request-local `thumbnail_bundles.php` variants prepared through `public_gallery_media_manifest.php`, respect `thumbnail_bounds.php`, and preserve `thumbnail_warmup.php` authorization metadata. SEO remains server-rendered: image elements, alt text, direct photo/gallery links, JSON-LD, social metadata, and media URLs retain their existing ownership. Security gates run before bundle/URL construction for restricted NSFW photos, and password/gallery visibility plus media endpoint authorization are unchanged. JavaScript-disabled progressive pages remain navigable with their small thumbnails.

Use the service family instead of hardcoding thumbnail paths:

```text
thumbnail_sources.php
thumbnail_generation.php
thumbnail_bundles.php
thumbnail_html.php
public_thumbnail_rendering.php
thumbnail_maintenance.php
thumbnail_bounds.php
```


## Scheduled Site Maintenance

Scheduled site maintenance is configured from Admin > Maintenance > Media. It is enabled by default and starts a daily cycle at the configured UTC time, with 00:00 UTC as the default. The default overall maintenance window is three hours. Normal public or Admin page requests can opportunistically trigger maintenance after the page response when the current UTC time is inside that window. The triggered slice can then queue the next hidden safe slice directly, so one visitor request can keep the daily cycle moving until the gallery is finished or the window ends. This request-triggered mode does not require a visible cron URL or browser JavaScript, but a completely idle site still needs hosting cron or CLI cron.

The service `app/services/site_maintenance.php` owns the persisted state, non-waiting filesystem lock, daily completion marker, hidden public cron token, thumbnail batch cursor, UTC maintenance window, chained-slice queueing, request-trigger throttle and cleanup phase. It intentionally reuses `create_image_thumbnails_result()` so upload-time thumbnails, Admin thumbnail generation, public warmup and scheduled maintenance all use the same thumbnail code path. Maintenance mode disables the heavier Imagick WebP metadata writer and saves the cursor before each source image so one hazardous file cannot keep the cycle repeating the same earlier images.

Available runners:

```text
normal GET request to home, gallery, tag, share or Admin pages after the UTC schedule
?page=site_maintenance_cron&token=<admin-generated-token>
scripts/site_maintenance.php --quiet
```

The hidden cron endpoint must never be called without its generated token. The CLI runner is preferable when hosting exposes PHP CLI and completely unattended execution is required on a site with no traffic. The web endpoint remains available for shared hosting control panels and for the internal request-triggered runner.

## AI Metadata and OpenAI Text Assist

There are two related but distinct AI areas.

### Local or worker-based image analysis

Implemented by:

```text
app/services/ai_image_analysis.php
app/controllers/upload_automation.php
image_ai_metadata
image_ai_analysis_jobs
```

This stores machine-generated image metadata and searchable text, with queue state and worker claim management.

### OpenAI text assistance

Implemented by:

```text
app/services/openai_text_assist.php
app/controllers/admin_openai_text_assist.php
user_openai_text_settings
```

This supports admin-controlled OpenAI API key storage, model selection, optional image input permission, single-generation actions and bulk image-description generation.

Do not call OpenAI directly from templates. Go through the service/controller pair.

## SimBrief and Flight Maps

Aviation-related gallery features are intentionally modular.

| Feature | Files | Storage |
| --- | --- | --- |
| SimBrief descriptions | `simbrief_descriptions.php`, `admin_simbrief.php` | Gallery description and saved OFP artifacts. |
| Flight route maps | `flight_maps.php`, `exif.php` | `gallery_flight_maps`, `flight_map_nav_points`. |
| Navigation data | `navigation_data.php`, `navigation_data.php` controller, navdata view | `navigation_data_cache`, `navigation_data_accounts`, bundled CSV data. |

The route map should prefer explicit coordinates from OFP data when available, with local nav points or cached provider lookup as fallback.


## Admin Gallery Discovery

Filesystem gallery discovery is handled by `app/controllers/admin_galleries_discovery.php`, `app/services/admin_gallery_discovery.php`, `public/assets/gallery-modules/admin-refresh-progress.js`, and `public/assets/gallery-modules/admin-thumbnail-progress.js`. The Admin dashboard button starts an Ajax job instead of submitting a long blocking request. The service stores a short-lived session job, scans a bounded number of directories per request, tracks candidate folder paths, and returns plain-language review rows to the browser. The completed table lets the admin import folders in place, move discovered photo files into an existing gallery folder, or delete selected unmanaged folders from disk. Candidate rows show user-facing photo counts, destination previews, visibility, title-duplicate warnings, and the exact effect of the selected action. Rows that look like existing sibling gallery titles are highlighted and left unchecked by default. Metadata-only folders without supported photos are reported as ignored instead of being offered for import, which prevents empty duplicate rows from stale `gallery.json` files. Import expansion reuses the same service helper so selected folders are expanded from the selected subtree instead of rescanning the entire gallery root, filters already-known exact, case-folded, realpath, and same-title sibling matches, and refuses paths whose branch contains no supported images. Move actions only accept unmanaged discovered folders, move supported photo files into the selected existing gallery folder with unique destination names, scan the destination gallery, and remove source directories only when they become empty. Delete actions refuse known database gallery folders and remove only selected unmanaged directory trees. Thumbnail follow-up jobs are skipped when the import or move scans zero images, and Ajax failures show the concrete server error instead of a generic thumbnail failure.

## EXIF/GPS Public Display Policy

EXIF/GPS display is default-enabled globally through `app_settings.exif_gps_maps_default_enabled`. The nullable `galleries.gps_map_enabled` column stores only branch-level overrides: `NULL` inherits, `1` forces display on, and `0` forces display off. The effective state is resolved by `gallery_effective_gps_map_enabled()` in `app/services/exif.php`, which walks from the current gallery to its parents and falls back to the global default when no explicit override exists.

The Admin dashboard renders a shared EXIF/GPS defaults card through `view_render_admin_exif_gps_defaults_card()`. That card posts to `cms_admin_exif_gps_settings()`, where the admin can change the global default and reset all gallery overrides to inherited behavior. The gallery editor uses the same storage rules with a tri-state select, so the full editor and side-panel editor do not need separate GPS-display logic. Bulk gallery actions use the same nullable model for force on, force off and inherit-default operations. Public map endpoints and GPS-coordinate renderers must call `gallery_allows_gps_maps()` or `gallery_effective_gps_map_enabled()` instead of reading `gps_map_enabled` directly.

## Admin Side-Panel Interaction Contract

The existing Admin right-side panel is the primary UI surface for workflows launched through `data-gallery-side-panel-link`. With JavaScript enabled, actions inside that panel must stay inside it: submit through the existing AJAX/fetch workflow, keep the panel shell open, and update only the panel fragment plus any directly affected background UI. Side-panel actions must not redirect to their normal full-page controller route, change the browser location, or reload the page as their normal success path. Traditional POST/redirect handling is retained only for JavaScript-disabled browsers and intentional direct-page use.

Panel content is dynamically replaced, so feature modules must use delegated or otherwise rebind-safe event handling. Panel-owned form submissions must be intercepted before generic Admin handlers can fall through to full-page navigation. When changing a side-panel JavaScript module, its versioned import in the application entrypoint must also be updated so deployed clients do not reuse a stale module that lacks the current in-panel behavior. Focused tests should assert both the AJAX implementation and the absence of unintended reload/navigation paths.

A one-click/in-place workflow must not gain an unsolicited browser confirmation dialog or intermediate page. Destructive actions still require the normal server-side Admin authentication, CSRF validation, scope/authorization checks, path safety, and existing mutation services.

The shared mutation completion coordinator verifies server-rendered postconditions before replacing public fragments. For physical gallery membership, direct observation of the target card is authoritative when the target is on the fetched page. Aggregate context counts are only a fallback for paginated contexts where the target may legitimately be off-page. This prevents a freshly created child card from being rejected merely because auxiliary count metadata differs while still detecting stale off-page create/delete responses.

Server-rendered mutation forms are posted back to the browser's current origin using their rendered path and query, rather than blindly fetching an absolute configured base URL. This preserves the active Admin session when the same installation is reached through a valid host alias or scheme. JSON mutation security boundaries must return JSON for expired Admin authentication and invalid CSRF instead of redirecting to HTML or emitting plain text. Public gallery-card deletion additionally isolates accidental displayed PHP output before its JSON response; discarded output is sent to the PHP error log and, when available, the Admin log without allowing a secondary logging warning to corrupt the response again.

## Gallery Date Ranges and EXIF Suggestions

Manual gallery dates use `galleries.gallery_date` as the start date and `galleries.gallery_date_end` as the optional end date. The public renderer keeps single-date galleries compact and only renders a range when both endpoints differ; visible ranges use an en dash (`–`) between endpoints. Gallery sidecars persist both values when present, so filesystem imports and migration transfer preserve the date range.

The Admin gallery dates tool builds suggestions from `images.exif_taken_at`. For each gallery, it aggregates the minimum and maximum EXIF capture date from images directly inside that gallery and all descendant galleries. Suggestions are only advisory: the admin can apply, edit, or ignore each row. Existing manual date ranges are shown and are not selected by default, which prevents accidental overwrite of curated dates.

The gallery editor surfaces the same recursive branch suggestion directly beside the date range fields. The **Apply to this gallery** action persists the suggested range for the current gallery only, using all images in that gallery and descendants. Both the full admin editor and side-panel editor use the same rendered suggestion component and the same `admin_gallery_date_suggestion` POST endpoint. JavaScript enhances this action through `public/assets/gallery-modules/admin-gallery-date-suggestion.js`, reads gallery id, CSRF token and endpoint URL from component data attributes, and keeps ownership of the From/To inputs plus the compact refreshed suggestion fragment. A successful enhanced save now returns the canonical Admin mutation envelope with `gallery.date_range_update`, `panel.workflow=gallery-edit`, and affected public contexts for both the edited gallery and its owning parent/root. Those contexts use the persisted gallery `updated_at` value as the observable postcondition, so the shared side-panel completion coordinator refreshes the current hero or gallery card from server-rendered HTML instead of treating the local input update as proof of public synchronization. Enhanced authentication and CSRF failures remain canonical JSON; normal POST/redirect and classic CSRF behavior remain available for direct-page or JavaScript-disabled use. The **Review branch suggestions** link opens `admin_gallery_dates` with `gallery_id`, limiting the review table to that gallery branch so a parent trip gallery and its daily subgalleries can be approved from one focused screen.


## Duplicate Photo Detector

The Admin Duplicate Photo Detector follows the same controller, service, view, and side-panel model used by other gallery maintenance workflows. The gallery editor exposes **Find duplicate photos** from the Images section with `data-gallery-side-panel-link` and the `duplicate-detector` workflow name. `public/assets/gallery-modules/admin-side-panel.js` keeps ownership of the reusable right-side panel shell, while `public/assets/gallery-modules/admin-duplicate-photo-detector.js` enhances the detector's normal POST forms with bounded AJAX continuation and in-panel mutation actions.

`app/controllers/admin_duplicate_photos.php` requires administrator authentication before resolving any detector scope. A selected gallery must exist even for global searches. The **Search all galleries** checkbox is unchecked in fresh detector forms. When it is off, the server resolves the selected gallery plus every descendant subgallery from the stored gallery hierarchy and snapshots those gallery IDs into the immutable session job scope. When it is explicitly checked, the scope expands to all galleries available to the authenticated administrator. Continuation requests do not resend or re-resolve `gallery_id`, descendant scope, or the global flag; they send only CSRF data, a bounded batch size, and an opaque session job token whose stored scope controls every later query.

`app/services/duplicate_photo_detector.php` reads metadata already stored in `images` by the existing scanner and does not reopen every image to extract EXIF data. A detector job snapshots the highest eligible image id and total row count, then reads rows in ascending primary-key batches. The default browser batch is 200 rows and the service caps one batch at 300 rows. Match accumulators and the image-to-gallery snapshot live in the administrator session for one hour; cleanup retains at most the three most recently updated detector jobs. After matching, efficient internal groups are expanded into deterministic left/right pair comparisons, because persistent review decisions apply to one exact image relationship rather than every member of a larger group. Pair expansion considers at most 10,000 candidate relationships per render before ledger filtering and result pages show 10 surviving pairs at once, preventing pathological duplicate groups or heavily ledgered groups from causing unbounded pair-combination work.

Matching rules are deterministic:

- **Exact duplicate**: every member has the same valid non-empty `images.checksum_sha256`. SHA-256 equality is treated as byte-for-byte identity.
- **Strong candidate signals**: an exact checksum group is additionally marked when file size and pixel dimensions also match and meaningful non-empty normalized EXIF metadata is compatible. Because checksum equality is already exact, this is corroboration rather than a weaker confidence class.
- **Possible duplicate**: the images share a sufficiently complete normalized EXIF fingerprint. File size is retained as corroborating evidence but is not required to match, because equivalent photos can have different byte sizes after upload-path, metadata, or encoding differences. File size alone never creates a pair.
- Missing or empty EXIF values are omitted from the fingerprint. Two absent values do not become evidence simply because both are absent.
- The EXIF fingerprint normalizes capture time, camera make/model, lens, focal length, aperture, exposure time, ISO, and paired GPS coordinates in a fixed order. A possible-match fingerprint requires capture time, camera or lens identity evidence, and sufficient additional meaningful values.

The result view uses existing translation, escaping, CSRF, public URL, thumbnail, and database helpers. Each pair shows matching signals and two labelled image cards. Gallery title and gallery path are links generated through `gallery_public_url()`. Filename, preview, and gallery-relative file path are links generated through `image_public_url()`. Context links open in a new tab so the administrator can inspect the public gallery/photo context without replacing the Admin page or closing the detector side panel.

### Duplicate review ledger

Migration `database/migrations/202608080001_duplicate_photo_ledger.php` adds two administrator-owned tables. `duplicate_photo_ledger_pairs` stores canonical image-id pairs `(image_id_low, image_id_high)` per administrator. `duplicate_photo_ledger_galleries` stores exact gallery IDs per administrator. Foreign keys cascade when users, images, or galleries are deleted, so stale review rows do not survive deleted parents. Generic database maintenance classifies both tables as protected administrator workflow state; their normal lifecycle is owned by the Duplicate Photo Detector.

`app/services/duplicate_photo_ledger.php` is the only persistence layer for these review decisions. An **Ignore this pair from now on** action stores only the displayed image relationship. Future result generation removes that pair while leaving other pair combinations from the same internal duplicate group eligible. Each left/right card also exposes **Ignore all from this gallery**. The controller accepts the result image ID, reloads the image server-side, revalidates it against the immutable detector scope, derives its current `gallery_id`, and only then stores the gallery rule. The browser does not choose the persisted gallery ID. Gallery rules are exact by design and do not cascade to descendants, so a parent gallery and any child gallery can be reviewed independently. **Clear ledger** removes only the authenticated administrator's pair and gallery rules.

Ledger filtering is applied to completed results and to later searches for the same administrator. A pair is omitted when its canonical image relationship is ledgered, or when either image belongs to an exact gallery ID in the gallery ledger. The ledger is per administrator, so one administrator's review decisions do not affect another account.

### Side-panel mutations

**Delete this**, **Ignore this pair from now on**, both independent **Ignore all from this gallery** controls, and **Clear ledger** are normal POST forms for non-JavaScript/direct-route fallback, but the primary browser pipeline is AJAX. `admin-duplicate-photo-detector.js` intercepts dynamically injected forms in the capture phase before generic Admin form handlers, adds the existing AJAX markers, consumes the controller's JSON response, replaces only the detector fragment, and leaves the reusable right-side panel open. Durable delete and ledger writes return the canonical Admin mutation envelope. The detector remains responsible for its own result fragment, while canonical durable results are also forwarded to the shared mutation completion coordinator. Ledger mutations intentionally declare no public gallery contexts because they alter only administrator review state, not gallery/image presentation. Every AJAX POST validates Admin authentication and CSRF before any classic login redirect or plain-text CSRF abort can run, so expired-authentication and invalid-CSRF failures remain JSON. The controller also requires a completed server-owned job for pair, gallery, and delete mutations, verifies submitted images or pairs belong to its result groups, reloads current image ownership, and checks gallery membership against the immutable job scope. These actions must not call `window.location`, assign `location.href`, reload the page, or introduce a browser confirmation dialog unless explicitly required by a future feature. The module import is cache-busted whenever this interaction contract changes so deployed browsers do not fall through to stale POST behavior.

Per-image deletion still delegates to `delete_gallery_images()` so original-file deletion, image-row deletion, thumbnail/DNG derivative cleanup, title-picture cleanup, path boundaries, and existing mutation semantics are reused. Successful deletion also prunes the image from the persisted detector job before the panel fragment is rendered again. Ledger actions never delete or modify gallery/image content.

Older rows with missing checksum/EXIF scanner metadata should be refreshed through the existing **Scan/import images** workflow, which reuses `app/services/image_scanning.php` and the existing EXIF extraction pipeline.


## Gallery Migration and API Transfer

Gallery migration is implemented by:

```text
app/controllers/gallery_migration.php
app/services/gallery_migration.php
app/views/admin_gallery_migration.php
public/assets/gallery-modules/admin-gallery-migration.js
public/assets/gallery-modules/admin-side-panel.js
```

The migration feature exchanges manifests and assets between two gallery installs. It supports receive status and completion endpoints so a transfer can compare already-present files and avoid re-sending successful assets after reconnects.

The Admin migration endpoint is a JSON-only enhanced workflow. Authentication, CSRF, missing-gallery, expected step failures, and success responses therefore remain JSON and do not redirect the injected editor to a standalone Admin page. `target_pull` is a local persistent mutation as soon as the source manifest is accepted because `gallery_migration_prepare_target_job()` applies gallery metadata before asset transfer finishes. The pull-manifest and pull-complete responses consequently carry the canonical mutation result for the stable local gallery ID plus the current gallery and owning parent/root public render contexts. Imported slug changes use the new canonical render URL while the visible browser URL remains unchanged.

`admin-gallery-migration.js` owns transfer progress, reconnect/status handling, cancellation, and log markup while transfer is active. When it runs inside the gallery side panel, it forwards canonical local pull results through `php-gallery:auxiliary-mutation-success`. The result also identifies the `gallery-edit` panel fragment. Final target-pull completion, and partial failure after manifest acceptance, explicitly request a server-rendered editor refresh through `admin-side-panel.js`; the active API tab and drawer remain mounted while stale Identity/Access/Display/Media values are replaced. This prevents a later Save from writing pre-import form values back over imported metadata. Public invalidation and panel refresh remain separate coordinator responsibilities, and a partial transfer failure is reported in the persistent drawer status after already-applied local state is synchronized. Source-push completion emits a typed remote mutation result but declares no local public contexts.

## Upload Automation and Windows Watcher

Upload automation is implemented by:

```text
app/controllers/upload_automation.php
app/services/upload_automation.php
public/assets/gallery-modules/admin-side-panel.js
winapp/gallery_watch_upload.pyw
winapp/requirements.txt
```

`gallery_upload_tokens` stores hashed tokens scoped to galleries. External tools should authenticate with those tokens and should not require admin session cookies.

The Windows uploader intentionally uses one compatibility multipart contract across thumbnail policies. When local thumbnail generation is enabled it can submit both JPG and WebP variants for every supported size. The API validates the submitted files, then the server-side install phase applies the active thumbnail compatibility policy: modern WebP-only installations silently skip valid JPG extras, while legacy installations keep both formats. A policy-mismatched but otherwise valid client thumbnail must never reject the original API upload or force the watcher into server-side thumbnail generation.

Gallery-scoped API-key create/revoke forms are also embedded in the gallery editor. Their enhanced path uses the canonical Admin mutation success/error envelope and `panel.workflow=gallery-edit`; the token mutation itself has no public gallery context because changing an API credential does not change public rendering. `admin-side-panel.js` delegates the successful response to the shared completion coordinator and refreshes the API-key fragment from the server-provided panel URL while keeping the drawer mounted. The ordinary POST/redirect route remains the non-JavaScript/direct-page fallback.

## Telemetry Model

Telemetry is opt-in/configurable and anonymized. It is implemented by:

```text
app/services/telemetry.php
app/services/telemetry_privacy.php
app/services/telemetry_settings.php
app/services/telemetry_rollup.php
app/services/database_observer.php
app/controllers/telemetry.php
app/controllers/admin_telemetry.php
public/assets/telemetry.js
public/assets/usage.js
```

Telemetry tables store sessions, events, hourly rollups, daily rollups, database query metrics and maintenance job runs. Privacy helpers bucket or hash sensitive values.

## Logging and Diagnostics

Admin logs are stored in `admin_logs` and handled by:

```text
app/services/logs.php
app/controllers/admin_logs.php
```

Logs support category, severity, status, subject, request id, route, method, AJAX flag and fingerprint fields. This gives enough structure for an admin diagnostics UI without requiring an external log server.

## Public Assets

| File | Purpose |
| --- | --- |
| `public/assets/styles.css` | Main public and admin styling. |
| `public/assets/gallery.js` | Gallery UI behavior, search, maps, inline admin behavior and related browser interactions. |
| `public/assets/gallery-modules/admin-gallery-date-suggestion.js` | In-place apply workflow for the reusable per-gallery EXIF date suggestion component in full editor and side-panel contexts. |
| `public/assets/gallery-modules/admin-duplicate-photo-detector.js` | Bounded scan continuation plus delete, pair-ledger, exact-gallery-ledger, and clear-ledger AJAX actions. All refresh only the detector fragment and preserve the existing side-panel shell; POST/redirect remains fallback-only. |
| `public/assets/styles/admin-duplicate-photo-detector.css` | Responsive Admin and side-panel presentation for pair comparisons, linked context, ledger controls, previews, metadata, confidence badges, and progress. |
| `public/assets/gallery-modules/admin-refresh-progress.js` | Ajax progress workflow for Admin filesystem gallery discovery. |
| `public/assets/telemetry.js` | Telemetry event capture. |
| `public/assets/usage.js` | Usage collection helper. |
| `public/assets/custom.css` | Public custom CSS entry. |

Keep JavaScript vanilla unless the project explicitly adopts a front-end dependency system.

## Centralized Operational Configuration

`app/configuration_defaults.php` is the canonical source for deployment-tunable numeric runtime policy used by the public download hardening and browser upload/rebuild pipelines. `app/bootstrap/configuration.php` merges those defaults underneath the local `config.php`, so an update can introduce a new safe limit without forcing administrators to rewrite existing configuration files. Feature modules read these values through `cms_runtime_limit(<stable dotted key>)` instead of redeclaring their own numeric defaults. Local installations may override selected `runtime_limits` keys in `config.php`; protocol/schema versions, enum values, ZIP format constants, and other non-tuning invariants remain close to their owning module.

The same merged configuration exposes an optional `download_security.capability_secret`. It is blank by default. Stage 2 capability signing therefore derives a purpose-separated HMAC key from the existing stable `visitor_vote_secret` (falling back to `setup_key` only for legacy/manual configurations), which keeps existing installations update-compatible and avoids a repository-shared secret.

## Public Download Capabilities

`app/services/download_capabilities.php` implements stateless public download capabilities. Tokens use a versioned `v1.<base64url-json>.<base64url-hmac>` envelope signed with HMAC-SHA-256 and carry only resource type, resource ID, scope, issue/expiry times, and a random nonce. The default lifetime is six hours, long enough for deliberate multi-gigabyte browser downloads without creating permanent bearer authority, and token length is bounded before payload decoding. Validation uses `hash_equals()` and rejects tampering, expiry, future-issued tokens outside the configured clock-skew allowance, wrong gallery IDs, wrong resource types, and wrong scopes without creating database state.

Two scopes remain intentionally separate. `progressive` authorizes the browser manifest and the independently re-authorized source requests emitted by that manifest. The normal Stage 3 browser flow POSTs to `download_gallery_start` or `download_smart_gallery_start`, receives only a progressive capability, and sends it in `X-PHP-Gallery-Download-Capability`; progressive manifest/source routes require that capability before normal gallery membership and visibility checks run. Query transport remains a bounded compatibility path for progressive endpoints, but normal browser requests keep bearer material out of URLs and use `Referrer-Policy: no-referrer` plus private/no-store responses.

Stage 4 removes crawler-triggerable legacy archive construction. Public gallery and Smart Gallery hero download controls are explicit POST forms containing only the resource ID and a short-lived resource-scoped `legacy` capability. JavaScript progressively enhances the same submit button by preventing the form submission and starting the progressive POST handshake; without working JavaScript, the form naturally becomes the bounded legacy fallback. Historic `GET download_gallery?id=...` and `GET download_smart_gallery?id=...` requests remain cheap compatibility pages that authorize the resource and render a fresh explicit POST form, but never enumerate a manifest or call a ZIP builder. The actual bounded server ZIP fallback accepts POST only and requires a valid `legacy` capability before resource authorization, manifest bounds checking, archive construction, and download streaming. A progressive capability cannot authorize the legacy builder, and the progressive start endpoints no longer return legacy bearer URLs.

Stage 5 bounds the remaining deliberate server-side ZIP preparation. Physical gallery build identity combines the existing gallery subtree signature with the already-authorized legacy manifest fingerprint, so empty-directory structure and visitor-specific file membership are both represented without hashing source payloads. Smart Gallery build identity is an ordered bounded result fingerprint derived from source image ID, stable public source version, ZIP entry path, and source size; capability tokens, client identity, request IDs, hosts, and unrelated query parameters are excluded. The Smart Gallery builder recomputes the current result identity before publication and rejects a changed result instead of publishing new content under an old fingerprint.

Single-flight and admission state lives under the private ZIP cache in `.legacy-build-state`. Each canonical build key uses a non-blocking exclusive `flock()`, and all expensive legacy builders share a configurable slot pool whose default maximum is two concurrent builds. The lock files themselves are inert and may persist: process termination releases the kernel lock automatically, so stale files do not become stale leases. A completed cache hit is checked before and after single-flight acquisition and never consumes a global slot. When the same build is already running or global preparation capacity is full, the legacy POST returns `503 Service Unavailable` with a bounded `Retry-After` value instead of waiting indefinitely or starting duplicate work. Build admission ends before `send_download()`, so any number of clients may transfer an already completed archive at normal speed. Physical and Smart Gallery publication both use unique partial files and atomic rename semantics so failed preparation is never exposed as a completed artifact.

The deployment-tunable Stage 5 defaults are `download.legacy_max_concurrent_builds = 2` and `download.legacy_busy_retry_after_seconds = 5` in `app/configuration_defaults.php`. No database schema, worker, daemon, Redis, or external queue is required.

Stage 6 makes completed legacy fallback archives immutable reusable artifacts rather than transient rebuild targets. `app/services/download_artifact_cache.php` owns a private `legacy-artifacts` subtree inside the configured ZIP cache. Artifact identity uses resource type, resource ID, resource revision/result fingerprint, and the canonical Stage 5 build key. Metadata contains only bounded build identity, creation time, archive size, and expected entry count. It never stores capability tokens, visitor identity, client identity, public URLs, or source filesystem paths. Publication occurs only after ZIP creation and close succeed, expected entry-count sanity passes, metadata is persisted, and the complete partial directory can be atomically renamed into its final immutable location.

Legacy artifact serving revalidates the current POST capability and resource authorization before locating the artifact. A reusable hit bypasses ZIP construction and global build admission, but transfer remains unthrottled. Build and serve leases use `flock()` so maintenance skips artifacts in active use. Maintenance also removes eligible partials, expired physical revisions, short-lived Smart Gallery result artifacts, and stale coordination state only inside managed download-cache roots. Capacity admission counts completed managed bytes plus active reservations, enforces `download.legacy_artifact_cache_max_bytes`, and preserves `download.legacy_artifact_free_space_margin_bytes`; refusal is a controlled HTTP 507 and emits `download.legacy_cache_capacity_refused`. Default retention is seven days for physical artifacts and 24 hours for Smart Gallery artifacts, with six-hour partial retention and 24-hour inactive coordination retention.

Stage 7 hardens the normal progressive path against manifest amplification. `app/services/download_manifest_cache.php` stores only normalized capability-free manifest metadata under a private `.download-manifests` subtree. The cache key is `(resource type, resource id, content revision)` and therefore cannot be split by capability nonce, request ID, host, User-Agent, visitor identity, or arbitrary query strings. Physical revisions are derived from the currently authorized ordered image set and stable database-backed source identity. Smart Gallery revisions are derived from the current bounded canonical query result, so rule/result changes naturally create a different identity. Current capability validation, gallery/Smart Gallery lookup, visitor authorization, and dynamic result membership are performed before cache use on every protected request.

A manifest cache miss performs the prior per-source `is_file()`, containment `realpath()` checks, and `filesize()` reads before writing normalized metadata atomically. A hit reuses ZIP path, image ID, stable source version, and byte size, then injects only the current request's source URLs. Generated source URLs also carry a non-secret manifest revision and expected byte-size snapshot. The pre-dispatch SEO request guard explicitly accepts these `mr` and `s` parameters on both physical and Smart Gallery source routes; otherwise valid Stage 7 source URLs would be rejected before controller authorization. The source route compares that snapshot only after current capability/resource/source authorization; if the independently resolved filesystem size changed, it verifies that the referenced cache entry really contains the same image/version/expected-size tuple before deleting that exact stale revision and returning 409. Forged size parameters therefore cannot be used as a general cache-eviction primitive. The normal header capability transport never enters a cache file; the bounded query-token compatibility transport is also added only while serializing the current response. Generic SEO guard rejection logging records only the request path plus a `?[query-redacted]` marker when a query string existed, while unexpected parameter names remain separate diagnostic fields. This prevents query-transport capabilities, share tokens, and other bearer values from being copied into Admin security logs. Physical manifest entries default to 24-hour retention and Smart Gallery entries to 15 minutes. `site_maintenance_process_cleanup_step()` invokes bounded manifest-cache cleanup alongside legacy-artifact and ordinary ZIP-cache cleanup. Cache failure is non-fatal: the already-authorized manifest is returned from memory, and no broader/public filesystem fallback is introduced.

Manifest profiling is deliberately credential-free. Every authorized physical or Smart Gallery manifest request records candidate gallery/image row counts, explicit filesystem check/size/realpath counters, elapsed time, and memory usage. When an Admin test run is active, the profile also records exact SQL-query delta and is attached as the `download_manifest_profile` component. Responses expose `X-PHP-Gallery-Manifest-Cache: hit|miss|bypass` and a bounded `Server-Timing` summary. This makes the first cache miss a production-representative cost baseline and repeated hits directly comparable without persisting bearer material or raw client identifiers.

The Stage 7 controller order is intentionally cheap-first: method and bounded decimal parameter validation, capability signature/expiry/scope validation, resource lookup, current visitor/resource authorization, content revision resolution, cache lookup, bounded manifest work, then payload transfer. Progressive manifest and source endpoints accept GET only; HEAD is rejected with 405 so automated metadata probes cannot initialize expensive manifest/source work without receiving the payload. Legacy compatibility GET/HEAD routes remain confirmation-only and never build ZIPs. Actual legacy archive preparation remains POST-only with a resource-scoped `legacy` capability, hard file/byte caps, single-flight deduplication, global admission, immutable artifact reuse, and capacity policy. Before a new legacy artifact is built, physical and Smart Gallery paths revalidate current file count and actual filesystem source bytes against both the manifest snapshot and `download.legacy_max_source_bytes`; a stale cached manifest can therefore cause a controlled retry/invalidation, but cannot under-report bytes to bypass the server-ZIP hard limit.

Original-source delivery remains PHP-mediated by design. After capability, membership, visibility, containment, size, and optional source-version checks, `cms_stream_progressive_download_source()` releases the public-media session lock, sends an exact `Content-Length`, and streams with `readfile()` at normal speed. The supported shared-hosting contract does not guarantee a protected `X-Sendfile`, `X-Accel-Redirect`, or equivalent internal redirect. The application therefore does not expose originals through predictable static paths or require a server extension merely to reduce PHP worker occupancy. A future deployment-specific handoff may replace `readfile()` only if it preserves the same authorization boundary.

The final public-download threat model assumes unauthenticated crawlers and deliberate clients can repeat public URLs, vary irrelevant parameters, replay non-expired capabilities, and initiate concurrent requests. The security goals are not CAPTCHA, email confirmation, or transfer throttling. They are to make GET safe from server ZIP creation, require short-lived scoped authority for protected manifest/source/build operations, reject malformed work early, make expensive server ZIP work bounded and reusable, prevent attacker-controlled cache-key multiplication, and keep authorization current when reusable metadata or artifacts exist. A capability is an action bearer, not a Viewer identity and not a persistent access grant.

Operational rollback remains layered. Disabling/removing the Stage 7 manifest cache returns progressive manifests to the cache-miss filesystem path without changing the browser protocol. Removing immutable artifact reuse still leaves Stage 5 single-flight and global build admission. Removing Stage 5 still leaves the Stage 4 POST-only explicit legacy boundary. Existing cache directories contain no schema state and may be left for later managed cleanup. If a cache entry is suspected corrupt, deleting only `.download-manifests` or the managed `legacy-artifacts` subtree is safe; subsequent authorized requests rebuild the required metadata/artifact. Do not delete gallery media or unrelated cache roots as part of download-cache recovery.

Stage 7 adds four deployment-tunable defaults in `app/configuration_defaults.php`: `download.manifest_cache_physical_retention_seconds = 86400`, `download.manifest_cache_smart_retention_seconds = 900`, `download.manifest_cache_max_entry_bytes = 16777216`, and `download.manifest_cache_cleanup_max_entries = 10000`. Existing `config.php` files need no change because runtime defaults are merged centrally; installations may override only these stable dotted `runtime_limits` keys when local workload/retention policy requires it.

## Testing

Tests live in `tests/`. Current tests are direct PHP scripts rather than a PHPUnit suite.

`scripts/audit.php` is the canonical source-tree quality gate. It runs isolated PHP regressions, explicitly registered Node models and fixtures, WinApp Python unittests, syntax checks, repository contract utilities, and profile-specific release checks behind one bounded orchestration layer. `scripts/audit_registry.php` owns exceptional invocations such as temporary ZIP outputs, the slow ZIP64 boundary test, browser integration, and per-test extension requirements; broad filename globs are not used for Node execution. Successful child stdout is collapsed into suite counts, while Markdown/JSON summaries and drill-down logs are written under `cache/test-audit/`. `quick`, `full`, and `release` profiles separate edit-cycle, deterministic full-tree, and release-only work. PASS/FAIL/SKIP/BLOCKED remain distinct so unavailable MySQL/browser/GD coverage cannot be misreported as a passing assertion. `tests/run.php` is a compatibility wrapper for the PHP-regression suite only. Deployment filtering still treats `tests/` as source-review material: it is excluded by default and may be included only in an explicitly opted-in local folder or ZIP, never an FTP deployment.

Examples:

```text
tests/gallery_branding_model_test.php
tests/gallery_dates_model_test.php
tests/gallery_migration_model_test.php
tests/gallery_visibility_model_test.php
tests/openai_text_assist_model_test.php
tests/simbrief_description_model_test.php
tests/url_rewrite_settings_test.php
```

When adding logic-heavy services, prefer creating a direct test script that exercises pure functions or model logic without requiring a browser.

## Coding Rules for Future Changes

1. Preserve existing file docstrings.
2. Prefer complete, overwrite-ready files when providing patches.
3. Keep new modules small and named after the feature responsibility.
4. Put DB changes in migrations only.
5. Use `db()` for database access.
6. Use service helpers for schema checks when supporting older partially migrated installs.
7. Validate admin mutations with CSRF.
8. Escape rendered output.
9. Keep source images immutable unless the feature is explicitly an image-editing operation.
10. Do not hardcode public paths when URL helper functions already exist.
11. Keep comments practical and close to the relevant code.
12. Avoid adding new dependencies unless the feature cannot be implemented reasonably without them.

### Comment and Docstring Rules

Every PHP function or method should keep a short PHPDoc entry above it. The entry should start with one factual purpose sentence, then list every parameter with `@param`, a concrete type, and a short description. Add `@return` when the function returns data. Mention important caller context, dependencies, or downstream service calls only when that context helps maintenance.

Keep descriptions brief and factual. Do not remove existing file headers or function docstrings while editing unrelated logic. Prefer normal inline comments for local reasoning, avoid decorative separator lines, and keep comments near the code they explain.

## Recommended AI Maintenance Workflow

When asking an AI agent to modify this project, include these documents with the ZIP:

```text
ARCHITECTURE.md
CODEMAP.md
DATABASE.md
```

For small changes, the agent should first inspect `CODEMAP.md` and only open the relevant files. For database changes, the agent should inspect `DATABASE.md`, then create a new migration instead of editing historical migrations.

After any structural change, update these docs in the same patch.

## Smart Gallery Architecture

`app/services/smart_galleries.php` owns the versioned rule format, allowlisted catalog, limits, parameterized SQL compiler, persistence, presentation normalization, access-aware result query, count, bounded page query, and bounded lazy-lightbox query. Search conversion produces the same rule representation. There is no membership table or synchronization job. `images.editorial_rating` is private metadata and remains independent from `image_votes`.

`smart_gallery_result_query()` is the canonical membership boundary. Public mode intersects compiled rules with source galleries accepted by both `gallery_is_public_listed()` and `visitor_can_access_gallery()`, then requires public image visibility and applies the existing NSFW policy. Counts and returned rows therefore have the same authorization semantics. Returned rows keep their physical `gallery_id`, so existing media, thumbnail, voting, and source-gallery policy services remain authoritative. Every allowlisted sort appends `images.id` in the same direction as a stable tie breaker.

`smart_gallery_query_images()` caps one database page at 200 records. Public presentation may disable visible pagination for smaller collections, but results above that cap force pagination. This prevents a presentation option from turning a large virtual collection into an unbounded PHP/HTML response.

Complete Smart Gallery lightbox traversal reuses the normal-gallery sparse-cache design. Public cards contain global result indexes and the Smart Gallery page emits the full authorized result count plus `smart_gallery_lightbox_data`. That endpoint caps one request at 80 metadata rows and queries the same canonical membership/order definition. The browser can therefore cross page boundaries while retaining existing lightbox pending-request and stale-response protection, keyboard/touch navigation, fullscreen, zoom, and slideshow lifecycle behavior. Original files are still requested only by the existing authorized media path when needed.

Migration `202608170001_smart_gallery_presentation.php` adds nullable `presentation_json`. Presentation version 1 normalizes optional Smart Gallery overrides, then resolves `Smart Gallery override > Theme/site default`. Missing, malformed, unknown-version, and invalid keys inherit defaults. The override model also reuses the canonical Theme gallery-card layout normalization for placed Smart Gallery cards. There is intentionally no physical-parent presentation inheritance because one Smart Gallery can have multiple placements. Physical gallery/image thumbnail bounds remain authoritative if Smart Gallery bounds conflict.

The public renderer and Admin preview share `smart_gallery_render_image_cards()`. The Admin controller remains the non-JavaScript form owner. `admin-side-panel.js` enhances the same Smart Gallery links and forms in the existing drawer, uses the current browser origin for local-host aliases, submits through the normal POST route, follows redirects, extracts `data-smart-gallery-editor-workspace`, and rebinds rules/presentation behavior without changing the browser URL.

Cycle safety is centralized in `app/services/smart_galleries.php`. The request-local relationship snapshot contains physical-parent-to-attached-Smart-Gallery edges plus Smart-Gallery-to-physical-gallery edges for rule branches that can positively include that gallery. The current or proposed physical hierarchy is used to expand `under` rules to the referenced gallery plus its descendants; `equals` remains an exact edge and does not accidentally inherit descendant semantics. The current rule schema has no direct Smart-Gallery-reference field. Proposed rule edits, attachment replacements, single gallery moves, complete Admin drag-and-drop parent maps, filesystem parent synchronization, and public-path hierarchy repair are validated before their first new hierarchy mutation. Existing unrelated cycles do not block a repair elsewhere; the validator compares current and proposed relationships and rejects newly introduced invalid paths. Every committed physical hierarchy change invalidates the request-local graph cache. A drag-and-drop request validates its complete final parent map once and moves folders through an explicit prevalidated batch path, deferring hierarchy synchronization until the final tree exists so temporary intermediate states cannot invalidate an otherwise valid batch.

The graph walker deduplicates visited stable IDs and caps traversal at 64 edges of depth, 4,096 expanded nodes, 1,024 expanded Smart Gallery nodes, 20,000 stored edges, and 50,000 source rows per entity class. One physical parent may submit at most 100 Smart Gallery attachments. The graph snapshot is cached per request and invalidated after relationship mutations. Public placement rendering skips invalid legacy attachment relations with bounded safe diagnostics. Flat direct/root Smart Gallery result evaluation does not follow attachment edges, so an invalid attachment does not hide an otherwise valid direct/root Smart Gallery; malformed rule definitions still fail closed. Smart Gallery result SQL itself is not recursively expanded: normal page queries remain capped at 200 rows and lazy-lightbox windows at 80.

Placement uses `placement_mode` for listing intent and `smart_gallery_placements` for many-to-many physical parents. `unlisted` retains URL-only access and `root` joins homepage gallery pagination. For `gallery` mode, migration `202608170002_smart_gallery_attachment_ordering.php` extends each composite attachment with `placement` (`top`/`bottom`, default `bottom`) and `placement_order` (default `0`). Physical gallery rendering loads attached Smart Galleries separately from ordinary child pagination, renders ordered `top` cards before normal subgallery/photo content, then ordered `bottom` cards afterward. Equal order values use Smart Gallery ID as the deterministic tie breaker. Editing one physical gallery replaces only that parent's junction rows transactionally, so the same Smart Gallery can keep independent placement/order values elsewhere.

Smart Gallery download uses `cms_download_smart_gallery()` plus the bounded `build_legacy_smart_gallery_zip()` coordinator around `build_smart_gallery_zip()`. It reconstructs authorized membership through the same service query, performs a final per-image access check before adding source files, streams through the normal download response, and never exposes filesystem paths. Archive generation has independent image-count and aggregate source-byte caps sourced from the centralized runtime-limit configuration; the historical top-level `smart_gallery_zip_max_source_bytes` key is still honored when an older installation already overrides it. Legacy preparation uses the Stage 5 canonical result fingerprint, per-build single-flight lock, and global filesystem admission slots described above. The builder revalidates the current dynamic result against the expected fingerprint, writes to a unique partial path, and publishes with `rename()` only after `ZipArchive` finalizes successfully. Persistent failure diagnostics store only bounded reason/class metadata and never the raw exception message.

Detailed behavior is documented in `docs/SMART_GALLERIES.md`.

## Release Documentation

Patch note formatting is standardized in `PATCH_NOTES_TEMPLATE.md`. AI coding agents and maintainers should use it when preparing new `PATCH_NOTES.md` entries so releases keep consistent structure, technical references, filename citation style, and user impact descriptions.

## Phase 1.0 Viewer Account HTTP Boundary

Phase 1.0 is the first reachable viewer-account slice. `app/controllers/viewer_accounts.php` is the thin HTTP orchestrator for administrator invitations, scanner-safe invitation acceptance, email verification, viewer login/logout, remember-me, forgotten-password/reset, and the minimal authenticated account page. It delegates durable state transitions to the existing Phase 0 through 0.7 viewer services. No controller contains account-creation, password-reset, remember-token, or gallery-authorization SQL.

The identity boundary remains strict: `current_user()` is the administrator principal and `current_viewer()` is the viewer principal. Viewer login never writes the administrator `user_id` session key, viewer logout revokes only viewer session/remember/pre-auth authority, and a browser may hold both principals at once. `visitor_can_access_gallery()` and all existing protected-gallery/share/media paths remain viewer-unaware, so viewer authentication does not unlock any gallery.

Invitation and email-token GET requests are scanner-safe. Invitation GET calls only `viewer_invitation_inspect()`. Verification GET calls only `viewer_registration_verification_validate()`. Reset GET calls only `viewer_password_reset_inspect()`. Irreversible authority changes require viewer/pre-auth CSRF-protected POSTs. Verification POST exchanges the bearer into short-lived server-side activation authority and final activation delegates to `viewer_registration_activate_verified()`, preserving invitation locking, replay protection, durable account-cap locking, and single-account activation semantics.

Viewer security mail reuses the existing configured PHP-mail/SMTP transport in the administrator password-reset mail subsystem. Viewer verification transport is reached only after `viewer_mail_authorize_send()` approves the address/visitor/global budgets; password-reset mail uses the existing reset request service, which performs the corresponding viewer mail authorization before issuing a send-eligible token. Trusted absolute URLs continue to come only from `viewer_security_url()` and configured `base_url`. Complete secret-bearing links are never added to security-event context. Dedicated `viewer_*` routes bypass the generic public SEO query-string guard because that guard is not the bearer-token validator and historically sampled rejected `REQUEST_URI` values into Admin security logs. Viewer pages keep their own strict token validation, emit `Referrer-Policy: no-referrer`, and the shared header does not copy a viewer bearer URL into the administrator-login `return` parameter.

`app/services/viewer_http.php` is the browser-cookie adapter for the established Phase 0 remember-token service. It emits only the dedicated `php_gallery_viewer_remember` cookie, restores through selector/verifier rotation, never touches administrator persistence, and explicitly leaves recent reauthentication unsatisfied. Request bootstrap attempts this restore before cache classification. A viewer session, viewer pre-auth authority, viewer CSRF token, or even the presence of the viewer remember bearer forces the private/no-store path. Anonymous public gallery pages remain viewer-independent and keep their previous cache behavior.

The HTTP surface now has two deliberate gates. The outer gate is the `viewer_accounts` entry in the canonical Admin feature registry. It defaults to disabled through `feature_flag.viewer_accounts.enabled`, owns every current `viewer_*` route plus the historical `admin_viewer_invitations` management route, hides the Admin Viewer accounts navigation entry, and forces `viewer_accounts_enabled()` plus `viewer_registration_mode()` closed regardless of any historical viewer configuration. Public requests to a disabled viewer route receive the normal not-found surface rather than a feature-advertising error. This wrapper lets the whole subsystem remain shipped but dormant until an administrator explicitly enables it from **Admin > Features**.

Inside that wrapper, `config.php` remains the fallback for viewer registration policy, while `app_settings.viewer_accounts_admin_mode` is the single administrator-controlled override exposed on **Account > Viewer accounts**. Phase 4.1 exposes exactly `disabled`, `invite_only`, and `open` through that selector. `disabled` keeps the viewer frontend unavailable under the existing semantics; `invite_only` keeps viewer login plus Admin invitation registration; `open` additionally exposes anonymous verified-email registration through `viewer_register`/`/viewer/register`, while Admin invitation links remain valid. Disabling the subordinate viewer frontend also clears local viewer-only session, pre-auth, CSRF, and remember-cookie state during request bootstrap so anonymous public requests return immediately to their historical cache behavior without touching administrator authority. Phase 1.0 itself intentionally left the Phase 0.7 change-password, email-change, and account-deletion lifecycle services unexposed and added no viewer-owned content. Later phases add the lifecycle/content/share surfaces described below; public viewer profiles, uploads, CAPTCHA/Turnstile, OIDC, TOTP, passkeys, and magic-link login remain unavailable.

## Phase 1.1 Viewer Favourites

Phase 1.1 adds the first viewer-owned content feature without changing the Phase 1.0 identity boundary. `app/services/viewer_favourites.php` owns favourite lookup and mutation, while `app/controllers/viewer_favourites.php` owns the private favourites page and the POST-only mutation endpoint. The existing Phase 0 `viewer_favourites` table is reused; no new migration or parallel ownership model is introduced.

A favourite row is only an `(viewer_account_id, image_id)` reference. Every add/remove request requires an authenticated `current_viewer()` principal, the established viewer CSRF token, an active/security-version-matching viewer account, and a fresh `viewer_source_image_can_reference()` decision. Adds serialize on the viewer account row before counting against `max_viewer_favourites_per_account`, so concurrent requests cannot bypass the per-account quota. The controller contains no favourite SQL and never writes administrator session state.

Stored favourites never preserve gallery access. The private favourites page calls `viewer_source_image_can_render_reference()` and the canonical authorized source resolver before emitting image/gallery metadata. Normal gallery and Smart Gallery cards are already source-authorized by their existing renderers; they only receive a batched boolean favourite decoration for the current viewer. Lazy lightbox JSON carries the same optional boolean state after its existing source authorization. `visitor_can_access_gallery()` and media authorization remain unaware of viewer favourites.

Authenticated viewers get a small favourite control on authorized gallery cards and in the lightbox plus a private `/viewer/favourites` landing page. The browser module submits the normal viewer-CSRF form asynchronously and synchronizes duplicate card/lightbox representations. With JavaScript disabled, the same POST form remains functional and falls back to the private favourites page. Favourite forms deliberately do not carry the current gallery URL through the mutation endpoint, so capability-bearing password/share URLs are not copied into viewer POST state.

Favourite state is personalized and therefore inherits the Phase 1.0 viewer no-store boundary. Anonymous public rendering remains unchanged: no favourite query/control is emitted without a viewer principal, and favourite-state lookup catches storage failures so a broken optional viewer schema cannot break ordinary public gallery browsing. Collections, collection sharing, public profiles, uploads, comments, open registration, and optional viewer authentication mechanisms remain outside Phase 1.1.

## Phase 1.2 Viewer Account Lifecycle HTTP Wiring

Phase 1.2 exposes only the existing Phase 0.7 viewer lifecycle services through `app/controllers/viewer_lifecycle.php`. The controller owns HTTP method checks, viewer/pre-auth CSRF, recent-reauthentication redirects, bounded return destinations, no-store rendering, generic user-facing failures, and configured security-mail transport. Password, email, session, remember-token, security-version, and account-deletion transitions remain authoritative in `app/services/viewer_lifecycle.php`; no lifecycle SQL is duplicated in the controller.

Sensitive operations require recent viewer password proof. The reauthentication form accepts only the server-owned action identifiers `password`, `email`, `email_confirm`, and `delete`; it never accepts an arbitrary return URL. An interactive viewer password login satisfies the established recent-authentication contract, while remember-me restoration deliberately does not. Reauthentication uses the existing viewer login rate limits and viewer CSRF namespace and never establishes or modifies administrator identity.

Password change is a viewer-CSRF POST after recent reauthentication. The controller delegates the new password to the existing password policy and atomic lifecycle service. A successful transition increments the viewer security version exactly through that service, revokes viewer sessions and remember credentials according to the Phase 0.7 contract, and signs the viewer out. The shared PHP session and any simultaneous administrator principal survive.

Email change remains staged. The current verified email is not replaced when the request form is submitted. The Phase 0.7 service normalizes/validates the candidate address, applies uniqueness and viewer mail-abuse authorization, supersedes prior pending requests, and returns one short-lived verification secret only to the mail caller. The email link uses the trusted viewer security origin. Its GET route only inspects the token and exchanges it into bounded server-side confirmation authority. The final tokenless viewer-CSRF POST rechecks recent authentication and performs the atomic email transition exactly once. Successful change invalidates viewer authentication authority according to the lifecycle service and signs the viewer out without affecting administrator state.

Self-deletion is also a viewer-CSRF POST after recent reauthentication and an explicit server-enforced destructive checkbox. `viewer_account_delete()` remains the only deletion authority. Existing foreign keys and lifecycle cleanup remove viewer-owned sessions, remember/reset/email-change authority, favourites, dormant collection data, and viewer share capabilities as defined by Phase 0.7. The operation does not delete gallery images, galleries, Smart Galleries, or existing gallery share links and does not call `session_destroy()`.

Every Phase 1.2 account, reauthentication, password, email, verification/confirmation, and deletion response uses the established viewer `private, no-store` boundary plus no-referrer/noindex token-page policy. Lifecycle routes fail closed when viewer accounts or required viewer lifecycle schema are unavailable. Ordinary anonymous gallery routing and rendering remain independent of viewer lifecycle schema inspection. No Phase 1.2 migration is required; the existing `202608180004_viewer_account_lifecycle_foundations.php` schema is reused. Collections, collection sharing, public profiles, uploads, comments, open registration, and optional viewer authentication mechanisms remain outside Phase 1.2.

## Phase 2.0 Private Viewer Collections

Phase 2.0 activates the dormant `viewer_collections` and `viewer_collection_items` schema from `202608180001_viewer_security_foundations.php`; no new migration is required. `app/services/viewer_collections.php` owns viewer-scoped collection CRUD, item reference mutations, quotas, and transactional ordering. `app/controllers/viewer_collections.php` owns the private/no-store HTTP surface and contains no collection SQL.

Collection ownership always comes from `current_viewer()`. Reads and writes use both the collection identifier and the authenticated `viewer_account_id`; no owner id is accepted from request state. Create/rename reuse the existing 120-code-point plain-text title validator and HTML output is escaped. Collection creation is bounded by `max_viewer_collections_per_account` under a locked viewer-account row plus a dedicated account rate limit. Item adds lock the owned collection before enforcing `max_viewer_items_per_collection`, and the existing `(viewer_collection_id, image_id)` primary key makes duplicate adds idempotent.

A collection item is only a canonical image reference plus position/timestamp. Add operations call the canonical viewer source-image authorization before insertion. Detail rendering loads owner-scoped image ids/order and then applies the no-admin-bypass source resolver for the current request. Inaccessible/stale references remain stored but are omitted without title, filename, path, thumbnail, EXIF, gallery title, or denial reason. Password/share-session access can disappear and later return without collection membership ever becoming an access grant. Media serving and gallery authorization remain unaware of collection membership.

The dual-principal case is explicit. A browser may hold both Admin and viewer state, but collection ownership is always viewer ownership and source checks use `visitor_can_access_gallery_without_admin_bypass()` plus the corresponding NSFW decision. Public physical-gallery and Smart Gallery cards expose the compact Add-to-collection chooser only for an authenticated viewer and recheck the viewer source policy whenever Admin authority could have widened the rendered card set. Anonymous public HTML performs no collection lookup and exposes no collection metadata.

Rename, delete, add, remove, and reorder are POST-only and use viewer CSRF. Reorder validates a bounded array, rejects malformed/duplicate/foreign ids, locks the owned collection and item rows, and updates integer positions in one transaction. The owner UI may submit only currently visible ids; inaccessible rows keep their ordinal slots. Deleting a collection deletes only that collection and its dependent viewer-collection rows through existing foreign keys; images, galleries, Smart Galleries, favourites, gallery share links, and Admin authentication are untouched.

All private collection routes fail closed when viewer accounts are disabled or collection schema cannot be verified, while ordinary anonymous gallery browsing remains independent. Collection sharing remains deliberately dormant: no share route, bearer token exchange, anonymous collection view, public profile, or Admin collection-management UI is introduced in Phase 2.0.

## Pre-Phase 3 Administrator Viewer Account Provisioning

The administrator can now provision and delete viewer accounts directly from **Account > Viewer accounts** in addition to issuing invitation links. `app/services/viewer_admin_accounts.php` owns these durable Admin-initiated account transitions, while `app/controllers/viewer_accounts.php` remains the HTTP/UI orchestrator. Direct provisioning does not reuse the administrator identity table or `current_user()` as viewer ownership; every created row still belongs exclusively to the separate `viewer_accounts` identity domain.

`202608180006_viewer_admin_account_management.php` adds only `viewer_accounts.must_change_password`, defaulting to `0` so existing invitation-created accounts retain their historical login behavior. A directly provisioned account is created `active` with an administrator-authorized verified email, a normal password hash, `security_version=1`, and `must_change_password=1`. The administrator may supply a policy-compliant temporary password or let the service generate a high-entropy value. The plaintext temporary password is never stored separately and is exposed only through the one-time Admin post-redirect result. Optional notification mail contains the trusted viewer-login URL but deliberately omits the temporary password, which must be delivered through a separate trusted channel. Direct provisioning uses the same locked installation-cap counter as normal activation, so it cannot bypass `max_viewer_accounts`. It remains available while the viewer frontend is disabled so an operator may stage accounts, but those accounts cannot sign in until the existing viewer-account feature switch is enabled.

A successful temporary-password check does **not** establish `current_viewer()`, issue a remember credential, or grant favourite/collection mutation authority. Instead, `viewer_authentication.php` creates a short-lived first-login password-replacement state bound to the account id, current security version, and temporary password hash, then redirects to `/viewer/first-login`. `current_viewer()`, normal session establishment, viewer content mutation, and remember-token issuance/restoration all reject `must_change_password=1`. The replacement POST uses viewer CSRF, the normal viewer password policy, rejects reuse of the temporary password, locks and revalidates the account, clears the flag, increments `security_version`, revokes older viewer session/remember/reset/email-change authority, and only then establishes the normal viewer principal. Completing the normal forgotten-password reset also clears the flag because the temporary credential has been replaced through an independently verified recovery path. A simultaneous administrator principal remains untouched throughout this flow.

Direct Admin deletion is an explicit CSRF-protected POST with confirmation. The service locks the target viewer account, invalidates viewer authority, revokes any still-active dormant collection-share capabilities created by that viewer, deletes only the `viewer_accounts` row, and relies on existing account-owned foreign keys for viewer sessions, tokens, favourites, collections, and lifecycle state. It never deletes canonical photographs, galleries, Smart Galleries, gallery share links, or administrator accounts/sessions. Knowledge of a viewer id is not sufficient authority because the route itself remains administrator-only. No public registration, collection sharing, public profile, upload, TOTP, OIDC, or passkey feature is introduced by this inter-step.

## Phase 2.5 Administrator Viewer Account Security Controls

Phase 2.5 keeps the historical **Account > Viewer accounts** route and adds three administrator-only POST actions to the existing account table: Suspend, Restore, and Sign out everywhere. The controller remains SQL-free and delegates to the existing `viewer_account_suspend()`, `viewer_account_restore()`, and `viewer_session_revoke_all()` service boundaries. Every mutation requires the established administrator principal and Admin CSRF token; viewer CSRF and viewer identity never authorize these operations.

The pre-Phase-3 master wrapper does not replace those lifecycle controls. It sits above the complete viewer subsystem. When the master `viewer_accounts` feature is off, the Viewer accounts Admin entry and route are hidden/guarded together with all viewer-facing routes. When the master feature is on but the subordinate viewer frontend mode is disabled, the Admin management page remains available and retains the Phase 2.5 emergency controls while public viewer authentication stays unavailable. Existing viewer rows, favourites, and private collections are not deleted by either switch.

Suspension and restoration use the existing transactional account-state transition. They lock the viewer row, rotate `security_version`, revoke viewer sessions and remember credentials, invalidate outstanding reset and durable verification authority, cancel pending email changes, and revoke dormant collection-share capabilities created by the viewer. A matching local `viewer_auth` namespace is cleared without destroying the shared PHP session or the administrator `user_id`. Restoration changes only the durable status back to active and rotates the security version again, so no pre-suspension session, remember cookie, reset token, email-change authority, recent reauthentication state, or collection-share capability is revived.

Sign out everywhere is intentionally narrower: it leaves the account status active and reuses central authentication invalidation to rotate `security_version`, revoke all viewer session rows and remember tokens, and invalidate outstanding reset authority. It does not delete favourites, collections, collection items, images, galleries, gallery share links, or Admin state, and it does not revoke collection-share capability merely because the active owner was signed out. The controls remain available even when the viewer frontend feature switch is disabled so an administrator can secure dormant viewer data.

The `must_change_password` flag is untouched by all three controls. A temporary-password first-login state created before suspension becomes unusable because it is bound to both the live account status and the old security version; after restoration the user must still complete the existing forced replacement flow. No Phase 2.5 migration, public route, viewer role editor, impersonation feature, collection sharing UI, or anonymous collection view is added.

## Phase 3.0 Unlisted Read-only Collection Sharing

Phase 3.0 activates the already-prepared `viewer_collection_share_tokens` table through a dedicated `app/services/viewer_collection_shares.php` authority boundary and `app/controllers/viewer_collection_shares.php` HTTP boundary. No migration is added. The product exposes exactly one active share per viewer collection. Create/replace and revoke are owner-only Viewer-CSRF POST operations integrated into the existing private collection detail page. Create/replace reuses the existing `viewer_share_create_account` limiter and serializes on the locked viewer account, owned collection, and unrevoked share rows. Previous unrevoked rows are marked revoked before a new 32-byte opaque secret is generated. Only `security_authority_token_hash($token)` is persisted and the plaintext secret is returned only through one collection-scoped flash immediately after creation/replacement. Shares expire 30 days after issuance.

The public bearer route is a reusable GET exchange, not an owner mutation. It rejects malformed tokens before database access, revalidates the share, owner account, collection, revocation state, and expiry, rotates the PHP session id while preserving unrelated Admin/viewer/gallery session namespaces, stores only a bounded narrow `viewer_collection_share_grants` reference, and responds with `303 See Other` to a token-free shared-collection URL. Link-scanner GETs do not consume or revoke the bearer token. The exchange and clean shared page use strict no-store, no-referrer, and noindex/nofollow policy. The initial bearer URL can still appear in web-server or reverse-proxy access logs; operators must treat those logs as sensitive, while application/security-event logging must never copy the raw token or complete share URL. Guessed clean collection ids are inert because the matching session grant is required and its durable share row is revalidated on every request.

Collection-share authority is a third authority domain. It never sets `current_viewer()`, never changes `current_user()`, and never creates a gallery password/share grant. The clean shared read loads only the collection container and ordered image references, then resolves every reference with `viewer_source_images_resolve_authorized()` in the recipient request context. That resolver uses `visitor_can_access_gallery_without_admin_bypass()` and the no-Admin-bypass NSFW policy, so a simultaneous administrator login cannot widen the public shared collection. Source visibility/password/share-session changes are effective on the next view; inaccessible items are omitted without hidden filename/path/gallery metadata. Direct media and gallery routes remain independently authoritative and do not consult collection-share grants.

Revocation, replacement, expiry, collection deletion, owner deletion, and owner suspension invalidate existing clean grants because clean requests re-check current durable state. Restoration never resurrects a share revoked during suspension. `viewer_session_revoke_all()` remains authentication-only and therefore does not revoke an active collection share for an otherwise active owner. `must_change_password=1` cannot create/replace shares because the transactional viewer mutation lock rechecks normal content authority. Share-schema uncertainty disables only sharing; private collections and ordinary anonymous gallery browsing remain independent. All four Phase 3 routes use the existing `viewer_` prefix and are therefore gated by the global `viewer_accounts` master feature, which remains disabled by default. No public collection directory, profile, recipient account, collaboration, open registration, comments, uploads, or optional viewer-auth mechanism is introduced.

## Phase 4.0 Open Registration Policy and Lifecycle Foundations

Phase 4.0 extends the existing registration policy to the bounded `disabled`, `invite_only`, and `open` states without adding schema. The single `app_settings.viewer_accounts_admin_mode` override remains subordinate to the global `viewer_accounts` feature flag, which defaults OFF. Registration origin is not stored in a new discriminator: `viewer_registration_requests.viewer_invitation_id IS NULL` is open-origin staging and a non-null value is invitation-backed staging. `viewer_registration_request_allowed_by_current_mode()` remains authoritative at verification validation, verification confirmation, and final activation. `disabled` permits no staged request to progress, `invite_only` permits only invitation-backed staging, and `open` permits both origins.

Mode transitions involving `open` serialize against the existing registration-capacity lock. Leaving open persists the restrictive policy before cancelling only open-origin pending/email-verified rows; entering open cancels stale open-origin authority before the permissive policy becomes effective. Invitation-backed staging is never cancelled by that cleanup. Request creation also re-reads policy after taking the same lock, closing the create-versus-mode-change race. This prevents old open-origin verification or activation authority from progressing after `open -> invite_only` or `open -> disabled` and prevents stale authority from resurrecting if open is later re-enabled.

## Phase 4.1 Public Verified-email Open Registration HTTP Flow

Phase 4.1 exposes the prepared lifecycle through one new anonymous route: page `viewer_register`, controller `cms_viewer_register()`, and clean route `/viewer/register` when URL rewriting is enabled. The route is reachable only when the outer Viewer Accounts feature is ON, effective registration mode is exactly `open`, viewer security transport is allowed, and both viewer authentication and registration storage are available. `app/services/viewer_http.php` owns this HTTP availability boundary through `viewer_http_registration_lifecycle_available()`, `viewer_http_open_registration_available()`, `viewer_http_invite_registration_available()`, and `viewer_http_registration_verification_available()`. General viewer login availability remains on the existing `viewer_http_auth_available()` controller boundary.

The public form accepts only email and the established Viewer/pre-auth CSRF token. Its POST calls `viewer_registration_request_begin($email, null, request_client_ip())`, so the controller cannot choose or spoof registration origin. Ordinary internal outcomes are collapsed to one generic public notice and no verification capability is rendered. Verification delivery reuses the existing `viewer_mail_authorize_send()` abuse budgets, `viewer_security_url()` trusted absolute URL builder, and configured mail transport. Open-origin mail uses neutral registration wording; the invitation route retains invitation wording. A successful transport handoff is recorded with `viewer_registration_mark_verification_sent()` only after the mail transport reports success.

The existing scanner-safe verification sequence is unchanged. GET validates but does not consume the verification token. Explicit Viewer-CSRF POST consumes the verification authority and establishes only short-lived activation state. Password POST delegates final creation to `viewer_registration_activate_verified()`, which rechecks Phase 4.0 current-mode authority before inserting the durable viewer row. Activation clears registration authority and returns to viewer login; no viewer session or Admin identity is created by registration submission, verification GET, verification confirmation, or activation itself.

Invitation registration remains a distinct bearer flow and is now HTTP-available under both `invite_only` and `open`. Its invitation token remains mandatory and is validated by the existing invitation service. The shared verification route is similarly available in both modes, but individual staged requests are still accepted or rejected only by the Phase 4.0 service policy. Thus an open-origin request cannot continue after a switch to `invite_only`, while invitation-backed requests continue in both `invite_only` and `open`.

The Admin viewer page replaces the historical binary registration checkbox with one selector containing exactly Disabled, Invite only, and Open registration. It posts only the allowlisted values to `viewer_accounts_set_admin_registration_mode()` and does not write settings SQL. Mode-change auditing records bounded old/new mode plus the cancelled open-origin staging count. The global Admin Features `viewer_accounts` switch remains separate and OFF by default.

Phase 4.1 also fixes duplicate registration submission without adding a resend feature. If an existing pending row has `verification_send_count > 0` and its verification authority is unconsumed and still within both request and token validity windows, `viewer_registration_request_begin()` returns mail-ineligible without changing the stored token hash or token expiry. If no successful send was recorded, or the prior token has expired, the normal retry path may mint fresh authority. No resend route/UI, CAPTCHA/Turnstile, public profile, or Phase 5 authentication mechanism is introduced.


## Phase 4.2 First-party Verification Resend and Recovery Hardening

Phase 4.2 adds one explicit anonymous recovery surface: page `viewer_resend_verification`, controller `cms_viewer_resend_verification()`, and clean route `/viewer/resend`. `viewer_http_verification_resend_available()` keeps that surface behind the existing Viewer Accounts master wrapper, effective registration mode `invite_only` or `open`, secure viewer transport, viewer authentication storage, and registration storage. The global Viewer Accounts feature remains OFF by default. The browser submits only a syntactically valid email lookup candidate plus the established Viewer/pre-auth CSRF token; registration request id, viewer id, invitation id/secret, registration origin, verification token, and password are not browser authority inputs.

Resend authorization remains first-party and layered. `viewer_registration_verification_resend_prepare()` uses the existing `viewer_resend_verification_identifier` limiter, locks authoritative registration state, revalidates `viewer_registration_request_allowed_by_current_mode()`, and validates invitation-backed server state where applicable. Delivery still passes through `viewer_mail_authorize_send(VIEWER_MAIL_ACTION_VERIFICATION, ...)`, so the existing verification email/IP/subnet/global mail budgets remain authoritative and limiter-storage failure remains fail-closed. All syntactically valid CSRF-valid submissions converge on one public result regardless of lookup, policy, limiter, storage, account, or mail outcome. Security events contain only bounded action/reason classes and never email, token material, invitation secrets, session ids, or complete verification URLs.

Migration `202608200001_viewer_registration_verification_tokens.php` adds normalized child verification authorities for true resend while preserving the Phase 4.1 primary authority columns on `viewer_registration_requests`. Each child stores only a unique SHA-256 token hash, request foreign key, bounded expiry, creation time, and nullable successful-handoff time. A prepared child is not valid until `sent_at` is recorded. The original primary token and its expiry are never rotated merely because resend was requested. Active child authority is capped per request, expired children are removed by bounded maintenance, and request deletion cascades child rows.

Verification lookup accepts either the historical primary token or a successfully handed-off child token. Both resolve to the same staged registration row and retain the scanner-safe sequence: GET validates only, explicit Viewer-CSRF POST performs the one request-level `email_verified` transition and establishes only the existing short-lived activation grant, then password POST performs durable activation. Successful confirmation through either authority consumes the request-level primary state and removes all child siblings, so the first valid confirmation wins and no sibling can establish a second activation path. Historical Phase 4.1 links therefore remain usable after migration.

Resend delivery is serialized with registration-mode transitions through the existing registration-capacity lock. An open-origin request may resend only while the effective mode is `open`; after `open -> invite_only` or `open -> disabled`, no new resend message is handed to transport. Invitation-backed pending staging may resend in both `invite_only` and `open` while its original invitation authority remains valid. Cancelled or request-lifetime-expired staging is never resurrected. If transport for a newly prepared token fails, only that unsent child authority is discarded; any prior usable primary or sent child authority remains intact. Resend, verification GET, and explicit verification confirmation establish neither Viewer nor Admin identity, and activation still returns to the separate Viewer Login flow.

Phase 4.2 adds no CAPTCHA, Turnstile, reCAPTCHA, hCaptcha, external reputation service, remote challenge JavaScript/API, Composer/npm dependency, Redis/Memcached, queue, worker daemon, public profile, or Phase 5 authentication mechanism. The boundary remains suitable for a later fully first-party Phase 4.3 adaptive challenge without coupling registration authority to a third-party service.

## Phase 4.3 First-party Adaptive Anti-automation Gate

Phase 4.3 inserts one local pre-auth authorization boundary in front of the expensive open-registration and verification-resend workflows. `app/services/viewer_anti_automation.php` owns this boundary. `/viewer/register` and `/viewer/resend` still verify the existing Viewer/pre-auth CSRF token first, validate email syntax locally, then call the anti-automation service before `viewer_registration_request_begin()` or `viewer_registration_verification_resend_prepare()`. Challenge success means only that the current anonymous POST may continue. Registration policy, invitation authority, verification authority, mail authorization, current-mode revalidation, capacity, token lifecycle, durable activation, Viewer login, and Admin identity remain owned by their existing services. Invitation registration and emailed verification GET/POST are deliberately unchanged.

Every protected GET issues a short-lived signed form ticket. The authenticated payload is versioned and action-bound and contains only ticket kind, protected action, a 24-byte random nonce, server issue/expiry timestamps, and a randomized honeypot field identifier. Challenge tickets replace honeypot metadata with server-selected proof difficulty. Tickets are HMAC-authenticated through the existing installation-specific `viewer_security_fingerprint()` authority. The browser never supplies authoritative action, timestamps, nonce, or proof difficulty. The current PHP session stores only scoped HMAC nonce fingerprints plus bounded kind/action/time/difficulty metadata under the isolated `viewer_anti_automation` namespace. Entries are opportunistically pruned, capped at 12 outstanding authorities, and consumed exactly once. No session identifier is encoded in browser-carried state.

Default normalized form lifetime is 600 seconds with hard configuration bounds of 120 to 1800 seconds. The default minimum form age is 2 seconds with hard bounds of 1 to 10 seconds. A populated randomized honeypot suppresses the request before rate-limit, registration, resend, or mail work. Otherwise the service reuses `viewer_rate_limit_consume()` with the fixed `viewer_automation_ip` and `viewer_automation_subnet` policies. Those buckets permit 8 exact-IP attempts and 48 subnet attempts per 600-second window before a 900-second lock. Existing subject normalization/HMAC fingerprinting and bounded database storage therefore remain authoritative, including fail-closed storage behavior. The first two ordinary attempts remain challenge-free; form age below the configured minimum, exact-IP attempt 3 or later, or subnet attempt 12 or later requires a local challenge. A hard anti-automation limiter denial suppresses downstream work. Existing `viewer_register_*`, `viewer_resend_verification_identifier`, and `viewer_verify_mail_*` limits remain unchanged and are still evaluated by their original services after anti-automation authorization.

Each active challenge POST remains subject to the same local anti-automation IP/subnet limiter before proof or fallback acceptance. The active challenge uses only first-party `public/assets/viewer-anti-automation.js` and native Web Crypto. Browser and PHP share the canonical proof input `viewer-aa-pow-v1\n<action>\n<challenge>\n<counter>` and SHA-256; the target is a server-signed number of leading zero bits. Default proof difficulty ranges from 12 to 15 bits with hard configuration bounds of 10 to 16 bits. Difficulty rises only within that range for repeated local requests. Challenge lifetime is 180 seconds and the submitted decimal counter is capped at 1,048,575. PHP performs exactly one SHA-256 verification for a submitted proof and never trusts a browser-side solved flag. Failed submitted proof authority is consumed before verification and replaced with fresh bounded challenge state, preventing replay as a server-side brute-force oracle.

JavaScript and Web Crypto are progressive enhancement rather than an identity prerequisite. When Web Crypto is unavailable, JavaScript fails, or JavaScript is disabled, the same signed challenge provides an explicit first-party fallback. The fallback is Viewer-CSRF protected, session-bound, single-use, short-lived, requires at least 3 seconds of server-measured challenge age, and consumes the existing anti-automation IP/subnet limiter dimensions before allowing continuation. No image/audio CAPTCHA, browser fingerprint, canvas/font/device probe, remote script, remote reputation request, Composer/npm package, Redis/Memcached dependency, queue, worker, or additional PHP extension is introduced.

Public anti-enumeration semantics remain unchanged. A hard honeypot or anti-automation limit suppression for a syntactically valid CSRF-valid request returns the existing generic registration/resend completion wording without entering registration/resend or mail authorization. Challenge pages reveal only that this browser/request needs an additional local verification step and never disclose account, registration, invitation, origin, limiter, or mail state. Phase 4.2 primary token A and sibling verification authorities are untouched by challenge handling; scanner-safe verification GET remains inspection-only; first-confirmed-token-wins behavior and current-mode revalidation remain authoritative. Phase 4.3 adds no migration or persistent schema.

## Phase 4.4 Viewer Registration Security Operations and Phase 4 Closure

Phase 4.4 adds read-only administrator observability to the existing **Admin -> Viewer accounts** surface. No public or Viewer route is added. `app/services/viewer_security_operations.php` owns the aggregate snapshot and the existing `cms_admin_viewer_invitations()` controller only renders its privacy-safe output after the established `require_admin()` and administrator-role boundary. The existing registration selector remains the only registration policy control and still exposes exactly `disabled`, `invite_only`, and `open`; the global Viewer Accounts feature remains the outer master switch and defaults OFF.

The current-state panel reuses existing capability helpers to report the Viewer Accounts master state, effective registration mode, open-registration and resend HTTP availability, normalized first-party anti-automation configuration, and Viewer auth/registration/security-event/rate-limit storage status. Storage status preserves `available`, `unavailable`, and `unknown` distinctions. A failed operations query cannot enable registration, weaken a limiter, change anti-automation behavior, alter mail authorization, or take down the rest of Viewer account administration.

Capacity is read from the existing durable Viewer and staged-registration storage. The panel shows current durable account count versus `max_viewer_accounts`, current staged registration count versus `max_pending_registration_requests`, and aggregate open-origin versus invitation-backed staging counts. No email dimension is exposed. The singleton capacity counters are read only for consistency diagnostics inside the service and are never reconciled or mutated by rendering the page.

Recent activity is derived only from a fixed allowlist in `viewer_security_events`: `viewer.registration_requested`, `viewer.verification_sent`, `viewer.verification_resend_requested`, `viewer.verification_resent`, `viewer.verification_resend_suppressed`, `viewer.automation_challenge_required`, `viewer.automation_challenge_passed`, `viewer.automation_challenge_failed`, and `viewer.automation_request_suppressed`. The service uses bounded SQL aggregation for rolling 24-hour and 7-day windows and a seven-calendar-day daily table. The trend's **Anti-automation interventions** value is defined narrowly as `viewer.automation_challenge_required + viewer.automation_request_suppressed`; challenge pass/fail events remain separately visible in the rolling summaries and are not added to that intervention number. Individual event rows, account ids, IP/IP hashes, user-agent hashes, request ids, and context JSON are never rendered.

Rate-limit visibility reads only the fixed Phase 4 policy subset returned by `viewer_rate_limit_policies()`: registration IP/subnet/identifier/global-day, verification-resend identifier, anti-automation IP/subnet, and the verification-mail email/IP/subnet/global families. An **active subject** is a limiter row whose `last_attempt_at` remains inside that bucket's configured window or whose `locked_until` is still in the future. A **currently locked subject** has `locked_until > now`. Stale records outside the policy window and without a live lock are not counted as active. The registration and verification-mail global-day buckets additionally show current attempts versus configured limit, but only when the row's `first_attempt_at` is still inside the current policy window. Operations queries never call `viewer_rate_limit_consume()`, never reset or delete limiter rows, and never run Viewer security maintenance.

Phase 4.4 creates no migration, metrics table, event table, limiter table, filesystem counter, or telemetry rollup. It reuses `viewer_security_events`, `viewer_rate_limit_buckets`, `viewer_rate_limits`, `viewer_accounts`, `viewer_account_state`, `viewer_registration_requests`, and `viewer_registration_state`. Viewer security operations remain independent of the generic anonymous telemetry subsystem and its enablement, DNT, public collection, rollup, and retention settings. No Viewer security/account data is copied into telemetry storage.

The operations layer is intentionally non-authoritative. Viewing it does not issue or consume anti-automation tickets/nonces, consume rate-limit budgets, mutate registration staging, rotate or consume Phase 4.2 verification authorities, alter invitation state, establish Viewer identity, or change scanner-safe verification. Phase 4.0 current-mode revalidation, Phase 4.1 generic registration semantics, Phase 4.2 token-A/sibling authority behavior, Phase 4.3 first-party anti-automation, Viewer/Admin identity separation, gallery authorization, and Phase 3 sharing remain unchanged.

Phase 4 is complete after Phase 4.4: 4.0 owns registration policy/lifecycle foundations, 4.1 exposes verified-email open registration, 4.2 hardens verification resend/recovery, 4.3 provides fully first-party adaptive anti-automation, and 4.4 provides aggregate administrator operations visibility. No third-party CAPTCHA, reputation, monitoring, analytics, browser-fingerprinting, Composer/npm security package, Redis/Memcached, queue, daemon, or Phase 5 authentication mechanism is introduced.
