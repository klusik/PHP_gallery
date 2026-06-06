# PHP Gallery Database Documentation

This document describes the database schema used by PHP Gallery as of application version 0.74. The source of truth remains the migration files in `database/migrations/`, but this file summarizes the final model and the purpose of each table.

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

Migration files are PHP files returning arrays of SQL statements. They are sorted by filename and applied in order.

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
| `202606060001_exif_gps_default_display.php` | Makes EXIF/GPS display default-enabled globally and converts gallery GPS display into nullable inherit/override state. |

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

galleries
  -> galleries.parent_id
  -> images.gallery_id
  -> gallery_tags.gallery_id
  -> zip_archives.gallery_id
  -> picture_game_votes.gallery_id
  -> gallery_upload_tokens.gallery_id
  -> gallery_flight_maps.gallery_id
  -> image_ai_analysis_jobs.gallery_id

images
  -> galleries.cover_image_id
  -> image_votes.image_id
  -> image_tags.image_id
  -> picture_game_votes.image_a_id
  -> picture_game_votes.image_b_id
  -> picture_game_votes.winner_image_id
  -> image_ai_metadata.image_id
  -> image_ai_analysis_jobs.image_id

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
| `gallery_date` | Optional manual gallery date. |
| `cover_image_id` | Optional linked image used as cover. |
| `cover_image_path` | Optional path-based cover override. |
| `sort_order` | Admin/public ordering. |
| `visibility` | `unpublished`, `public`, or `private`. Some compatibility code knows about older `draft`. |
| `picture_game_enabled` | Enables picture comparison game. |
| `gps_map_enabled` | Nullable EXIF/GPS display override. `NULL` inherits the global `exif_gps_maps_default_enabled` setting, `1` forces map/GPS display on for the branch and `0` forces it off. |
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
| `exif_taken_at` | EXIF capture datetime. |
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

`admin_upload_auto_rename_enabled` stores whether newly uploaded images are automatically renamed after scan with the default media-renamer template. Missing values default to enabled (`1`) for browser, upload API, and mobile WebDAV upload paths.

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

Loads one gallery by clean path or slug, then loads child galleries, image rows, tags, thumbnail metadata, vote state and optional map/lightbox data.

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
5. Do not insert environment-specific data.
6. For sensitive values, store hashes or encrypted ciphers, never raw tokens.
