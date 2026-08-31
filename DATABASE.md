# PHP Gallery Database Documentation

This document describes the database schema used by PHP Gallery as of application version 0.94.5. Version 0.94.5 adds the updater server-policy reconciliation migration; the source of truth remains the migration files in `database/migrations/`, but this file summarizes the final model and the purpose of each table.

## Database Engine

### Multilingual content

Migration `202608150001_multilingual_content.php` adds nullable `content_language` tags to `galleries` and `images`, plus `gallery_translations` and `image_translations`. Existing titles/descriptions are not copied or reclassified; null means the source language is unspecified. Translation tables use owner/language unique keys and cascading foreign keys. Nullable title and description fields permit independent fallback, and rows with both fields blank are removed.

Base fields remain the compatibility/source representation. Additional-language rows never affect slugs, filesystem paths, ordering, visibility, passwords, NSFW policy, or media authorization. Gallery sidecars may contain validated `content_language` and `translations` data; unsupported language keys are ignored by persistence normalization.

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
| `202608180001_viewer_security_foundations.php` | Adds dormant viewer identity, hashed security-token/session, bounded abuse-control, favourites/collection-reference, collection share-token, audit, and passkey-public-credential foundations. No viewer route is enabled. |
| `202608180002_viewer_registration_foundations.php` | Adds dormant expiring pending-registration rows, administrator-issued hashed invitations, and a locked hard-cap counter. No account, route, form, or email sender is created. |
| `202608180003_viewer_authentication_foundations.php` | Adds the lockable singleton `viewer_account_state` counter used to serialize the hard durable viewer-account installation cap. Replay-safe `CREATE TABLE IF NOT EXISTS`; no existing table is rebuilt. |
| `202608180004_viewer_account_lifecycle_foundations.php` | Adds dormant staged verified-email-change state with hashed verification authority and security-version binding. Replay-safe `CREATE TABLE IF NOT EXISTS`; no route, form, sender, or existing table rebuild is introduced. |
| `202608180005_viewer_invitation_admin_management.php` | Adds nullable administrator-visible `viewer_invitations.target_email` metadata while retaining the existing HMAC email fingerprint as invitation authorization authority. |
| `202608180006_viewer_admin_account_management.php` | Adds `viewer_accounts.must_change_password` with a default of `0`, allowing administrator-provisioned temporary passwords to be replaced before a normal viewer principal is established while preserving existing account login behavior. |
| `202608200001_viewer_registration_verification_tokens.php` | Adds bounded child verification authorities for explicit registration resend without replacing the existing Phase 4.1 primary verification token. Stores only token hashes and cascades with the owning staged registration request. |
| `202608300001_link_favicon_cache.php` | Adds hostname-keyed metadata for bounded gallery-description favicon discovery, including success/failure state, validated cached-file metadata, and retry timing. |

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
  -> viewer_invitations.created_by_admin_user_id (nullable, SET NULL on admin deletion)

viewer_invitations
  -> viewer_registration_requests.viewer_invitation_id (nullable, unique)

viewer_registration_requests
  -> viewer_registration_verification_tokens.viewer_registration_request_id (CASCADE)

viewer_accounts
  -> viewer_email_verification_tokens.viewer_account_id
  -> viewer_password_reset_tokens.viewer_account_id
  -> viewer_remember_tokens.viewer_account_id
  -> viewer_sessions.viewer_account_id
  -> viewer_security_events.viewer_account_id (nullable, SET NULL on account deletion)
  -> viewer_favourites.viewer_account_id
  -> viewer_collections.viewer_account_id
  -> viewer_collection_share_tokens.created_by_viewer_account_id (nullable)
  -> viewer_passkeys.viewer_account_id

viewer_collections
  -> viewer_collection_items.viewer_collection_id
  -> viewer_collection_share_tokens.viewer_collection_id

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
  -> viewer_favourites.image_id
  -> viewer_collection_items.image_id

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

### Viewer identity and security foundation tables

The viewer domain is deliberately separate from `users`. `current_user()` remains the administrator principal and must never resolve a row from `viewer_accounts`. The future viewer principal is `current_viewer()` and uses viewer-only session/database state. This prevents a viewer login from satisfying historical administrator access checks.

`viewer_accounts` contains only the minimum viewer identity/security data: email, deterministic `normalized_email`, native password hash, lifecycle status, security version, security timestamps, the server-owned `must_change_password` first-login flag, and creation/update timestamps. Its lifecycle states are `pending_verification`, `active`, `suspended`, and `disabled`. The binary unique index on `normalized_email` makes the application's chosen email comparison deterministic. `must_change_password=0` is the compatibility default; `1` marks an administrator-provisioned temporary credential that may prove the first login but cannot establish a normal viewer principal or remember-me authority until replaced.

Phase 0.6 activates a durable row only after verified staging has been bound to short-lived server-side activation authority. The activation transaction re-locks staging/invitation state, enforces the binary unique email constraint, locks `viewer_account_state`, recounts authoritative durable rows, enforces `max_viewer_accounts`, inserts one `active` row with `security_version=1` and verified/password timestamps, deletes the staging row, and updates both capacity counters before commit. `viewer_account_state.account_count` counts every durable viewer-account row regardless of lifecycle status, so suspended/disabled accounts still consume the installation cap.

Administrator direct provisioning uses the same durable account namespace and the same locked `viewer_account_state` installation-cap discipline instead of introducing a second account type. The Admin-authorized email is stored as verified immediately, the temporary password is stored only through `password_hash`, and the row is created `active` with `security_version=1`, `must_change_password=1`, and no completed `password_changed_at`. A successful first-login replacement atomically writes the new hash, clears `must_change_password`, records `password_changed_at`, increments `security_version`, and invalidates older viewer authentication/recovery authority before a normal viewer session is established. Password reset also clears the flag. The plaintext temporary password is not a database field.

`viewer_account_state` is deliberately tiny: `state_key` primary key, `account_count`, and `updated_at`. Services lazily ensure the singleton row, lock it with `SELECT ... FOR UPDATE`, and can reconcile the count from `viewer_accounts`. This avoids a racy `COUNT(*)`-then-INSERT cap check. Phase 0.7 account deletion locks the same state row, deletes the durable account inside the transaction, recounts authoritative rows, and writes the post-delete count before commit, so rollback cannot persist a capacity decrement without the corresponding deletion.

`viewer_email_change_requests` is the only Phase 0.7 storage addition. Each row belongs to one durable viewer account and stores the candidate email, deterministic normalized candidate, random selector, only the SHA-256 verification-token hash, the account `security_version` captured at request creation, expiry, and consumed/cancelled state. Account-row locking serializes pending-request replacement so only a small bounded active set is possible. Inspection is read-only. Final confirmation re-locks account and request, re-checks expiry/state/security version, and relies on the existing binary unique `viewer_accounts.normalized_email` constraint as the structural last line of defense against a conflicting target-email race. Deleting the account cascades its staged email-change rows.

Authority-bearing viewer credentials are stored in separate tables. Verification and reset tables store only `token_hash` plus expiry/consumption/invalidation state. Remember credentials use a public random selector plus hashed verifier. Viewer sessions store only a hash of an independent viewer session token plus account security version. `security_version` allows a password reset/change, recovery, suspension, or logout-all operation to invalidate older authentication state.

Phase 0.6 enforces default per-account active limits of 10 viewer sessions and 10 remember credentials. Expired/revoked rows do not count. Account-row locks serialize admission, and the oldest active `(created_at, id)` rows are revoked deterministically when a replacement slot is needed. Remember restoration replaces selector/verifier authority atomically; the old verifier cannot match after commit. Final password reset is security-version-bound and revokes all viewer sessions/remember credentials while invalidating sibling reset tokens.

`viewer_rate_limit_buckets` and `viewer_rate_limits` are designed for bounded abuse-control storage. The fixed bucket row records the current admitted subject count; the composite `(bucket, subject_hash)` key prevents duplicates. Future throttling normalizes subjects, stores keyed fingerprints instead of raw IP/email values, serializes new-subject admission, and refuses unbounded growth after a configured per-bucket cap.

`viewer_security_events` contains privacy-bounded diagnostics with optional account relation, keyed IP/user-agent fingerprints, low-risk structured context, and an indexed retention deadline. Secrets and raw viewer email addresses do not have dedicated event columns.

`viewer_favourites` and `viewer_collection_items` reference canonical `images.id`. The favourite composite key `(viewer_account_id, image_id)` prevents duplicate favourites. The collection-item composite key `(viewer_collection_id, image_id)` prevents duplicate image membership and `position` supports deterministic ordering. Both image foreign keys use `ON DELETE CASCADE` so deleting canonical media removes stale viewer references. No viewer foreign key ever cascades into `images` or `galleries`.

`viewer_collections` contains bounded plain-text title/description fields and an explicit owner. `viewer_collection_share_tokens` stores only a unique hash of future collection-container share authority plus lifecycle timestamps. Neither table stores media/gallery permission state. **A collection reference is not an authorization grant.** A future collection/share read must re-check canonical gallery/media authorization for every image at request time.

`viewer_passkeys` stores only public WebAuthn credential material and metadata. The large raw credential id is accompanied by a fixed-size unique SHA-256 identity hash to avoid relying on an oversized variable index on older MySQL/MariaDB/InnoDB configurations. No private passkey key is ever stored.

Deletion policy is deliberately one-directional. Physical account deletion cascades to account-owned viewer credentials/preferences/collections/passkeys and Phase 0.7 email-change requests; collection deletion cascades to its items/share capabilities; image deletion cascades only to viewer references; security events detach with `ON DELETE SET NULL`. Before deleting an account, the lifecycle transaction also revokes still-active collection-share capabilities created by that viewer for collections the viewer does not own, preventing a creator relation from disappearing while leaving a capability alive. Gallery/media content is never deleted by viewer-account cleanup.

Phase 0.5 adds three registration-staging tables without changing the durable account table. `viewer_registration_requests` stores one expiring request per binary-unique `normalized_email`, a hashed verification capability, optional invitation relation, HMAC request metadata, verification-send counters, and indexed expiry/verification state. It stores no password. Verification changes only the staging row to `email_verified`; it does not insert into `viewer_accounts`.

`viewer_invitations` stores a unique hash of the invitation capability, optional administrator-visible intended email plus its HMAC target-email authorization binding, optional administrator creator foreign key, expiry, claim, and revocation timestamps. A unique `viewer_registration_requests.viewer_invitation_id` ensures one invitation cannot stage multiple identities. `viewer_registration_state` contains the singleton locked counter used to serialize admission of new pending rows and enforce `max_pending_registration_requests` even under concurrent anonymous requests. Scheduled cleanup deletes expired staging rows in bounded batches and repairs the counter from authoritative table state.


Phase 4.2 migration `202608200001_viewer_registration_verification_tokens.php` adds `viewer_registration_verification_tokens` without altering or migrating away the existing primary verification columns on `viewer_registration_requests`. The child table contains `id`, `viewer_registration_request_id`, unique `token_hash`, `expires_at`, `created_at`, and nullable `sent_at`. The request foreign key uses `ON DELETE CASCADE`; token lookup is structurally unique; request ownership and expiry have dedicated indexes. Plaintext verification capabilities are never persisted. Existing Phase 4.1 mail continues to validate against the primary request-row hash, while successfully handed-off resend capabilities validate against child hashes.

Phase 4.3 adds no persistent schema and no migration. Its signed form/challenge state is browser-carried, one-time replay authority is bounded to the current PHP session using installation-HMAC nonce fingerprints, and repeated-request signals reuse the existing `viewer_rate_limit_buckets` / `viewer_rate_limits` tables through `viewer_rate_limit_consume()`. Only two new fixed bucket names, `viewer_automation_ip` and `viewer_automation_subnet`, are introduced in policy code; existing subject normalization, HMAC hashing, per-bucket storage caps, cleanup, and fail-closed semantics apply unchanged. No plaintext challenge secret, proof candidate, raw email, or raw IP is added to persistent storage by Phase 4.3.

Phase 4.4 adds no persistent schema and no migration. `app/services/viewer_security_operations.php` performs read-only, bounded aggregate queries against the already-authoritative `viewer_security_events`, `viewer_rate_limit_buckets`, `viewer_rate_limits`, `viewer_accounts`, `viewer_account_state`, `viewer_registration_requests`, and `viewer_registration_state` storage. It creates no metrics/statistics/analytics table and copies no Viewer account or security data into generic telemetry tables. Fixed event-key/time predicates use the existing `(event_key, created_at)` event index; fixed limiter-bucket reads use the existing bucket primary/indexed time state and policy windows. The operations page does not consume/reset limiter rows, reconcile capacity counters, run maintenance, or mutate registration/verification authority. Stale limiter rows outside their configured window are ignored unless `locked_until` remains in the future.

A prepared child token is deliberately unusable until `sent_at` records successful transport handoff. Resend never changes the request row's existing primary token hash or primary token expiry. Active child creation is bounded by an application-level per-request cap and request lifetime; bounded maintenance deletes expired child authorities. Successful verification through either the primary authority or any sent child transitions the same registration request and removes all child siblings. Expired/cancelled request deletion also removes children by cascade, so no child authority can outlive its staged registration owner.

See `docs/VIEWER_SECURITY_FOUNDATIONS.md` for the complete token, session, abuse-control, proxy, privacy, pending-registration, mail-budget, cleanup, and threat-model contract.

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
| `nsfw_enabled` | Image-level restricted flag. The migration creates it as `NOT NULL DEFAULT 0`. |
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

NSFW Guard readiness requires both `galleries.nsfw_enabled` and
`images.nsfw_enabled`. A successful inspection that confirms either column is
absent identifies a pre-feature or incomplete migration state. An inspection
failure is reported separately as unknown and does not authorize public media
delivery or an NSFW setting change. This diagnostic policy adds no table or
migration of its own.

Schema capability answers are cached only for the current PHP request. The
migration runner therefore invalidates that cache after schema-changing
`CREATE`, `ALTER`, `DROP`, and `RENAME` statements, after the migration ledger
table bootstrap, and after successful migration repair callbacks. This matters
when one Admin, installer, updater, or CLI process inspects a column before a
migration and validates it again afterward. Data-only migration statements keep
the cache because they cannot change table, column, or index availability.

### Phase 9 security/authentication schema capabilities

Security policy must distinguish a database object that is **confirmed absent**
from an object whose metadata **could not be inspected**. The shared inspection
service therefore returns `available`, `missing`, or `unknown`; aggregate
capabilities preserve individual requirements and apply `unknown > missing >
available` precedence.

| Capability | Database requirements | Confirmed missing behavior | Unknown behavior |
| --- | --- | --- | --- |
| `gallery_access` | `galleries.access_mode`, `access_listing`, `access_password_hash`, `access_token_hash`, `access_token_expires_at` | Legacy compatibility only when all five columns are absent | Public access/listing policy and related mutations fail closed |
| `gallery_visibility` | `galleries.visibility`, plus definition support for `unpublished` | Historical enum vocabulary uses `draft` for canonical unpublished | Storage/public policy is refused until the enum can be inspected |
| `gallery_share_token` | `galleries.access_share_token` | Generation/display-token use unavailable; validating hash may still be revoked | Generation/use unavailable; validating hash may still be revoked when core access schema is verified |
| `auth_persistent_login` | `admin_remember_tokens` | Ordinary PHP-session login continues without durable browser token | Token issue/use is refused; session authentication remains independent |
| `auth_user_email` | `users.email` | Username-only login/account compatibility remains available | Email identity/recovery operations are not guessed as absent |
| `auth_password_reset` | `password_reset_tokens` and `users.email` | Reset issue/consume requires migration | Reset issue/consume is temporarily refused |
| `auth_external_identity` | `user_google_accounts` | Google link/login storage requires migration | Linked-account lookup/login/link/disconnect is temporarily refused |

The gallery-access legacy rule is intentionally stronger than aggregate
`missing`. A partially applied password-protection migration can report
`missing` while some access columns already exist and contain protection state.
`gallery_access_schema_is_confirmed_legacy()` therefore requires every one of
the five core access columns to be confirmed absent before the application may
substitute historical `normal`/`listed` behavior.

`access_share_token` is not part of that five-column core decision because it is
optional encrypted/display storage for the raw share token. Authorization is
validated with `access_token_hash` and `access_token_expires_at`. Generation and
use of a current share token require the applicable schema to be verified.
Revocation is deliberately stricter in the safe direction: when the core hash
columns are verified, the application clears the validating hash even if
`access_share_token` is missing or unknown. Clearing the hash invalidates every
copy of the link and avoids making revocation dependent on optional display
storage.

The visibility compatibility check uses the trusted column-definition helper,
not a broad SQL exception fallback. A successful metadata result that proves the
`visibility` definition lacks `unpublished` identifies the historical `draft`
vocabulary. An exception produces `unknown`; it never selects `draft` merely
because inspection failed.

Authentication tables contain credentials or credential-derived state. Schema
health output and logs may expose only validated table/column identifiers,
normalized capability names, bounded status/error categories, and request
correlation IDs. They must never include password hashes, reset/share token
values, remember-login cookies, OAuth client secrets, CSRF values, DSNs, raw SQL,
or raw database exception messages.

### Phase 10 destructive/ingestion schema capabilities

Destructive and ingestion operations use `app/services/mutation_schema_policy.php`
on top of the shared three-state inspector. These checks are mutation guards, not
new migrations. Their purpose is to prove that the database shape needed by an
existing write/delete workflow is known before persistent state changes.

| Capability | Database requirements | Confirmed missing behavior | Unknown behavior |
| --- | --- | --- | --- |
| `mutation.gallery_delete` | `galleries.id`, `folder_path`, `cover_image_id`, `parent_id`, `updated_at`; `images.id`, `gallery_id` | Core deletion requires migration; optional dependency cleanup skips only objects independently confirmed absent | Refuse before row or filesystem deletion |
| `mutation.gallery_move` | Gallery id/path/hash/parent/cover/update fields; image id/gallery/path/hash/order/update fields | Require current core schema; optional tag propagation may skip only proven absence | Refuse before move/copy/path mutation |
| `mutation.duplicate_photo_ledger` | Both duplicate ledger tables and their user/entity/timestamp fields | Ledger UI reports migration requirement and does not write | Refuse ledger writes as temporary inspection failure |
| `mutation.upload_ingestion` | Gallery target id/path and image identity/path/hash/filename/order/timestamps | Require current ingestion migration | Refuse before uploaded source is committed to gallery |
| `mutation.upload_automation` | Full `gallery_upload_tokens` issuance/authentication shape | Require migration for token creation/auth/use | Refuse token creation/auth/use |
| `mutation.gallery_migration` | Core target gallery/image identity, path, and timestamps | Core migration required; confirmed absent optional imported metadata is omitted | Pause resumable migration before target mutation |
| `mutation.mobile_webdav` | Full `mobile_webdav_upload_tokens` credential/authentication shape | Require migration for create/auth/upload | Refuse create/auth/PUT |
| `mutation.thumbnail_metadata` | Core `image_thumbnail_variants` identity, geometry, file-state, status, and timestamps | Confirmed absent table preserves documented file-only derivative compatibility | Refuse derivative/metadata mutation before file changes |
| `mutation.database_maintenance` | Inspectable `schema_migrations` table state | Confirmed absence may be handled by migration/repair bootstrap | Refuse cleanup/repair mutation |
| `mutation.application_update` | Inspectable `schema_migrations` table state | Confirmed absence is allowed because activation can bootstrap/apply migrations | Refuse before active application files are replaced |

The upload-ingestion capability deliberately contains only core registration
columns that every accepted source needs. Optional image metadata remains
compatibility-aware at the service that owns that metadata. Gallery migration,
for example, probes each optional destination column before copying source
metadata into it. A confirmed missing optional field is omitted. An inspection
failure is `unknown` and stops the import instead of producing a partially
registered image.

Thumbnail writes have a second compatibility preflight because old installations
may not have `image_thumbnail_variants` at all. If the table is confirmed absent,
the established derivative-file-only mode may continue. If the table exists,
`thumbnail_metadata_preflight_write_schema()` also checks optional variant columns
`derivative_version`, `gallery_id`, and `thumbnail_rel_path`, plus optional source
geometry/status columns `images.display_width`, `display_height`,
`exif_orientation`, and `thumbnail_metadata_refreshed_at`. Missing optional fields
are omitted from writes; unknown fields block the derivative mutation before a
thumbnail is generated, replaced, or deleted.

Upload-token revocation uses narrower capabilities than token issuance:

- `mutation.upload_automation_revoke` requires `gallery_upload_tokens.id`,
  `gallery_id`, `active`, and `revoked_at`, allowing an existing API key to be
  disabled even when unrelated issuance/authentication fields are missing;
- `mutation.mobile_webdav_revoke` requires the WebDAV credential `id`, which is
  sufficient for the existing credential-delete operation.

This is a security-tightening exception, not a general partial-schema fallback.
If the columns required for revocation cannot themselves be inspected, revocation
is refused rather than guessed.

The legacy `db_table_exists()` and `db_column_exists()` helpers remain for
unconverted compatibility callers, but they are no longer valid mutation-policy
evidence in the Phase 10 paths. Their boolean `false` can mean either absence or
an inspection error. Converted deletion, upload, token, migration, thumbnail,
database-maintenance, and updater code instead uses explicit `missing` versus
`unknown` results.

Mutation-schema refusal diagnostics use the event
`database.mutation_schema_refused`. Its context is intentionally limited to a
bounded capability identifier, normalized state, stable operation identifier,
and validated affected table/column names. It must not contain SQL, PDO exception
messages, DSNs, database credentials, API/WebDAV secrets, upload filenames or
paths, migration source paths, or updater staging paths.

### Phase 11 optional presentation/reporting schema capabilities

Phase 11 completes the schema-reliability conversion for optional presentation,
reporting, map, AI, navigation, and telemetry features. It adds no database
migration by itself. `app/services/presentation_schema_policy.php` describes the
shape that existing migrations are expected to provide and applies read/write policy
to the structured inspector results.

The database-state interpretation is intentionally different from destructive
Phase 10 mutation policy:

- `available`: use the optional database-backed feature normally;
- confirmed `missing`: omit the optional read surface or show migration guidance. A
  write is allowed to skip persistence only where the exact legacy behavior has
  been audited;
- `unknown`: a safe base page may omit the optional surface, but the failure is
  logged and visible in System Health. A write must not interpret `unknown` as
  evidence that a table or column is absent;
- `disabled`: an administrator-disabled optional feature is reported without
  resolving its schema capability, so System Health does not spend metadata queries
  on an inactive feature.

The final capability set is shown below. Metadata-organizer capture-date grouping
also uses a narrow internal structured check for `images.exif_taken_at`; it is not a
separate System Health card because it is a derivative consumer of the EXIF metadata
surface rather than an independently configured feature. Gallery-description favicon
storage similarly uses the internal `presentation.link_favicon_cache` capability:
confirmed missing storage omits the icon cache, while unknown inspection state omits
and diagnoses the cosmetic operation without treating the failure as proof of absence.

The final capability set is:

| Capability | Database requirements | Confirmed missing behavior | Unknown behavior |
| --- | --- | --- | --- |
| `presentation.gps_exif` | `galleries.gps_map_enabled`; image EXIF/GPS date, camera, lens, coordinate, altitude, direction and source columns | Omit GPS/EXIF map presentation | Omit optional map, log degraded state, show System Health warning |
| `presentation.gps_override` | Nullable definition of `galleries.gps_map_enabled` | Use global/default map behavior | Do not save/guess a per-gallery override; surface unknown |
| `presentation.flight_map` | `gallery_flight_maps` route/source/saved-route fields | Omit stored route map or route persistence | Omit read-only map; refuse dependent DB write |
| `presentation.flight_navdata` | `flight_map_nav_points` identity/location/source/search fields | Omit local navigation dataset | Omit read results; refuse DB-dependent navdata mutation |
| `presentation.image_voting` | `galleries.voting_enabled` plus complete `image_votes` shape | Hide voting / require migration for vote writes | Hide optional UI but return operational failure for vote write |
| `presentation.picture_game` | Voting requirements, `galleries.picture_game_enabled`, complete `picture_game_votes` shape | Hide game / require migration for stored pair and vote state | Core gallery continues; game route/write is unavailable |
| `presentation.lightbox_override` | `galleries.lightbox_browsing_mode` and supported definition tokens including `picture_strip` and `3d_carousel` | Use inherited/global lightbox mode; legacy gallery creation/import may omit the unavailable column | Safe display may fall back; submitted, sidecar-imported, or organizer-inherited override persistence is refused rather than silently discarded |
| `presentation.openai_text` | Core `user_openai_text_settings` profile/endpoint/model/prompt fields | OpenAI profile persistence unavailable | Settings write is refused as schema unavailable |
| `presentation.openai_image_input` | `user_openai_text_settings.allow_image_input` | Preserve old text-only settings behavior | Do not guess/persist image-input setting |
| `presentation.ai_image_analysis` | `image_ai_metadata` and `image_ai_analysis_jobs` identity, queue, lease, result and timestamp fields | AI metadata UI/queue unavailable until migration | Read-only metadata may be omitted; worker/queue mutations return bounded unavailable response |
| `presentation.simbrief_route_map` | Same stored route-map requirements as flight maps | SimBrief draft may continue without route persistence | SimBrief draft may continue; only optional route DB write is omitted and diagnosed |
| `presentation.navigation_cache` | Complete `navigation_data_cache` cache-key/provider/payload/expiry fields | Perform lookup without persistent cache | Incidental cache write/read may be skipped; unknown is diagnosed |
| `presentation.navigation_account` | Complete `navigation_data_accounts` provider/account/token/expiry fields | Proven old install may use session-only OAuth state | Unknown account storage is not treated as legacy; persistence is refused |
| `presentation.telemetry_reporting` | Telemetry settings, sessions, events, hourly/daily aggregates, DB query metrics and job-run tables | Telemetry report is unavailable; maintenance may no-op on proven pre-telemetry install | Dashboard/export distinguish outage from migration; rollup/purge refuse silent success |
| `presentation.admin_gallery_report` | Known report dependency tables including galleries/images/tags/votes/settings/log/telemetry/navigation/flight-map/AI tables | Omit only report sections whose dependencies are proven absent | Keep report operational where safe, mark unavailable sections, never export raw database exception text |

`schema_inspection_column_nullable()` is part of the final observation API. It
queries `information_schema.COLUMNS.IS_NULLABLE` through the same validated,
request-cached executor boundary as table/column/index/definition inspection. The
GPS override capability uses this to distinguish the intended nullable per-gallery
override shape from a schema that cannot safely represent inheritance.

The presentation health registry is lazy. Calling
`presentation_schema_health_definitions()` builds resolver metadata only and performs
zero database metadata queries. `admin_presentation_schema_health_statuses()` checks
feature flags first, resolves enabled capabilities only, and reuses the request-local
cache. The voting aggregate has ten first-use metadata requirements and performs no
additional metadata queries when re-evaluated within the same PHP request.

#### Intentional dynamic Admin-report inventory query

`admin_gallery_report_exact_database_table_counts()` still queries
`information_schema.TABLES` directly for the active database. This is intentional
and is not an existence-policy bypass. The Complete Admin Gallery Report must
enumerate every base table present at runtime, including future/plugin tables whose
names are not known in advance. Named-object helpers cannot express that dynamic
inventory. Failure produces generic report-unavailable text and never includes raw
SQL or the database exception message in the exported report. All known optional
report section dependencies use structured schema inspection.

#### No Phase 11 migration

No new schema objects are introduced by the policy conversion. Existing migrations
remain the source of truth for the optional tables and columns above. A `missing`
result therefore means either an older installation or pending migration, while an
`unknown` result is an operational metadata-inspection problem and must not be fixed
by guessing or applying migrations blindly.

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

Public tag-page presentation uses scalar settings in this existing table: tag_page_gallery_grid_columns, tag_page_gallery_grid_rows, and tag_page_gallery_description_layout. Missing values inherit the global pagination dimensions and Theme gallery-card layout, so no schema migration is required.

| Column | Meaning |
| --- | --- |
| `setting_key` | Primary setting key. |
| `setting_value` | Text value, often scalar or JSON depending on helper. |
| `updated_at` | Last update timestamp. |

Use `app/services/app_settings.php` to access this table.

Public language configuration uses four independent keys in this table. `public_language` stores the site-wide public interface default. `public_language_selector_enabled` controls whether visitors may override that default and is treated as enabled (`1`) when missing. `public_language_selector_languages` stores the site-wide ordered availability list containing a non-empty subset of the maintained `en`, `cs`, `de`, and `sv` codes; missing, malformed, or empty values fall back to all four. `public_language_selector_design` stores normalized JSON containing the selected five-preset design, browser-visible elements, layout choices, and safe per-preset visual overrides; colors accept validated hex values or the literal `transparent`, and missing or malformed content falls back to Classic with flags and codes enabled. Basic Settings writes merge preset/flag changes into this document so detailed Theme overrides are retained. These keys configure whether, what, and how viewers may choose, but never store an individual viewer's choice. That personal preference is persisted only in the viewer's browser cookie and mirrored in the request session. The viewer settings require no migration and do not change which catalogs administrators may select or edit.

`public_thumbnail_rendering_mode` stores the site-level selected-gallery photo-card renderer. `app/services/public_thumbnail_rendering.php` accepts only `responsive` and `progressive`; missing or invalid values normalize to `responsive`. It is a scalar runtime setting and requires no dedicated schema migration.

Browser-side uploads store their mutable admin configuration in `app_settings` through `app/services/browser_uploads.php`. Current keys are `browser_upload_enabled`, `browser_upload_default_worker_count`, `browser_upload_max_worker_count`, `browser_upload_hard_worker_cap`, `browser_upload_batch_size_policy`, `browser_upload_zip_size_threshold_ratio`, `browser_upload_max_items_per_batch`, `browser_upload_max_zip_batch_bytes` and `browser_thumbnail_rebuild_source_chunk_bytes`. Values are normalized defensively at read time, with worker counts clamped to 1 through 32, the ZIP threshold ratio clamped to 0.10 through 0.95, ZIP item count clamped to 1 through 64, the absolute ZIP upload batch cap clamped to 1 MB through 128 MB, and the browser-assisted thumbnail rebuild source-download chunk clamped to 16 MB through 3 GB. The browser uses the smallest effective upload limit from PHP upload settings, the configured ratio, and the absolute ZIP cap so shared hosting does not need to parse very large browser-prepared upload packages in one request. The source-download chunk setting is larger by design because it streams originals from the server to the browser and does not pass through PHP multipart upload limits.

`theme_favorite_gallery_ids` stores the optional top-navigation shortcuts as a JSON array of up to three entries. Numeric entries are gallery IDs, and the `home` token represents the main gallery page. The value is resolved by `app/services/favorite_galleries.php`; duplicate entries, missing galleries and unavailable public rows are ignored defensively.

### Centralized Settings and schema behavior

The Admin Settings hub does not introduce a table, column or migration. Scalar global settings continue to use their existing storage, primarily `app_settings`, while telemetry continues to use `telemetry_settings` and user/account integrations keep their existing account-specific tables. `app/services/admin_settings_registry.php` is application metadata, not persistent configuration.

The hub does not rename keys or copy values. Missing and invalid values are still resolved by the owning service. In particular, tag landing-page grid keys inherit global pagination values, tag landing-page card layout inherits the global Theme card layout, the public thumbnail renderer falls back to `progressive`, and the lightbox default falls back to `single`. Per-gallery overrides remain in the `galleries` schema and are not flattened into global settings.

Sensitive values such as `password_reset_smtp_password`, site-maintenance cron tokens, OpenAI keys and upload API keys are never copied into a second table or rendered as raw central summaries. The central page stores no secret shadow values. Existing optional migrations still gate the specialized subsystem that owns them. For example, the central EXIF/GPS checkbox is editable only when the existing per-gallery EXIF/GPS override schema is ready.

See `docs/ADMIN_SETTINGS_INVENTORY.md` for the key-by-key storage and migration classification.

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

## Gallery Description Favicon Cache

### `link_favicon_cache`

Stores one bounded favicon-discovery result per normalized hostname. `status` is
`ok`, `missing`, `failed`, or `blocked`; `icon_file`, `mime_type`, `source_url`,
`content_sha256`, and `fetched_at` describe only a validated successful local cache
entry. `last_attempt_at`, `retry_after`, and `updated_at` bound refresh frequency.
The database never stores downloaded response bodies, request headers, credentials,
or cookies. Cached image bytes live under the internal gallery favicon directory and
are served only through validated generated filenames.

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
8. Legacy compatibility code may still contain `db_table_exists()` or `db_column_exists()` guards where a boolean fallback is explicitly harmless. These helpers return `false` after inspection errors and therefore must never authorize security, authentication, destructive/ingestion, or optional-feature writes. Security/authentication callers use explicit three-state access policy, destructive/ingestion callers use `app/services/mutation_schema_policy.php`, and converted optional presentation/reporting callers use `app/services/presentation_schema_policy.php`. New schema-sensitive code must choose the appropriate structured policy instead of adding another ambiguous existence guard.

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

## Smart Galleries and editorial ratings

Migration `202608140001_smart_galleries.php` adds `smart_galleries` and nullable `images.editorial_rating`. Definitions contain title, unique slug, description, versioned `rules_json`, rule version, enabled/public state, allowlisted sorting, and timestamps. No image membership is duplicated. The public index covers `(enabled, visibility, title)`; the rating lookup index covers `(editorial_rating, gallery_id, visibility)`.

Rule version 1 contains `version` and `root`. Nodes are boolean groups (`AND`, `OR`, or single-child `NOT`) or conditions containing an allowlisted field/operator/value. The maximum depth is five and maximum condition count is 50. Missing stable references and malformed/unknown versions fail safely.

Migration `202608140002_smart_gallery_placement.php` introduces the `unlisted`, `root`, and `gallery` listing modes. Migration `202608140003_smart_gallery_multiple_placements.php` adds the `smart_gallery_placements` junction table and migrates existing single-parent assignments. Each `(smart_gallery_id, gallery_id)` pair is unique; deleting either record removes its junction rows through foreign-key cascades. A gallery-mode Smart Gallery may be attached beneath any number of physical galleries.

Migration `202608170001_smart_gallery_presentation.php` adds nullable `smart_galleries.presentation_json MEDIUMTEXT`. The JSON document is versioned separately from `rules_json`; version 1 stores only Smart Gallery presentation overrides. Supported normalized keys are `grid_columns`, `grid_rows`, `pagination_enabled`, `thumbnail_min_size`, `thumbnail_max_size`, `thumbnail_rendering_mode`, `card_layout`, `metadata_visible`, `lightbox_enabled`, `lightbox_browsing_mode`, `slideshow_enabled`, `download_enabled`, and `voting_enabled`. Missing, malformed, unknown-version, or invalid values are treated as absent so current Theme/site defaults remain authoritative. An override-free document is stored as `{"version":1}`.

Migration `202608170002_smart_gallery_attachment_ordering.php` extends the canonical `smart_gallery_placements` junction rather than adding a parallel relationship table. `placement ENUM('top','bottom') NOT NULL DEFAULT 'bottom'` preserves the previous below-content behavior for every existing row. `placement_order INT NOT NULL DEFAULT 0` stores per-parent order, and the `(gallery_id, placement, placement_order, smart_gallery_id)` index supports deterministic grouped reads. The existing composite primary key `(smart_gallery_id, gallery_id)` continues to prevent duplicate attachment instances under one physical parent while allowing the same Smart Gallery under multiple parents.

The new placement/order columns are a mutation boundary. Read paths can safely treat a pre-migration junction row as `bottom`/`0`, but Admin attachment replacement or placement/order updates require the structured mutation-schema capability to be conclusively available before deleting or inserting any relationship row. Unknown or missing schema therefore cannot cause a partial attachment rewrite.

`presentation_json` is intentionally nullable and is not included in the base Smart Gallery schema-readiness requirement. This preserves read/write compatibility while the additive migration is pending during an update. Once the column exists, saves and duplicates persist it. Public behavior remains safe before the column exists because effective presentation falls back to Theme/site defaults.

Smart Gallery result membership remains derived at query time. No new membership, lightbox-manifest, or download-manifest table is introduced. Counts, paged rows, lazy lightbox windows, and downloads use the same compiled rule/access query and stable image-id tie breaker.

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

## Phase 1.0 Viewer Account HTTP Use

Phase 1.0 adds no database migration and no parallel viewer schema. The reachable HTTP controllers use the Phase 0 through 0.7 tables and transactional service contracts already documented above: `viewer_invitations`, `viewer_registration_requests`, `viewer_registration_state`, `viewer_accounts`, `viewer_email_verification_tokens`, `viewer_sessions`, `viewer_remember_tokens`, `viewer_password_reset_tokens`, `viewer_rate_limit_buckets`, `viewer_rate_limits`, and `viewer_security_events`.

Administrator invitation listing reads only operational invitation metadata and staged registration status. Invitation token hashes are never returned to the Admin UI. Invitation creation also performs a conservative durable-capacity preflight, while final activation still relies on the existing transactional account-cap singleton lock and recount/reconciliation path. The preflight is not an authorization substitute and cannot consume capacity.

No password is stored in pending registration rows. The invitation recipient first verifies email through the scanner-safe token exchange, then supplies the password only while holding short-lived server-side activation authority. Final durable viewer creation remains the existing atomic `viewer_registration_activate_verified()` transaction.

Phase 1.0 introduces no mail queue or transport tables. Viewer verification and password-reset messages use the existing configured PHP-mail/SMTP transport after the viewer mail-abuse budget service has authorized the send. Remember-me continues to store only selector plus verifier hash in `viewer_remember_tokens`; the plaintext verifier exists only in the dedicated browser cookie.

## Phase 1.1 Viewer Favourites Use

Phase 1.1 adds no database migration. It activates the existing `viewer_favourites` table created by `202608180001_viewer_security_foundations.php`. The composite primary key `(viewer_account_id, image_id)` remains the duplicate/race backstop; account and image foreign keys continue to cascade only stale reference rows and never grant or mutate gallery/media access.

Favourite addition locks the owning `viewer_accounts` row with `SELECT ... FOR UPDATE`, verifies the active account/security version, counts current favourites while that owner lock is held, and applies the centralized `max_viewer_favourites_per_account` limit before inserting. Removal deletes only the current viewer's matching reference. No permission snapshot, gallery password, share token, visibility state, or media URL is stored in `viewer_favourites`.

Read paths first load owned image ids, then re-evaluate the current canonical source-image authorization before rendering metadata. If an image/gallery becomes inaccessible, the saved reference may remain in storage but produces no image/gallery disclosure and grants no access. Image/account deletion continues to remove stale favourite rows through the established foreign-key cascades.

## Phase 1.2 Viewer Account Lifecycle HTTP Use

Phase 1.2 adds no database migration. It reuses the existing Phase 0.7 lifecycle schema from `202608180004_viewer_account_lifecycle_foundations.php` together with the previously established `viewer_accounts`, `viewer_sessions`, `viewer_remember_tokens`, `viewer_password_reset_tokens`, `viewer_email_change_requests`, `viewer_security_events`, favourites, dormant collection tables, and account-capacity state.

Password change remains one atomic service transaction against the current viewer account/security version. On success the service updates the password hash and `password_changed_at`, increments `security_version`, revokes prior viewer session/remember/reset/email-change authority according to the Phase 0.7 contract, and clears only viewer authentication state. No administrator table, Admin remember token, or Admin session key participates.

Email change never writes `viewer_accounts.email` at request time. A candidate address lives in `viewer_email_change_requests` with a high-entropy verification token stored only as a hash, expiry, current viewer account id, and current security version. Verification-link GET is non-consuming. Final confirmation locks both the account and request, rechecks expiry/cancellation/security version/uniqueness, updates the durable email and normalized email, increments the viewer security version, consumes exactly one request, and cancels competing pending requests. Replay, stale confirmation state, and concurrent losing transitions therefore fail without a second email switch.

Self-deletion remains the existing `viewer_account_delete()` transaction. It invalidates the account security version, revokes viewer-created collection share authority as defined by the foundation, writes the bounded deletion security event, deletes the viewer account, and relies on existing foreign-key cascades for viewer-owned sessions, remember/reset/verification/email-change records, favourites, collections/items/shares, and other viewer-owned authority. Gallery/image rows, Smart Galleries, and gallery share-link tables are not account-owned cascades and are not deleted by this lifecycle path.

## Phase 2.0 Private Viewer Collections Use

Phase 2.0 adds no database migration. It activates the existing `viewer_collections` and `viewer_collection_items` tables created by `202608180001_viewer_security_foundations.php`. The existing `description` column and `viewer_collection_share_tokens` table remain dormant and are not exposed by the Phase 2.0 service/controller.

`viewer_collections.viewer_account_id` is the authoritative owner foreign key. Every object lookup/mutation includes the current viewer id in the predicate. Collection creation locks the `viewer_accounts` row before counting against `max_viewer_collections_per_account`, while item mutation/reorder locks the owned `viewer_collections` row before checking `max_viewer_items_per_collection`. The composite primary key `(viewer_collection_id, image_id)` is the duplicate/race backstop. New items receive bounded integer `position` values and reorder normalizes positions transactionally.

`viewer_collection_items` stores no path, thumbnail URL, password, gallery share token, cached permission, or copied authorization decision. Rendering first reads owner-scoped image ids/order and then resolves current authoritative image/gallery rows through the no-admin-bypass viewer source policy. A row may survive a temporary access loss; inaccessible items disclose no protected source metadata. Image deletion and collection/account deletion continue to use the existing foreign-key cascades, but collection deletion never cascades into `images`, `galleries`, `viewer_favourites`, Smart Galleries, or gallery share-link tables.

## Phase 2.5 Admin Viewer Security Controls

Phase 2.5 adds no migration. Suspend and Restore reuse the existing `viewer_accounts.status`, `security_version`, `suspended_at`, and the already-prepared viewer authentication/lifecycle token tables. The existing account-state transition runs under a viewer-row lock and increments `security_version`; it revokes active `viewer_sessions` and `viewer_remember_tokens`, invalidates outstanding `viewer_password_reset_tokens` and durable `viewer_email_verification_tokens`, cancels pending `viewer_email_change_requests`, and revokes `viewer_collection_share_tokens` created by the affected viewer. Restore performs the same authority rotation while returning the durable account status to `active`, so rows revoked before suspension are never made valid again.

Admin Sign out everywhere also adds no storage. It reuses central viewer authentication invalidation to increment `security_version`, revoke all current viewer session and remember rows, and invalidate outstanding reset authority while leaving `viewer_accounts.status`, password hash, `must_change_password`, favourites, collections, collection items, and dormant collection-share capabilities unchanged. An account suspended/restored through Phase 2.5 retains `must_change_password=1` when it was administrator-provisioned with a temporary password.

These operations never delete or update canonical `images`, `galleries`, Smart Gallery definitions, gallery share-link rows, or the administrator `users`/persistent-auth domain. The viewer frontend feature switch is not a storage prerequisite for Admin security operations; schema uncertainty still fails the relevant mutation closed.

## Phase 3.0 Collection Share Storage Use

Phase 3.0 adds no migration. It activates the existing `viewer_collection_share_tokens` table from `202608180001_viewer_security_foundations.php`: `id`, `viewer_collection_id`, nullable `created_by_viewer_account_id`, unique `token_hash`, `created_at`, optional `last_used_at`, `expires_at`, and `revoked_at`, with the existing collection/state, creator/state, and expiry indexes. The database never stores a plaintext share token, complete share URL, reversible display copy, copied collection title, or source-gallery permission snapshot.

One active share per collection is an application-level Phase 3 product invariant serialized by the authoritative `viewer_collections` row lock. Create/replace locks the viewer account, owned collection, and unrevoked share rows in one transaction, revokes every previous unrevoked row, then inserts one SHA-256 authority hash with a 30-day expiry. Historic revoked/expired rows may remain. Revoke marks unrevoked rows with `revoked_at` and does not delete the collection, items, favourites, images, galleries, or gallery shares. `last_used_at` is best-effort exchange telemetry only and is not human-open proof or authorization state.

The existing foreign key from `viewer_collection_share_tokens.viewer_collection_id` to `viewer_collections.id` keeps collection deletion authoritative and never points toward `images`. Owner account deletion continues to remove/cascade viewer-owned collections and shares without touching source media. Account suspension explicitly revokes created shares through the established lifecycle transition; restoration never clears `revoked_at`. Sign out everywhere does not mutate share rows. Share schema inspection is intentionally separate from `viewer_collections_schema_status()`, so missing/unknown share storage fails sharing closed without disabling private collections.
