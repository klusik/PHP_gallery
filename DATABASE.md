# PHP Gallery Database Documentation

This document describes the database schema used by PHP Gallery as of application version 0.87.1. The source of truth remains the migration files in `database/migrations/`, but this file summarizes the final model and the purpose of each table.

## Database Engine

The migrations target MySQL or MariaDB through PDO.

Default table options used by migrations:

```sql
ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
```

The database connection is configured in `config.php` and opened by `app/database.php` through the shared `db()` function.

## Migration System

Applied migrations are recorded in:

```sql
schema_migrations(version, applied_at)
```

Migration files are PHP files returning validated definitions with SQL statements and optional repair callbacks. Legacy statement-list files remain supported. Files are sorted by filename and applied in order.

Current migration sequence:

| Migration | Purpose |
| --- | --- |
| `202604270001_initial_schema.php` | Base users, galleries, images, tags, image votes, settings and ZIP archives. |
| `202604270002_tags_and_theme.php` | Theme/tag seed settings. |
| `202604280001_picture_game.php` | Picture game enable flag, admin logs, comparison votes. |
| `202604280002_admin_logs_status.php` | Admin log status workflow. |
| `202604280003_exif_gps_maps.php` | EXIF and GPS metadata. |
| `202604290001_password_protected_galleries.php` | Gallery password protection and share tokens. |
| `202604290002_persistent_share_links.php` | Persistent share token compatibility. |
| `202604300001_gallery_voting.php` | Gallery voting flag compatibility. |
| `202605010001_gallery_thumbnail_path.php` | Manual cover path. |
| `202605010002_gallery_background_source.php` | Background source type. |
| `202605010003_gallery_background_source_nullable.php` | Background source nullable compatibility. |
| `202605020001_public_url_slugs.php` | Clean gallery/image URL slugs and paths. |
| `202605020002_clean_public_paths.php` | Clean path compatibility. |
| `202605040001_gallery_filename_display.php` | Filename display toggle. |
| `202605050001_admin_log_observability.php` | Structured log category, severity, subject and request data. |
| `202605050002_anonymous_telemetry.php` | Anonymous telemetry tables. |
| `202605060001_gallery_grid_overrides.php` | Gallery grid overrides and inheritance. |
| `202605060002_nsfw_guard.php` | Gallery/image NSFW flags. |
| `202605060003_user_email_login.php` | User email login compatibility. |
| `202605060004_password_reset_tokens.php` | Password reset tokens. |
| `202605070001_gallery_visibility_model.php` | Gallery visibility enum compatibility. |
| `202605070002_gallery_branding_assets.php` | Gallery banner/logo/separator assets. |
| `202605070003_thumbnail_quality_bounds.php` | Thumbnail size bounds. |
| `202605080001_telemetry_thumbnail_variants.php` | Telemetry media variant enum update. |
| `202605110001_auth_rate_limits.php` | Authentication throttling. |
| `202605110002_gallery_description_layout.php` | Gallery description layout option. |
| `202605110003_gallery_manual_date.php` | Manual gallery date. |
| `202605120001_gallery_count_badge_visibility.php` | Count badge visibility. |
| `202605120002_tag_metadata.php` | Tag description compatibility. |
| `202605120003_admin_log_diagnostics.php` | Log fingerprint, method and AJAX diagnostics. |
| `202605160001_upload_automation_tokens.php` | Token-authenticated upload automation. |
| `202605210001_gallery_flight_maps.php` | Flight maps and local nav points. |
| `202605270001_navigation_data_cache.php` | Navigation lookup cache. |
| `202605270002_navigation_data_accounts.php` | Navigation provider account tokens. |
| `202605280001_ai_image_analysis_queue.php` | AI image metadata and worker jobs. |
| `202605290001_user_openai_text_settings.php` | Per-user OpenAI text settings. |
| `202605290002_user_openai_image_input_flag.php` | OpenAI image input permission flag. |
| `202605310001_admin_persistent_auth_and_google_login.php` | Durable admin login and linked Google accounts. |
| `202606010001_gallery_lightbox_browsing_mode.php` | Nullable per-gallery lightbox browsing-mode override. |
| `202606010002_gallery_lightbox_browsing_mode_carousel.php` | Adds `picture_strip` and `3d_carousel`, and upgrades legacy `strip` values. |
| `202606060001_exif_gps_default_display.php` | Makes EXIF/GPS display enabled by default globally and changes `galleries.gps_map_enabled` to nullable inherit/override storage. |
| `202606070001_gallery_date_ranges.php` | Adds optional gallery date range end values. |
| `202607250001_database_maintenance_schema_repair.php` | Creates the transactional cleanup audit table, conditionally repairs partial thumbnail metadata compaction, and removes only proven legacy objects after preserving source geometry. |
| `202608080001_duplicate_photo_ledger.php` | Adds per-administrator reviewed duplicate-pair and exact-gallery ledger tables used by the Admin Duplicate Photo Detector. |
| `202608100001_admin_log_scaling.php` | Adds age and grouping indexes for bounded Admin log retention and grouped browsing on large installations. |

## Entity Relationship Overview

```text
users
  -> image_votes.user_id
  -> admin_logs.user_id
  -> password_reset_tokens.user_id
  -> gallery_upload_tokens.created_by_user_id
  -> navigation_data_accounts.user_id
  -> user_openai_text_settings.user_id
  -> admin_remember_tokens.user_id
  -> user_google_accounts.user_id
  -> duplicate_photo_ledger_pairs.user_id
  -> duplicate_photo_ledger_galleries.user_id

galleries
  -> galleries.parent_id
  -> images.gallery_id
  -> gallery_tags.gallery_id
  -> zip_archives.gallery_id
  -> picture_game_votes.gallery_id
  -> gallery_upload_tokens.gallery_id
  -> gallery_flight_maps.gallery_id
  -> image_ai_analysis_jobs.gallery_id
  -> duplicate_photo_ledger_galleries.gallery_id

images
  -> galleries.cover_image_id
  -> image_votes.image_id
  -> image_tags.image_id
  -> picture_game_votes.image_a_id
  -> picture_game_votes.image_b_id
  -> picture_game_votes.winner_image_id
  -> image_ai_metadata.image_id
  -> image_ai_analysis_jobs.image_id
  -> duplicate_photo_ledger_pairs.image_id_low
  -> duplicate_photo_ledger_pairs.image_id_high

tags
  -> gallery_tags.tag_id
  -> image_tags.tag_id
```

## Core Tables

### `users`

Stores admin users.

Important columns:

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `username` | Unique admin username. |
| `email` | Optional unique email used for login/password reset. |
| `password_hash` | Password hash created by PHP password functions. |
| `role` | Currently `admin`. |
| `created_at`, `updated_at` | Audit timestamps. |

Related services/controllers:

```text
app/controllers/admin_auth.php
app/services/auth_persistence.php
app/services/google_auth.php
app/services/auth_throttle.php
```

### `galleries`

Primary gallery metadata table. A gallery corresponds to a folder on disk and can have a parent gallery.

Important columns:

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `parent_id` | Parent gallery id, nullable. |
| `folder_path` | Gallery folder path relative to gallery root. |
| `folder_path_hash` | SHA-256 style hash used for unique lookup. |
| `slug` | Legacy/simple slug. |
| `url_slug` | Clean URL slug. |
| `url_path` | Full clean URL path for nested galleries. |
| `url_path_hash` | Hash for unique clean path lookup. |
| `title` | Display title. |
| `description` | Public description. |
| `gallery_date` | Optional manual gallery date or date range start. |
| `gallery_date_end` | Optional manual gallery date range end. `NULL` means the gallery has a single date or no manual range end. |
| `cover_image_id` | Optional linked image used as cover. |
| `cover_image_path` | Optional path-based cover override. |
| `sort_order` | Admin/public ordering. |
| `visibility` | `unpublished`, `public`, or `private`. Some compatibility code knows about older `draft`. |
| `picture_game_enabled` | Enables picture comparison game. |
| `gps_map_enabled` | Nullable EXIF/GPS display override. `NULL` inherits `app_settings.exif_gps_maps_default_enabled`, `1` forces EXIF/GPS map and coordinate display on for the gallery branch, and `0` forces it off. |
| `voting_enabled` | Enables image voting. |
| `show_filenames` | Shows filenames in public UI. |
| `description_layout` | `vertical` or `horizontal`, nullable for inherited/default behavior. |
| `count_badge_visibility` | `show` or `hide`, nullable for inherited/default behavior. |
| `lightbox_browsing_mode` | `single`, `picture_strip`, or `3d_carousel`, nullable for inherited Theme behavior. |
| `grid_columns`, `grid_rows` | Optional per-gallery grid dimensions. |
| `grid_use_for_subgalleries` | Whether child galleries inherit grid settings. |
| `nsfw_enabled` | Marks gallery as restricted/sensitive. |
| `thumbnail_min_size`, `thumbnail_max_size` | Optional thumbnail size bounds. |
| `access_mode` | `normal` or `password`. |
| `access_listing` | `listed` or `unlisted`. |
| `access_password_hash` | Password hash for protected galleries. |
| `access_share_token` | Legacy/display share token. |
| `access_token_hash` | Hash used for token lookup. |
| `access_token_expires_at` | Optional token expiry. |
| `background_source` | `upload`, `existing`, `collage`, or null. |
| `banner_image_path` | Gallery banner asset path. |
| `logo_image_path` | Gallery logo asset path. |
| `separator_image_path` | Gallery separator asset path. |
| `created_at`, `updated_at` | Audit timestamps. |

### EXIF/GPS Display Defaults

The global default is stored in `app_settings.exif_gps_maps_default_enabled`. Missing values are treated as enabled (`1`), so scanned EXIF/GPS data is visible unless a gallery branch explicitly opts out.

The per-gallery value is stored in `galleries.gps_map_enabled` after migration `202606060001_exif_gps_default_display.php`:

| Value | Meaning |
| --- | --- |
| `NULL` | Inherit from the closest parent override or the global default. |
| `1` | Force EXIF/GPS map and coordinate display on for this gallery branch. |
| `0` | Force EXIF/GPS map and coordinate display off for this gallery branch. |

Admin dashboard settings use `cms_admin_exif_gps_settings()` to change the global default and optionally reset all gallery overrides to `NULL`. Gallery editing uses the same storage normalization through `gallery_gps_map_storage_value()`, so full-page editing and side-panel editing resolve the same effective state through `gallery_effective_gps_map_enabled()`.

### Lightbox Browsing Mode Settings

The Theme default is stored in `app_settings` under `theme_lightbox_browsing_mode`. Accepted values are `single`, `picture_strip`, and `3d_carousel`; invalid stored values are normalized back to `single` by `app/services/gallery_lightbox_mode.php`. The older `strip` value is accepted as a legacy alias and normalized to `picture_strip`.

The per-gallery override is stored in `galleries.lightbox_browsing_mode`. `NULL` means inherit the Theme default. Non-null values currently support:

| Value | Meaning |
| --- | --- |
| `single` | Use the legacy single-image lightbox for this gallery. |
| `picture_strip` | Use the picture-strip lightbox for this gallery. |
| `3d_carousel` | Use the layered 3D carousel lightbox for this gallery. |

`gallery.json` sidecars may also contain `lightbox_browsing_mode`. The import path stores only valid explicit values; inheritance remains `NULL`.

Important indexes and constraints:

| Name | Purpose |
| --- | --- |
| `galleries_folder_path_hash_unique` | Ensures one DB record per filesystem folder. |
| `galleries_slug_unique` | Legacy slug uniqueness. |
| `galleries_url_path_hash_unique` | Clean URL uniqueness. |
| `galleries_parent_id_foreign` | Parent gallery relation, sets null on parent delete. |
| `galleries_cover_image_id_foreign` | Optional cover image, sets null on image delete. |
| Parent/visibility/order indexes | Efficient gallery listing and tree rendering. |

### `images`

Stores metadata for files inside galleries.

Important columns:

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `gallery_id` | Owning gallery. |
| `relative_path` | File path relative to gallery folder. |
| `relative_path_hash` | Hash for stable lookup. |
| `filename` | Basename shown or used in admin. |
| `url_slug` | Clean image URL slug. |
| `title` | Optional image title. |
| `description` | Optional image caption/description. |
| `width`, `height` | Pixel dimensions. |
| `mime_type` | Detected MIME type. |
| `file_size` | Source file size. |
| `modified_at` | Source file modification timestamp. |
| `checksum_sha256` | Source checksum when known. |
| `exif_taken_at` | EXIF capture datetime. Admin gallery-date suggestions aggregate the minimum and maximum value across a gallery and all descendant galleries, either globally or scoped to a selected gallery branch. |
| `exif_camera_make`, `exif_camera_model` | Camera metadata. |
| `exif_lens_model` | Lens metadata. |
| `exif_focal_length`, `exif_aperture`, `exif_exposure_time`, `exif_iso` | Exposure metadata. |
| `gps_lat`, `gps_lng`, `gps_altitude`, `gps_extracted_at` | GPS metadata. Public display is controlled by the global EXIF/GPS default and the nearest non-null gallery `gps_map_enabled` override. |
| `sort_order` | Order inside gallery. |
| `visibility` | `draft`, `public`, or `private`. |
| `nsfw_enabled` | Image-level restricted flag. |
| `thumbnail_min_size`, `thumbnail_max_size` | Optional image-level thumbnail bounds. |
| `created_at`, `updated_at` | Audit timestamps. |

Important indexes and constraints:

| Name | Purpose |
| --- | --- |
| `images_gallery_path_hash_unique` | Ensures one image row per gallery/path. |
| `images_gallery_id_foreign` | Cascades image rows when gallery is deleted. |
| `images_visibility_sort_index` | Public gallery rendering. |
| `images_gallery_url_slug_index` | Clean image URL lookup. |
| `images_gps_gallery_index` | Map data queries. |
| `images_gallery_nsfw_visibility_index` | Public filtering with NSFW state. |
| `images_thumbnail_bounds_index` | Thumbnail override queries. |

### `duplicate_photo_ledger_pairs`

Stores administrator-confirmed/reviewed image relationships that should not be offered again by the Duplicate Photo Detector. The pair is canonicalized so the smaller image id is always stored in `image_id_low` and the larger in `image_id_high`.

| Column | Meaning |
| --- | --- |
| `user_id` | Administrator who owns the review decision. |
| `image_id_low` | Smaller image id in the canonical ignored pair. |
| `image_id_high` | Larger image id in the canonical ignored pair. |
| `created_at` | When the pair was added to the ledger. |

Primary key `(user_id, image_id_low, image_id_high)` prevents duplicate decisions for the same administrator. Indexes `duplicate_photo_ledger_pairs_low_index` and `duplicate_photo_ledger_pairs_high_index` support cleanup and lookup by either referenced image. Both image foreign keys and the user foreign key use `ON DELETE CASCADE`, so ledger rows disappear automatically when their owning account or either referenced image is deleted. Ledger rows filter future result pairs; image deletion is a separate explicit detector action delegated to the normal gallery image-deletion service.

### `duplicate_photo_ledger_galleries`

Stores exact-gallery suppression rules for the Duplicate Photo Detector. A rule applies only to the stored `gallery_id`; it deliberately does not imply descendants. This keeps a parent gallery and any nested child gallery independently reviewable from the left/right comparison controls.

| Column | Meaning |
| --- | --- |
| `user_id` | Administrator who owns the review decision. |
| `gallery_id` | Exact gallery whose photos should be omitted from duplicate pairs for this administrator. |
| `created_at` | When the gallery rule was added. |

Primary key `(user_id, gallery_id)` keeps each exact-gallery rule unique per administrator. Index `duplicate_photo_ledger_galleries_gallery_index` supports lookup and cascade cleanup by gallery. User and gallery foreign keys use `ON DELETE CASCADE`. **Clear ledger** deletes rows from both ledger tables only for the authenticated administrator.

These tables are created by `database/migrations/202608080001_duplicate_photo_ledger.php`. No changes are made to the `images` matching metadata columns. Existing checksum and EXIF data remains the detector evidence source.

### `tags`

Reusable tag records.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `name` | Human-readable tag name. |
| `slug` | Unique URL-safe slug. |
| `description` | Optional tag description. |
| `created_at`, `updated_at` | Audit timestamps. |

### `gallery_tags`

Many-to-many relation between galleries and tags.

| Column | Meaning |
| --- | --- |
| `gallery_id` | Gallery id. |
| `tag_id` | Tag id. |

Primary key is `(gallery_id, tag_id)`.

### `image_tags`

Many-to-many relation between images and tags.

| Column | Meaning |
| --- | --- |
| `image_id` | Image id. |
| `tag_id` | Tag id. |

Primary key is `(image_id, tag_id)`.

### `app_settings`

Key-value storage for mutable runtime settings.

| Column | Meaning |
| --- | --- |
| `setting_key` | Primary setting key. |
| `setting_value` | Text value, often scalar or JSON depending on helper. |
| `updated_at` | Last update timestamp. |

Use `app/services/app_settings.php` to access this table.

`public_thumbnail_rendering_mode` stores the site-level selected-gallery photo-card renderer. `app/services/public_thumbnail_rendering.php` accepts only `responsive` and `progressive`; missing or invalid values normalize to `responsive`. It is a scalar runtime setting and requires no dedicated schema migration.

Browser-side uploads store their mutable admin configuration in `app_settings` through `app/services/browser_uploads.php`. Current keys are `browser_upload_enabled`, `browser_upload_default_worker_count`, `browser_upload_max_worker_count`, `browser_upload_hard_worker_cap`, `browser_upload_batch_size_policy`, `browser_upload_zip_size_threshold_ratio`, `browser_upload_max_items_per_batch`, `browser_upload_max_zip_batch_bytes` and `browser_thumbnail_rebuild_source_chunk_bytes`. Values are normalized defensively at read time, with worker counts clamped to 1 through 32, the ZIP threshold ratio clamped to 0.10 through 0.95, ZIP item count clamped to 1 through 64, the absolute ZIP upload batch cap clamped to 1 MB through 128 MB, and the browser-assisted thumbnail rebuild source-download chunk clamped to 16 MB through 3 GB. The browser uses the smallest effective upload limit from PHP upload settings, the configured ratio, and the absolute ZIP cap so shared hosting does not need to parse very large browser-prepared upload packages in one request. The source-download chunk setting is larger by design because it streams originals from the server to the browser and does not pass through PHP multipart upload limits.

`theme_favorite_gallery_ids` stores the optional top-navigation shortcuts as a JSON array of up to three entries. Numeric entries are gallery IDs, and the `home` token represents the main gallery page. The value is resolved by `app/services/favorite_galleries.php`; duplicate entries, missing galleries and unavailable public rows are ignored defensively.

## Voting and Game Tables

### `image_votes`

Stores direct image votes.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `image_id` | Voted image. |
| `user_id` | Admin user id if logged in. |
| `visitor_hash` | Anonymous visitor hash. |
| `vote` | `-1` or `1`. |
| `created_at`, `updated_at` | Audit timestamps. |

Uniqueness prevents duplicate votes per image/user or image/visitor.

### `picture_game_votes`

Stores side-by-side comparison votes.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `gallery_id` | Gallery where comparison occurred. |
| `image_a_id`, `image_b_id` | Compared images. |
| `winner_image_id` | Winning image or null. |
| `voter_hash` | Anonymous visitor hash. |
| `created_at` | Vote timestamp. |

## Downloads

### `zip_archives`

Caches generated ZIP archives by scope and content signature.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `scope` | `gallery` or `all`. |
| `gallery_id` | Gallery id for gallery-scoped archive. |
| `file_path` | Generated ZIP path. |
| `content_signature` | Signature of archive content state. |
| `created_at`, `updated_at` | Audit timestamps. |

## Admin Logs and Diagnostics

### `admin_logs`

Structured admin/system log table.

Important columns:

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `user_id` | Admin user when available. |
| `level` | Legacy level: `info`, `warning`, `error`. |
| `category` | `system`, `gallery`, `media`, `upload`, `thumbnail`, `update`, `security`, `database`, `telemetry`, `admin`, `other`. |
| `severity` | `debug`, `info`, `notice`, `warning`, `error`, `critical`. |
| `status` | `todo`, `doing`, `done`, `waiting`. |
| `status_updated_at` | Last status change. |
| `event_key` | Stable event identifier. |
| `subject_type`, `subject_id` | Optional affected entity. |
| `request_id` | Per-request diagnostic id. |
| `route_name` | Route active when event was logged. |
| `fingerprint` | Deduplication or grouping hash. |
| `http_method` | HTTP method. |
| `is_ajax` | Whether the request was AJAX-like. |
| `message` | Human-readable message. |
| `context_json` | Structured context. |
| `resolved_at`, `resolution_note` | Resolution workflow fields. |
| `created_at` | Log timestamp. |

Admin logs remain the live operational history and are not removed by generic database cleanup. Version 0.87 adds indexed age and grouping access paths so Admin browsing, grouped summaries, and bounded retention work do not require an avoidable full-table scan. The explicit Admin log archive workflow moves complete older calendar days into protected filesystem archives before removing their live rows. Archive manifests identify the application version, date, row count, and export format; the archive service verifies the expected row count before deletion and keeps recoverable state when work is interrupted.

## Authentication and Account Security

### `password_reset_tokens`

Stores hashed password reset tokens.

Expected columns from migration:

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `user_id` | User requesting reset. |
| `selector` | Public selector for token lookup. |
| `token_hash` | Secret token hash. |
| `expires_at` | Expiration time. |
| `used_at` | Completion time. |
| `created_at` | Creation time. |

### `auth_rate_limits`

Stores login and reset throttling attempts.

Expected use:

| Column concept | Meaning |
| --- | --- |
| subject/action identifiers | Hashes of visitor or normalized login subject. |
| counters/timestamps | Attempt count, window and lockout state. |

Use `app/services/auth_throttle.php`; do not query this table directly from controllers.

### `admin_remember_tokens`

Stores durable login tokens.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `user_id` | Admin user. |
| `selector` | Cookie selector. |
| `token_hash` | Hash of remember token secret. |
| `user_agent_hash` | Optional browser binding hash. |
| `created_at` | Token creation. |
| `last_used_at` | Last successful restore. |
| `expires_at` | Expiration. |
| `revoked_at` | Manual or logout revocation. |

### `user_google_accounts`

Stores linked Google identities for admin login.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `user_id` | Linked local admin user. |
| `google_sub` | Stable Google subject identifier. |
| `email` | Google email claim. |
| `email_verified` | Google email verification flag. |
| `name` | Google display name. |
| `picture_url` | Google profile image URL. |
| `linked_at` | Link creation. |
| `last_login_at` | Last Google login. |
| `updated_at` | Last account update. |

One local user can have one linked Google account in the current schema.

## Upload Automation

### `gallery_upload_tokens`

Stores hashed gallery-scoped API upload tokens.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `gallery_id` | Target gallery. |
| `token_hash` | Hash of API token. |
| `label` | Optional human label. |
| `active` | Whether token can be used. |
| `created_by_user_id` | Admin creator. |
| `created_at` | Creation timestamp. |
| `last_used_at` | Last successful use. |
| `revoked_at` | Revocation timestamp. |

## Telemetry Tables

Telemetry is intentionally anonymous/bucketed. Use telemetry services instead of direct writes.

### `telemetry_settings`

Stores telemetry preferences and limits.

### `telemetry_sessions`

Stores anonymous session hashes and coarse session metadata.

### `telemetry_events`

Stores individual usage/media events.

Common concepts:

| Concept | Meaning |
| --- | --- |
| event name | Normalized event type. |
| session hash | Anonymous visitor/session identifier. |
| gallery/image references | Optional entity references. |
| media variant | Original/thumb/thumbnail variant, including newer thumbnail variants. |
| context JSON | Sanitized diagnostic context. |

### `telemetry_hourly_metrics`

Aggregated hourly metrics.

### `telemetry_daily_metrics`

Aggregated daily metrics.

### `telemetry_db_query_metrics`

Aggregated database query timing and fingerprint metrics.

### `telemetry_job_runs`

Tracks telemetry maintenance and rollup jobs.

## Maps, SimBrief and Navigation Data

### `gallery_flight_maps`

Stores resolved route data for a gallery flight map.

| Column | Meaning |
| --- | --- |
| `gallery_id` | Primary key and gallery relation. |
| `map_source_type` | Currently `flight_path`. |
| `route_text` | Original route text or parsed route source. |
| `resolved_points_json` | Resolved route points. |
| `unresolved_points_json` | Waypoints that could not be resolved. |
| `point_count` | Number of resolved points. |
| `resolved_at` | Last route resolution time. |
| `created_at`, `updated_at` | Audit timestamps. |

### `flight_map_nav_points`

Local navigation point table.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `ident` | Waypoint/NAVAID/airport identifier. |
| `kind` | Point type, default `waypoint`. |
| `region` | Optional region. |
| `latitude`, `longitude` | Coordinates. |
| `source` | Dataset source. |
| `cycle` | AIRAC or data cycle. |
| `created_at`, `updated_at` | Audit timestamps. |

Unique key: `(ident, kind, region)`.

### `navigation_data_cache`

Caches provider or local lookup results.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `cache_key` | Unique cache key. |
| `ident` | Requested identifier. |
| `kind` | Point type. |
| `source` | Source/provider. |
| `cycle` | Dataset cycle. |
| `payload_json` | Cached lookup payload. |
| `expires_at` | Optional expiration. |
| `created_at`, `updated_at` | Audit timestamps. |

### `navigation_data_accounts`

Stores linked navigation provider credentials.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `user_id` | Owning admin user. |
| `provider` | Provider name, default `navigraph`. |
| `access_token_cipher`, `refresh_token_cipher`, `id_token_cipher` | Encrypted token values. |
| `token_expires_at` | Token expiry as Unix-style integer. |
| `scope_text` | OAuth scope text. |
| `claims_json` | Identity claims. |
| `subscription_json` | Subscription or package details. |
| `package_cycle`, `package_status`, `package_format` | Provider package metadata. |
| `package_checked_at` | Last subscription/package check. |
| `connected_at`, `updated_at` | Audit timestamps. |

## AI Metadata Tables

### `image_ai_metadata`

Stores generated image analysis metadata.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `image_id` | Source image. |
| `model_name`, `model_version` | Generator identity. |
| `source_checksum_sha256`, `source_file_size`, `source_modified_at` | Source freshness tuple. |
| `metadata_json` | Structured metadata. |
| `searchable_text` | Text included in public/admin search. |
| `generated_at` | Generation timestamp. |
| `created_at`, `updated_at` | Audit timestamps. |

Unique key: `(image_id, model_name, model_version)`.

### `image_ai_analysis_jobs`

Queue table for image analysis workers.

| Column | Meaning |
| --- | --- |
| `id` | Primary key. |
| `gallery_id`, `image_id` | Target gallery and image. |
| `job_key` | Unique idempotency key. |
| `model_name`, `model_version` | Requested generator. |
| source freshness fields | Detect whether metadata is stale. |
| `state` | `queued`, `claimed`, `succeeded`, `failed`, or `cancelled`. |
| `claim_owner`, `claim_token_hash`, `claim_expires_at` | Worker lease. |
| `claimed_at`, `heartbeat_at` | Worker progress timestamps. |
| `progress_percent`, `progress_message` | User-visible progress. |
| `attempt_count` | Retry count. |
| `available_at` | Earliest retry time. |
| `completed_at` | Completion timestamp. |
| `last_error` | Last failure. |
| `created_at`, `updated_at` | Audit timestamps. |

## OpenAI User Settings

### `user_openai_text_settings`

Stores per-user OpenAI API configuration.

| Column | Meaning |
| --- | --- |
| `user_id` | Primary key and user relation. |
| `enabled` | Whether OpenAI assist is enabled. |
| `api_key_cipher` | Encrypted API key. |
| `api_key_hint` | Short display hint. |
| `model` | Selected model. |
| `allow_image_input` | Whether image input may be sent to OpenAI. |
| `created_at`, `updated_at` | Audit timestamps. |

## Administrator Database Audit and Cleanup Policy

The Admin database-maintenance inspection dynamically audits every table in the active schema. The following registry covers all current application tables and both current and legacy migration audit tables. Additional tables discovered at runtime are classified as `unknown/unclassified` and protected until an explicit ownership and deletion policy is implemented. A high row count, old timestamp, NULL value, or large allocation is never sufficient evidence for deletion.

The structured audit is cached at `cache/admin-database-maintenance-report.json`. It includes table and column metadata, keys, foreign keys, migration history references, broad code references, separately scoped production/test SQL references, cleanup candidates, reasons, confidence, and table-specific policies. Obsolete-column confidence is based on same-statement production SQL evidence, not a repository-wide word match.

| Table | Category | Owner | Safe orphan rule | Retention rule | Duplicate rule | Protection and operation mode |
| --- | --- | --- | --- | --- | --- | --- |
| `galleries` | gallery/content data | Filesystem gallery folder and gallery metadata | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Valid gallery content is always protected. Cleanup: **disabled**. Physical optimization: **manual**. |
| `images` | gallery/content data | Gallery and source image | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Valid image content is always protected. Cleanup: **disabled**. Physical optimization: **manual**. |
| `duplicate_photo_ledger_pairs` | administrator workflow state | Per-administrator reviewed duplicate image relationships | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | No generic duplicate-cleanup rule is defined. | Ledger decisions are removed only by the Duplicate Photo Detector controls or foreign-key cascades. Cleanup: **disabled**. Physical optimization: **manual**. |
| `duplicate_photo_ledger_galleries` | administrator workflow state | Per-administrator exact-gallery duplicate suppression rules | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | No generic duplicate-cleanup rule is defined. | Ledger decisions are removed only by the Duplicate Photo Detector controls or foreign-key cascades. Cleanup: **disabled**. Physical optimization: **manual**. |
| `tags` | gallery/content data | Administrator-defined taxonomy | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Unused tags may be intentional and are not removed automatically. Cleanup: **disabled**. Physical optimization: **manual**. |
| `gallery_tags` | gallery/content data | Gallery and tag link | Remove only links whose gallery or tag parent is missing. | No retention rule. | Composite primary key defines one gallery/tag link. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `image_tags` | gallery/content data | Image and tag link | Remove only links whose image or tag parent is missing. | No retention rule. | Composite primary key defines one image/tag link. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `image_votes` | gallery/content data | Image vote | Remove only votes whose image parent is missing. | No retention rule. | Existing user/visitor unique keys define identity. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `picture_game_votes` | gallery/content data | Gallery picture-game vote | Remove only votes whose gallery or referenced image is missing. | No retention rule. | Gallery, voter, and image-pair unique key defines identity. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `zip_archives` | caches and temporary state | Generated ZIP metadata | Remove only gallery-scoped rows whose gallery parent is missing. Never delete ZIP files from this workflow. | No age-based deletion. | Content signature participates in lookup identity. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `gallery_upload_tokens` | authentication and accounts | Gallery upload credential | Remove only tokens whose gallery parent is missing. | Revoked tokens are preserved unless a separate credential policy removes them. | Token hash is unique. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `gallery_flight_maps` | external integration data | Gallery flight-map metadata | Remove only rows whose gallery parent is missing. | No retention rule. | One row per gallery primary key. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `image_ai_metadata` | gallery/content data | Image AI-derived metadata | Remove only rows whose image parent is missing. | No age-based deletion. | Image, model name, and model version define identity. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `image_ai_analysis_jobs` | caches and temporary state | Gallery image analysis queue | Remove only jobs whose gallery or image parent is missing. | Completed jobs are not deleted by age in this workflow. | Job key is unique. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `image_thumbnail_variants` | caches and temporary state | Generated thumbnail metadata only, never image bytes | Remove only rows whose image parent is missing. | Unsupported sizes and formats are reported, not deleted automatically. | Image, size, and format define identity; deterministic duplicate survivor is the lowest id. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `flight_map_nav_points` | external integration data | Imported navigation dataset | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Data lifecycle is owned by the navigation-data refresh workflow. Cleanup: **disabled**. Physical optimization: **manual**. |
| `navigation_data_accounts` | external integration data | User navigation provider credential | Remove only rows whose user parent is missing. | Token expiry does not imply account deletion. | User and provider define identity. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `navigation_data_cache` | caches and temporary state | Navigation lookup cache | No parent relationship. | Rows are eligible only when expires_at is explicitly in the past. | cache_key is unique; deterministic duplicate survivor is the lowest id. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `users` | authentication and accounts | Administrator account | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Accounts are never removed by database maintenance. Cleanup: **disabled**. Physical optimization: **manual**. |
| `admin_remember_tokens` | authentication and accounts | Administrator persistent login token | Remove only rows whose user parent is missing. | Expired or explicitly revoked credentials are eligible. | Selector is unique. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `password_reset_tokens` | authentication and accounts | One-time password reset credential | Remove only rows whose user parent is missing. | Expired or already-used credentials are eligible. | Selector is unique. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `auth_rate_limits` | authentication and accounts | Authentication throttle state | No parent relationship. | Use the application policy: inactive rows older than 24 hours and expired locks older than 24 hours. | Bucket and subject hash define identity. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `mobile_webdav_upload_tokens` | authentication and accounts | User and gallery WebDAV credential | Remove only rows whose user or gallery parent is missing. | Disabled credentials are preserved. | Path token is unique. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `user_google_accounts` | authentication and accounts | User Google account link | Remove only rows whose user parent is missing. | No retention rule. | User id and Google subject are unique identities. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `user_openai_text_settings` | settings | User OpenAI text profile | Remove only rows whose user parent is missing. | No retention rule. | One row per user. | Only rows matching a listed high-confidence rule may be removed. Cleanup: **automatic**. Physical optimization: **manual**. |
| `app_settings` | settings | Application configuration | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Unknown and compatibility settings are retained. Dedicated migrations may remove proven obsolete keys. Cleanup: **disabled**. Physical optimization: **manual**. |
| `admin_logs` | audit logs | Administrator audit and diagnostics history | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Never deleted by generic database cleanup. Retention requires a separate explicit policy. Cleanup: **disabled**. Physical optimization: **manual**. |
| `database_maintenance_audit_log` | audit logs | Transactional database cleanup audit trail | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Immutable row-identifier audit records are never deleted by generic database cleanup. Cleanup: **disabled**. Physical optimization: **manual**. |
| `telemetry_settings` | telemetry/analytics | Telemetry configuration | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Never deleted by generic database cleanup. Cleanup: **disabled**. Physical optimization: **manual**. |
| `telemetry_sessions` | telemetry/analytics | Anonymous telemetry session data | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Retention is owned by the separate telemetry maintenance workflow. Cleanup: **disabled**. Physical optimization: **manual**. |
| `telemetry_events` | telemetry/analytics | Raw telemetry events | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Retention is owned by the separate telemetry maintenance workflow. Cleanup: **disabled**. Physical optimization: **manual**. |
| `telemetry_hourly_metrics` | telemetry/analytics | Hourly telemetry aggregates | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Retention is owned by the separate telemetry maintenance workflow. Cleanup: **disabled**. Physical optimization: **manual**. |
| `telemetry_daily_metrics` | telemetry/analytics | Daily telemetry aggregates | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Retention is owned by the separate telemetry maintenance workflow. Cleanup: **disabled**. Physical optimization: **manual**. |
| `telemetry_db_query_metrics` | telemetry/analytics | Database query aggregates | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Retention is owned by the separate telemetry maintenance workflow. Cleanup: **disabled**. Physical optimization: **manual**. |
| `telemetry_job_runs` | telemetry/analytics | Telemetry maintenance job history | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Never deleted by generic database cleanup. Cleanup: **disabled**. Physical optimization: **manual**. |
| `schema_migrations` | migration/system tables | Current migration audit trail | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Migration audit rows are immutable. Cleanup: **disabled**. Physical optimization: **manual**. |
| `migrations` | migration/system tables | Legacy migration audit trail when present | Disabled. No generic deletion semantics are assumed. | No automatic retention deletion. | Report only unless a deterministic logical identity is explicitly defined. | Legacy audit rows are preserved for old installations. Cleanup: **disabled**. Physical optimization: **manual**. |

### Cleanup confidence and execution

Only `high` confidence rules enter the generic cleanup runner. Current categories are proven parent orphans, deterministic logical duplicates with the lowest numeric ID retained, and temporary/authentication state whose real expiry columns or application retention logic declare it expired. Every action supports dry-run, explicit Admin confirmation, bounded batches, persisted resumable state, idempotent retry, before/after accounting, and a visible failure state.

Each live batch first selects a deterministic primary-key identity set, then deletes exactly that bounded set inside a transaction. Before commit, the same transaction writes the operation ID, rule, table, reason, identifier columns, every removed row identity, and deleted count to `database_maintenance_audit_log`. An audit insertion failure or identifier-count mismatch rolls back the deletion. The general `admin_logs` entry is secondary and is not relied upon for audit integrity.

The workflow does not delete filesystem media, thumbnails, ZIP files, galleries, images, accounts, audit history, telemetry, migration history, imported navigation datasets, or unknown tables. Audit-log and telemetry retention remain separate policies and controls.

### Thumbnail metadata special handling

`image_thumbnail_variants` stores metadata only. It does not store thumbnail image bytes. Inspection groups rows by `size_px`, `format`, and `status`, compares sizes with `thumbnail_sizes()`, accepts the production formats `jpg` and `webp`, reports unsupported combinations, and never deletes unsupported variants automatically. Generic cleanup may remove only rows with a proven missing `images` parent and deterministic duplicate logical rows keyed by `(image_id, size_px, format)`. Physical table optimization is separate from logical row cleanup.

The only repository-proven obsolete schema objects are the legacy pre-compaction columns `gallery_id`, `thumbnail_rel_path`, `source_width`, `source_height`, `source_mime_type`, `source_file_size`, `source_modified_at`, `source_checksum_sha256`, `source_exif_orientation`, and `source_exif_json`, plus `image_thumbnail_variants_gallery_index` and `image_thumbnail_variants_gallery_id_foreign`. The 2026-07-25 repair migration copies source geometry and orientation to `images` first, checks every object, drops only objects still present, validates the compact result, and creates the transactional cleanup audit table when absent. Historical migrations are unchanged.

### Statistics and physical storage

`ANALYZE TABLE` is a selected-table action that refreshes optimizer statistics. It does not reclaim table files. `OPTIMIZE TABLE` is a different selected-table action with a separate `OPTIMIZE` confirmation. Before that confirmation, the Admin workflow can generate a selected-table dry-run plan showing operation type, allocated bytes, reclaimable-byte estimate, engine, and warnings without executing any table statement. It may lock or rebuild tables and may be expensive on shared hosting. `information_schema.DATA_FREE` is an engine estimate, not a guaranteed number of bytes that the hosting filesystem will return. Inspection and logical cleanup never invoke `OPTIMIZE TABLE`. Schema repair also has a fresh, non-mutating dry-run that reports the pending migration and exact legacy objects before any DDL is applied.

## Data Integrity Rules

1. `galleries.folder_path_hash` identifies a folder uniquely.
2. `images.gallery_id + images.relative_path_hash` identifies an image uniquely inside a gallery.
3. Deleting a gallery cascades images, gallery tags, ZIP archive rows, upload tokens, flight maps and AI jobs.
4. Deleting an image cascades image tags, votes, picture-game references and AI metadata/jobs.
5. Deleting a user sets some historical references to null but cascades private account token/config rows.
6. Mutable runtime options belong in `app_settings`, not in `config.php`.
7. New schema changes should be added through new migrations only.
8. Code that may execute before all migrations are applied should use `db_table_exists()` or `db_column_exists()` guards.

## Common Query Patterns

### Public gallery listing

Uses `galleries` filtered by parent, visibility, access listing and sort order. Related services include `gallery_lookup.php`, `gallery_display.php` and `pagination.php`.

### Gallery detail page

Loads one gallery by clean path or slug, then loads child galleries, image rows, tags, thumbnail metadata, vote state, manual date range and optional map/lightbox data.

### Public search

Searches gallery titles/descriptions/tags and image titles/descriptions/filenames/tags/searchable AI metadata depending on context and settings.

### Sitemap

Uses public gallery and image rows plus derived filesystem timestamps. Current sitemap freshness logic is in `app/services/public_paths.php`.

### Thumbnail maintenance

Uses `images` and filesystem-derived thumbnail inventory. Thumbnail files are derived artifacts and should be regenerated rather than treated as source truth.

## Migration Authoring Guidelines

Use this pattern for future migration files:

```php
<?php

declare(strict_types=1);

return [
    "ALTER TABLE galleries ADD COLUMN example_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER updated_at",
    "ALTER TABLE galleries ADD KEY galleries_example_flag_index (example_flag)",
];
```

Rules:

1. Use a new timestamped file name.
2. Do not edit old migrations unless fixing a syntax error before release.
3. Keep statements independent when possible.
4. Include indexes in the same migration when the new query pattern needs them.
5. Manual gallery date ranges use `gallery_date` plus nullable `gallery_date_end`; do not replace the start column because older installs and sidecars depend on it. EXIF suggestions only read `images.exif_taken_at` and then persist approved values back to these gallery date columns through the shared `gallery_date_save_range()` writer. Date-range labels must use an en dash (`–`) when rendered for visitors, editor suggestions or admin review.
6. Do not insert environment-specific data.
7. For sensitive values, store hashes or encrypted ciphers, never raw tokens.
