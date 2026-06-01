# PHP Gallery Architecture

PHP Gallery is a plain-PHP, filesystem-backed photo gallery CMS designed for normal shared hosting. It avoids frameworks and build steps while still keeping the application organized into controllers, services, views, migrations, and public assets.

This document is intended to help future maintainers and AI coding agents understand the application without rediscovering the same structure from source every time.

## Current Application Version

The runtime version is defined in `app/bootstrap.php`:

```php
const CMS_VERSION = '0.73';
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

4. **Database migrations are append-only**
   Schema changes are stored as sequential PHP migration files in `database/migrations/`. Migrations are applied in filename order and recorded in `schema_migrations`.

5. **Shared hosting compatibility**
   Runtime dependencies are intentionally small. The app expects PHP, PDO MySQL, Apache-style rewrite support when available, and writable directories for galleries, cache, uploads, thumbnails, and generated assets.

6. **Defensive updates**
   Migrations tolerate some duplicate DDL errors to recover from interrupted installs or partially applied browser updates.

7. **Feature isolation**
   Recent features are usually introduced as focused controller and service files instead of expanding old monolithic files.

## Runtime Entry Points

### Public request entry

Root-level `index.php` delegates to `public/index.php`, and `public/index.php` loads the application bootstrap.

Typical request flow:

```text
browser request
  -> index.php or public/index.php
  -> app/bootstrap.php
  -> cms_run()
  -> cms_route_from_request()
  -> route handler from cms_run() route table
  -> controller function
  -> service functions
  -> view helpers or JSON/file response
```

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

`app/bootstrap.php` is the central runtime loader. It does the following:

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

The definitive route table is in `cms_run()` inside `app/bootstrap.php`. Important groups are listed here for orientation.

### Public gallery routes

| Page | Handler | Responsibility |
| --- | --- | --- |
| `home` | `cms_home` | Public landing page with top-level galleries. |
| `gallery` | `cms_gallery` | Public gallery page with images, subgalleries, tags, maps, search, voting, and inline admin controls. |
| `tag` | `cms_tag` | Public tag page and tag-filtered gallery listing. |
| `public_search` | `cms_public_search` | JSON search endpoint for public gallery search bars. |
| `gallery_lightbox_data` | `cms_gallery_lightbox_data` | JSON payload for lightbox navigation and metadata. |
| `gallery_map_data` | `cms_gallery_map_data` | JSON map data from EXIF GPS and flight paths. |
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
| `download_all` | `cms_download_all` | Generates or serves full accessible archive. |

### Admin routes

| Page | Handler | Responsibility |
| --- | --- | --- |
| `admin` | `cms_admin` | Admin dashboard. |
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
| `admin_edit_gallery` | `cms_admin_edit_gallery` | Edits gallery metadata and advanced settings. |
| `admin_bulk_galleries` | `cms_admin_bulk_galleries` | Bulk gallery actions. |
| `admin_reorder_galleries` | `cms_admin_reorder_galleries` | Admin hierarchy/order changes. |
| `admin_reorder_public_galleries` | `cms_admin_reorder_public_galleries` | Public ordering changes. |
| `admin_upload` | `cms_admin_upload` | Upload images into existing or new galleries. |
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

### Integration and automation routes

| Page | Handler | Responsibility |
| --- | --- | --- |
| `upload_automation_upload` | `cms_upload_automation_upload` | External watcher upload API. |
| `admin_upload_automation_token` | `cms_admin_upload_automation_token` | Token management for upload automation. |
| `admin_api_manager` | `cms_admin_api_manager` | API/export/import manager entry. |
| `gallery_migration_*` | Multiple handlers | Manifest, asset transfer, receive status and completion for gallery migration. |
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
| `app/controllers/admin_dashboard.php` | Dashboard, dev mode, migrations, rewrite/public search settings and navdata maintenance card. |
| `app/controllers/admin_theme.php` | Theme and branding settings form. |
| `app/controllers/admin_galleries*.php` | Gallery discovery, creation, edit, reorder, bulk actions and scan actions. |
| `app/controllers/admin_images*.php` | Bulk image operations and reorder actions. |
| `app/controllers/admin_public_inline.php` | Inline public-page editing for logged-in admins. |
| `app/controllers/admin_uploads.php` | Upload UI and upload processing. |
| `app/controllers/admin_thumbnails.php` | Thumbnail creation, deletion, maintenance and notices. |
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
| Settings | `app_settings.php`, `theme.php`, `custom_css.php`, `translations.php` | DB-backed settings, theme defaults, CSS variables, language packs. |
| Gallery model | `gallery_lookup.php`, `gallery_mutations.php`, `gallery_paths.php`, `gallery_display.php`, `gallery_grid.php`, `gallery_dates.php`, `gallery_count_badges.php`, `gallery_description_layout.php` | Gallery queries, edits, URLs, display inheritance and presentation options. |
| Gallery assets | `gallery_covers.php`, `gallery_backgrounds.php`, `gallery_branding.php`, `favicon.php` | Cover, background, banner, logo, separator and favicon handling. |
| Images | `image_scanning.php`, `uploads.php`, `dng_derivatives.php`, `picture_manager.php` | Image discovery, metadata scan, upload, copy/move and DNG helper logic. |
| Thumbnails | `thumbnails.php`, `thumbnail_sources.php`, `thumbnail_generation.php`, `thumbnail_bundles.php`, `thumbnail_formats.php`, `thumbnail_html.php`, `thumbnail_bounds.php`, `thumbnail_maintenance.php` | Thumbnail pathing, static serving, generation, quality bounds and responsive HTML. |
| Access | `gallery_access.php`, `auth_persistence.php`, `auth_throttle.php`, `google_auth.php`, `download_signatures.php` | Protected gallery access, admin sessions, durable login, Google linking, download signatures. |
| Tags | `tags.php`, `tag_metadata.php` | Tag CRUD, slugs, entity linking and weighted suggestions. |
| Search | `public_search.php`, `lightbox_metadata.php` | Public search across galleries, images, tags and AI metadata. |
| Maps and aviation | `exif.php`, `flight_maps.php`, `navigation_data.php`, `simbrief_descriptions.php` | EXIF GPS, flight route maps, waypoint lookup and SimBrief OFP processing. |
| AI | `ai_image_analysis.php`, `openai_text_assist.php` | Local AI metadata queue, OpenAI text/image-description integration. |
| Telemetry | `telemetry.php`, `telemetry_privacy.php`, `telemetry_settings.php`, `telemetry_rollup.php`, `database_observer.php` | Anonymous usage events, media serving metrics, privacy bucketing and rollups. |
| Admin operations | `admin_dashboard.php`, `admin_render_profiler.php`, `logs.php`, `updates.php`, `github.php`, `gallery_migration.php` | Dashboard model, diagnostics, audit logs, GitHub update checks and API migration. |

## View Layer

`app/views.php` loads shared rendering helpers and view modules. The application does not use a template engine. HTML is emitted by PHP functions.

Important view files:

| File | Purpose |
| --- | --- |
| `app/views/layout.php` | Page layout and shared chrome. |
| `app/views/admin_chrome.php` | Admin navigation and shared admin page shell. |
| `app/views/admin_dashboard.php` | Dashboard visual sections. |
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

## Migration System

`app/migrations.php` implements the migration runner.

Migration rules:

1. Files are read from `database/migrations/*.php`.
2. Files are sorted lexically, so timestamp prefixes define execution order.
3. Each migration returns an array of SQL statements.
4. Each statement is executed by `apply_migration_statement()`.
5. The migration is recorded in `schema_migrations` only after all statements in that file complete.
6. Duplicate DDL errors are treated as safe replays for common MySQL/MariaDB object-exists cases.

The migration table is created automatically:

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(64) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL
)
```

Use migrations for all schema changes. Do not alter tables from controller code.

## Settings System

DB-backed settings live in `app_settings` and are accessed through `app/services/app_settings.php`.

Core helpers:

```php
app_setting(string $key, ?string $default = null): ?string
set_app_setting(string $key, ?string $value): void
delete_app_settings(array $keys): void
```

Settings are used for URL rewrites, site name, dev mode, collapsed admin state, public search, theme behavior, telemetry preferences and related runtime options.

When adding a setting:

1. Add a named helper when the setting has validation or conversion rules.
2. Use `app_setting()` only for simple scalar settings.
3. Keep defaults in service functions, not in templates.
4. Save from admin controllers after CSRF validation.
5. Do not create new config.php values for mutable runtime options.

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
app/services/custom_css.php
app/services/favicon.php
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
11. Custom CSS presets.

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

Persistence details:

```text
database/migrations/202606010001_gallery_lightbox_browsing_mode.php
database/migrations/202606010002_gallery_lightbox_browsing_mode_carousel.php
galleries.lightbox_browsing_mode ENUM('single','picture_strip','3d_carousel') NULL
gallery.json key: lightbox_browsing_mode
```

## Thumbnail Model

Thumbnails are generated and served through the thumbnail service family.

Important concepts:

1. Thumbnails are derived files, not source data.
2. Source images remain in gallery folders.
3. Generated thumbnails can be JPEG and sometimes WebP depending on source and PHP imaging support.
4. Thumbnail bounds can be configured globally, per gallery and per image.
5. Admin maintenance screens can generate or delete thumbnails.
6. Public pages use responsive picture helpers to select suitable variants.

Use the service family instead of hardcoding thumbnail paths:

```text
thumbnail_sources.php
thumbnail_generation.php
thumbnail_bundles.php
thumbnail_html.php
thumbnail_maintenance.php
thumbnail_bounds.php
```

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

## Gallery Migration and API Transfer

Gallery migration is implemented by:

```text
app/controllers/gallery_migration.php
app/services/gallery_migration.php
app/views/admin_gallery_migration.php
```

The migration feature exchanges manifests and assets between two gallery installs. It supports receive status and completion endpoints so a transfer can compare already-present files and avoid re-sending successful assets after reconnects.

## Upload Automation and Windows Watcher

Upload automation is implemented by:

```text
app/controllers/upload_automation.php
app/services/upload_automation.php
winapp/gallery_watch_upload.pyw
winapp/requirements.txt
```

`gallery_upload_tokens` stores hashed tokens scoped to galleries. External tools should authenticate with those tokens and should not require admin session cookies.

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
| `public/assets/telemetry.js` | Telemetry event capture. |
| `public/assets/usage.js` | Usage collection helper. |
| `public/assets/custom.css` | Public custom CSS entry. |

Keep JavaScript vanilla unless the project explicitly adopts a front-end dependency system.

## Testing

Tests live in `tests/`. Current tests are direct PHP scripts rather than a PHPUnit suite.

Examples:

```text
tests/gallery_branding_model_test.php
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

## Recommended AI Maintenance Workflow

When asking an AI agent to modify this project, include these documents with the ZIP:

```text
ARCHITECTURE.md
CODEMAP.md
DATABASE.md
```

For small changes, the agent should first inspect `CODEMAP.md` and only open the relevant files. For database changes, the agent should inspect `DATABASE.md`, then create a new migration instead of editing historical migrations.

After any structural change, update these docs in the same patch.
