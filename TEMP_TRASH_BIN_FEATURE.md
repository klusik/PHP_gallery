# TEMP - Gallery Trash Bin (soft delete + restore + optional auto-purge)

> **Status:** design / implementation brief. TEMPORARY working file - delete before release.
> **Branch:** `feature/delete_trashbin`
> **Author of brief:** exploration + architecture review, 2026-09-07
> **Audience:** implementing agents. Read `AGENTS.md`, `ARCHITECTURE.md` (Migrations, Split Modules,
> Schema Inspection, Admin Side-Panel), `DATABASE.md`, and `TESTING.md` (central audit rule) first.
>
> This revision was checked against the current project tree in `php-gallery-deploy(20260907-154134)` and the subsequent trash-bin review worktree.
> Function names, controller call sites, service-load order, deploy exclusions, updater-owned server
> policies, and the current transaction behavior of gallery deletion are reflected below.

---

## 1. Goal

When an administrator deletes a gallery through a normal user-facing delete affordance, the gallery
must not be destroyed immediately. Instead:

1. The selected gallery and its complete live subtree of subgalleries, images, thumbnails, and other
   files physically stored inside that gallery folder are removed from `galleries_root` so the
   existing filesystem-driven discovery model stops treating them as live content.
2. The filesystem subtree is moved to a persistent trash store outside `galleries_root`.
3. Durable gallery/image metadata needed for restoration is captured before live DB rows are removed.
4. The trash feature itself is configurable and **enabled by default**. Disabling it changes only
   future user-facing delete operations back to the legacy hard-delete path. Existing trash entries
   and payloads remain untouched and visible in Admin.
5. Automatic retention-based purge is a **separate setting, disabled by default**. The configured
   retention remains **30 days by default** and is used only when automatic purge is enabled.
6. A trash item is permanently purged only when either:
   - automatic purge is enabled, the trash feature is enabled, `purge_after` has been reached, and
     normal Site Maintenance successfully claims/processes it, or
   - an administrator explicitly permanently deletes that entry, or
   - an administrator explicitly empties the trash.
7. Restore recreates the original gallery hierarchy and metadata at the original filesystem path when
   that path is still available.

Disabling the trash feature must also pause retention-based automatic purge without erasing the
administrator's auto-purge preference. Re-enabling the feature while auto-purge is configured on must
re-arm all currently recoverable/in-flight entries with a fresh full retention window before scheduled
purge can resume. Likewise, switching auto-purge from off to on must re-arm those entries before the
destructive maintenance path becomes active. This avoids an old entry being deleted immediately merely
because a previously paused setting was re-enabled.

The implementation must be **fail-closed, crash-recoverable, and race-safe**. The trash bin exists to
prevent accidental data loss, so its own error paths must not introduce a new data-loss window.

### Non-goals

- Trashing individual images. Existing individual-image deletion remains a hard delete.
- Version history or multiple revisions of one gallery.
- Viewer-specific "recently deleted" content.
- Restoring transient state such as votes, favourites, collection membership, upload automation
  tokens, mobile WebDAV tokens, telemetry rows, AI queue rows, ZIP archives, duplicate-ledger rows,
  or generated thumbnail-variant DB rows.
- Preserving numeric `galleries.id` / `images.id` values. Restored rows receive new IDs.

---

## 2. Architecture decision: move the folder out of `galleries_root`

The current project explicitly treats the filesystem as the source of truth for physical galleries.
`Gallery\Services\galleries_root()` resolves `cms_config()['galleries_root']`, and
`Gallery\Services\gallery_abs_path()` enforces that gallery paths stay inside that configured root.
The live DB mirrors this filesystem model.

Current permanent deletion lives in `app/services/gallery_mutations.php`:

- `delete_gallery_subtrees()` normalizes/deduplicates selected roots.
- `gallery_subtree_rows()` discovers all DB gallery rows below one filesystem path.
- `gallery_delete_database_subtree_rows()` deletes gallery/image dependent records inside its own DB
  transaction.
- `delete_directory_tree()` deletes the filesystem tree with an allowed-root guard.
- the deletion tail clears thumbnail maintenance state, runs `sync_gallery_parent_ids()`, and refreshes
  public paths when `public_path_schema_ready()`.

Two soft-delete designs were considered:

| Approach | Verdict |
| --- | --- |
| Add `deleted_at` to live `galleries` rows and filter every reader | Rejected. It conflicts with the filesystem-as-truth model and would require defensive filtering across discovery, public listings, search, tags, sitemap/public paths, downloads, Smart Galleries, and future readers. A missed filter would leak trashed content. |
| Move the physical root outside `galleries_root`, remove its live DB rows, keep a durable restore snapshot | Chosen. Existing public/discovery code stops seeing the folder naturally, unique live constraints such as `galleries.folder_path_hash` and `galleries.slug` are freed, and restore becomes an explicit inverse operation. |

Do not place the trash payload in `galleries/`, even under a dot-prefixed or ignored directory. The
trash root must be physically outside `galleries_root`; ignored-directory logic is not a security or
correctness boundary.

---

## 3. Persistent trash storage

### 3.1 Use `data/gallery-trash/`, not `cache/`

Trash content is **recoverable user data**, not reconstructible cache state. Therefore use:

```text
<project_root>/data/gallery-trash/
```

Do not use `cache/gallery-trash/`. Generic cache cleanup must be allowed to delete cache data without
risking an administrator's recoverable gallery.

This choice matches the existing repository distinction:

- `cache/` is runtime cache and is excluded from deploy packages.
- `data/` is runtime/user data and is also excluded by both `scripts/deploy.sh` and
  `scripts/deploy.ps1`.
- the project already keeps permanent runtime data under `data/`, for example
  `data/admin-log-archives/`.

### 3.2 HTTP protection and updater/deploy ownership

The new runtime directory must not be directly web-readable. Add a release-owned policy file:

```text
data/gallery-trash/.htaccess
```

with the same deny policy style as `data/admin-log-archives/.htaccess`:

```apache
Require all denied
```

Because deploy currently excludes all neighboring `data/` runtime content, explicitly whitelist only
this `.htaccess` file in the same manner as `data/admin-log-archives/.htaccess`:

- `scripts/deploy.sh`
- `scripts/deploy.ps1`
- `app/services/update_server_policy_reconciliation.php` in
  `application_update_server_policy_files()`

The updater must own the policy file but **never own, traverse, replace, or clean neighboring
`data/gallery-trash/<token>/` payloads**.

### 3.3 Per-entry layout

```text
data/gallery-trash/<trash_token>/
    payload/<original folder tree>
    manifest.json
```

Example:

```text
data/gallery-trash/8db3...c19/
    payload/trips/iceland-2025/...
    manifest.json
```

`trash_relative_path` stored in DB is project-relative, for example:

```text
data/gallery-trash/8db3...c19
```

Never persist an install-specific absolute path.

`manifest.json` is a **sanitized recovery/debug manifest**, not a full copy of the DB snapshot. It may
contain:

```json
{
  "version": 1,
  "trash_token": "...",
  "original_folder_path": "trips/iceland-2025",
  "deleted_at": "2026-09-07 16:00:00",
  "purge_after": "2026-10-07 16:00:00",
  "gallery_count": 4,
  "image_count": 812,
  "byte_size": 123456789,
  "app_version": "0.x"
}
```

Do **not** write password hashes, share/access tokens, upload tokens, or other credentials to the
human-readable manifest. The DB snapshot is authoritative for restoration.

---

## 4. Database model

### 4.1 New table `gallery_trash_entries`

One row represents one user-facing trash operation for one kept root. A selected parent and selected
child are deduplicated exactly as current `delete_gallery_subtrees()` does, so a parent subtree creates
one trash entry.

Create a timestamp-prefixed migration under `database/migrations/`:

```php
<?php

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS gallery_trash_entries (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        trash_token CHAR(32) NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'preparing',
        original_folder_path VARCHAR(1024) NOT NULL,
        original_parent_folder_path VARCHAR(1024) NULL,
        title VARCHAR(255) NOT NULL,
        subtree_gallery_count INT UNSIGNED NOT NULL DEFAULT 0,
        image_count INT UNSIGNED NOT NULL DEFAULT 0,
        byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
        snapshot_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        snapshot_json LONGTEXT NULL,
        trash_relative_path VARCHAR(1024) NOT NULL,
        deleted_by_user_id BIGINT UNSIGNED NULL,
        deleted_from VARCHAR(32) NOT NULL DEFAULT 'admin',
        deleted_at DATETIME NOT NULL,
        purge_after DATETIME NOT NULL,
        operation_started_at DATETIME NULL,
        restored_at DATETIME NULL,
        purged_at DATETIME NULL,
        last_error_code VARCHAR(64) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY gallery_trash_entries_token_unique (trash_token),
        KEY gallery_trash_entries_status_purge_index (status, purge_after),
        KEY gallery_trash_entries_deleted_at_index (deleted_at),
        KEY gallery_trash_entries_original_path_index (original_folder_path(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "ALTER TABLE gallery_trash_entries
        ADD CONSTRAINT gallery_trash_entries_user_foreign
        FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL",
];
```

Notes:

- Use `VARCHAR(24)` rather than MySQL/MariaDB `ENUM` for `status`; the state machine may need future
  recovery states without another enum-alter migration.
- Use `LONGTEXT`, not `MEDIUMTEXT`, because one subtree snapshot can contain metadata for many
  thousands of images.
- `snapshot_json` becomes `NULL` after a successful `restored` or `purged` finalization so the table
  does not become a permanent multi-megabyte JSON archive. Keep the compact audit columns.
- There is no FK to `galleries`; live gallery rows intentionally disappear while the trash entry
  survives.
- `original_parent_folder_path`, not `original_parent_id`, is authoritative. Numeric gallery IDs are
  not stable across delete/restore.
- Migration replay behavior is already handled by `app/migrations.php::apply_migration_statement()`
  and its duplicate-DDL tolerance.

### 4.2 State machine

Allowed application states:

```text
preparing
trashed
restoring
purging
restored
purged
broken
```

Normal transitions:

```text
preparing -> trashed
trashed   -> restoring -> restored
trashed   -> purging   -> purged
```

`broken` is reserved for a state that reconciliation cannot safely complete or roll back without
administrator attention.

Never implement restore/purge as "SELECT status, then act" only. State acquisition must be an atomic
conditional `UPDATE`, described in section 7.

---

## 5. Snapshot contract

### 5.1 Versioned snapshot

The DB snapshot is versioned from day one:

```jsonc
{
  "version": 1,
  "root_folder_path": "trips/iceland-2025",
  "galleries": [
    {
      "folder_path": "trips/iceland-2025",
      "parent_folder_path": "trips",
      "title": "...",
      "description": "...",
      "slug": "...",
      "visibility": "public",
      "sort_order": 30,
      "access_mode": "password",
      "access_listing": "unlisted",
      "access_password_hash": "...",
      "access_share_token": "...",
      "access_token_hash": "...",
      "access_token_expires_at": null,
      "voting_enabled": 1,
      "picture_game_enabled": 0,
      "show_filenames": 0,
      "gps_map_enabled": null,
      "content_language": "en",
      "manual_date_start": null,
      "manual_date_end": null,
      "lightbox_browsing_mode": null,
      "cover_image_relative_path": "hero.jpg",
      "tags": ["iceland", "aurora"],
      "translations": [],
      "branding": null,
      "flight_maps": []
    }
  ],
  "images": [
    {
      "gallery_folder_path": "trips/iceland-2025",
      "relative_path": "hero.jpg",
      "title": null,
      "description": null,
      "sort_order": 10,
      "visibility": "public",
      "editorial_rating": null,
      "content_language": null,
      "tags": ["aurora"],
      "translations": []
    }
  ]
}
```

`gallery_subtree_rows()` currently returns rows ordered by `folder_path`; preserve explicit
parent-first ordering in the snapshot rather than relying on incidental SQL lexical behavior alone.

### 5.2 Durable vs transient metadata

Restore durable gallery configuration, including long-lived gallery access configuration:

- title / description / slug / sort order / visibility
- access mode and listing mode
- `access_password_hash`
- gallery access/share-token fields that belong to the gallery access configuration itself
- gallery display options
- manual date settings
- content language
- lightbox browsing mode
- gallery tags/translations
- image title/description/sort/visibility/editorial rating/content language/tags/translations
- branding metadata/assets that are already physically inside the moved subtree or can be safely
  reconstructed by current branding helpers
- gallery flight-map configuration
- cover image by `cover_image_relative_path`

Do not restore transient ownership/activity rows:

- numeric gallery/image IDs
- votes and picture-game votes
- Viewer favourites and collections
- `gallery_upload_tokens`
- `mobile_webdav_upload_tokens`
- telemetry rows
- AI analysis queue/metadata rows
- ZIP archive rows/files outside the gallery subtree
- duplicate-photo ledger rows
- thumbnail-variant DB rows
- generated public-path/hash fields that current services can regenerate
- width/height/checksum/file-size metadata that `scan_gallery_images()` derives from disk

Clarification: "share tokens are not restored" must **not** be used as a blanket rule. Durable
`galleries.access_*` credentials/configuration are part of gallery state and should return with the
gallery. Temporary upload/WebDAV/session/collection tokens are not restored.

### 5.3 Snapshot field registry

Do not scatter an unreviewed hard-coded column list through capture/restore code. Add centralized
classification helpers in `gallery_trash.php`, for example:

```text
gallery_trash_gallery_snapshot_fields()
gallery_trash_image_snapshot_fields()
gallery_trash_gallery_derived_fields()
gallery_trash_image_derived_fields()
```

When the live schema gains a new gallery/image column, focused tests should force the implementer to
classify it as one of:

```text
restore
derived
transient
unsupported
```

Every optional table/column read must continue to follow the current three-state schema policy and
existing `*_schema_ready()` helpers, including current helpers such as:

- `gallery_access_schema_ready()`
- `gallery_access_share_token_schema_ready()`
- `gallery_branding_schema_ready()`
- `gallery_lightbox_browsing_mode_schema_ready()`
- `gallery_date_schema_ready()` / `gallery_date_range_schema_ready()`
- other existing schema-inspection helpers for translations/tags/flight maps/image metadata

### 5.4 Snapshot upgrades

Add:

```text
gallery_trash_snapshot_upgrade(array $snapshot): array
```

Version 1 may initially be a no-op. Restore must always pass stored JSON through this boundary so a
25-day-old trash item can still be restored after a later application schema change.

---

## 6. New service: `app/services/gallery_trash.php`

Create a normal strict namespaced service:

```php
declare(strict_types=1);
namespace Gallery\Services;
```

Every function must have a docblock because `tests/function_documentation_test.php` enforces service
function documentation.

### 6.1 Load order

The original draft suggested loading immediately after `gallery_mutations.php`, but the current
`app/services.php` order places important helpers later:

- `gallery_mutations.php` at ~93
- `image_scanning.php` at ~96
- `gallery_access.php` at ~116
- `public_paths.php` at ~117
- `gallery_lookup.php` at ~118
- `gallery_sidecars.php` at ~121
- `gallery_paths.php` at ~122
- `admin_storage_statistics.php` at ~128
- `site_maintenance.php` at ~150
- `smart_galleries.php` at ~206
- `flight_maps.php` at ~207

Register `gallery_trash.php` **after `admin_storage_statistics.php` and before `site_maintenance.php`**.
Functions that depend on later-loaded optional services such as Smart Galleries or flight maps must
call them only at request execution time and, where appropriate, behind `function_exists()` / schema
readiness guards. Do not execute dependency-sensitive work at include time.

### 6.2 Core configuration/readiness helpers

```text
gallery_trash_enabled(): bool
gallery_trash_auto_purge_enabled(): bool
gallery_trash_auto_purge_active(): bool
gallery_trash_retention_days(): int
gallery_trash_purge_batch_size(): int
gallery_trash_root(): string
gallery_trash_schema_status(): array
gallery_trash_entries(array $filters = []): array
gallery_trash_entry(string $trashToken): ?array
gallery_trash_summary(): array
```

Suggested clamps:

```text
gallery_trash_enabled: boolean, default on
gallery_trash_auto_purge_enabled: boolean, default off
gallery_trash_retention_days: 1..365, default 30
gallery_trash_purge_batch_size: 1..100, default 25
```

`gallery_trash_root()` resolves `<project_root>/data/gallery-trash`, creates it when missing, and
must verify that it is outside `galleries_root()`.

`gallery_trash_schema_status()` should use current mutation schema infrastructure:

```php
function gallery_trash_schema_status(): array
{
    return mutation_schema_tables_status('mutation.gallery_trash', [
        'gallery_trash_entries' => [
            'id',
            'trash_token',
            'status',
            'original_folder_path',
            'snapshot_version',
            'snapshot_json',
            'trash_relative_path',
            'deleted_at',
            'purge_after',
            'operation_started_at',
            'updated_at',
        ],
    ]);
}
```

### 6.3 Exact DB transaction refactor required in `gallery_mutations.php`

Current `gallery_delete_database_subtree_rows()` owns its own transaction:

```text
$pdo->beginTransaction()
...
$pdo->commit()
```

Trash needs the live-row deletion and the `preparing -> trashed` state transition to commit in the
**same DB transaction**. PDO/MySQL must not be given a nested transaction.

Refactor internally without changing public hard-delete behavior:

```text
gallery_delete_database_subtree_rows(array $galleryIds): int
    -> owns begin/commit/rollback exactly as today
    -> delegates the SQL cleanup body to:

gallery_delete_database_subtree_rows_in_transaction(array $galleryIds): int
    -> requires db()->inTransaction() === true
    -> performs the existing dependent-row cleanup only
    -> never begin/commit/rollback itself
```

`delete_gallery_subtrees()` remains the public permanent-delete primitive and preserves its current
external behavior/result shape. Internal rollback callers continue using it as today.

The new trash service uses the transaction-neutral helper only after opening its own transaction.

---

## 7. Trash operation: crash-safe state protocol

### 7.1 Public API

```text
move_gallery_subtrees_to_trash(array $galleryIds, array $options = []): array
```

Options:

```text
user_id
deleted_from: dashboard_bulk | public_inline | other
```

Return at least:

```php
[
    'requested_root_count' => int,
    'root_count' => int,
    'failed_root_count' => int,
    'row_count' => int,
    'image_count' => int,
    'entries' => [string, ...],
    'missing_folders' => int,
    'failures' => [
        ['gallery_id' => int, 'error_code' => string],
        ...
    ],
]
```

Keep `root_count`, `row_count`, and `missing_folders` because current dashboard/public delete callers
already consume those concepts.

### 7.2 Preflight

Before the first filesystem or destructive DB mutation:

1. Assert `gallery_trash_schema_status()` available.
2. Assert `gallery_deletion_schema_status()` available.
3. Normalize IDs exactly like current `delete_gallery_subtrees()`:
   - positive integers
   - unique
   - `find_gallery($id, true)`
   - sort by normalized `folder_path` length
   - drop roots already covered by a previously kept root
4. Capture the complete restore snapshot for each kept root before removing any live row.
5. Resolve source with `gallery_abs_path()` and assert it remains inside `galleries_root()`.
6. Generate `bin2hex(random_bytes(16))` token.
7. Calculate `deleted_at` and `purge_after` from the clamped retention setting.

### 7.3 Per-root state protocol

Treat each kept root as its own atomic unit. Do not attempt an all-or-nothing filesystem transaction
across a 20-gallery bulk request.

For each root:

1. Insert `gallery_trash_entries` with:
   - `status='preparing'`
   - complete `snapshot_json`
   - `operation_started_at=NOW()`
   - token/path/counts/audit fields
2. Create `data/gallery-trash/<token>/payload/...` parent directories.
3. Move the gallery directory from `galleries_root` to the payload location.
4. Write sanitized `manifest.json`.
5. Begin one DB transaction.
6. Call `gallery_delete_database_subtree_rows_in_transaction($subtreeRowIds)`.
7. In the same DB transaction update the trash row:
   - `status='trashed'`
   - `operation_started_at=NULL`
   - `updated_at=NOW()`
8. Commit.

If step 2/3/4 fails before DB deletion:

- best-effort return the folder to its source path if it was already moved;
- delete or mark the still-`preparing` row as failed only after the filesystem state is known;
- throw/record a bounded operation error;
- never hard-delete the live DB subtree as a fallback.

If the DB transaction in steps 5-8 fails:

- rollback DB;
- live gallery rows therefore remain;
- best-effort move payload back to the original location;
- if the request cannot complete the filesystem rollback, leave the row in `preparing` for the
  reconciliation worker rather than guessing.

A PHP fatal error, FPM termination, timeout, or process kill can bypass `catch`. This is why the
persistent `preparing` state is mandatory.

### 7.4 Missing source folder

Current hard deletion simply counts a missing folder and still removes stale DB rows. A user-facing
trash operation must not silently turn this into an untracked permanent metadata delete.

When the root folder is already missing:

- capture the metadata snapshot;
- create a trash entry so the operation remains auditable/recoverable at metadata level;
- set `missing_folders` accordingly;
- remove the stale live DB rows inside the same state-transition transaction;
- keep enough snapshot metadata to support a **metadata-only restore** that recreates the gallery
  folder hierarchy but obviously cannot restore image bytes that were already absent before trashing;
- surface this condition clearly in Admin.

Do not pretend media was preserved when the source directory did not exist.

### 7.5 Filesystem move helper

```text
gallery_trash_move_directory(string $from, string $to, string $allowedSourceRoot, string $allowedDestinationRoot): void
```

Preferred path is `rename()`, which should be O(1) when `galleries/` and `data/` are on the same
filesystem, as expected on the normal WEDOS/shared-host install.

If a cross-device fallback is implemented, it must be conservative:

```text
copy source -> temporary destination under data/gallery-trash/<token>/
verify complete tree
rename temporary destination -> final payload location
delete original with allowed-root guard
```

Do not delete the source after a partial/unverified copy. Do not follow symlinks outside either
allowed root.

It is acceptable for the first implementation to fail closed when `rename()` fails rather than ship
an unsafe recursive-copy fallback. Data safety is more important than supporting an unusual
cross-device layout silently.

### 7.6 Post-trash invalidation

After successfully processing the batch:

- `thumbnail_maintenance_summary_cache_clear()`
- `sync_gallery_parent_ids()`
- `refresh_gallery_public_paths()` when `public_path_schema_ready()`
- `smart_gallery_graph_cache_clear()` when loaded

Do not mention the flight-navigation `navigation_data_cache`; that cache is unrelated to gallery tree
navigation.

---

## 8. Restore operation

### 8.1 Public API

```text
restore_gallery_trash_entry(string $trashToken, array $options = []): array
```

Initial implementation restores only to the original folder path. A future `restore_to` feature may
be added, but do not silently rename or regenerate the root slug/path during ordinary restore.

### 8.2 Atomic claim

Never do only:

```text
SELECT status='trashed'
then restore
```

Two simultaneous requests, or Restore racing with auto-purge, could then mutate the same payload.
Claim the entry atomically:

```sql
UPDATE gallery_trash_entries
SET status = 'restoring', operation_started_at = NOW(), updated_at = NOW()
WHERE trash_token = ? AND status = 'trashed'
```

Require `rowCount() === 1`. Otherwise reload the entry and return a bounded "already being processed
or no longer available" result.

If preflight collision validation fails after the claim, atomically return `restoring -> trashed`
without touching the payload.

### 8.3 Collision checks

Before moving payload back, refuse restore if:

- `gallery_abs_path(original_folder_path)` already exists as a live directory;
- `find_gallery_by_folder_path(original_folder_path, true)` returns a live gallery;
- any stored gallery slug conflicts with the current unique `galleries.slug` constraint;
- any reconstructed `folder_path_hash` conflicts with a live row;
- another active trashed entry represents an ancestor of this path, as described below.

Do not overwrite existing content and do not silently choose a new slug.

### 8.4 Parent/child trash dependency

Example:

```text
A/
  B/
```

If `B` is trashed first and later `A` is trashed separately, both trash entries are valid. Restoring
`B` while the original `A` entry is still actively trashed must **not** create a fake empty `A/` that
would later block restoring the real `A`.

Before creating missing ancestors, query active trash entries for an ancestor path. If one exists,
refuse with a specific message such as:

```text
Cannot restore this gallery while its original parent "A" is still in the trash. Restore the parent first.
```

Only use `ensure_gallery_ancestors_for_path()` to create empty ancestor shells when no live ancestor
and no actively trashed ancestor represents the original hierarchy. Surface a notice when such shells
were created.

### 8.5 Restore sequence

For a normal payload-present entry:

1. Atomically claim `trashed -> restoring`.
2. Decode `snapshot_json` and pass it through `gallery_trash_snapshot_upgrade()`.
3. Perform all collision/ancestor checks.
4. Verify payload path boundaries and existence.
5. Begin a DB transaction.
6. Insert the snapshot gallery rows parent-first using current gallery creation/path/slug/hash rules,
   but do not commit yet.
7. Move payload back to `gallery_abs_path(original_folder_path)` while the DB transaction remains
   uncommitted.
8. `sync_gallery_parent_ids()` or the smallest safe equivalent needed to link the new rows.
9. For each restored gallery, call `scan_gallery_images($galleryId)` so image rows are reconstructed
   from the actual returned filesystem.
10. Reapply durable image metadata by `(gallery_folder_path, relative_path)`.
11. Reapply gallery tags/translations/access/display/date/lightbox/branding/flight-map metadata behind
    current schema-readiness guards.
12. Resolve and restore `cover_image_id` from `cover_image_relative_path` after image scan.
13. Run `write_gallery_sidecar()` for restored galleries so sidecars reflect current DB state.
14. Refresh public paths when ready.
15. In the same DB transaction mark trash row:
    - `status='restored'`
    - `restored_at=NOW()`
    - `operation_started_at=NULL`
    - `snapshot_json=NULL`
16. Commit.
17. Remove the now-empty `data/gallery-trash/<token>/` directory.
18. Clear thumbnail/storage/Smart Gallery request caches as appropriate.

Important: verify that every helper used inside the restore transaction does **not** unconditionally
open/commit its own transaction. If a helper owns transactions, extract a transaction-neutral internal
variant just as required for gallery deletion. Do not accidentally commit the outer restore halfway
through.

If restore fails after the filesystem payload moved but before DB commit:

- roll back DB;
- best-effort move the gallery folder back to its trash payload path;
- if rollback cannot complete, leave `status='restoring'` for reconciliation instead of marking the
  entry `trashed` falsely.

### 8.6 Metadata-only restore

For an entry created from an already-missing source folder:

- recreate the folder/subgallery hierarchy;
- recreate gallery DB metadata from snapshot;
- no image bytes can be recovered;
- return a result flag such as `metadata_only => true` and an explicit admin notice.

### 8.7 Return shape

```php
[
    'gallery_count' => int,
    'image_count' => int,
    'root_gallery_id' => int,
    'metadata_only' => bool,
    'ancestor_shells_created' => int,
]
```

---

## 9. Purge, empty trash, and reconciliation

### 9.1 APIs

```text
purge_gallery_trash_entry(string $trashToken): array
purge_expired_gallery_trash(int $limit = 25): array
reconcile_gallery_trash_transitional_entries(int $limit = 10): array
```

The Admin "Empty trash" action should use a bounded batch endpoint rather than one unbounded PHP
request:

```text
empty_gallery_trash_batch(int $limit = 25): array
```

Return `remaining` so the Admin UI can continue requesting batches until zero. This avoids shared-host
execution-time failures while still making the user-visible command genuinely empty the entire trash.

### 9.2 Purge atomic claim

Claim one item:

```sql
UPDATE gallery_trash_entries
SET status = 'purging', operation_started_at = NOW(), updated_at = NOW()
WHERE trash_token = ? AND status = 'trashed'
```

Require one affected row.

Then:

1. Resolve `<trash_root>/<token>` and verify it is inside `gallery_trash_root()`.
2. `delete_directory_tree($tokenDir, gallery_trash_root())` when the token directory exists.
3. Mark:
   - `status='purged'`
   - `purged_at=NOW()`
   - `operation_started_at=NULL`
   - `snapshot_json=NULL`
4. Log a bounded `gallery.trash_purged` admin event.

If the payload is already missing, purge may still finalize the entry as `purged`; there is nothing
left to preserve. Do not fail permanently because an out-of-band cleanup already removed the files.

### 9.3 Expired purge

Select only:

```text
status='trashed' AND purge_after <= NOW()
```

with deterministic oldest-first ordering and a bounded limit. Each selected token is still atomically
claimed before deletion, because a Restore click can race with the maintenance query.

### 9.4 Reconciliation worker

Add a bounded reconciliation pass for transitional rows whose `operation_started_at` is stale, for
example older than 5 minutes. Reconciliation is required because PHP fatal errors/timeouts may bypass
all `catch` blocks.

Expected recovery decisions:

#### stale `preparing`

- If live DB rows still exist, source folder is absent, and trash payload exists: move payload back to
  the original source path, then remove/close the abandoned preparing row.
- If live DB rows are gone and payload exists: verify snapshot/state, then finalize to `trashed` only
  when the DB deletion can be proven complete.
- If both live source and payload exist or neither state can be proven safe: mark `broken` and log a
  bounded diagnostic.

#### stale `restoring`

- If DB restore did not commit and the folder sits at the live target, move it back into the payload
  and return the entry to `trashed` when safe.
- If the DB restore and final `restored` state already committed, no repair is needed.
- Ambiguous mixed ownership becomes `broken`; never overwrite either copy to guess.

#### stale `purging`

- If token directory no longer exists, finalize `purged`.
- If token directory still exists, retry the guarded purge or leave it for the next bounded pass.

Reconciliation must never use age alone to delete gallery bytes.

### 9.5 Site Maintenance integration

`app/services/site_maintenance.php` currently loads after gallery/media/log helpers and already drives
cron, CLI, and request-trigger maintenance. Add two bounded hooks to the cleanup step:

```text
reconcile_gallery_trash_transitional_entries(...)
purge_expired_gallery_trash(...)
```

Use `gallery_trash_purge_batch_size()` instead of reading an undeclared setting ad hoc.

`reconcile_gallery_trash_transitional_entries(...)` continues to run even when the trash feature or
auto-purge is disabled because it repairs interrupted state-machine operations. By contrast,
`purge_expired_gallery_trash(...)` must return without deleting anything unless **both**
`gallery_trash_enabled()` and `gallery_trash_auto_purge_enabled()` are true.

No new cron endpoint is required.

---

## 10. User-facing delete wiring

### 10.1 Keep permanent delete primitive

`delete_gallery_subtrees()` remains the hard-delete primitive. Preserve its public behavior because it
is used for rollback/cleanup of galleries auto-created inside larger operations.

Its internal DB cleanup may be refactored as described in section 6.3, but the legacy function must
still behave as a permanent delete when explicitly called.

### 10.2 Dashboard bulk delete

Current call site:

```text
app/controllers/admin_galleries_bulk.php
cms_admin_bulk_galleries()
if ($action === 'delete' && $galleryIds)   around line 96
```

Behavior:

```text
trash enabled + trash schema available
    -> move_gallery_subtrees_to_trash(..., deleted_from=dashboard_bulk)

trash enabled + schema missing/unknown
    -> refuse, show migration/temporary-unavailable notice, delete nothing

trash explicitly disabled
    -> existing delete_gallery_subtrees() hard-delete behavior
```

Do not silently fall back to hard delete because schema inspection failed.

Bulk UI must report partial success accurately if one root fails after earlier independent roots were
successfully trashed.

### 10.3 Public inline gallery delete

Current call site:

```text
app/controllers/admin_public_inline.php
cms_admin_public_update_gallery()
if ($action === 'delete')                 around line 189
```

Replace only the underlying user-facing delete operation. Preserve:

- parent redirect calculation
- JSON buffer isolation
- `admin_mutation_success_envelope()` / `admin_mutation_error_envelope()`
- `admin_mutation_public_gallery_context()`
- gallery membership postcondition
- side-panel in-place interaction without a full page reload

Keep the mutation entity IDs stable. Prefer changing the semantic action/operation to a clear trash
name only if `scripts/check_admin_mutation_contracts.php` and current client code accept it. Otherwise
retain the existing `gallery.delete` mutation descriptor and treat trash as the implementation of the
user-facing delete command.

### 10.4 Intentional hard-delete call sites

Keep current `delete_gallery_subtrees()` behavior at these rollback call sites because they destroy a
just-created gallery after a larger operation failed and should not pollute the user's trash:

- `app/controllers/admin_images_bulk.php` around lines 178 and 244
- `app/controllers/picture_manager.php` around lines 334 and 377
- `app/services/gallery_metadata_organizer.php` around lines 645, 653, 868, 876

Add a concise comment at each logical rollback block explaining that hard delete is intentional for a
failed auto-created destination.

---

## 11. Admin UI integration into the existing Maintenance tab

Do not create an unrelated standalone admin page unless implementation constraints require it. The
current dashboard already lazily loads Maintenance through:

```text
app/controllers/admin_dashboard.php::cms_admin_dashboard_maintenance()
app/views/admin_dashboard_sections.php::view_render_admin_dashboard_maintenance_panel()
```

and has nested subtabs for Content, Media, Navigation, and System Health.

### 11.1 Add a Trash maintenance subtab

Add:

```text
admin-maintenance-trash
```

with label `Trash` / `Koš` and badge from `gallery_trash_summary()['trashed_count']` when non-zero.

Extend current `maintenance_tab` handling beyond the hard-coded `media` special case so
`maintenance_tab=trash` opens and lazy-loads the Trash panel directly.

The trash panel shows:

- enabled toggle
- retention days
- Title
- Original path
- Deleted at
- Deleted by
- Purges in / purge date
- Size
- Gallery count
- Image count
- state/problem indicator when relevant
- Restore
- Delete permanently
- Empty trash

### 11.2 New controller for POST mutations

Create:

```text
app/controllers/admin_trash.php
```

Register it in `app/controllers.php` near the Admin gallery/dashboard controllers.

Routes in `app/bootstrap/dispatch.php`:

```php
'admin_trash_restore'  => '\\Gallery\\Controllers\\cms_admin_trash_restore',
'admin_trash_purge'    => '\\Gallery\\Controllers\\cms_admin_trash_purge',
'admin_trash_empty'    => '\\Gallery\\Controllers\\cms_admin_trash_empty',
'admin_trash_settings' => '\\Gallery\\Controllers\\cms_admin_trash_settings',
```

No separate GET listing route is necessary when the existing deferred Maintenance endpoint supplies
the list model.

All mutation routes:

- `require_admin()`
- POST only
- `verify_csrf()`
- preserve canonical Admin mutation response contracts when AJAX is used
- provide redirect fallback to `url_for('admin', ['maintenance_tab' => 'trash']) . '#admin-tab-maintenance'`

"Empty trash" uses repeated bounded batches until `remaining=0`; it must not claim success after only
one 25-entry batch.

### 11.3 Dashboard delete wording

In `app/views/admin_dashboard.php`, the current bulk option is around line 150:

```text
admin.dashboard.bulk_delete_selected
```

When trash is enabled and available, use recoverable wording such as "Move selected galleries to
trash". If automatic purge is enabled, the confirmation may include the configured retention period.
If automatic purge is disabled, the confirmation must instead state that the item remains in Trash
until restored or explicitly permanently deleted.

When trash is explicitly disabled, retain the current destructive wording and confirmation. This
setting affects only future deletes: Admin > Maintenance > Trash remains available for previously
trashed items and those items are not automatically destroyed while the feature is disabled.

Public inline gallery controls follow the same distinction. Never promise a fixed restore window when
automatic purge is disabled.

---

## 12. Settings

Use `app_settings`; no separate settings table migration is needed.

| Key | Default | Clamp | Meaning |
| --- | ---: | ---: | --- |
| `gallery_trash_enabled` | `1` | boolean | Controls future user-facing gallery deletes. `1` moves them to Trash; `0` explicitly uses legacy hard delete. Existing Trash rows/payloads are never removed by toggling this setting. Automatic purge is paused while this is `0`. |
| `gallery_trash_auto_purge_enabled` | `0` | boolean | Enables retention-based permanent purge by Site Maintenance. Default **off**. Manual Restore / Delete permanently / Empty trash remain available regardless. |
| `gallery_trash_retention_days` | `30` | `1..365` | Retention interval used to calculate `purge_after`. It has no destructive effect while automatic purge is disabled or the whole Trash feature is disabled. |
| `gallery_trash_purge_batch` | `25` | `1..100` | Maximum purge/reconciliation batch size per maintenance slice / Empty-trash request. |

Add one setter boundary in `gallery_trash.php`, following the style of
`set_site_maintenance_settings()`:

```text
set_gallery_trash_settings(bool $enabled, bool $autoPurgeEnabled, int $retentionDays, int $purgeBatch): void
```

The setter is a safety boundary, not four unrelated `app_settings` writes:

- toggling `gallery_trash_enabled` off must not touch any trash row or payload;
- toggling it off pauses scheduled purge even if the saved auto-purge preference is still on;
- switching auto-purge `off -> on`, or re-enabling the Trash feature while auto-purge is configured
  on, must first move `purge_after` for current `trashed` / relevant in-flight entries to
  `now + retention_days` while at least one destructive guard is still off;
- only after that re-arm succeeds may the destructive setting combination become active;
- `broken` entries are never automatic-purge candidates.

Register discoverability in `app/services/admin_settings_registry.php` if appropriate for the current
Settings hub, but the primary controls live in the Maintenance > Trash subtab.

Retention is captured in each entry's `purge_after`; do not recompute expiration dynamically from the
current setting on every list/maintenance read. The explicit safety re-arm on destructive-setting
activation is the intentional exception.

---

## 13. Three-state schema policy and health registration

### 13.1 Mutation boundary

All trash/restore/purge operations must fail closed through current mutation schema policy:

```text
available -> proceed
missing   -> migration-required error, no destructive fallback
unknown   -> temporary-unavailable error, no destructive fallback
```

If `gallery_trash_enabled=0`, the administrator has explicitly opted out of the feature and current
hard-delete behavior remains allowed subject to the existing `gallery_deletion_schema_status()` gate.

Do not convert `missing` or `unknown` into hard delete automatically.

### 13.2 Actual health registration location

`admin_mutation_schema_health_statuses()` is currently in:

```text
app/services/admin_dashboard.php
```

around line 433, not in `mutation_schema_policy.php`.

Add:

```text
mutation_gallery_trash => gallery_trash_schema_status()
```

there so Runtime/System Health uses the same three-state vocabulary as the mutation boundary.

`gallery_trash_schema_status()` itself belongs in `app/services/gallery_trash.php` and uses helpers
provided by `mutation_schema_policy.php`.

Add the translation label used by the health renderer, following the existing mutation feature-key
pattern.

---

## 14. Cache/path/derived-state refresh rules

After successful trash or restore:

- `thumbnail_maintenance_summary_cache_clear()`
- `sync_gallery_parent_ids()` when hierarchy may have changed
- `refresh_gallery_public_paths()` when `public_path_schema_ready()`
- `smart_gallery_graph_cache_clear()` when loaded/needed
- `admin_storage_statistics_cache_clear()` if not already reached through thumbnail maintenance cache
  clearing and the storage panel would otherwise retain stale totals

After restore, call `write_gallery_sidecar()` for each restored gallery after durable metadata has
been reapplied.

Do not regenerate thumbnails merely because of restore when the existing thumbnail files travelled
inside the gallery folder. Let normal thumbnail maintenance repair only genuinely missing/invalid
variants.

---

## 15. Edge-case decisions

1. **Selected parent + selected descendant in one bulk request**
   - Deduplicate exactly as current hard delete; one parent trash entry owns the live subtree.

2. **Child trashed earlier, then parent trashed later**
   - Entries remain independent.
   - Parent snapshot contains only content still live at the later trash time.

3. **Restore child while its original parent is still actively trashed**
   - Refuse and instruct administrator to restore the parent first.
   - Do not create an empty parent shell that would block the parent's later restore.

4. **Restore child with missing ancestor that is neither live nor in trash**
   - `ensure_gallery_ancestors_for_path()` may recreate missing shells.
   - Surface `ancestor_shells_created` notice.

5. **Original path or slug reused after trash**
   - Restore refuses with collision message.
   - No silent overwrite, auto-rename, or slug mutation in v1.

6. **Source folder missing at delete time**
   - Preserve a metadata-only trash entry; remove stale live DB rows only through the tracked trash
     transition.
   - Restore can recreate gallery metadata/folder structure but not already-missing image bytes.

7. **Trash payload removed out of band**
   - Restore cannot claim full recovery and marks/report state appropriately.
   - Permanent purge can finalize to `purged` because bytes are already gone.

8. **Password/unlisted gallery**
   - Restore durable `galleries.access_*` configuration, including password/share access state.
   - Do not restore temporary upload/WebDAV tokens.

9. **Cover image**
   - Snapshot relative path, then resolve new image ID after `scan_gallery_images()`.

10. **Cross-device filesystem**
    - Prefer fail-closed rename-only v1 over unsafe copy/delete.
    - If fallback exists, copy to temp, verify, then delete source.

11. **Concurrent Restore/Purge/Empty/maintenance**
    - Conditional status updates claim each item.
    - A prior list/read does not grant mutation ownership.

12. **PHP timeout/fatal/restart**
    - Transitional state remains persistent.
    - bounded Site Maintenance reconciliation repairs or marks ambiguous entries `broken`.

13. **Bulk partial failure**
    - Each root is independent.
    - report succeeded and failed roots separately.

14. **Snapshot size/history**
    - `LONGTEXT` while active.
    - clear full snapshot after `restored`/`purged` finalization.

15. **Deploy/update**
    - runtime payload must never ship in deployment/update archives.
    - only the deny `.htaccess` policy file is release-owned.

---

## 16. Translations

Add every new key to all four maintained JSON catalogs, key-for-key and with identical placeholders:

```text
app/lang/en.json
app/lang/cs.json
app/lang/de.json
app/lang/sv.json
```

At minimum cover:

```text
admin.galleries.trashed_result
admin.galleries.trash_partial_result
admin.galleries.trash_requires_migration
admin.galleries.trash_temporarily_unavailable
admin.trash.title
admin.trash.empty_state
admin.trash.enabled_label
admin.trash.retention_label
admin.trash.purge_batch_label
admin.trash.column_original_path
admin.trash.column_deleted
admin.trash.column_purges_in
admin.trash.column_size
admin.trash.column_galleries
admin.trash.column_images
admin.trash.restore
admin.trash.restore_confirm
admin.trash.restore_success
admin.trash.restore_collision
admin.trash.restore_parent_still_trashed
admin.trash.restore_metadata_only
admin.trash.restore_broken
admin.trash.purge
admin.trash.purge_confirm
admin.trash.purge_success
admin.trash.empty
admin.trash.empty_confirm
admin.trash.empty_success
admin.trash.processing
admin.trash.broken
admin.dashboard.maintenance_trash_tab
admin.dashboard.mutation_schema_feature_gallery_trash
```

Run `tests/translation_catalog_consistency_test.php` through the central audit rather than maintaining
catalog parity manually by assumption.

---

## 17. File change checklist for implementation

This TEMP-only planning task does **not** make these code changes yet. The eventual implementation is
expected to touch approximately:

| # | File | Action |
| ---: | --- | --- |
| 1 | `database/migrations/2026XXXXXXXX_gallery_trash_bin.php` | NEW: `gallery_trash_entries` state table. |
| 2 | `app/services/gallery_trash.php` | NEW: snapshot, state machine, trash, restore, purge, reconciliation, settings/listing. |
| 3 | `app/services.php` | Register after `admin_storage_statistics.php`, before `site_maintenance.php`. |
| 4 | `app/services/gallery_mutations.php` | Extract transaction-neutral DB subtree cleanup while preserving `delete_gallery_subtrees()` behavior. |
| 5 | `app/services/site_maintenance.php` | Bounded reconciliation + expired purge hooks. |
| 6 | `app/services/admin_dashboard.php` | Trash view model/summary and mutation-schema health registration. |
| 7 | `app/services/admin_settings_registry.php` | Register trash settings if appropriate. |
| 8 | `app/controllers/admin_trash.php` | NEW: restore/purge/empty/settings POST boundaries. |
| 9 | `app/controllers.php` | Register new Admin trash controller. |
| 10 | `app/controllers/admin_galleries_bulk.php` | User-facing bulk delete routes through trash. |
| 11 | `app/controllers/admin_public_inline.php` | Inline delete routes through trash while preserving canonical envelope and panel behavior. |
| 12 | `app/controllers/admin_images_bulk.php` | Comment intentional rollback hard delete. |
| 13 | `app/controllers/picture_manager.php` | Comment intentional rollback hard delete. |
| 14 | `app/services/gallery_metadata_organizer.php` | Comment intentional rollback hard delete. |
| 15 | `app/bootstrap/dispatch.php` | Add trash POST routes. |
| 16 | `app/views/admin_dashboard.php` | Bulk delete wording + allow `maintenance_tab=trash` deferred target. |
| 17 | `app/views/admin_dashboard_sections.php` | Add Maintenance > Trash subtab/table/actions. |
| 18 | `app/lang/en.json`, `cs.json`, `de.json`, `sv.json` | New translation keys. |
| 19 | `data/gallery-trash/.htaccess` | NEW: deny direct HTTP access. |
| 20 | `scripts/deploy.sh`, `scripts/deploy.ps1` | Include only trash `.htaccess`, never payloads. |
| 21 | `app/services/update_server_policy_reconciliation.php` | Add trash `.htaccess` to release-owned policy list. |
| 22 | `tests/gallery_trash_model_test.php` | NEW focused model/state test. |
| 23 | `tests/...` as required | Focused controller/state/contract regression tests. |
| 24 | `ARCHITECTURE.md`, `DATABASE.md`, `CODEMAP.md`, `README.md` | Document permanent feature after implementation stabilizes. |
| 25 | `app/core-manifest.json` | Regenerate after final code/docs/server-policy changes as required by manifest policy. |

Do not touch `PATCH_NOTES.md` or bump `CMS_VERSION` unless a separate release task asks for it.

---

## 18. Tests

Follow `TESTING.md`; the central audit remains authoritative.

During implementation:

```text
php scripts/audit.php --profile=quick
```

Before handoff:

```text
php scripts/audit.php --profile=full
```

and explicitly run/confirm:

```text
php scripts/check_admin_mutation_contracts.php
php scripts/generate_manifest.php --check
```

### 18.1 Focused model/state tests

`tests/gallery_trash_model_test.php` should cover at least:

1. Root normalization and nested-root deduplication identical to current deletion semantics.
2. Parent-first snapshot order and image grouping by gallery path.
3. Optional snapshot fields omitted safely when schema status is unavailable/missing according to
   current optional-feature policy.
4. Retention clamp `[1,365]` and correct persisted `purge_after`.
5. Purge-batch clamp `[1,100]`.
6. Three-state mutation gate: `available`, `missing`, `unknown`; no filesystem/DB destructive call on
   refused states.
7. `gallery_trash_enabled=0` selects explicit legacy hard delete only at user-facing call sites and leaves existing Trash content untouched/visible.
8. `gallery_trash_auto_purge_enabled=0` prevents retention-based purge even for overdue rows; default is off.
9. Disabling the whole Trash feature pauses auto-purge without deleting rows or payloads.
10. `off -> on` auto-purge activation and re-enabling Trash with auto-purge configured on re-arm existing recoverable/in-flight deadlines before purge becomes active.
11. `preparing -> trashed` success transition.
12. DB failure after filesystem move leaves recoverable `preparing` state / rollback path and does not
    lose both DB state and payload.
13. Restore atomic claim; a second restore/purge claim for the same token fails.
14. Purge atomic claim; maintenance selection alone is not ownership.
15. Restore collisions for existing directory/live row/slug/hash.
16. Parent-still-trashed restore refusal.
17. Missing-source metadata-only trash/restore path.
18. Stale `preparing`, `restoring`, and `purging` reconciliation decisions.
19. Expired purge selects only `status='trashed' AND purge_after <= NOW()` when both automatic-purge guards are enabled, and respects limit.
20. Empty-trash batching returns `remaining` and reaches zero after repeated batches regardless of automatic-purge setting because it is an explicit admin action.
21. Path safety: `gallery_trash_root()` under `data/gallery-trash`, outside `galleries_root()`.
22. Purge passes token directory as target and trash root as `delete_directory_tree()` allowed root.
23. Sanitized `manifest.json` excludes access/password/token secrets.
24. Snapshot finalization clears large `snapshot_json` after restore/purge.
25. Post-mutation source contracts include parent sync/public-path refresh/thumbnail cache clear.

### 18.2 Existing suites expected to catch regressions

At minimum:

```text
tests/migration_consistency_test.php
tests/migration_legacy_runner_compatibility_test.php
tests/translation_catalog_consistency_test.php
tests/function_documentation_test.php
tests/runtime_refactor_boundaries_test.php
scripts/check_admin_mutation_contracts.php
```

Also let the full audit discover any additional Admin dashboard, public-inline, deploy-policy, updater,
manifest, or source-boundary tests affected by the actual implementation.

---

## 19. Manual acceptance on a disposable install

1. Create `A/`, `A/B/`, `A/B/C/` with photos and generated thumbnails in all levels.
2. Publish/access-configure galleries so restore can verify visibility/access state.
3. Dashboard bulk-select `A` and choose "Move selected galleries to trash".
4. Verify:
   - `galleries/A` is gone;
   - payload exists under `data/gallery-trash/<token>/payload/A/...`;
   - sanitized manifest exists;
   - live gallery/image rows are gone;
   - trash row is `trashed`;
   - home/search/tags/download/public-path/discovery no longer expose `A`;
   - discovery does not re-import trash because it is outside `galleries_root`.
5. Open Maintenance > Trash. Verify title/path/deleted/count/size information and badge. With automatic purge disabled by default, verify the row says it is kept until manually deleted rather than showing a false countdown.
6. Restore `A`.
7. Verify:
   - filesystem tree is back;
   - new gallery/image IDs are valid;
   - `A/B/C` hierarchy is correct;
   - image metadata/tags/translations restored;
   - cover image resolves to new image ID;
   - password/unlisted/access state is preserved;
   - temporary upload/WebDAV tokens are not restored;
   - public paths and sidecars are current;
   - trash entry is finalized `restored` and large snapshot JSON cleared.
8. Trash the gallery again from the public inline delete control. Verify canonical mutation response,
   side panel behavior, card removal, and no full-page reload regression.
9. Permanently delete one trash item. Verify payload removed and row finalized `purged`.
10. Create more than one purge batch worth of entries in a synthetic/test environment and verify Empty
    Trash continues bounded requests until `remaining=0`.
11. Verify scheduled purge safety: with automatic purge still off, backdate `purge_after` and run Site Maintenance; nothing is purged. Then enable automatic purge and verify existing recoverable entries receive a fresh full retention deadline rather than being deleted immediately. Backdate one newly armed deadline and rerun maintenance; that entry may now be purged. Do **not** set retention to `0`; the configured clamp is `[1,365]`.
12. Disable the whole Trash feature while an entry exists. Verify the entry remains listed/restorable, scheduled purge is paused, and new user-facing deletes use the legacy hard-delete wording/path. Re-enable Trash and verify the stored entry is still present; if auto-purge preference is on, verify its deadline was safely re-armed before purge resumed.
13. Migration gate: remove/omit `gallery_trash_entries`; user-facing delete refuses and destroys
    nothing while trash is enabled.
14. Race test: claim Restore and attempt Purge for the same token; exactly one operation owns it.
15. Crash/reconciliation test: manually leave one stale `preparing`, `restoring`, and `purging` state
    with controlled filesystem conditions and verify the bounded reconciler chooses the documented
    safe result.
16. Nested independent trash test: trash `B`, then trash `A`; restoring `B` while `A` remains trashed
    must refuse instead of creating a conflicting empty `A` shell.
17. Missing-source test: remove a gallery folder out-of-band, then trash its stale DB gallery; verify
    the trash item is explicitly metadata-only and no false media-restored claim is shown.
18. Deploy/update test: generated deployment ZIP contains `data/gallery-trash/.htaccess` policy when
    required by deploy policy, but contains no `data/gallery-trash/<token>` runtime payload.

---

## 20. Suggested staged build order

Keep each stage shippable enough that the gallery still works even though the complete feature is not
yet enabled at user-facing delete call sites.

### Stage 1 - foundations only

- migration
- `gallery_trash.php` constants/settings/readiness/root/path helpers
- `data/gallery-trash/.htaccess` + deploy/updater policy ownership
- focused state/schema/path tests
- no user-facing delete behavior changed yet

### Stage 2 - safe trash primitive

- snapshot capture/versioning
- transaction-neutral DB deletion helper extraction
- `preparing -> trashed` state protocol
- rename-only safe move first; add verified cross-device fallback only if justified
- reconciliation for stale `preparing`
- focused tests

### Stage 3 - dashboard bulk integration

- route dashboard bulk delete through trash
- partial-success result model
- wording/translation changes for dashboard bulk action
- manual trash verification

### Stage 4 - restore

- atomic `trashed -> restoring` claim
- collision + parent-still-trashed checks
- hybrid DB reconstruction + `scan_gallery_images()` + metadata reapply
- restore rollback/reconciliation
- metadata-only restore path
- manual restore verification

### Stage 5 - Trash Admin UI and permanent purge

- Maintenance > Trash subtab/model
- restore/purge/settings POST controller/routes
- atomic purge claim
- bounded Empty Trash batches
- remaining translations

### Stage 6 - automatic lifecycle

- stale transitional-state reconciliation in Site Maintenance
- expired bounded purge
- admin logs/diagnostics
- acceptance tests for race/crash cases

### Stage 7 - public inline delete

- swap public inline user-facing delete to trash
- preserve canonical mutation envelope and side-panel behavior
- run `scripts/check_admin_mutation_contracts.php`

### Stage 8 - documentation/final audit

- final docs: `ARCHITECTURE.md`, `DATABASE.md`, `CODEMAP.md`, `README.md`
- regenerate core manifest according to project policy
- `php scripts/audit.php --profile=full`
- remove this TEMP file before the actual release

---

## 21. Final implementation invariants

An implementation is not complete unless all of these remain true:

1. With Trash enabled, an ordinary administrator gallery delete never silently hard-deletes because
   trash schema inspection failed.
2. Trashed physical content is outside `galleries_root()` and therefore outside normal discovery.
3. Recoverable trash payload is not stored in generic cache.
4. No direct HTTP route can serve files from the trash store.
5. Trash, Restore, and Purge each have an explicit persistent state transition.
6. Restore and Purge cannot own the same token concurrently.
7. A PHP process dying between filesystem and DB operations leaves a state that bounded maintenance can
   reconcile without guessing destructively.
8. The live hard-delete primitive still exists for explicit/internal rollback use.
9. The full snapshot exists only while the entry can still need restoration/reconciliation; finalized
   audit rows do not retain unbounded JSON indefinitely.
10. Temporary tokens/activity/telemetry are not resurrected.
11. Durable gallery access configuration is restored.
12. Existing public inline Admin mutation contracts and side-panel UX remain intact.
13. Deployment/update workflows never package or replace runtime trash payloads.
14. Central full audit is green before handoff/release.
