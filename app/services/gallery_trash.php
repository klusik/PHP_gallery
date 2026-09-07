<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_trash.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides a recoverable trash bin for deleted gallery subtrees.
 *
 * Responsibilities:
 *   - Move a deleted gallery folder tree out of the gallery root instead of destroying it
 *   - Keep a durable restore snapshot of gallery and image metadata
 *   - Restore a trashed subtree back to its original path and hierarchy
 *   - Purge trash entries on admin request or, when explicitly enabled, after the configured retention window
 *   - Refuse every destructive step when required schema state cannot be verified
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - The trash store lives under persistent data/gallery-trash/ outside galleries_root(),
 *     so discovery, scanning, listings, public routes, and generic cache cleanup never see it.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-09-07
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;

/** Default number of days a trashed gallery stays recoverable. */
const GALLERY_TRASH_DEFAULT_RETENTION_DAYS = 30;

/** Lowest retention value an administrator may configure. */
const GALLERY_TRASH_MIN_RETENTION_DAYS = 1;

/** Highest retention value an administrator may configure. */
const GALLERY_TRASH_MAX_RETENTION_DAYS = 365;

/** Directory name of the persistent trash store inside the project data directory. */
const GALLERY_TRASH_DATA_DIRECTORY = 'gallery-trash';

/** Legacy cache directory used by the first development implementation. */
const GALLERY_TRASH_LEGACY_CACHE_DIRECTORY = 'gallery-trash';

/** Default number of entries processed by one trash purge/reconciliation batch. */
const GALLERY_TRASH_DEFAULT_PURGE_BATCH = 25;

/** Lowest configurable trash purge/reconciliation batch size. */
const GALLERY_TRASH_MIN_PURGE_BATCH = 1;

/** Highest configurable trash purge/reconciliation batch size. */
const GALLERY_TRASH_MAX_PURGE_BATCH = 100;

/** Lifecycle operations older than this may be reconciled by maintenance. */
const GALLERY_TRASH_STALE_OPERATION_SECONDS = 900;

/** Current restore snapshot document version. */
const GALLERY_TRASH_SNAPSHOT_VERSION = 1;

/**
 * Return whether deleted galleries should be moved to the trash bin.
 *
 * @return bool True when the trash workflow replaces immediate deletion.
 */
function gallery_trash_enabled(): bool
{
    return (string) app_setting('gallery_trash_enabled', '1') !== '0';
}

/**
 * Return whether scheduled maintenance may permanently purge expired trash entries.
 *
 * This setting is deliberately independent from gallery_trash_enabled(): disabling
 * the trash feature only changes future delete behavior and never destroys or hides
 * entries that are already stored in the trash bin.
 *
 * @return bool True when retention-based automatic purge is enabled.
 */
function gallery_trash_auto_purge_enabled(): bool
{
    return (string) app_setting('gallery_trash_auto_purge_enabled', '0') === '1';
}

/**
 * Return whether retention-based permanent purge is currently allowed to run.
 *
 * The administrator's auto-purge preference is preserved while the whole trash
 * feature is disabled, but scheduled deletion remains paused until both guards
 * are enabled again.
 *
 * @return bool True only when both the trash feature and automatic purge are enabled.
 */
function gallery_trash_auto_purge_active(): bool
{
    return gallery_trash_enabled() && gallery_trash_auto_purge_enabled();
}

/**
 * Return the configured retention window in days.
 *
 * @return int Clamped retention length in days.
 */
function gallery_trash_retention_days(): int
{
    return gallery_trash_normalize_retention_days(
        (int) app_setting('gallery_trash_retention_days', (string) GALLERY_TRASH_DEFAULT_RETENTION_DAYS)
    );
}

/**
 * Clamp an administrator-submitted retention value to safe bounds.
 *
 * @param int $days Requested retention length in days.
 * @return int Clamped retention length in days.
 */
function gallery_trash_normalize_retention_days(int $days): int
{
    return max(GALLERY_TRASH_MIN_RETENTION_DAYS, min(GALLERY_TRASH_MAX_RETENTION_DAYS, $days));
}

/**
 * Return how many expired entries one scheduled maintenance slice may purge.
 *
 * @return int Bounded purge batch size.
 */
function gallery_trash_purge_batch_size(): int
{
    return gallery_trash_normalize_purge_batch_size(
        (int) app_setting('gallery_trash_purge_batch', (string) GALLERY_TRASH_DEFAULT_PURGE_BATCH)
    );
}

/**
 * Clamp an administrator-submitted purge/reconciliation batch size.
 *
 * @param int $batchSize Requested batch size.
 * @return int Clamped batch size.
 */
function gallery_trash_normalize_purge_batch_size(int $batchSize): int
{
    return max(GALLERY_TRASH_MIN_PURGE_BATCH, min(GALLERY_TRASH_MAX_PURGE_BATCH, $batchSize));
}

/**
 * Persist administrator-configurable trash bin settings.
 *
 * Disabling the trash feature never touches existing trash entries. When automatic
 * purge transitions from disabled to enabled, every currently recoverable or
 * in-flight entry receives a fresh full retention window before the setting becomes
 * active. This prevents old entries from being destroyed immediately merely because
 * automatic purge was enabled after a long disabled period.
 *
 * @param bool $enabled Whether future user-facing deletion should move galleries to the trash.
 * @param bool $autoPurgeEnabled Whether maintenance may permanently purge expired entries.
 * @param int $retentionDays Requested retention length in days.
 * @param int $purgeBatch Requested maintenance/empty-trash batch size.
 */
function set_gallery_trash_settings(bool $enabled, bool $autoPurgeEnabled, int $retentionDays, int $purgeBatch): void
{
    // $retentionDays stores the normalized retention value written and used for any deadline reset.
    $retentionDays = gallery_trash_normalize_retention_days($retentionDays);
    // $purgeBatch stores the normalized maintenance batch value written below.
    $purgeBatch = gallery_trash_normalize_purge_batch_size($purgeBatch);
    // $wasEnabled records whether automatic purge was allowed to run before this settings change.
    $wasEnabled = gallery_trash_enabled();
    // $wasAutoPurgeEnabled records the old independent automatic-purge preference.
    $wasAutoPurgeEnabled = gallery_trash_auto_purge_enabled();

    // Disable destructive paths first. Existing trash rows and payloads are never touched.
    if (!$enabled && $wasEnabled) {
        set_app_setting('gallery_trash_enabled', '0');
    }
    if (!$autoPurgeEnabled && $wasAutoPurgeEnabled) {
        set_app_setting('gallery_trash_auto_purge_enabled', '0');
    }

    // Activating the destructive combination after either component was paused gets a fresh
    // retention window while at least one old guard is still off. This prevents immediate purge.
    if ($enabled && $autoPurgeEnabled && (!$wasEnabled || !$wasAutoPurgeEnabled)) {
        gallery_trash_rearm_retention_deadlines($retentionDays);
    }

    set_app_setting('gallery_trash_retention_days', (string) $retentionDays);
    set_app_setting('gallery_trash_purge_batch', (string) $purgeBatch);
    set_app_setting('gallery_trash_auto_purge_enabled', $autoPurgeEnabled ? '1' : '0');
    set_app_setting('gallery_trash_enabled', $enabled ? '1' : '0');
}

/**
 * Give all currently recoverable/in-flight trash rows a fresh retention deadline.
 *
 * This is used only when automatic purge changes from off to on. BROKEN rows are
 * intentionally excluded because maintenance never auto-purges them, and PURGING
 * rows may already belong to an explicit administrator action.
 *
 * @param int $retentionDays Normalized retention window in days.
 */
function gallery_trash_rearm_retention_deadlines(int $retentionDays): void
{
    mutation_schema_assert_available(
        gallery_trash_schema_status(),
        'gallery.trash.settings_auto_purge',
        'The gallery trash bin needs a database migration before automatic purge can be enabled.',
        'The gallery trash schema could not be verified, so automatic purge was not enabled.'
    );

    // $now stores one stable timestamp for both updated_at and the new purge deadline.
    $now = now_sql();
    // $deadline stores a fresh full retention window from the explicit enable action.
    $deadlineTimestamp = strtotime($now . ' +' . gallery_trash_normalize_retention_days($retentionDays) . ' days');
    if ($deadlineTimestamp === false) {
        throw new RuntimeException('Could not calculate the gallery trash retention deadline.');
    }
    $deadline = date('Y-m-d H:i:s', $deadlineTimestamp);
    // $stmt updates only entries that could later return to the normal TRASHED state.
    $stmt = db()->prepare(
        "UPDATE gallery_trash_entries
            SET purge_after = ?, updated_at = ?
          WHERE status IN ('trashed','preparing','restoring')"
    );
    $stmt->execute([$deadline, $now]);
}

/**
 * Return the project root directory that owns the trash store.
 *
 * @return string Absolute project root path.
 */
function gallery_trash_project_root(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Refuse any managed trash root that overlaps the configured live gallery root.
 *
 * The trash architecture relies on the two trees being physically disjoint. A
 * custom configuration that nests either root inside the other would make public
 * discovery, HTTP protection, or guarded cleanup operate on the wrong ownership
 * boundary, so the feature fails closed instead of trying to compensate.
 *
 * @param string $storageRoot Existing absolute trash storage root.
 */
function gallery_trash_assert_storage_root_disjoint(string $storageRoot): void
{
    $galleryRoot = galleries_root();
    if (!is_dir($storageRoot) || !is_dir($galleryRoot)) {
        throw new RuntimeException('Could not verify that gallery trash storage is outside the live gallery root.');
    }
    if (path_inside($galleryRoot, $storageRoot) || path_inside($storageRoot, $galleryRoot)) {
        throw new RuntimeException('Gallery trash storage must not overlap the configured live gallery root.');
    }
}

/**
 * Return the absolute trash store directory, creating it when possible.
 *
 * @return string Absolute trash store path.
 */
function gallery_trash_root(): string
{
    // $directory stores recoverable user data outside galleries_root() and outside disposable cache.
    $directory = gallery_trash_project_root() . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . GALLERY_TRASH_DATA_DIRECTORY;
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the gallery trash storage directory.');
    }
    gallery_trash_assert_storage_root_disjoint($directory);

    // Keep runtime-created stores protected even before an application update reconciles policy files.
    // The identity header lets future updater policy reconciliation recognize the file as application-owned.
    $policyPath = $directory . DIRECTORY_SEPARATOR . '.htaccess';
    $policyContents = "# Project: PHP Gallery\n# File: data/gallery-trash/.htaccess\nRequire all denied\n";
    if (!is_file($policyPath) && @file_put_contents($policyPath, $policyContents) === false) {
        throw new RuntimeException('Could not create the gallery trash HTTP protection policy.');
    }
    return $directory;
}

/**
 * Return the project-relative storage path recorded for one trash entry.
 *
 * @param string $trashToken Trash entry token.
 * @return string Project-relative trash entry path.
 */
function gallery_trash_relative_path(string $trashToken): string
{
    gallery_trash_assert_token($trashToken);
    return 'data/' . GALLERY_TRASH_DATA_DIRECTORY . '/' . $trashToken;
}

/**
 * Return the absolute directory that holds one trash entry.
 *
 * @param string $trashToken Trash entry token.
 * @return string Absolute trash entry directory.
 */
function gallery_trash_entry_dir(string $trashToken): string
{
    gallery_trash_assert_token($trashToken);

    // Check both existing locations without forcing creation of the new root. This keeps
    // a legacy cache-backed entry readable even if the persistent data root is temporarily
    // unavailable during an upgrade.
    $projectRoot = gallery_trash_project_root();
    $persistent = $projectRoot . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . GALLERY_TRASH_DATA_DIRECTORY . DIRECTORY_SEPARATOR . $trashToken;
    if (is_dir($persistent)) {
        gallery_trash_assert_storage_root_disjoint(dirname($persistent));
        return $persistent;
    }

    // The first development implementation stored payloads under cache/gallery-trash.
    // Keep those already-created entries recoverable after the storage-root correction.
    $legacyRoot = gallery_trash_legacy_root();
    $legacy = $legacyRoot . DIRECTORY_SEPARATOR . $trashToken;
    if (is_dir($legacy)) {
        gallery_trash_assert_storage_root_disjoint($legacyRoot);
        return $legacy;
    }

    return gallery_trash_root() . DIRECTORY_SEPARATOR . $trashToken;
}

/**
 * Return the legacy development trash root without creating it.
 *
 * @return string Absolute legacy cache-backed trash root.
 */
function gallery_trash_legacy_root(): string
{
    return gallery_trash_project_root() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . GALLERY_TRASH_LEGACY_CACHE_DIRECTORY;
}

/**
 * Return the allowed deletion root that owns one existing trash entry directory.
 *
 * New entries live under persistent data/. Existing development entries may still
 * live under cache/. The returned root is used only for guarded deletion/move of
 * the isolated trash copy and is never inferred from database input.
 *
 * @param string $trashToken Trash entry token.
 * @return string Absolute managed root that owns the entry directory.
 */
function gallery_trash_entry_storage_root(string $trashToken): string
{
    $directory = gallery_trash_entry_dir($trashToken);

    // Resolve the legacy root first so compatibility operations do not depend on the
    // corrected persistent root being writable merely to remove an old isolated payload.
    $legacyRoot = gallery_trash_legacy_root();
    if (is_dir($legacyRoot) && is_dir($directory) && path_inside($legacyRoot, $directory)) {
        return $legacyRoot;
    }

    $persistentRoot = gallery_trash_root();
    if (is_dir($directory) && path_inside($persistentRoot, $directory)) {
        return $persistentRoot;
    }

    // A not-yet-created new entry always belongs to persistent storage.
    if (!file_exists($directory) && str_starts_with($directory, rtrim($persistentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return $persistentRoot;
    }
    throw new RuntimeException('Trash entry storage path is outside the managed trash roots.');
}

/**
 * Return the absolute payload directory that holds the moved gallery tree.
 *
 * @param string $trashToken Trash entry token.
 * @param string $folderPath Original gallery folder path.
 * @return string Absolute payload path for the moved gallery folder.
 */
function gallery_trash_payload_path(string $trashToken, string $folderPath): string
{
    // $relative stores the original gallery path rebuilt with platform separators.
    $relative = str_replace('/', DIRECTORY_SEPARATOR, normalize_relative_path($folderPath));
    return gallery_trash_entry_dir($trashToken) . DIRECTORY_SEPARATOR . 'payload' . DIRECTORY_SEPARATOR . $relative;
}

/**
 * Build the absolute gallery path for a folder that may not exist yet.
 *
 * gallery_abs_path() and gallery_target_abs_path() both require an existing
 * directory or an existing parent. A restore has to name its destination before
 * the ancestor chain is recreated, so this helper performs the textual traversal
 * guard only. Callers still verify containment with
 * gallery_filesystem_path_inside_root() once the parent exists.
 *
 * @param string $folderPath Gallery folder path relative to the gallery root.
 * @return string Absolute gallery folder path.
 */
function gallery_trash_gallery_path(string $folderPath): string
{
    // $folderPath stores the normalized relative gallery path.
    $folderPath = normalize_relative_path($folderPath);
    if ($folderPath === '' || $folderPath === '.') {
        throw new RuntimeException('Invalid gallery folder path.');
    }
    return galleries_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $folderPath);
}

/**
 * Reject a trash token that is not a plain hexadecimal identifier.
 *
 * Tokens become directory names, so they are validated before any filesystem
 * expression is built from them.
 *
 * @param string $trashToken Trash entry token.
 */
function gallery_trash_assert_token(string $trashToken): void
{
    if (preg_match('/^[a-f0-9]{32}$/', $trashToken) !== 1) {
        throw new RuntimeException('Invalid trash entry token.');
    }
}

/**
 * Return whether the trash workflow is both enabled and schema-ready.
 *
 * @return bool True when a delete action may safely use the trash bin.
 */
function gallery_trash_available(): bool
{
    return gallery_trash_enabled() && schema_inspection_is_available(gallery_trash_schema_status());
}

/**
 * Refuse a trash mutation when the required storage cannot be verified.
 *
 * @param string $operation Stable operation identifier used by refusal logs.
 */
function gallery_trash_assert_available(string $operation): void
{
    mutation_schema_assert_available(
        gallery_trash_schema_status(),
        $operation,
        'The gallery trash bin requires the current database schema. Run pending migrations first.',
        'The gallery trash bin is temporarily unavailable because the required database schema could not be verified.'
    );
}

/**
 * Move a directory tree, falling back to staged copy and delete across filesystems.
 *
 * A same-filesystem rename is the normal path and keeps the operation cheap even
 * for very large galleries. The fallback copies into a temporary sibling,
 * verifies file count and byte size, atomically renames that staged copy into its
 * final destination, and only then removes the source. Symbolic links are refused
 * by the copy fallback so it can never dereference content outside the managed
 * source tree.
 *
 * @param string $source Existing absolute source directory.
 * @param string $destination Absolute destination directory that must not exist.
 * @param string $deleteAllowedRoot Allowed root for the fallback source removal.
 */
function gallery_trash_move_directory(string $source, string $destination, string $deleteAllowedRoot): void
{
    if (!is_dir($source)) {
        throw new RuntimeException('Source folder does not exist.');
    }
    if (file_exists($destination)) {
        throw new RuntimeException('Destination folder already exists.');
    }

    // $parent stores the destination parent directory required by both strategies.
    $parent = dirname($destination);
    if (!is_dir($parent) && !@mkdir($parent, 0775, true) && !is_dir($parent)) {
        throw new RuntimeException('Could not create destination folder.');
    }

    if (@rename($source, $destination)) {
        return;
    }

    // $staged stores an incomplete cross-device copy that is never treated as a valid payload.
    $staged = $destination . '.partial-' . bin2hex(random_bytes(6));
    try {
        gallery_trash_copy_directory($source, $staged);
        if (gallery_trash_directory_signature($source) !== gallery_trash_directory_signature($staged)) {
            throw new RuntimeException('Cross-filesystem gallery copy verification failed.');
        }
        if (!@rename($staged, $destination)) {
            throw new RuntimeException('Could not finalize the copied gallery folder.');
        }
        delete_directory_tree($source, $deleteAllowedRoot);
    } catch (Throwable $exception) {
        if (is_dir($staged)) {
            try {
                delete_directory_tree($staged, $parent);
            } catch (Throwable) {
                // Preserve the original exception; a stale .partial directory is non-authoritative.
            }
        }
        throw $exception;
    }
}

/**
 * Recursively copy a directory tree used by the cross-filesystem move fallback.
 *
 * @param string $source Existing absolute source directory.
 * @param string $destination Absolute destination directory.
 */
function gallery_trash_copy_directory(string $source, string $destination): void
{
    if (!is_dir($destination) && !@mkdir($destination, 0775, true) && !is_dir($destination)) {
        throw new RuntimeException('Could not create folder: ' . basename($destination));
    }

    // $iterator stores a parent-first walk so directories exist before their files.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            throw new RuntimeException('Cross-filesystem trash moves do not follow symbolic links.');
        }
        // $relative stores the entry path relative to the copied root.
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        if ($relative === false || $relative === '') {
            continue;
        }
        // $target stores the matching path inside the destination tree.
        $target = $destination . DIRECTORY_SEPARATOR . $relative;
        if ($entry->isDir()) {
            if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
                throw new RuntimeException('Could not create folder: ' . $relative);
            }
            continue;
        }
        if (!$entry->isFile() || !@copy($entry->getPathname(), $target)) {
            throw new RuntimeException('Could not copy file: ' . $relative);
        }
    }
}

/**
 * Return a deterministic count, size, path, and content signature for copy verification.
 *
 * Cross-filesystem moves are rare and already require a full byte copy. Reading both
 * trees once more for SHA-256 verification is intentionally conservative: equal file
 * counts and lengths alone cannot prove that large photo data was copied intact.
 *
 * @param string $directory Absolute directory to inspect.
 * @return string Compact signature of regular files in the tree.
 */
function gallery_trash_directory_signature(string $directory): string
{
    // $files stores the number of regular files found under the root.
    $files = 0;
    // $bytes stores the total regular-file size found under the root.
    $bytes = 0;
    // $records stores deterministic path/size/content identities before sorting.
    $records = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink()) {
            throw new RuntimeException('Trash copy verification refuses symbolic links.');
        }
        if (!$entry->isFile()) {
            continue;
        }
        $relative = substr($entry->getPathname(), strlen(rtrim($directory, DIRECTORY_SEPARATOR)) + 1);
        if (!is_string($relative) || $relative === '') {
            throw new RuntimeException('Trash copy verification could not resolve a relative file path.');
        }
        $size = max(0, (int) $entry->getSize());
        $hash = @hash_file('sha256', $entry->getPathname());
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Trash copy verification could not hash a copied file.');
        }
        $files++;
        $bytes += $size;
        $records[] = str_replace('\\', '/', $relative) . "\0" . $size . "\0" . $hash;
    }
    sort($records, SORT_STRING);
    return $files . ':' . $bytes . ':' . hash('sha256', implode("\n", $records));
}

/**
 * Return the total byte size of a directory tree as a best-effort value.
 *
 * @param string $directory Absolute directory to measure.
 * @return int Total byte size, or zero when the tree cannot be walked.
 */
function gallery_trash_directory_size(string $directory): int
{
    if (!is_dir($directory)) {
        return 0;
    }

    // $bytes stores the accumulated file size for the whole tree.
    $bytes = 0;
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && !$entry->isLink()) {
                $bytes += max(0, (int) $entry->getSize());
            }
        }
    } catch (Throwable) {
        // A constrained host may refuse enumeration. A zero size only affects display.
        return $bytes;
    }

    return $bytes;
}

/**
 * Return the gallery columns that a restore may re-apply after re-import.
 *
 * Every durable gallery column must be explicitly classified here. Generated
 * identifiers, public-path caches, timestamps, and numeric ownership ids are
 * deliberately omitted because restore rebuilds them from the filesystem.
 *
 * @return array<int,string> Candidate gallery column names.
 */
function gallery_trash_restorable_gallery_columns(): array
{
    return [
        'title',
        'description',
        'slug',
        'sort_order',
        'visibility',
        'access_mode',
        'access_listing',
        'access_password_hash',
        'access_share_token',
        'access_token_hash',
        'access_token_expires_at',
        'voting_enabled',
        'picture_game_enabled',
        'show_filenames',
        'gps_map_enabled',
        'content_language',
        'gallery_date',
        'gallery_date_end',
        'description_layout',
        'count_badge_visibility',
        'lightbox_browsing_mode',
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'background_source',
        'banner_image_path',
        'logo_image_path',
        'separator_image_path',
        'cover_image_path',
        'nsfw_enabled',
    ];
}

/**
 * Return the image columns that a restore may re-apply after a rescan.
 *
 * Filesystem-derived geometry, hashes, MIME type, and EXIF/GPS values are
 * intentionally re-derived by scan_gallery_images() instead of being restored.
 *
 * @return array<int,string> Candidate image column names.
 */
function gallery_trash_restorable_image_columns(): array
{
    return [
        'title',
        'description',
        'sort_order',
        'visibility',
        'editorial_rating',
        'content_language',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'nsfw_enabled',
    ];
}

/**
 * Filter a candidate column list down to columns this database really has.
 *
 * Older installations may miss optional columns from later migrations. A column
 * is used only after a successful inspection confirms it, so an inspection
 * failure omits the optional field instead of guessing.
 *
 * @param string $table Table that owns the columns.
 * @param array<int,string> $columns Candidate column names.
 * @return array<int,string> Confirmed available column names.
 */
function gallery_trash_existing_columns(string $table, array $columns): array
{
    // $available stores only columns whose presence was positively confirmed.
    $available = [];
    foreach ($columns as $column) {
        if (schema_inspection_is_available(schema_inspection_column($table, $column))) {
            $available[] = $column;
        }
    }
    return $available;
}

/**
 * Build one durable gallery snapshot record without generated ids or caches.
 *
 * @param array<string,mixed> $gallery Live gallery row.
 * @return array<string,mixed> Restore-safe gallery metadata.
 */
function gallery_trash_snapshot_gallery_record(array $gallery): array
{
    // $folderPath stores the stable filesystem identity used during restore.
    $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
    // $parentPath stores the stable parent filesystem identity, when nested.
    $parentPath = trim(str_replace('\\', '/', dirname($folderPath)), '.');
    // $record stores only explicitly restorable durable columns.
    $record = [
        'folder_path' => $folderPath,
        'parent_folder_path' => ($parentPath === '' || $parentPath === '/') ? null : $parentPath,
        'tags' => implode(', ', array_column(tags_for_entity('gallery', (int) ($gallery['id'] ?? 0)), 'name')),
        'cover_image_relative_path' => gallery_trash_cover_relative_path($gallery),
    ];
    foreach (gallery_trash_existing_columns('galleries', gallery_trash_restorable_gallery_columns()) as $column) {
        if (array_key_exists($column, $gallery)) {
            $record[$column] = $gallery[$column];
        }
    }

    if (content_localization_schema_ready('gallery')) {
        $translationRows = content_translation_rows('gallery', [(int) ($gallery['id'] ?? 0)]);
        $record['translations'] = $translationRows[(int) ($gallery['id'] ?? 0)] ?? [];
    }

    if (function_exists('Gallery\\Services\\flight_map_schema_ready')
        && function_exists('Gallery\\Services\\gallery_flight_map_row')
        && flight_map_schema_ready()) {
        // $flightMap stores durable route-map metadata; numeric ownership ids are omitted.
        $flightMap = gallery_flight_map_row((int) ($gallery['id'] ?? 0));
        if (is_array($flightMap)) {
            $record['flight_map'] = array_intersect_key($flightMap, array_flip([
                'map_source_type',
                'route_text',
                'resolved_points_json',
                'unresolved_points_json',
                'point_count',
                'resolved_at',
            ]));
        }
    }

    return $record;
}

/**
 * Build one durable image snapshot record without generated ids or file facts.
 *
 * @param array<string,mixed> $image Live image row.
 * @param string $galleryFolderPath Stable owner gallery path.
 * @param array<int,array<string,mixed>> $translations Preloaded translations keyed by image id.
 * @param array<int,array<int,array<string,mixed>>> $tags Preloaded tags keyed by image id.
 * @return array<string,mixed> Restore-safe image metadata.
 */
function gallery_trash_snapshot_image_record(array $image, string $galleryFolderPath, array $translations = [], array $tags = []): array
{
    // $imageId stores the live id used only to load dependent metadata before deletion.
    $imageId = (int) ($image['id'] ?? 0);
    // $record stores path identity and durable user-authored metadata.
    $record = [
        'gallery_folder_path' => $galleryFolderPath,
        'relative_path' => normalize_relative_path((string) ($image['relative_path'] ?? '')),
        'tags' => implode(', ', array_column($tags[$imageId] ?? [], 'name')),
    ];
    foreach (gallery_trash_existing_columns('images', gallery_trash_restorable_image_columns()) as $column) {
        if (array_key_exists($column, $image)) {
            $record[$column] = $image[$column];
        }
    }
    if (content_localization_schema_ready('image')) {
        $record['translations'] = $translations[$imageId] ?? [];
    }
    return $record;
}

/**
 * Build the restore snapshot for one gallery subtree.
 *
 * @param array $rootGallery Root gallery row selected for deletion.
 * @param bool $payloadPresent Whether the physical source folder exists and will travel with the entry.
 * @return array<string,mixed> Snapshot document stored with the trash entry.
 */
function gallery_trash_capture_snapshot(array $rootGallery, bool $payloadPresent = true): array
{
    // $rootPath stores the normalized root folder path of this subtree.
    $rootPath = normalize_relative_path((string) $rootGallery['folder_path']);
    // $galleries stores one snapshot record per gallery, ordered parent-first by path.
    $galleries = [];
    // $images stores one snapshot record per image across the whole subtree.
    $images = [];

    foreach (gallery_subtree_rows((int) $rootGallery['id']) as $row) {
        // $galleryId stores the live gallery id used for dependent metadata lookups.
        $galleryId = (int) $row['id'];
        // $folderPath stores the normalized folder path of this subtree member.
        $folderPath = normalize_relative_path((string) $row['folder_path']);
        $galleries[] = gallery_trash_snapshot_gallery_record($row);

        // $galleryImages stores image rows in a stable order for deterministic snapshots.
        $galleryImages = gallery_trash_image_rows($galleryId);
        // $imageIds stores live ids used only for bulk dependent-metadata capture.
        $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $galleryImages);
        // $translationRows stores all image translations for this gallery in one lookup.
        $translationRows = [];
        if ($galleryImages && content_localization_schema_ready('image')) {
            $translationRows = content_translation_rows('image', $imageIds);
        }
        // $imageTags stores all image tags for this gallery in one lookup.
        $imageTags = $imageIds ? tags_for_entities('image', $imageIds) : [];
        foreach ($galleryImages as $image) {
            $images[] = gallery_trash_snapshot_image_record($image, $folderPath, $translationRows, $imageTags);
        }
    }

    return [
        'version' => GALLERY_TRASH_SNAPSHOT_VERSION,
        'root_folder_path' => $rootPath,
        'payload_present' => $payloadPresent,
        'app_version' => defined('CMS_VERSION') ? CMS_VERSION : '',
        'captured_at' => now_sql(),
        'gallery_count' => count($galleries),
        'image_count' => count($images),
        'galleries' => $galleries,
        'images' => $images,
    ];
}

/**
 * Upgrade a stored snapshot document to the shape expected by current restore code.
 *
 * Version 1 is currently the only format. Keeping the upgrader as an explicit
 * boundary prevents future code from silently assuming that a 25-day-old trash
 * entry was created by the current release.
 *
 * @param array<string,mixed> $snapshot Stored snapshot document.
 * @return array<string,mixed> Current snapshot representation.
 */
function gallery_trash_snapshot_upgrade(array $snapshot): array
{
    // $version stores the declared snapshot format version.
    $version = (int) ($snapshot['version'] ?? 1);
    if ($version !== GALLERY_TRASH_SNAPSHOT_VERSION) {
        throw new RuntimeException('This trash entry uses an unsupported restore snapshot version.');
    }
    if (!isset($snapshot['payload_present'])) {
        // Early development snapshots always represented a real moved payload.
        $snapshot['payload_present'] = true;
    }
    return $snapshot;
}

/**
 * Return the image rows owned by one gallery in a stable order.
 *
 * @param int $galleryId Gallery that owns the images.
 * @return array<int,array<string,mixed>> Image rows.
 */
function gallery_trash_image_rows(int $galleryId): array
{
    // $stmt stores the ordered image lookup for one gallery.
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? ORDER BY sort_order, filename, id');
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll();
}

/**
 * Resolve the relative path of a gallery title picture, when one is set.
 *
 * The restore re-scans images and therefore assigns new image ids, so the cover
 * reference is stored as a path rather than an identifier.
 *
 * @param array $gallery Gallery row.
 * @return ?string Cover image relative path, or null when unset.
 */
function gallery_trash_cover_relative_path(array $gallery): ?string
{
    // $coverImageId stores the configured title picture identifier.
    $coverImageId = (int) ($gallery['cover_image_id'] ?? 0);
    if ($coverImageId <= 0) {
        return null;
    }

    // $stmt stores the single-row lookup for the configured title picture.
    $stmt = db()->prepare('SELECT relative_path FROM images WHERE id = ?');
    $stmt->execute([$coverImageId]);
    // $relativePath stores the stored path of the title picture, when it still exists.
    $relativePath = $stmt->fetchColumn();
    return is_string($relativePath) && $relativePath !== '' ? $relativePath : null;
}

/**
 * Move gallery subtrees into the trash bin instead of deleting them.
 *
 * The filesystem move happens before database deletion so a failed database step
 * can put the folder back. Once the rows are gone the gallery is invisible to
 * every listing, scanner, and public route without any additional filtering.
 *
 * @param array<int,int> $galleryIds Gallery identifiers selected by the administrator.
 * @param array<string,mixed> $options Optional user_id and deleted_from context.
 * @return array{requested_root_count:int,root_count:int,row_count:int,image_count:int,missing_folders:int,failed_root_count:int,entries:array<int,string>,failures:array<int,array<string,mixed>>} Structured result data for the caller.
 */
function move_gallery_subtrees_to_trash(array $galleryIds, array $options = []): array
{
    // $rootIds stores the unique positive gallery ids selected for deletion.
    $rootIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds))));
    if (!$rootIds) {
        return ['requested_root_count' => count($rootIds), 'root_count' => 0, 'row_count' => 0, 'image_count' => 0, 'missing_folders' => 0, 'failed_root_count' => 0, 'entries' => [], 'failures' => []];
    }

    gallery_trash_assert_available('gallery.trash_subtree');
    mutation_schema_assert_available(
        gallery_deletion_schema_status(),
        'gallery.trash_subtree_rows',
        'Gallery deletion requires the current core gallery/image database schema. Run pending migrations first.',
        'Gallery deletion is temporarily unavailable because the required database schema could not be verified.'
    );

    // $keptRoots stores the selected roots after nested selections are collapsed.
    $keptRoots = gallery_trash_collapse_selected_roots($rootIds);
    if (!$keptRoots) {
        return ['requested_root_count' => count($rootIds), 'root_count' => 0, 'row_count' => 0, 'image_count' => 0, 'missing_folders' => 0, 'failed_root_count' => 0, 'entries' => [], 'failures' => []];
    }

    // $userId stores the administrator credited with the deletion, when known.
    $userId = isset($options['user_id']) && (int) $options['user_id'] > 0 ? (int) $options['user_id'] : null;
    // $deletedFrom stores the bounded origin label recorded for auditing.
    $deletedFrom = gallery_trash_normalize_origin((string) ($options['deleted_from'] ?? 'admin'));

    // $entries stores the created trash tokens in processing order.
    $entries = [];
    // $failures stores independently failed roots without rolling back earlier successful roots.
    $failures = [];
    // $rowCount stores how many gallery rows were removed across all roots.
    $rowCount = 0;
    // $imageCount stores how many image rows travelled into the trash snapshot.
    $imageCount = 0;
    // $missingFolders stores metadata-only roots whose folder was already absent on disk.
    $missingFolders = 0;

    foreach ($keptRoots as $rootGallery) {
        // $folderPath stores the normalized root folder path.
        $folderPath = normalize_relative_path((string) $rootGallery['folder_path']);
        // $absolutePath stores the live gallery folder about to leave galleries_root().
        $absolutePath = gallery_trash_gallery_path($folderPath);
        // $subtreeRows stores one stable view of the live subtree used by this operation.
        $subtreeRows = gallery_subtree_rows((int) $rootGallery['id']);
        // $subtreeIds stores every gallery row id inside this root subtree.
        $subtreeIds = array_map(static fn (array $row): int => (int) $row['id'], $subtreeRows);
        // $payloadPresent records whether recoverable files exist for this entry.
        $payloadPresent = is_dir($absolutePath);
        // $trashToken stores the unique identity of this attempted trash entry.
        $trashToken = '';
        // $payloadPath stores the destination after token allocation.
        $payloadPath = '';
        // $payloadMoved tracks whether failure recovery must move files back.
        $payloadMoved = false;
        // $databaseOutcomeUnknown prevents filesystem rollback after an ambiguous PDO commit failure.
        $databaseOutcomeUnknown = false;

        try {
            if ($payloadPresent) {
                if (!path_inside(galleries_root(), $absolutePath)) {
                    throw new RuntimeException('Refusing to trash a gallery path outside the gallery root.');
                }
                // Refresh every sidecar first so the moved folder carries current metadata.
                foreach ($subtreeRows as $row) {
                    write_gallery_sidecar($row);
                }
            } else {
                $missingFolders++;
            }

            // $snapshot stores the restore document captured while every live row still exists.
            $snapshot = gallery_trash_capture_snapshot($rootGallery, $payloadPresent);
            // $byteSize stores the measured payload size shown in the trash listing.
            $byteSize = $payloadPresent ? gallery_trash_directory_size($absolutePath) : 0;
            $trashToken = bin2hex(random_bytes(16));
            $payloadPath = gallery_trash_payload_path($trashToken, $folderPath);

            // Persist PREPARING before the first filesystem move so a fatal timeout leaves a recoverable state marker.
            $entryTiming = gallery_trash_insert_preparing_entry($trashToken, $rootGallery, $snapshot, $byteSize, $userId, $deletedFrom);

            if ($payloadPresent) {
                gallery_trash_move_directory($absolutePath, $payloadPath, galleries_root());
                $payloadMoved = true;
            }
            gallery_trash_write_manifest($trashToken, $snapshot, $entryTiming, $byteSize);

            // Live-row removal and PREPARING -> TRASHED become one atomic database commit.
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $deletedRows = $subtreeIds ? gallery_delete_database_subtree_rows_in_transaction($subtreeIds) : 0;
                gallery_trash_transition_status_in_transaction($trashToken, 'preparing', 'trashed');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            try {
                $pdo->commit();
            } catch (Throwable $exception) {
                // A transport failure during COMMIT can leave the client unable to prove whether
                // the server committed or rolled back. Only a confirmed rollback permits the
                // outer handler to move files back into the live gallery tree.
                $rollbackConfirmed = false;
                if ($pdo->inTransaction()) {
                    try {
                        $pdo->rollBack();
                        $rollbackConfirmed = true;
                    } catch (Throwable) {
                        $rollbackConfirmed = false;
                    }
                }
                $databaseOutcomeUnknown = !$rollbackConfirmed;
                throw $exception;
            }

            $rowCount += $deletedRows;
            $imageCount += (int) ($snapshot['image_count'] ?? 0);
            $entries[] = $trashToken;
        } catch (Throwable $exception) {
            if ($databaseOutcomeUnknown) {
                // Preserve PREPARING/TRASHED plus the isolated payload exactly as they stand.
                // Reconciliation can inspect live rows and both filesystem locations later;
                // moving files now could resurrect a folder whose DB deletion actually committed.
                $failures[] = [
                    'gallery_id' => (int) ($rootGallery['id'] ?? 0),
                    'error' => $exception->getMessage(),
                ];
                continue;
            }

            // A cross-device fallback can fail while removing the source after a verified destination
            // already exists. Detect that state explicitly so we never delete the only complete copy.
            if (!$payloadMoved && $payloadPath !== '' && is_dir($payloadPath)) {
                $payloadMoved = true;
            }
            // A rolled-back database transaction leaves the live rows intact. Restore the payload before
            // removing PREPARING; if the move-back itself fails, keep PREPARING for maintenance reconciliation.
            $recovered = !$payloadMoved;
            if ($payloadMoved && $trashToken !== '' && $payloadPath !== '' && is_dir($payloadPath) && !is_dir($absolutePath)) {
                try {
                    gallery_trash_move_directory($payloadPath, $absolutePath, gallery_trash_root());
                    $recovered = true;
                } catch (Throwable) {
                    $recovered = false;
                }
            }
            if ($trashToken !== '' && $recovered) {
                gallery_trash_delete_preparing_entry($trashToken);
                try {
                    gallery_trash_remove_entry_directory($trashToken);
                } catch (Throwable) {
                    // An abandoned manifest directory is non-authoritative once PREPARING is removed.
                }
            }

            $failures[] = [
                'gallery_id' => (int) ($rootGallery['id'] ?? 0),
                'error' => $exception->getMessage(),
            ];
        }
    }

    if ($entries) {
        // The trash mutations above are already durably committed. A cache/public-path refresh
        // failure must not make the caller retry an operation that has in fact succeeded.
        try {
            gallery_trash_refresh_after_structure_change();
        } catch (Throwable $exception) {
            admin_log_event('warning', 'gallery.trash_post_commit_refresh_failed', 'Gallery trash post-commit refresh failed.', [
                'trashed_roots' => count($entries),
                'error' => substr($exception->getMessage(), 0, 500),
            ]);
        }
    }

    if (!$entries && $failures) {
        throw new RuntimeException((string) ($failures[0]['error'] ?? 'No selected gallery could be moved to the trash.'));
    }

    return [
        'requested_root_count' => count($rootIds),
        'root_count' => count($entries),
        'row_count' => $rowCount,
        'image_count' => $imageCount,
        'missing_folders' => $missingFolders,
        'failed_root_count' => count($failures),
        'entries' => $entries,
        'failures' => $failures,
    ];
}

/**
 * Collapse a selection so a nested gallery is not trashed twice.
 *
 * @param array<int,int> $rootIds Selected gallery identifiers.
 * @return array<int,array<string,mixed>> Independent root gallery rows, shallowest first.
 */
function gallery_trash_collapse_selected_roots(array $rootIds): array
{
    // $roots stores the resolved gallery rows for the selection.
    $roots = [];
    foreach ($rootIds as $galleryId) {
        // $gallery stores one freshly loaded gallery row.
        $gallery = find_gallery($galleryId, true);
        if ($gallery) {
            $roots[] = $gallery;
        }
    }
    if (!$roots) {
        return [];
    }

    usort($roots, static fn (array $left, array $right): int => strlen((string) $left['folder_path']) <=> strlen((string) $right['folder_path']));

    // $keptRoots stores roots that are not already covered by a shallower selection.
    $keptRoots = [];
    foreach ($roots as $gallery) {
        // $folderPath stores the normalized path of the candidate root.
        $folderPath = normalize_relative_path((string) $gallery['folder_path']);
        // $isCovered stores whether an earlier kept root already contains this path.
        $isCovered = false;
        foreach ($keptRoots as $keptRoot) {
            // $keptPath stores the normalized path of an already accepted root.
            $keptPath = normalize_relative_path((string) $keptRoot['folder_path']);
            if ($folderPath === $keptPath || str_starts_with($folderPath, $keptPath . '/')) {
                $isCovered = true;
                break;
            }
        }
        if (!$isCovered) {
            $keptRoots[] = $gallery;
        }
    }

    return $keptRoots;
}

/**
 * Normalize the bounded origin label stored with a trash entry.
 *
 * @param string $origin Requested origin label.
 * @return string Safe stored origin label.
 */
function gallery_trash_normalize_origin(string $origin): string
{
    return in_array($origin, ['dashboard_bulk', 'public_inline', 'admin', 'other'], true) ? $origin : 'other';
}

/**
 * Write a sanitized human-readable recovery manifest beside the moved payload.
 *
 * The full database snapshot is intentionally not copied here because it can
 * contain password hashes and persistent access credentials. The manifest is a
 * filesystem recovery aid only; gallery_trash_entries remains authoritative.
 *
 * @param string $trashToken Trash entry token.
 * @param array<string,mixed> $snapshot Restore snapshot document.
 * @param array{deleted_at:string,purge_after:string} $timing Entry retention timestamps.
 * @param int $byteSize Measured payload size in bytes.
 */
function gallery_trash_write_manifest(string $trashToken, array $snapshot, array $timing, int $byteSize): void
{
    // $directory stores the entry root that owns the manifest.
    $directory = gallery_trash_entry_dir($trashToken);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create the trash entry directory.');
    }
    // $manifest stores only non-secret recovery metadata.
    $manifest = [
        'version' => (int) ($snapshot['version'] ?? GALLERY_TRASH_SNAPSHOT_VERSION),
        'trash_token' => $trashToken,
        'original_folder_path' => (string) ($snapshot['root_folder_path'] ?? ''),
        'payload_present' => !empty($snapshot['payload_present']),
        'deleted_at' => (string) $timing['deleted_at'],
        'purge_after' => (string) $timing['purge_after'],
        'gallery_count' => (int) ($snapshot['gallery_count'] ?? 0),
        'image_count' => (int) ($snapshot['image_count'] ?? 0),
        'byte_size' => max(0, $byteSize),
        'app_version' => (string) ($snapshot['app_version'] ?? ''),
    ];
    // $encoded stores the serialized sanitized document.
    $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || @file_put_contents($directory . DIRECTORY_SEPARATOR . 'manifest.json', $encoded . "\n") === false) {
        throw new RuntimeException('Could not write the gallery trash recovery manifest.');
    }
}

/**
 * Insert the durable PREPARING row before a gallery leaves the live filesystem.
 *
 * @param string $trashToken Trash entry token.
 * @param array<string,mixed> $rootGallery Root gallery row captured before deletion.
 * @param array<string,mixed> $snapshot Restore snapshot document.
 * @param int $byteSize Measured payload size in bytes.
 * @param ?int $userId Administrator credited with the deletion.
 * @param string $deletedFrom Bounded origin label.
 * @return array{deleted_at:string,purge_after:string} Shared timestamps used by the manifest.
 */
function gallery_trash_insert_preparing_entry(string $trashToken, array $rootGallery, array $snapshot, int $byteSize, ?int $userId, string $deletedFrom): array
{
    // $folderPath stores the normalized original root folder path.
    $folderPath = normalize_relative_path((string) $rootGallery['folder_path']);
    // $parentPath stores the original parent folder path, when this root was nested.
    $parentPath = trim(str_replace('\\', '/', dirname($folderPath)), '.');
    // $deletedAt stores the shared timestamp used for retention math.
    $deletedAt = now_sql();
    // $purgeAfter stores the moment scheduled maintenance may destroy this entry.
    $purgeAfterTimestamp = strtotime($deletedAt . ' +' . gallery_trash_retention_days() . ' days');
    if ($purgeAfterTimestamp === false) {
        throw new RuntimeException('Could not calculate the gallery trash retention deadline.');
    }
    $purgeAfter = date('Y-m-d H:i:s', $purgeAfterTimestamp);
    // $snapshotJson stores the authoritative restore document.
    $snapshotJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($snapshotJson)) {
        throw new RuntimeException('Could not encode the gallery restore snapshot.');
    }

    // $stmt stores the PREPARING insert that makes subsequent filesystem work crash-recoverable.
    $stmt = db()->prepare(
        'INSERT INTO gallery_trash_entries
            (trash_token, status, original_folder_path, original_parent_folder_path,
             title, subtree_gallery_count, image_count, byte_size, snapshot_version, snapshot_json, trash_relative_path,
             deleted_by_user_id, deleted_from, deleted_at, purge_after, operation_started_at, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $trashToken,
        'preparing',
        $folderPath,
        ($parentPath === '' || $parentPath === '/') ? null : $parentPath,
        (string) ($rootGallery['title'] ?? basename($folderPath)),
        (int) ($snapshot['gallery_count'] ?? 0),
        (int) ($snapshot['image_count'] ?? 0),
        max(0, $byteSize),
        (int) ($snapshot['version'] ?? GALLERY_TRASH_SNAPSHOT_VERSION),
        $snapshotJson,
        gallery_trash_relative_path($trashToken),
        $userId,
        $deletedFrom,
        $deletedAt,
        $purgeAfter,
        $deletedAt,
        $deletedAt,
        $deletedAt,
    ]);

    return ['deleted_at' => $deletedAt, 'purge_after' => $purgeAfter];
}

/**
 * Delete an abandoned PREPARING row after the live filesystem has been restored.
 *
 * @param string $trashToken Trash entry token.
 */
function gallery_trash_delete_preparing_entry(string $trashToken): void
{
    gallery_trash_assert_token($trashToken);
    $stmt = db()->prepare("DELETE FROM gallery_trash_entries WHERE trash_token = ? AND status = 'preparing'");
    $stmt->execute([$trashToken]);
}

/**
 * Transition one trash entry inside the caller's active database transaction.
 *
 * @param string $trashToken Trash entry token.
 * @param string $fromStatus Required current lifecycle state.
 * @param string $toStatus New lifecycle state.
 * @param bool $clearSnapshot Whether finalized restore data should be released.
 * @param ?string $errorCode Optional bounded reconciliation/error code.
 */
function gallery_trash_transition_status_in_transaction(string $trashToken, string $fromStatus, string $toStatus, bool $clearSnapshot = false, ?string $errorCode = null): void
{
    gallery_trash_assert_token($trashToken);
    if (!db()->inTransaction()) {
        throw new RuntimeException('Trash lifecycle transition requires an active database transaction.');
    }
    // $timestamp stores the shared transition time.
    $timestamp = now_sql();
    // $stmt stores a compare-and-swap transition so concurrent mutations cannot steal the entry.
    $stmt = db()->prepare(
        'UPDATE gallery_trash_entries
            SET status = ?,
                operation_started_at = NULL,
                restored_at = CASE WHEN ? = \'restored\' THEN ? ELSE restored_at END,
                purged_at = CASE WHEN ? = \'purged\' THEN ? ELSE purged_at END,
                snapshot_json = CASE WHEN ? = 1 THEN NULL ELSE snapshot_json END,
                last_error_code = ?,
                updated_at = ?
          WHERE trash_token = ? AND status = ?'
    );
    $stmt->execute([
        $toStatus,
        $toStatus,
        $timestamp,
        $toStatus,
        $timestamp,
        $clearSnapshot ? 1 : 0,
        $errorCode,
        $timestamp,
        $trashToken,
        $fromStatus,
    ]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('The trash entry lifecycle changed concurrently.');
    }
}

/**
 * Atomically claim one recoverable/problem entry for restore or purge.
 *
 * @param string $trashToken Trash entry token.
 * @param string $targetStatus Transitional status, restoring or purging.
 * @param string $fromStatus Required source state, normally trashed; purge may explicitly claim broken.
 * @return bool True when this caller acquired ownership.
 */
function gallery_trash_claim_entry(string $trashToken, string $targetStatus, string $fromStatus = 'trashed'): bool
{
    gallery_trash_assert_token($trashToken);
    if (!in_array($targetStatus, ['restoring', 'purging'], true)
        || !in_array($fromStatus, ['trashed', 'broken'], true)) {
        throw new RuntimeException('Invalid trash claim status.');
    }
    // $timestamp stores when reconciliation may begin aging this operation.
    $timestamp = now_sql();
    $stmt = db()->prepare(
        "UPDATE gallery_trash_entries
            SET status = ?, operation_started_at = ?, last_error_code = NULL, updated_at = ?
          WHERE trash_token = ? AND status = ?"
    );
    $stmt->execute([$targetStatus, $timestamp, $timestamp, $trashToken, $fromStatus]);
    return $stmt->rowCount() === 1;
}

/**
 * Release a failed restore/purge claim back to the recoverable TRASHED state.
 *
 * @param string $trashToken Trash entry token.
 * @param string $claimedStatus Transitional status currently owned by the caller.
 * @param ?string $errorCode Optional bounded diagnostic code.
 * @param string $returnStatus Recoverable/problem state restored after a failed claim.
 * @return bool True when the claim was released.
 */
function gallery_trash_release_claim(string $trashToken, string $claimedStatus, ?string $errorCode = null, string $returnStatus = 'trashed'): bool
{
    gallery_trash_assert_token($trashToken);
    if (!in_array($claimedStatus, ['restoring', 'purging'], true)
        || !in_array($returnStatus, ['trashed', 'broken'], true)) {
        return false;
    }
    $stmt = db()->prepare(
        "UPDATE gallery_trash_entries
            SET status = ?, operation_started_at = NULL, last_error_code = ?, updated_at = ?
          WHERE trash_token = ? AND status = ?"
    );
    $stmt->execute([$returnStatus, $errorCode, now_sql(), $trashToken, $claimedStatus]);
    return $stmt->rowCount() === 1;
}

/**
 * Mark one transitional entry broken without discarding its restore snapshot.
 *
 * @param string $trashToken Trash entry token.
 * @param string $fromStatus Required current status.
 * @param string $errorCode Bounded diagnostic category.
 */
function gallery_trash_mark_broken(string $trashToken, string $fromStatus, string $errorCode): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        gallery_trash_transition_status_in_transaction($trashToken, $fromStatus, 'broken', false, substr($errorCode, 0, 64));
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Refresh shared caches and hierarchy after the gallery tree changed.
 */
function gallery_trash_refresh_after_structure_change(): void
{
    thumbnail_maintenance_summary_cache_clear();
    sync_gallery_parent_ids();
    if (public_path_schema_ready()) {
        refresh_gallery_public_paths();
    }
}

/**
 * Restore one trashed gallery subtree back to its original location.
 *
 * Restore first claims TRASHED -> RESTORING so scheduled purge and duplicate
 * clicks cannot mutate the same token concurrently. If a normal exception
 * occurs, recreated live rows are removed and the payload is moved back before
 * the claim returns to TRASHED. A fatal timeout leaves RESTORING for bounded
 * maintenance reconciliation instead of guessing destructively.
 *
 * @param string $trashToken Trash entry token.
 * @param array<string,mixed> $options Reserved for future restore overrides.
 * @return array{gallery_count:int,image_count:int,root_gallery_id:int,folder_path:string,metadata_only:bool,ancestor_shells_created:int} Structured result data for the caller.
 */
function restore_gallery_trash_entry(string $trashToken, array $options = []): array
{
    gallery_trash_assert_available('gallery.trash_restore');
    mutation_schema_assert_available(
        gallery_move_schema_status(),
        'gallery.trash_restore_rows',
        'Restoring a gallery requires the current gallery/image ownership schema. Run pending migrations first.',
        'Restoring a gallery is temporarily unavailable because the required database schema could not be verified.'
    );
    mutation_schema_assert_available(
        gallery_deletion_schema_status(),
        'gallery.trash_restore_cleanup',
        'Restoring a gallery requires the current deletion dependency schema. Run pending migrations first.',
        'Restoring a gallery is temporarily unavailable because rollback safety could not be verified.'
    );

    // $entry stores the trash row selected for restoration.
    $entry = gallery_trash_entry($trashToken);
    if (!$entry) {
        throw new RuntimeException('Trash entry was not found.');
    }
    if ((string) $entry['status'] !== 'trashed' || !gallery_trash_claim_entry($trashToken, 'restoring')) {
        throw new RuntimeException('This trash entry is already being changed or is no longer restorable.');
    }

    // $folderPath stores the original root folder path being reclaimed.
    $folderPath = normalize_relative_path((string) $entry['original_folder_path']);
    // $payloadPath stores the moved gallery folder inside the trash store.
    $payloadPath = gallery_trash_payload_path($trashToken, $folderPath);
    // $targetPath stores the absolute destination inside galleries_root().
    $targetPath = gallery_trash_gallery_path($folderPath);
    // $payloadMoved tracks whether normal exception recovery must move files back.
    $payloadMoved = false;
    // $metadataOnlyTargetCreated tracks a synthetic target created for a missing-folder trash item.
    $metadataOnlyTargetCreated = false;
    // $createdGalleryIds tracks only rows created by this restore so rollback never deletes unrelated live rows.
    $createdGalleryIds = [];
    // $ancestorShellIds tracks parent rows created by ensure_gallery_ancestors_for_path().
    $ancestorShellIds = [];
    // $createdParentDirectories tracks filesystem ancestors created only for this restore.
    $createdParentDirectories = [];
    // $finalizeOutcomeUnknown prevents destructive compensation after an ambiguous final COMMIT.
    $finalizeOutcomeUnknown = false;

    try {
        // $snapshot stores the decoded and version-normalized restore document.
        $snapshot = json_decode((string) ($entry['snapshot_json'] ?? ''), true);
        if (!is_array($snapshot) || !is_array($snapshot['galleries'] ?? null)) {
            gallery_trash_mark_broken($trashToken, 'restoring', 'snapshot_unreadable');
            throw new RuntimeException('The restore snapshot for this entry is unreadable.');
        }
        $snapshot = gallery_trash_snapshot_upgrade($snapshot);
        // $payloadPresent stores whether the entry originally contained physical media.
        $payloadPresent = !empty($snapshot['payload_present']);
        if ($payloadPresent && !is_dir($payloadPath)) {
            gallery_trash_mark_broken($trashToken, 'restoring', 'payload_missing');
            throw new RuntimeException('The trashed files for this entry are missing.');
        }

        // $collision stores the first blocking reason found before anything is moved.
        $collision = gallery_trash_restore_collision($trashToken, $folderPath, $snapshot);
        if ($collision !== null) {
            gallery_trash_release_claim($trashToken, 'restoring', 'restore_collision');
            throw new RuntimeException($collision);
        }

        // $parentDirectory stores the ancestor chain that must exist before the move.
        $parentDirectory = dirname($targetPath);
        // Record only directories that do not exist yet so a failed restore can remove empty shells safely.
        $directoryCursor = $parentDirectory;
        while ($directoryCursor !== galleries_root() && !is_dir($directoryCursor)) {
            $createdParentDirectories[] = $directoryCursor;
            $nextDirectory = dirname($directoryCursor);
            if ($nextDirectory === $directoryCursor) {
                break;
            }
            $directoryCursor = $nextDirectory;
        }
        if (!is_dir($parentDirectory) && !@mkdir($parentDirectory, 0775, true) && !is_dir($parentDirectory)) {
            throw new RuntimeException('Could not recreate the parent folder for this gallery.');
        }
        if (!gallery_filesystem_path_inside_root($targetPath)) {
            throw new RuntimeException('Refusing to restore a gallery outside the gallery root.');
        }

        if ($payloadPresent) {
            gallery_trash_move_directory($payloadPath, $targetPath, gallery_trash_entry_storage_root($trashToken));
            $payloadMoved = true;
        } else {
            if (!@mkdir($targetPath, 0775, true) && !is_dir($targetPath)) {
                throw new RuntimeException('Could not recreate the metadata-only gallery folder.');
            }
            $metadataOnlyTargetCreated = true;
        }

        // $rootGalleryId stores the recreated root gallery identifier.
        $rootGalleryId = 0;
        // $galleryIdsByPath stores recreated gallery ids keyed by folder path.
        $galleryIdsByPath = [];
        // $galleryRecords stores snapshot gallery records ordered parent-first.
        $galleryRecords = $snapshot['galleries'];
        usort($galleryRecords, static fn (array $left, array $right): int => strlen((string) $left['folder_path']) <=> strlen((string) $right['folder_path']));

        // Recreate only ancestors that are neither live nor represented by another active trash entry.
        $ancestorShellIds = ensure_gallery_ancestors_for_path($folderPath);

        foreach ($galleryRecords as $record) {
            // $recordPath stores the folder path of one restored gallery.
            $recordPath = normalize_relative_path((string) ($record['folder_path'] ?? ''));
            if ($recordPath === '') {
                throw new RuntimeException('Restore snapshot contains an invalid gallery path.');
            }
            // Metadata-only stale rows may need their empty subtree folders reconstructed.
            $recordAbsolutePath = gallery_trash_gallery_path($recordPath);
            if (!$payloadPresent && !is_dir($recordAbsolutePath) && !@mkdir($recordAbsolutePath, 0775, true) && !is_dir($recordAbsolutePath)) {
                throw new RuntimeException('Could not recreate a metadata-only subgallery folder.');
            }
            if (!is_dir($recordAbsolutePath)) {
                throw new RuntimeException('A gallery folder expected by the restore snapshot is missing.');
            }

            // $created stores the gallery row rebuilt from the restored folder.
            if (find_gallery_by_folder_path($recordPath, true)) {
                throw new RuntimeException('A gallery record appeared at ' . $recordPath . ' while the restore was running.');
            }
            $created = create_gallery_row_for_folder($recordPath);
            if (!$created) {
                throw new RuntimeException('Could not recreate a gallery database row during restore.');
            }
            $createdGalleryIds[(int) $created['id']] = (int) $created['id'];
            $galleryIdsByPath[$recordPath] = (int) $created['id'];
            if ($recordPath === $folderPath) {
                $rootGalleryId = (int) $created['id'];
            }
            gallery_trash_apply_gallery_snapshot((int) $created['id'], $record);
        }

        if ($rootGalleryId <= 0 || count($galleryIdsByPath) !== (int) ($snapshot['gallery_count'] ?? count($galleryRecords))) {
            throw new RuntimeException('Gallery restore did not rebuild the complete gallery hierarchy.');
        }

        sync_gallery_parent_ids();
        if (public_path_schema_ready()) {
            refresh_gallery_public_paths();
        }

        // $imageCount stores how many image rows the rescan recreated.
        $imageCount = 0;
        if ($payloadPresent) {
            foreach ($galleryIdsByPath as $galleryId) {
                $imageCount += scan_gallery_images($galleryId);
            }
            gallery_trash_apply_image_snapshot($galleryIdsByPath, is_array($snapshot['images'] ?? null) ? $snapshot['images'] : []);
            gallery_trash_apply_cover_images($galleryIdsByPath, $galleryRecords);
        }

        thumbnail_maintenance_summary_cache_clear();
        foreach ($galleryIdsByPath as $galleryId) {
            // $restored stores the final row used to refresh the on-disk sidecar.
            $restored = find_gallery($galleryId, true);
            if ($restored) {
                write_gallery_sidecar($restored);
            }
        }

        // Finalized rows no longer need the potentially large restore JSON.
        $pdo = db();
        $pdo->beginTransaction();
        try {
            gallery_trash_transition_status_in_transaction($trashToken, 'restoring', 'restored', true);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        try {
            $pdo->commit();
        } catch (Throwable $exception) {
            // As with trash creation, a connection failure during COMMIT can make the
            // server outcome unknowable to this request. Never compensate by deleting
            // the newly restored live tree in that case. If the commit rolled back, the
            // stale RESTORING marker is reconciled later; if it committed, the live tree
            // is already authoritative and the RESTORED row needs no compensation.
            $rollbackConfirmed = false;
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                    $rollbackConfirmed = true;
                } catch (Throwable) {
                    $rollbackConfirmed = false;
                }
            }
            $finalizeOutcomeUnknown = !$rollbackConfirmed;
            throw $exception;
        }
        // The restore is already durably finalized. Failure to remove an empty manifest
        // directory must not turn a successful restore into a false client-side error.
        try {
            gallery_trash_remove_entry_directory($trashToken);
        } catch (Throwable) {
            // A later maintenance/deploy cleanup may remove the non-authoritative leftover.
        }

        return [
            'gallery_count' => count($galleryIdsByPath),
            'image_count' => $imageCount,
            'root_gallery_id' => $rootGalleryId,
            'folder_path' => $folderPath,
            'metadata_only' => !$payloadPresent,
            'ancestor_shells_created' => count($ancestorShellIds),
        ];
    } catch (Throwable $exception) {
        if ($finalizeOutcomeUnknown) {
            // Preserve the live filesystem and recreated DB rows. The final lifecycle COMMIT
            // may already have succeeded on the server, so compensating here could destroy a
            // successful restore. A rolled-back RESTORING state is handled by reconciliation.
            throw $exception;
        }
        // BROKEN is intentionally sticky; reconciliation must not overwrite an explicitly diagnosed state.
        $current = gallery_trash_entry($trashToken);
        $currentStatus = (string) ($current['status'] ?? '');
        if ($currentStatus === 'broken') {
            throw $exception;
        }
        // Preflight failures release RESTORING before throwing. Never treat an already-released
        // collision as a partial restore, otherwise the colliding live gallery could be deleted.
        if ($currentStatus !== 'restoring') {
            throw $exception;
        }

        // A cross-filesystem move can fail after the verified destination has already been
        // finalized but while removing the old trash payload. In that state the move helper
        // deliberately throws, so $payloadMoved is still false even though a live directory
        // now exists. Do not release the claim as TRASHED: that would present an ambiguous,
        // potentially incomplete payload as safely restorable. Preserve both copies and make
        // the problem visible to an administrator instead.
        if ($payloadPresent && !$payloadMoved && is_dir($targetPath)) {
            gallery_trash_mark_broken($trashToken, 'restoring', 'restore_move_ambiguous');
            throw $exception;
        }

        // Remove only rows created by this restore before moving the filesystem payload back.
        // Path-wide deletion would risk destroying an unrelated row created concurrently.
        $cleanupSucceeded = true;
        try {
            $cleanupIds = array_values(array_unique(array_merge(array_values($createdGalleryIds), array_values($ancestorShellIds))));
            if ($cleanupIds) {
                gallery_delete_database_subtree_rows($cleanupIds);
            }
        } catch (Throwable) {
            $cleanupSucceeded = false;
        }

        if ($cleanupSucceeded && $payloadMoved && is_dir($targetPath) && !is_dir($payloadPath)) {
            try {
                gallery_trash_move_directory($targetPath, $payloadPath, galleries_root());
                $payloadMoved = false;
            } catch (Throwable) {
                $cleanupSucceeded = false;
            }
        } elseif ($cleanupSucceeded && $metadataOnlyTargetCreated && is_dir($targetPath)) {
            try {
                delete_directory_tree($targetPath, galleries_root());
                $metadataOnlyTargetCreated = false;
            } catch (Throwable) {
                $cleanupSucceeded = false;
            }
        }

        if ($cleanupSucceeded) {
            // Remove only empty ancestor directories that this restore created. Existing ancestors are never touched.
            foreach ($createdParentDirectories as $createdDirectory) {
                if (is_dir($createdDirectory) && gallery_filesystem_path_inside_root($createdDirectory)) {
                    @rmdir($createdDirectory);
                }
            }
            sync_gallery_parent_ids();
            if (public_path_schema_ready()) {
                refresh_gallery_public_paths();
            }
            gallery_trash_release_claim($trashToken, 'restoring', 'restore_failed');
        }
        // Otherwise leave RESTORING with operation_started_at intact for reconciliation.
        throw $exception;
    }
}

/**
 * Return the first reason a restore cannot safely reclaim its original path.
 *
 * @param string $trashToken Entry being restored, excluded from ancestor checks.
 * @param string $folderPath Original root folder path.
 * @param array<string,mixed> $snapshot Restore snapshot document.
 * @return ?string Safe caller-facing reason, or null when the restore may proceed.
 */
function gallery_trash_restore_collision(string $trashToken, string $folderPath, array $snapshot): ?string
{
    if (is_dir(gallery_trash_gallery_path($folderPath))) {
        return 'A folder already exists at ' . $folderPath . '.';
    }
    if (find_gallery_by_folder_path($folderPath, true)) {
        return 'A gallery record already occupies ' . $folderPath . '.';
    }

    // Restoring a child while its original parent is itself in trash would create an empty shell
    // that later blocks restoring the real parent. Require the ancestor to be restored first.
    $ancestor = gallery_trash_active_ancestor_entry($folderPath, $trashToken);
    if ($ancestor !== null) {
        return 'The original parent gallery "' . (string) ($ancestor['title'] ?? $ancestor['original_folder_path']) . '" is also in the trash. Restore that parent first.';
    }

    foreach ((array) ($snapshot['galleries'] ?? []) as $record) {
        // $recordPath stores one snapshot gallery folder path.
        $recordPath = normalize_relative_path((string) ($record['folder_path'] ?? ''));
        if ($recordPath !== '' && find_gallery_by_folder_path($recordPath, true)) {
            return 'A gallery record already occupies ' . $recordPath . '.';
        }
        // Preserve stable public slugs instead of silently accepting a new unique_slug() value.
        $slug = trim((string) ($record['slug'] ?? ''));
        if ($slug !== '' && schema_inspection_is_available(schema_inspection_column('galleries', 'slug'))) {
            $stmt = db()->prepare('SELECT folder_path FROM galleries WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
            $takenPath = $stmt->fetchColumn();
            if (is_string($takenPath) && $takenPath !== '') {
                return 'The gallery URL slug "' . $slug . '" is already used by ' . $takenPath . '.';
            }
        }
    }

    return null;
}

/**
 * Find an active trashed ancestor of a restore target.
 *
 * @param string $folderPath Target gallery path.
 * @param string $excludeToken Entry currently being restored.
 * @return ?array<string,mixed> Blocking ancestor entry, or null.
 */
function gallery_trash_active_ancestor_entry(string $folderPath, string $excludeToken): ?array
{
    // $segments stores target path components used to build proper ancestors only.
    $segments = explode('/', normalize_relative_path($folderPath));
    array_pop($segments);
    while ($segments) {
        $ancestorPath = implode('/', $segments);
        $stmt = db()->prepare(
            "SELECT trash_token, title, original_folder_path, status
               FROM gallery_trash_entries
              WHERE original_folder_path = ?
                AND trash_token <> ?
                AND status IN ('preparing','trashed','restoring','purging','broken')
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$ancestorPath, $excludeToken]);
        $entry = $stmt->fetch();
        if (is_array($entry)) {
            return $entry;
        }
        array_pop($segments);
    }
    return null;
}

/**
 * Return live gallery ids for one exact folder path and all descendants.
 *
 * @param string $folderPath Root folder path.
 * @return array<int,int> Live gallery ids ordered deepest first.
 */
function gallery_trash_live_row_ids_for_path(string $folderPath): array
{
    $folderPath = normalize_relative_path($folderPath);
    // Use an exact prefix comparison instead of LIKE so '%' and '_' in legitimate
    // folder names can never widen rollback/reconciliation to a sibling gallery.
    $prefix = $folderPath . '/';
    $stmt = db()->prepare(
        'SELECT id FROM galleries
          WHERE folder_path = ? OR LEFT(folder_path, CHAR_LENGTH(?)) = ?
          ORDER BY LENGTH(folder_path) DESC, id DESC'
    );
    $stmt->execute([$folderPath, $prefix, $prefix]);
    return array_values(array_unique(array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), static fn (int $id): bool => $id > 0)));
}

/**
 * Remove database rows created by an incomplete restore without touching files.
 *
 * @param string $folderPath Restored root folder path.
 * @return int Number of gallery rows removed.
 */
function gallery_trash_delete_live_rows_for_path(string $folderPath): int
{
    $ids = gallery_trash_live_row_ids_for_path($folderPath);
    return $ids ? gallery_delete_database_subtree_rows($ids) : 0;
}

/**
 * Return whether one trash entry can be permanently purged without touching live data.
 *
 * Normal TRASHED entries are isolated by definition. BROKEN entries require an
 * additional overlap check because an interrupted restore can leave a live folder
 * or recreated DB rows at the original path. In that case deleting the trash row
 * would hide the only diagnostic marker for a partial restore.
 *
 * @param array<string,mixed> $entry Trash entry row.
 * @return bool True when purge affects only isolated trash storage.
 */
function gallery_trash_entry_can_purge(array $entry): bool
{
    $status = (string) ($entry['status'] ?? '');
    if ($status === 'trashed') {
        return true;
    }
    if ($status !== 'broken') {
        return false;
    }

    try {
        $folderPath = normalize_relative_path((string) ($entry['original_folder_path'] ?? ''));
        if ($folderPath === '') {
            return false;
        }
        if (is_dir(gallery_trash_gallery_path($folderPath))) {
            return false;
        }
        return gallery_trash_live_row_ids_for_path($folderPath) === [];
    } catch (Throwable) {
        return false;
    }
}

/**
 * Re-apply database-only gallery fields after the folder was re-imported.
 *
 * @param int $galleryId Recreated gallery identifier.
 * @param array $record Snapshot gallery record.
 */
function gallery_trash_apply_gallery_snapshot(int $galleryId, array $record): void
{
    // $columns stores the restorable columns this database really provides.
    $columns = gallery_trash_existing_columns('galleries', gallery_trash_restorable_gallery_columns());
    // $assignments stores the SQL SET fragments for confirmed columns.
    $assignments = [];
    // $values stores the bound values matching the assignments.
    $values = [];
    foreach ($columns as $column) {
        if (!array_key_exists($column, $record)) {
            continue;
        }
        $assignments[] = '`' . $column . '` = ?';
        $values[] = $record[$column];
    }

    if ($assignments) {
        $assignments[] = '`updated_at` = ?';
        $values[] = now_sql();
        $values[] = $galleryId;
        db()->prepare('UPDATE galleries SET ' . implode(', ', $assignments) . ' WHERE id = ?')->execute($values);
    }

    if (isset($record['tags']) && is_string($record['tags'])) {
        sync_entity_tags('gallery', $galleryId, $record['tags']);
    }
    if (content_localization_schema_ready('gallery')
        && (array_key_exists('content_language', $record) || array_key_exists('translations', $record))) {
        content_save_localizations('gallery', $galleryId, $record['content_language'] ?? null, $record['translations'] ?? []);
    }
    if (isset($record['flight_map'])
        && is_array($record['flight_map'])
        && function_exists('Gallery\\Services\\gallery_migration_apply_flight_map')) {
        gallery_migration_apply_flight_map($galleryId, ['flight_map' => $record['flight_map']]);
    }
}

/**
 * Re-apply stored image metadata to rows recreated by the rescan.
 *
 * Image identifiers change during a restore because the rescan rebuilds rows
 * from the files on disk. Snapshot records are therefore matched by their
 * gallery folder path and relative image path.
 *
 * @param array<string,int> $galleryIdsByPath Recreated gallery ids keyed by folder path.
 * @param array<int,array<string,mixed>> $images Snapshot image records.
 * @return int Number of image rows updated.
 */
function gallery_trash_apply_image_snapshot(array $galleryIdsByPath, array $images): int
{
    if (!$images) {
        return 0;
    }

    // $columns stores the restorable image columns this database really provides.
    $columns = gallery_trash_existing_columns('images', gallery_trash_restorable_image_columns());
    // $updated stores how many image rows received their stored metadata back.
    $updated = 0;

    foreach ($images as $record) {
        // $galleryPath stores the folder path that owned this image.
        $galleryPath = normalize_relative_path((string) ($record['gallery_folder_path'] ?? ''));
        // $galleryId stores the recreated owner gallery identifier.
        $galleryId = (int) ($galleryIdsByPath[$galleryPath] ?? 0);
        // $relativePath stores the stored image path inside its gallery.
        $relativePath = normalize_relative_path((string) ($record['relative_path'] ?? ''));
        if ($galleryId <= 0 || $relativePath === '') {
            continue;
        }

        // $image stores the rescanned row that now owns this file.
        $image = find_image_by_path($galleryId, $relativePath);
        if (!$image) {
            continue;
        }

        // $assignments stores the SQL SET fragments for confirmed columns.
        $assignments = [];
        // $values stores the bound values matching the assignments.
        $values = [];
        foreach ($columns as $column) {
            if (!array_key_exists($column, $record)) {
                continue;
            }
            $assignments[] = '`' . $column . '` = ?';
            $values[] = $record[$column];
        }
        if ($assignments) {
            $assignments[] = '`updated_at` = ?';
            $values[] = now_sql();
            $values[] = (int) $image['id'];
            db()->prepare('UPDATE images SET ' . implode(', ', $assignments) . ' WHERE id = ?')->execute($values);
            $updated++;
        }

        if (isset($record['tags']) && is_string($record['tags'])) {
            sync_entity_tags('image', (int) $image['id'], $record['tags']);
        }
        if (content_localization_schema_ready('image')
            && (array_key_exists('content_language', $record) || array_key_exists('translations', $record))) {
            content_save_localizations('image', (int) $image['id'], $record['content_language'] ?? null, $record['translations'] ?? []);
        }
    }

    return $updated;
}

/**
 * Re-link gallery title pictures by their stored relative paths.
 *
 * @param array<string,int> $galleryIdsByPath Recreated gallery ids keyed by folder path.
 * @param array<int,array<string,mixed>> $galleryRecords Snapshot gallery records.
 */
function gallery_trash_apply_cover_images(array $galleryIdsByPath, array $galleryRecords): void
{
    foreach ($galleryRecords as $record) {
        // $coverPath stores the stored title picture path, when the gallery had one.
        $coverPath = (string) ($record['cover_image_relative_path'] ?? '');
        // $galleryPath stores the folder path of this snapshot gallery.
        $galleryPath = normalize_relative_path((string) ($record['folder_path'] ?? ''));
        // $galleryId stores the recreated gallery identifier.
        $galleryId = (int) ($galleryIdsByPath[$galleryPath] ?? 0);
        if ($coverPath === '' || $galleryId <= 0) {
            continue;
        }

        // $image stores the rescanned row matching the stored title picture path.
        $image = find_image_by_path($galleryId, normalize_relative_path($coverPath));
        if (!$image) {
            continue;
        }
        db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?')
            ->execute([(int) $image['id'], now_sql(), $galleryId]);
    }
}

/**
 * Permanently destroy one trash entry and its stored payload.
 *
 * @param string $trashToken Trash entry token.
 * @return array{purged:bool,title:string} Structured result data for the caller.
 */
function purge_gallery_trash_entry(string $trashToken): array
{
    gallery_trash_assert_available('gallery.trash_purge');

    // $entry stores the trash row selected for permanent deletion.
    $entry = gallery_trash_entry($trashToken);
    if (!$entry) {
        throw new RuntimeException('Trash entry was not found.');
    }
    if ((string) $entry['status'] === 'purged') {
        return ['purged' => false, 'title' => (string) $entry['title']];
    }
    // Broken entries are not safe to restore automatically, but an administrator may still
    // explicitly discard their isolated trash payload. The purge path never deletes live gallery data.
    $sourceStatus = (string) ($entry['status'] ?? '');
    if ($sourceStatus === 'broken' && !gallery_trash_entry_can_purge($entry)) {
        throw new RuntimeException('This problem trash entry overlaps live gallery data and cannot be purged automatically.');
    }
    if (!in_array($sourceStatus, ['trashed', 'broken'], true)
        || !gallery_trash_claim_entry($trashToken, 'purging', $sourceStatus)) {
        throw new RuntimeException('This trash entry is already being changed or cannot be purged.');
    }

    try {
        gallery_trash_remove_entry_directory($trashToken);
    } catch (Throwable $exception) {
        // Permanent deletion is irreversible after the claim. A recursive filesystem delete
        // may already have removed part of the payload before throwing, so never advertise the
        // entry as safely restorable again. Keep PURGING for bounded maintenance reconciliation.
        db()->prepare("UPDATE gallery_trash_entries SET last_error_code = ?, updated_at = ? WHERE trash_token = ? AND status = 'purging'")
            ->execute(['purge_files_failed', now_sql(), $trashToken]);
        throw $exception;
    }

    // Files are already gone at this point. If this final DB transition fails, keep PURGING so
    // reconciliation completes it instead of falsely presenting the entry as restorable.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        gallery_trash_transition_status_in_transaction($trashToken, 'purging', 'purged', true);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return ['purged' => true, 'title' => (string) $entry['title']];
}

/**
 * Permanently destroy a bounded batch of entries currently sitting in the trash.
 *
 * The caller receives the remaining count so UI code never claims that the trash
 * is empty after only one bounded batch.
 *
 * @param array<string,mixed> $options Optional bounded limit for one invocation.
 * @return array{purged:int,failed:int,remaining:int} Structured result data for the caller.
 */
function empty_gallery_trash(array $options = []): array
{
    gallery_trash_assert_available('gallery.trash_empty');

    // $limit stores how many entries this invocation may destroy.
    $limit = gallery_trash_normalize_purge_batch_size((int) ($options['limit'] ?? gallery_trash_purge_batch_size()));
    // Empty Trash handles only normal recoverable entries. BROKEN rows require explicit
    // per-entry handling because some can overlap a partially restored live gallery.
    $stmt = db()->prepare("SELECT trash_token FROM gallery_trash_entries WHERE status = 'trashed' ORDER BY deleted_at LIMIT " . $limit);
    $stmt->execute();

    // $purged stores how many entries were destroyed.
    $purged = 0;
    // $failed stores entries that could not be destroyed in this invocation.
    $failed = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $token) {
        try {
            if (purge_gallery_trash_entry((string) $token)['purged']) {
                $purged++;
            }
        } catch (Throwable) {
            $failed++;
        }
    }

    // $remainingStmt stores the normal recoverable entries still eligible for a follow-up batch.
    $remainingStmt = db()->query("SELECT COUNT(*) FROM gallery_trash_entries WHERE status = 'trashed'");
    $remaining = $remainingStmt === false ? 0 : (int) $remainingStmt->fetchColumn();
    return ['purged' => $purged, 'failed' => $failed, 'remaining' => $remaining];
}

/**
 * Purge trash entries whose retention window has elapsed.
 *
 * Scheduled maintenance calls this repeatedly, so the work is bounded per
 * invocation and every selected candidate still has to atomically claim PURGING.
 *
 * @param ?int $limit Maximum entries destroyed in this invocation.
 * @return array{purged:int,failed:int,skipped:bool} Structured result data for the caller.
 */
function purge_expired_gallery_trash(?int $limit = null): array
{
    if (!gallery_trash_auto_purge_active()) {
        return ['purged' => 0, 'failed' => 0, 'skipped' => true];
    }
    if (!schema_inspection_is_available(gallery_trash_schema_status())) {
        return ['purged' => 0, 'failed' => 0, 'skipped' => true];
    }

    // $batchSize stores the bounded number of entries this slice may destroy.
    $batchSize = gallery_trash_normalize_purge_batch_size($limit ?? gallery_trash_purge_batch_size());
    // $stmt stores the lookup of entries whose retention window has elapsed.
    $stmt = db()->prepare("SELECT trash_token FROM gallery_trash_entries WHERE status = 'trashed' AND purge_after <= ? ORDER BY purge_after LIMIT " . $batchSize);
    $stmt->execute([now_sql()]);

    // $purged stores how many expired entries were destroyed.
    $purged = 0;
    // $failed stores expired entries that could not be destroyed yet.
    $failed = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $token) {
        try {
            // $purgeResult retains the title long enough to record the irreversible maintenance action.
            $purgeResult = purge_gallery_trash_entry((string) $token);
            if ($purgeResult['purged']) {
                $purged++;
                try {
                    admin_log_event('warning', 'gallery.trash_auto_purged', 'Scheduled maintenance permanently deleted an expired gallery trash entry.', [
                        'trash_token' => (string) $token,
                        'title' => substr((string) ($purgeResult['title'] ?? ''), 0, 255),
                    ]);
                } catch (Throwable) {
                    // The irreversible purge already committed; audit logging failure must not make maintenance retry it.
                }
            }
        } catch (Throwable) {
            $failed++;
        }
    }

    return ['purged' => $purged, 'failed' => $failed, 'skipped' => false];
}

/**
 * Remove the stored directory that belongs to one trash entry.
 *
 * @param string $trashToken Trash entry token.
 */
function gallery_trash_remove_entry_directory(string $trashToken): void
{
    // $directory stores the trash entry directory scheduled for removal.
    $directory = gallery_trash_entry_dir($trashToken);
    if (!is_dir($directory)) {
        return;
    }
    // The allowed root guard keeps deletion inside either the current persistent store
    // or the first development implementation's legacy cache-backed store.
    delete_directory_tree($directory, gallery_trash_entry_storage_root($trashToken));
}

/**
 * Reconcile stale PREPARING, RESTORING, and PURGING entries after fatal timeouts.
 *
 * The state machine deliberately leaves transitional rows behind when a request
 * cannot prove that rollback completed. This bounded worker uses both DB and
 * filesystem state to finish only deterministic cases and marks ambiguous cases
 * BROKEN rather than deleting or overwriting data speculatively.
 *
 * @param int $limit Maximum stale operations inspected in this invocation.
 * @return array{recovered:int,finalized:int,broken:int,failed:int,skipped:bool} Structured maintenance result.
 */
function reconcile_gallery_trash_transitional_entries(int $limit = 10): array
{
    if (!schema_inspection_is_available(gallery_trash_schema_status())) {
        return ['recovered' => 0, 'finalized' => 0, 'broken' => 0, 'failed' => 0, 'skipped' => true];
    }

    // $limit stores the bounded maintenance slice size.
    $limit = max(1, min(100, $limit));
    // $cutoff stores the oldest operation timestamp still considered active.
    $cutoff = date('Y-m-d H:i:s', time() - GALLERY_TRASH_STALE_OPERATION_SECONDS);
    $stmt = db()->prepare(
        "SELECT * FROM gallery_trash_entries
          WHERE status IN ('preparing','restoring','purging')
            AND operation_started_at IS NOT NULL
            AND operation_started_at <= ?
          ORDER BY operation_started_at, id
          LIMIT " . $limit
    );
    $stmt->execute([$cutoff]);

    $result = ['recovered' => 0, 'finalized' => 0, 'broken' => 0, 'failed' => 0, 'skipped' => false];
    foreach ($stmt->fetchAll() as $entry) {
        try {
            $outcome = gallery_trash_reconcile_entry($entry);
            if (isset($result[$outcome])) {
                $result[$outcome]++;
            }
        } catch (Throwable) {
            $result['failed']++;
        }
    }
    return $result;
}

/**
 * Reconcile one stale transitional trash entry.
 *
 * @param array<string,mixed> $entry Stale trash row.
 * @return string Result bucket: recovered, finalized, or broken.
 */
function gallery_trash_reconcile_entry(array $entry): string
{
    // $token stores the validated filesystem/DB identity.
    $token = (string) ($entry['trash_token'] ?? '');
    gallery_trash_assert_token($token);
    // $status stores the transitional lifecycle state being reconciled.
    $status = (string) ($entry['status'] ?? '');
    // $folderPath stores the stable original live path.
    $folderPath = normalize_relative_path((string) ($entry['original_folder_path'] ?? ''));
    // $targetPath stores the live filesystem location.
    $targetPath = gallery_trash_gallery_path($folderPath);
    // $payloadPath stores the trash payload location.
    $payloadPath = gallery_trash_payload_path($token, $folderPath);
    // $snapshot stores enough information to distinguish metadata-only trash entries.
    $snapshot = json_decode((string) ($entry['snapshot_json'] ?? ''), true);
    $payloadExpected = !is_array($snapshot) || !array_key_exists('payload_present', $snapshot) || !empty($snapshot['payload_present']);

    if ($status === 'purging') {
        // Purge intent is irreversible. Once claimed, reconciliation finishes deletion rather than reviving it.
        gallery_trash_remove_entry_directory($token);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            gallery_trash_transition_status_in_transaction($token, 'purging', 'purged', true);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        return 'finalized';
    }

    $liveExists = is_dir($targetPath);
    $payloadExists = is_dir($payloadPath);
    $liveRows = gallery_trash_live_row_ids_for_path($folderPath);

    if ($status === 'preparing') {
        if ($liveExists && !$payloadExists) {
            if ($liveRows) {
                // The move never completed. Live files and live rows are intact, so cancel the abandoned trash operation.
                gallery_trash_delete_preparing_entry($token);
                gallery_trash_remove_entry_directory($token);
                return 'recovered';
            }
            // A live folder without its live rows is not a state the normal protocol can prove safe.
            gallery_trash_mark_broken($token, 'preparing', 'reconcile_preparing_live_without_rows');
            return 'broken';
        }
        if (!$liveExists && $payloadExists) {
            if ($liveRows) {
                // DB delete did not commit; move files back to match the still-live rows.
                gallery_trash_move_directory($payloadPath, $targetPath, gallery_trash_entry_storage_root($token));
                gallery_trash_delete_preparing_entry($token);
                gallery_trash_remove_entry_directory($token);
                return 'recovered';
            }
            // Files are already out of live root and rows are gone. Effective trash succeeded; finalize its state.
            $pdo = db();
            $pdo->beginTransaction();
            try {
                gallery_trash_transition_status_in_transaction($token, 'preparing', 'trashed');
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            return 'finalized';
        }
        if (!$payloadExpected && !$liveExists && !$payloadExists && !$liveRows) {
            // Metadata-only deletion reached the effective trashed state before its status update committed.
            $pdo = db();
            $pdo->beginTransaction();
            try {
                gallery_trash_transition_status_in_transaction($token, 'preparing', 'trashed');
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            return 'finalized';
        }
        if (!$payloadExpected && !$liveExists && !$payloadExists && $liveRows) {
            // No file move was expected and DB deletion rolled back, so cancel the abandoned trash request.
            gallery_trash_delete_preparing_entry($token);
            gallery_trash_remove_entry_directory($token);
            return 'recovered';
        }
        gallery_trash_mark_broken($token, 'preparing', 'reconcile_preparing_ambiguous');
        return 'broken';
    }

    if ($status === 'restoring') {
        if (!$liveExists && $payloadExists) {
            gallery_trash_release_claim($token, 'restoring', 'reconciled_restore_claim');
            return 'recovered';
        }
        if ($liveExists && !$payloadExists) {
            // This may be either a partial restore or a fully rebuilt gallery whose final RESTORED
            // status commit never happened. Reconciliation cannot prove which metadata writes
            // completed, so never delete or move the live copy speculatively. Preserve both the
            // live data and the restore snapshot for explicit administrator recovery.
            gallery_trash_mark_broken($token, 'restoring', 'reconcile_restoring_live_ambiguous');
            return 'broken';
        }
        if (!$payloadExpected && !$liveExists && !$payloadExists) {
            gallery_trash_release_claim($token, 'restoring', 'reconciled_restore_claim');
            return 'recovered';
        }
        gallery_trash_mark_broken($token, 'restoring', 'reconcile_restoring_ambiguous');
        return 'broken';
    }

    throw new RuntimeException('Unsupported transitional trash state.');
}

/**
 * Load one trash entry by its token.
 *
 * @param string $trashToken Trash entry token.
 * @return ?array<string,mixed> Trash entry row, or null when unknown.
 */
function gallery_trash_entry(string $trashToken): ?array
{
    gallery_trash_assert_token($trashToken);

    // $stmt stores the single-row trash entry lookup.
    $stmt = db()->prepare('SELECT * FROM gallery_trash_entries WHERE trash_token = ?');
    $stmt->execute([$trashToken]);
    // $entry stores the resolved trash entry row.
    $entry = $stmt->fetch();
    return $entry ?: null;
}

/**
 * List trash entries for the Admin trash view.
 *
 * @param array<string,mixed> $filters Optional status and limit overrides.
 * @return array<int,array<string,mixed>> Trash entry rows, newest first.
 */
function gallery_trash_entries(array $filters = []): array
{
    if (!schema_inspection_is_available(gallery_trash_schema_status())) {
        return [];
    }

    // $status stores the requested lifecycle status filter.
    $status = (string) ($filters['status'] ?? 'trashed');
    if (!in_array($status, ['trashed', 'restored', 'purged', 'broken', 'active', 'all'], true)) {
        $status = 'trashed';
    }
    // $limit stores the bounded number of rows returned to the view.
    $limit = max(1, min(500, (int) ($filters['limit'] ?? 200)));
    // $select keeps the deleted-admin username optional through the existing nullable FK.
    $select = 'SELECT e.*, u.username AS deleted_by_username FROM gallery_trash_entries e LEFT JOIN users u ON u.id = e.deleted_by_user_id';

    if ($status === 'all') {
        // $stmt stores the unfiltered listing query.
        $stmt = db()->prepare($select . ' ORDER BY e.deleted_at DESC LIMIT ' . $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    if ($status === 'active') {
        // Active includes recoverable rows plus transitional/problem rows that must stay visible to administrators.
        $stmt = db()->prepare($select . " WHERE e.status IN ('trashed','broken','preparing','restoring','purging') ORDER BY e.deleted_at DESC LIMIT " . $limit);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // $stmt stores the status-filtered listing query.
    $stmt = db()->prepare($select . ' WHERE e.status = ? ORDER BY e.deleted_at DESC LIMIT ' . $limit);
    $stmt->execute([$status]);
    return $stmt->fetchAll();
}

/**
 * Return compact trash counters used by dashboard badges and headers.
 *
 * @return array{available:bool,count:int,trashed_count:int,broken_count:int,transitional_count:int,problem_count:int,purgeable_count:int,active_count:int,bytes:int,expired:int,next_purge_at:string} Structured result data for the caller.
 */
function gallery_trash_summary(): array
{
    if (!schema_inspection_is_available(gallery_trash_schema_status())) {
        return [
            'available' => false,
            'count' => 0,
            'trashed_count' => 0,
            'broken_count' => 0,
            'transitional_count' => 0,
            'problem_count' => 0,
            'purgeable_count' => 0,
            'active_count' => 0,
            'bytes' => 0,
            'expired' => 0,
            'next_purge_at' => '',
        ];
    }

    try {
        // $stmt stores one aggregate across visible active states. Retention counters intentionally
        // consider only TRASHED because transitional/BROKEN entries are never auto-purged.
        $stmt = db()->prepare(
            "SELECT
                    SUM(CASE WHEN status = 'trashed' THEN 1 ELSE 0 END) AS trashed_count,
                    SUM(CASE WHEN status = 'broken' THEN 1 ELSE 0 END) AS broken_count,
                    SUM(CASE WHEN status IN ('preparing','restoring','purging') THEN 1 ELSE 0 END) AS transitional_count,
                    SUM(CASE WHEN status IN ('broken','preparing','restoring','purging') THEN 1 ELSE 0 END) AS problem_count,
                    SUM(CASE WHEN status = 'trashed' THEN 1 ELSE 0 END) AS purgeable_count,
                    COUNT(*) AS active_count,
                    COALESCE(SUM(byte_size), 0) AS total_bytes,
                    COALESCE(MIN(CASE WHEN status = 'trashed' THEN purge_after ELSE NULL END), '') AS next_purge_at,
                    SUM(CASE WHEN status = 'trashed' AND purge_after <= ? THEN 1 ELSE 0 END) AS expired_count
               FROM gallery_trash_entries
              WHERE status IN ('trashed','broken','preparing','restoring','purging')"
        );
        $stmt->execute([now_sql()]);
        // $row stores the aggregate result for the trash header.
        $row = $stmt->fetch() ?: [];
    } catch (Throwable) {
        return [
            'available' => false,
            'count' => 0,
            'trashed_count' => 0,
            'broken_count' => 0,
            'transitional_count' => 0,
            'problem_count' => 0,
            'purgeable_count' => 0,
            'active_count' => 0,
            'bytes' => 0,
            'expired' => 0,
            'next_purge_at' => '',
        ];
    }

    // $autoPurgeActive keeps schedule metadata aligned with the actual destructive maintenance guard.
    $autoPurgeActive = gallery_trash_auto_purge_active();
    return [
        'available' => true,
        'count' => (int) ($row['trashed_count'] ?? 0),
        'trashed_count' => (int) ($row['trashed_count'] ?? 0),
        'broken_count' => (int) ($row['broken_count'] ?? 0),
        'transitional_count' => (int) ($row['transitional_count'] ?? 0),
        'problem_count' => (int) ($row['problem_count'] ?? 0),
        'purgeable_count' => (int) ($row['purgeable_count'] ?? 0),
        'active_count' => (int) ($row['active_count'] ?? 0),
        'bytes' => (int) ($row['total_bytes'] ?? 0),
        'expired' => $autoPurgeActive ? (int) ($row['expired_count'] ?? 0) : 0,
        'next_purge_at' => $autoPurgeActive ? (string) ($row['next_purge_at'] ?? '') : '',
    ];
}

/**
 * Return the displayed number of days left before one entry is purged.
 *
 * @param array $entry Trash entry row.
 * @return int Remaining calendar-style days rounded up, never negative.
 */
function gallery_trash_days_remaining(array $entry): int
{
    // $purgeAfter stores the parsed retention deadline.
    $purgeAfter = strtotime((string) ($entry['purge_after'] ?? ''));
    if (!$purgeAfter) {
        return 0;
    }
    return max(0, (int) ceil(($purgeAfter - time()) / 86400));
}
