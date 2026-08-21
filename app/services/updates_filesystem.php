<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_filesystem.php
 * Module Type: Service
 *
 * Purpose:
 *   Handles updater staging filesystem validation, backup/copy cleanup, protected paths, and OPcache invalidation.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;
use const Gallery\Core\CMS_GITHUB_REPOSITORY;
use const Gallery\Core\CMS_UPDATE_BRANCHES;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\e;
use function Gallery\Core\run_migrations;

/**
 * Application update service model.
 *
 * This module owns GitHub version checks, cached update status, release ZIP download,
 * beta install/restore helpers, protected-path rules, filesystem copy logic, and
 * OPcache invalidation for application updates.
 *
 * The functions remain deliberately procedural because the rest of PHP Gallery uses
 * function-based services. Keeping the original public function names avoids route,
 * controller, installer, and admin template changes while allowing the legacy
 * app/services.php file to shrink safely.
 */

/**
 * Create an updater working directory when needed.
 *
 * @param string $path Filesystem path.
 */
function application_update_ensure_dir(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true)) {
        throw new RuntimeException('Could not create update directory: ' . $path);
    }
    if (!is_writable($path)) {
        throw new RuntimeException('Update directory is not writable: ' . $path);
    }
}


/**
 * Return the application project root that contains index.php, app, public, and cache.
 *
 * @return string Text result for the caller.
 */
function application_update_project_root(): string
{
    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = dirname(__DIR__, 2);
    application_update_assert_project_root($root);
    return $root;
}

/**
 * Reject dangerous updater destinations before any files are copied or removed.
 *
 * @param string $root Root value.
 */
function application_update_assert_project_root(string $root): void
{
    // $normalizedRoot stores an intermediate value used by the surrounding gallery workflow.
    $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
    if ($normalizedRoot === '' || basename($normalizedRoot) === 'app') {
        throw new RuntimeException('Updater refused to run because the destination root resolved to the app directory instead of the project root.');
    }

    // $requiredPaths stores an intermediate value used by the surrounding gallery workflow.
    $requiredPaths = [
        'index.php',
        'app/bootstrap.php',
        'app/services/updates.php',
        'public/assets/styles.css',
    ];
    foreach ($requiredPaths as $requiredPath) {
        // $absolutePath stores an intermediate value used by the surrounding gallery workflow.
        $absolutePath = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $requiredPath);
        if (!is_file($absolutePath)) {
            throw new RuntimeException('Updater refused to run because the project root is missing: ' . $requiredPath);
        }
    }

    foreach (['app', 'public', 'cache'] as $requiredDirectory) {
        // $absoluteDirectory stores an intermediate value used by the surrounding gallery workflow.
        $absoluteDirectory = $root . '/' . $requiredDirectory;
        if (!is_dir($absoluteDirectory)) {
            throw new RuntimeException('Updater refused to run because the project root is missing directory: ' . $requiredDirectory);
        }
    }
}

/**
 * Validate that the extracted archive looks like a PHP Gallery repository snapshot.
 *
 * @param string $sourceRoot Source root value.
 */
function application_update_assert_source_root(string $sourceRoot): void
{
    // $requiredPaths stores files that must exist before the active installation is touched.
    $requiredPaths = [
        'index.php',
        'public/index.php',
        'app/bootstrap.php',
        'app/bootstrap/configuration.php',
        'app/bootstrap/dispatch.php',
        'app/bootstrap/maintenance.php',
        'app/bootstrap/request.php',
        'app/bootstrap/routing.php',
        'app/bootstrap/session.php',
        'app/controllers.php',
        'app/controllers/admin_galleries_edit.php',
        'app/controllers/admin_galleries_edit_actions.php',
        'app/controllers/admin_galleries_edit_metadata.php',
        'app/controllers/admin_galleries_edit_page.php',
        'app/controllers/admin_galleries_edit_views.php',
        'app/controllers/admin_theme.php',
        'app/controllers/admin_theme_actions.php',
        'app/controllers/admin_theme_appearance.php',
        'app/controllers/admin_theme_custom_css.php',
        'app/controllers/admin_theme_language.php',
        'app/controllers/admin_theme_layout.php',
        'app/controllers/admin_theme_media.php',
        'app/controllers/admin_theme_page.php',
        'app/controllers/public_gallery.php',
        'app/controllers/public_gallery_cards.php',
        'app/controllers/public_gallery_controls.php',
        'app/controllers/public_gallery_home.php',
        'app/controllers/public_gallery_lightbox.php',
        'app/controllers/public_gallery_page.php',
        'app/database.php',
        'app/helpers.php',
        'app/helpers_admin_rendering.php',
        'app/helpers_files.php',
        'app/helpers_page_rendering.php',
        'app/helpers_public_urls.php',
        'app/helpers_request.php',
        'app/helpers_runtime.php',
        'app/integrity.php',
        'app/core-manifest.json',
        'app/migrations.php',
        'app/security.php',
        'app/services.php',
        'app/services/schema_inspection.php',
        'app/services/mutation_schema_policy.php',
        'app/services/updates.php',
        'app/services/updates_filesystem.php',
        'app/services/updates_install.php',
        'app/services/updates_jobs.php',
        'app/services/updates_patch_notes.php',
        'app/services/updates_remote.php',
        'app/services/updates_status.php',
        'app/views.php',
        'app/views/layout.php',
        'app/lang/en.php',
        'public/assets/styles.css',
    ];
    foreach ($requiredPaths as $requiredPath) {
        // $absolutePath stores the required file inside the extracted release snapshot.
        $absolutePath = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $requiredPath);
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('Downloaded update archive is incomplete. Missing or unreadable: ' . $requiredPath);
        }
    }

    // A current release must provide at least one migration-definition implementation.
    if (!is_file($sourceRoot . '/app/migration_definitions.php') && !is_file($sourceRoot . '/app/migrations.php')) {
        throw new RuntimeException('Downloaded update archive is incomplete. Missing migration support files.');
    }
}

/**
 * Find the single root directory produced by GitHub zip extraction.
 *
 * @param string $extractDir Extract dir value.
 * @return string Text result for the caller.
 */
function application_update_extracted_root(string $extractDir): string
{
    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = array_values(array_filter(scandir($extractDir) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
    foreach ($entries as $entry) {
        // $path stores an intermediate value used by the surrounding gallery workflow.
        $path = $extractDir . '/' . $entry;
        if (is_dir($path)) {
            return $path;
        }
    }
    throw new RuntimeException('Extracted update archive did not contain an application directory.');
}

/**
 * Copy update files, backing up overwritten files and preserving local data.
 *
 * @param string $sourceRoot Source root value.
 * @param string $destinationRoot Destination root value.
 * @param string $backupPath Backup path filesystem path.
 * @param bool $cleanUnexpectedFiles Clean unexpected files value.
 * @return array Structured result data for the caller.
 */
function application_update_copy_files(string $sourceRoot, string $destinationRoot, string $backupPath, bool $cleanUnexpectedFiles = false): array
{
    application_update_assert_project_root($destinationRoot);
    application_update_assert_source_root($sourceRoot);

    // $backup stores the rollback archive for overwritten and removed files.
    $backup = new ZipArchive();
    if ($backup->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create update backup archive.');
    }

    // $stagedFiles maps temporary sibling files to their final destinations.
    $stagedFiles = [];
    // $copied stores the number of release files committed to the installation.
    $copied = 0;
    // $removed stores obsolete managed paths removed only after all replacements succeed.
    $removed = [];

    try {
        // Stage every incoming file first. A staging failure leaves the active installation untouched.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                continue;
            }
            // $relativePath stores the normalized path inside the release snapshot.
            $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
            if (application_update_path_is_protected($relativePath)) {
                continue;
            }

            // $destination stores the corresponding active-installation path.
            $destination = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if ($item->isDir()) {
                application_update_ensure_dir($destination);
                continue;
            }
            if (is_dir($destination)) {
                throw new RuntimeException('Cannot replace directory with file during update: ' . $relativePath);
            }

            $parent = dirname($destination);
            application_update_ensure_dir($parent);
            $temporaryPath = $parent . '/.php-gallery-update-' . bin2hex(random_bytes(8)) . '.tmp';
            if (!copy($item->getPathname(), $temporaryPath)) {
                throw new RuntimeException('Could not stage update file: ' . $relativePath);
            }
            $expectedSize = filesize($item->getPathname());
            $stagedSize = filesize($temporaryPath);
            if ($expectedSize === false || $stagedSize === false || $expectedSize !== $stagedSize) {
                @unlink($temporaryPath);
                throw new RuntimeException('Staged update file failed size verification: ' . $relativePath);
            }
            $stagedFiles[] = [
                'temporary' => $temporaryPath,
                'destination' => $destination,
                'relative' => $relativePath,
            ];
        }

        // Commit dependency files before bootstrap and public entry points.
        usort($stagedFiles, static function (array $left, array $right): int {
            $priority = static function (string $path): int {
                return match ($path) {
                    'index.php' => 40,
                    'public/index.php' => 30,
                    'app/bootstrap.php' => 20,
                    'app/helpers.php',
                    'app/controllers/admin_galleries_edit.php',
                    'app/controllers/admin_theme.php',
                    'app/controllers/public_gallery.php',
                    'app/services/updates.php' => 10,
                    default => 0,
                };
            };
            $priorityComparison = $priority((string) $left['relative']) <=> $priority((string) $right['relative']);
            return $priorityComparison !== 0
                ? $priorityComparison
                : strcmp((string) $left['relative'], (string) $right['relative']);
        });

        foreach ($stagedFiles as $stagedFile) {
            $destination = (string) $stagedFile['destination'];
            $temporaryPath = (string) $stagedFile['temporary'];
            $relativePath = (string) $stagedFile['relative'];
            if (is_file($destination)) {
                $backup->addFile($destination, $relativePath);
            }
            if (!rename($temporaryPath, $destination)) {
                throw new RuntimeException('Could not atomically replace update file: ' . $relativePath);
            }
            application_update_invalidate_opcache_for_path($destination);
            $copied++;
        }
        $stagedFiles = [];

        // Cleanup happens only after the complete replacement snapshot is active.
        application_update_backup_and_remove_misplaced_project_copy($destinationRoot, $backup);
        $removed = array_values(array_unique(array_merge(
            application_update_remove_malformed_root_files($destinationRoot, $backup),
            application_update_remove_obsolete_managed_paths($sourceRoot, $destinationRoot, $backup, $cleanUnexpectedFiles)
        )));
    } finally {
        foreach ($stagedFiles as $stagedFile) {
            $temporaryPath = (string) ($stagedFile['temporary'] ?? '');
            if ($temporaryPath !== '' && is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }
        $backup->close();
    }

    return [
        'files_copied' => $copied,
        'removed_paths' => $removed,
        'removed_count' => count($removed),
    ];
}

/**
 * Back up and remove malformed root files created by broken ZIP extraction or path flattening.
 *
 * Only regular, non-symlink files directly inside the verified project root are eligible. Directories and
 * files below the root are intentionally untouched.
 *
 * @param string $root Verified application project root.
 * @param ZipArchive $backup Open updater rollback archive.
 * @return array<int, string> Removed root filenames.
 */
function application_update_remove_malformed_root_files(string $root, ZipArchive $backup): array
{
    application_update_assert_project_root($root);

    // $removed stores literal malformed filenames removed from the project root.
    $removed = [];
    // $entries limits inspection to immediate children of the verified project root.
    $entries = new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS);
    foreach ($entries as $entry) {
        if (!$entry->isFile() || $entry->isLink()) {
            continue;
        }

        // $filename may contain a literal backslash or be a known app module flattened into the project root.
        $filename = $entry->getFilename();
        if (!str_contains($filename, '\\') && !application_update_root_filename_is_misplaced($filename)) {
            continue;
        }

        // Store the artifact under a safe, non-restoring diagnostic path in the rollback archive.
        $backupName = 'malformed-root-files/' . str_replace('\\', '__BACKSLASH__', $filename);
        // $contents is loaded before deletion because ZipArchive may defer reading addFile sources until close().
        $contents = file_get_contents($entry->getPathname());
        if ($contents === false || !$backup->addFromString($backupName, $contents)) {
            throw new RuntimeException('Could not back up malformed root file before removal: ' . $filename);
        }
        if (!unlink($entry->getPathname())) {
            throw new RuntimeException('Could not remove malformed root file: ' . $filename);
        }
        $removed[] = $filename;
    }

    return $removed;
}

/**
 * Return true only for known application files that belong directly inside app/, never the project root.
 *
 * @param string $filename Immediate project-root filename.
 * @return bool True when the filename is a known flattened app module.
 */
function application_update_root_filename_is_misplaced(string $filename): bool
{
    static $misplacedApplicationFiles = [
        'bootstrap.php' => true,
        'controllers.php' => true,
        'core-manifest.json' => true,
        'database.php' => true,
        'helpers.php' => true,
        'helpers_admin_rendering.php' => true,
        'helpers_files.php' => true,
        'helpers_page_rendering.php' => true,
        'helpers_public_urls.php' => true,
        'helpers_request.php' => true,
        'helpers_runtime.php' => true,
        'integrity.php' => true,
        'migration_definitions.php' => true,
        'migration_repairs.php' => true,
        'migrations.php' => true,
        'security.php' => true,
        'services.php' => true,
        'views.php' => true,
    ];

    return isset($misplacedApplicationFiles[$filename]);
}

/**
 * Run the misplaced root-file cleanup as a standalone administrator maintenance action.
 *
 * @return array{removed_paths: array<int, string>, removed_count: int, backup: string}
 */
function application_update_cleanup_malformed_root_files(): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP ZipArchive extension is required for misplaced file cleanup.');
    }

    // $root stores the verified application root inspected by this narrowly scoped cleanup.
    $root = application_update_project_root();
    // $backupDir stores standalone cleanup rollback archives.
    $backupDir = $root . '/cache/updates/backups';
    application_update_ensure_dir($backupDir);
    // $backupPath stores any malformed files before they are removed.
    $backupPath = $backupDir . '/before-malformed-root-cleanup-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
    // $backup stores the rollback archive passed to the shared guarded cleanup.
    $backup = new ZipArchive();
    if ($backup->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create misplaced file cleanup backup archive.');
    }

    try {
        // $removed stores the literal root filenames deleted by the cleanup.
        $removed = application_update_remove_malformed_root_files($root, $backup);
        if ($removed === []) {
            $backup->addFromString('cleanup-result.txt', "No malformed root files were found.\n");
        }
    } finally {
        $backup->close();
    }

    return [
        'removed_paths' => $removed,
        'removed_count' => count($removed),
        'backup' => str_replace('\\', '/', substr($backupPath, strlen($root) + 1)),
    ];
}

/**
 * Return true when a path is within a directory that the updater owns.
 *
 * @param string $relativePath Relative path filesystem path.
 * @param bool $cleanUnexpectedFiles Clean unexpected files value.
 * @return bool True when the condition matches.
 */
function application_update_path_is_managed_by_updater(string $relativePath, bool $cleanUnexpectedFiles): bool
{
    // $relativePath stores the normalized project-relative path tested against managed areas.
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if ($relativePath === '' || application_update_path_is_protected($relativePath)) {
        return false;
    }
    if ($cleanUnexpectedFiles) {
        return true;
    }
    foreach (['app', 'public', 'database/migrations', 'scripts'] as $managedDirectory) {
        if ($relativePath === $managedDirectory || str_starts_with($relativePath, $managedDirectory . '/')) {
            return true;
        }
    }
    foreach (['index.php', 'install.php', 'reset.php', 'setup-gallery.php', 'deploy.bat', 'README.md', 'PATCH_NOTES.md', 'ARCHITECTURE.md', 'config.example.php'] as $managedFile) {
        if ($relativePath === $managedFile) {
            return true;
        }
    }
    return false;
}

/**
 * Remove local application files that are not present in the incoming release snapshot.
 *
 * @param string $sourceRoot Source root value.
 * @param string $destinationRoot Destination root value.
 * @param ZipArchive $backup Backup value.
 * @param bool $cleanUnexpectedFiles Clean unexpected files value.
 * @return array Structured result data for the caller.
 */
function application_update_remove_obsolete_managed_paths(string $sourceRoot, string $destinationRoot, ZipArchive $backup, bool $cleanUnexpectedFiles): array
{
    // $removed stores normalized relative paths removed from the installation.
    $removed = [];
    // $iterator stores destination entries checked from deepest to shallowest so directories can be removed safely.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($destinationRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        // $relativePath stores the candidate path relative to the project root.
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($destinationRoot) + 1));
        if ($relativePath === '' || $item->isLink() || application_update_path_is_protected($relativePath)) {
            continue;
        }
        if (!application_update_path_is_managed_by_updater($relativePath, $cleanUnexpectedFiles)) {
            continue;
        }
        // $sourcePath stores the corresponding path in the downloaded release snapshot.
        $sourcePath = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (file_exists($sourcePath)) {
            continue;
        }
        application_update_add_path_to_backup($backup, $item->getPathname(), 'removed-before-update/' . $relativePath);
        application_update_remove_path($item->getPathname());
        $removed[] = $relativePath;
    }
    sort($removed);
    return $removed;
}

/**
 * Remove stale ZIP files and temporary update extraction folders from cache.
 *
 * @param string $root Root value.
 * @param string $activeBackupPath Active backup path filesystem path.
 * @return array Structured result data for the caller.
 */
function application_update_clean_cache_artifacts(string $root, string $activeBackupPath = ''): array
{
    // $cacheRoot stores the cache directory whose generated archives can be safely cleaned.
    $cacheRoot = $root . '/cache';
    if (!is_dir($cacheRoot)) {
        return ['zip_files_removed' => 0, 'temporary_paths_removed' => []];
    }
    // $activeBackupRealPath stores the current rollback backup, which must survive the reinstall.
    $activeBackupRealPath = $activeBackupPath !== '' ? realpath($activeBackupPath) : false;
    // $zipFilesRemoved stores how many generated ZIP files were deleted.
    $zipFilesRemoved = 0;
    // $temporaryPathsRemoved stores cache/update working directories removed after extraction.
    $temporaryPathsRemoved = [];
    // $iterator stores all cache entries, deepest first for safe directory cleanup.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cacheRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        // $path stores the absolute cache item path.
        $path = $item->getPathname();
        // $pathRealPath stores a canonical path used to avoid deleting the active backup.
        $pathRealPath = realpath($path);
        if ($activeBackupRealPath !== false && $pathRealPath !== false && $activeBackupRealPath === $pathRealPath) {
            continue;
        }
        if ($item->isFile() && preg_match('/\.zip$/i', $item->getFilename())) {
            if (!unlink($path)) {
                throw new RuntimeException('Could not remove cached ZIP file: ' . $path);
            }
            $zipFilesRemoved++;
            continue;
        }
        if ($item->isDir() && preg_match('/^(extract|beta-extract|stable-restore)-[0-9]{8}-[0-9]{6}$/', $item->getFilename())) {
            application_update_remove_path($path);
            $temporaryPathsRemoved[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
        }
    }
    sort($temporaryPathsRemoved);
    return [
        'zip_files_removed' => $zipFilesRemoved,
        'temporary_paths_removed' => $temporaryPathsRemoved,
    ];
}


/**
 * Return persistent cache paths whose contents are derived from application code or remote metadata.
 *
 * User assets, generated image derivatives, diagnostics, installation locks, OIDC keys,
 * and updater rollback data are deliberately excluded. These paths can be atomically
 * moved out of their canonical locations after a successful update so the next request
 * starts from a clean logical cache state without recursively deleting files inline.
 *
 * @param string $root Project root.
 * @return array<string,string> Relative cache path => absolute path.
 */
function application_update_version_sensitive_cache_paths(string $root): array
{
    $cacheRoot = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'cache';
    return [
        'github-api' => $cacheRoot . DIRECTORY_SEPARATOR . 'github-api',
        'patch-notes' => $cacheRoot . DIRECTORY_SEPARATOR . 'patch-notes',
        'thumbnail-warmup' => $cacheRoot . DIRECTORY_SEPARATOR . 'thumbnail-warmup',
        'integrity-status.json' => $cacheRoot . DIRECTORY_SEPARATOR . 'integrity-status.json',
        'admin-database-maintenance-report.json' => $cacheRoot . DIRECTORY_SEPARATOR . 'admin-database-maintenance-report.json',
    ];
}

/**
 * Return the updater-owned trash root used for bounded post-update deletion.
 */
function application_update_prune_trash_root(string $root): string
{
    return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'updates' . DIRECTORY_SEPARATOR . 'prune-trash';
}

/**
 * Atomically advance the application cache generation marker after an update.
 *
 * The marker is diagnostic and future-cache friendly. Actual invalidation is performed
 * by moving known version-sensitive persistent caches away from their canonical paths.
 *
 * @return array{generation:int,updated_at:int,job_id:string,version:string}
 */
function application_update_bump_cache_generation(string $root, string $jobId, string $version): array
{
    $cacheRoot = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'cache';
    application_update_ensure_dir($cacheRoot);
    $path = $cacheRoot . DIRECTORY_SEPARATOR . 'application-cache-generation.json';
    $current = [];
    if (is_file($path)) {
        $decoded = json_decode((string) @file_get_contents($path), true);
        if (is_array($decoded)) {
            $current = $decoded;
        }
    }
    $payload = [
        'generation' => max(0, (int) ($current['generation'] ?? 0)) + 1,
        'updated_at' => time(),
        'job_id' => $jobId,
        'version' => $version,
    ];
    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        throw new RuntimeException('Could not encode application cache generation state.');
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $encoded, LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('Could not persist application cache generation state.');
    }
    if (!@rename($temporary, $path)) {
        // Windows may refuse rename-over-existing even within one filesystem.
        // Cache generation is advisory, so a narrow replace fallback is sufficient.
        @unlink($path);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not persist application cache generation state.');
        }
    }
    return $payload;
}

/**
 * Atomically invalidate version-sensitive persistent caches after files and migrations are complete.
 *
 * Renaming a cache directory is intentionally preferred over recursive deletion here: canonical
 * cache paths disappear immediately, while physical deletion is deferred to the bounded cleanup
 * stage. Replays are idempotent because an already-staged path is never moved twice.
 *
 * @return array<string,mixed> Invalidation diagnostics stored in the update result.
 */
function application_update_invalidate_persistent_caches(string $root, string $jobId, string $version): array
{
    $startedAt = microtime(true);
    $trashRoot = application_update_prune_trash_root($root)
        . DIRECTORY_SEPARATOR . 'cache-invalidated'
        . DIRECTORY_SEPARATOR . $jobId;
    $moved = [];
    $alreadyInvalidated = [];
    $errors = [];

    foreach (application_update_version_sensitive_cache_paths($root) as $relative => $source) {
        $target = $trashRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (file_exists($target) || is_link($target)) {
            $alreadyInvalidated[] = $relative;
            continue;
        }
        if (!file_exists($source) && !is_link($source)) {
            continue;
        }
        try {
            application_update_ensure_dir(dirname($target));
            if (!@rename($source, $target)) {
                throw new RuntimeException('Could not atomically stage cache path for deletion.');
            }
            $moved[] = $relative;
        } catch (Throwable $exception) {
            $errors[$relative] = 'cache_invalidation_failed';
        }
    }

    $generation = null;
    try {
        $generation = application_update_bump_cache_generation($root, $jobId, $version);
    } catch (Throwable $exception) {
        $errors['application-cache-generation.json'] = 'cache_generation_persist_failed';
    }

    clearstatcache(true);
    return [
        'strategy' => 'atomic canonical-path invalidation followed by bounded physical deletion',
        'moved' => $moved,
        'already_invalidated' => $alreadyInvalidated,
        'errors' => $errors,
        'generation' => $generation,
        'elapsed_ms' => (microtime(true) - $startedAt) * 1000,
    ];
}

/**
 * Move one updater-owned path into prune-trash without traversing its contents.
 *
 * @return bool True when the source was moved or had already been staged.
 */
function application_update_stage_path_for_prune(string $source, string $target): bool
{
    if (file_exists($target) || is_link($target)) {
        // A source that still exists has not been staged yet. Do not report success
        // merely because an older same-name trash entry is awaiting deletion.
        return !file_exists($source) && !is_link($source);
    }
    if (!file_exists($source) && !is_link($source)) {
        return true;
    }
    application_update_ensure_dir(dirname($target));
    return @rename($source, $target);
}

/**
 * Stage legacy updater backups and extraction folders for bounded deletion.
 *
 * @return array{moved:int,remaining:bool}
 */
function application_update_stage_legacy_cache_artifacts(string $root, int $maxMoves = 12): array
{
    $updateRoot = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'updates';
    if (!is_dir($updateRoot)) {
        return ['moved' => 0, 'remaining' => false];
    }
    $trashRoot = application_update_prune_trash_root($root) . DIRECTORY_SEPARATOR . 'legacy';
    $moved = 0;
    $remaining = false;
    $now = time();

    $backupRoot = $updateRoot . DIRECTORY_SEPARATOR . 'backups';
    if (is_dir($backupRoot)) {
        foreach ((array) @scandir($backupRoot) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $source = $backupRoot . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($source) || $now - (int) (@filemtime($source) ?: $now) < 7 * 86400) {
                continue;
            }
            if ($moved >= $maxMoves) {
                $remaining = true;
                continue;
            }
            $target = $trashRoot . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . $entry;
            if (application_update_stage_path_for_prune($source, $target)) {
                $moved++;
            } else {
                $remaining = true;
            }
        }
    }

    foreach ((array) @scandir($updateRoot) as $entry) {
        if ($entry === '.' || $entry === '..' || in_array($entry, ['jobs', 'backups', 'prune-trash'], true)) {
            continue;
        }
        if (preg_match('/^(extract|beta-extract|stable-restore)-[0-9]{8}-[0-9]{6}$/', $entry) !== 1) {
            continue;
        }
        $source = $updateRoot . DIRECTORY_SEPARATOR . $entry;
        if (!is_dir($source)) {
            continue;
        }
        if ($moved >= $maxMoves) {
            $remaining = true;
            continue;
        }
        $target = $trashRoot . DIRECTORY_SEPARATOR . 'extracts' . DIRECTORY_SEPARATOR . $entry;
        if (application_update_stage_path_for_prune($source, $target)) {
            $moved++;
        } else {
            $remaining = true;
        }
    }

    return ['moved' => $moved, 'remaining' => $remaining];
}

/**
 * Delete a bounded slice of one updater-owned trash tree.
 *
 * The caller controls both entry count and wall-clock duration. This prevents a large historical
 * cache from turning one automatic-update request into a long-running shared-hosting worker.
 *
 * @return array{removed:int,done:bool,elapsed_ms:float}
 */
function application_update_delete_tree_slice(string $path, int $maxEntries = 180, float $maxSeconds = 0.18): array
{
    $startedAt = microtime(true);
    $deadline = $startedAt + max(0.01, min(0.50, $maxSeconds));
    $removed = 0;

    if (is_file($path) || is_link($path)) {
        $done = @unlink($path) || !file_exists($path);
        return ['removed' => $done ? 1 : 0, 'done' => $done, 'elapsed_ms' => (microtime(true) - $startedAt) * 1000];
    }
    if (!is_dir($path)) {
        return ['removed' => 0, 'done' => true, 'elapsed_ms' => (microtime(true) - $startedAt) * 1000];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($removed >= max(1, $maxEntries) || microtime(true) >= $deadline) {
            break;
        }
        $itemPath = $item->getPathname();
        $ok = $item->isDir() && !$item->isLink() ? @rmdir($itemPath) : @unlink($itemPath);
        if ($ok || (!file_exists($itemPath) && !is_link($itemPath))) {
            $removed++;
        }
    }

    if (microtime(true) < $deadline && is_dir($path)) {
        @rmdir($path);
    }
    clearstatcache(true, $path);
    return [
        'removed' => $removed,
        'done' => !file_exists($path) && !is_link($path),
        'elapsed_ms' => (microtime(true) - $startedAt) * 1000,
    ];
}


/**
 * Back up and remove a full project copy that was accidentally written inside app.
 *
 * @param string $root Root value.
 * @param ZipArchive $backup Backup value.
 */
function application_update_backup_and_remove_misplaced_project_copy(string $root, ZipArchive $backup): void
{
    // $appDirectory stores an intermediate value used by the surrounding gallery workflow.
    $appDirectory = $root . '/app';
    if (!is_dir($appDirectory)) {
        return;
    }

    // $misplacedPaths stores an intermediate value used by the surrounding gallery workflow.
    $misplacedPaths = application_update_misplaced_project_paths($root);
    foreach ($misplacedPaths as $relativePath) {
        // $absolutePath stores an intermediate value used by the surrounding gallery workflow.
        $absolutePath = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!file_exists($absolutePath)) {
            continue;
        }
        application_update_add_path_to_backup($backup, $absolutePath, 'misplaced-before-update/' . $relativePath);
        application_update_remove_path($absolutePath);
    }
}

/**
 * Return known wrong locations created when the updater used app as the project root.
 *
 * @param string $root Root value.
 * @return array Structured result data for the caller.
 */
function application_update_misplaced_project_paths(string $root): array
{
    // Only remove paths that unambiguously represent a complete project copied inside app.
    // Never infer misplaced files from a whitelist of valid app entries, because that list
    // becomes stale whenever a legitimate top-level module or directory is added.
    return [
        'app/app',
        'app/public',
        'app/database',
        'app/galleries',
        'app/cache',
        'app/custom_css',
        'app/scripts',
        'app/_for_codex',
        'app/.git',
        'app/.github',
        'app/.htaccess',
        'app/index.php',
        'app/install.php',
        'app/reset.php',
        'app/setup-gallery.php',
        'app/deploy.bat',
        'app/config.php',
        'app/config.example.php',
        'app/README.md',
        'app/PATCH_NOTES.md',
        'app/ARCHITECTURE.md',
    ];
}

/**
 * Add one file or directory tree to the updater backup archive.
 *
 * @param ZipArchive $backup Backup value.
 * @param string $path Filesystem path.
 * @param string $archivePath Archive path filesystem path.
 */
function application_update_add_path_to_backup(ZipArchive $backup, string $path, string $archivePath): void
{
    // $archivePath stores an intermediate value used by the surrounding gallery workflow.
    $archivePath = ltrim(str_replace('\\', '/', $archivePath), '/');
    if (is_file($path)) {
        $backup->addFile($path, $archivePath);
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    // $iterator stores an intermediate value used by the surrounding gallery workflow.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isLink() || $item->isDir()) {
            continue;
        }
        // $relativePath stores an intermediate value used by the surrounding gallery workflow.
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($path) + 1));
        $backup->addFile($item->getPathname(), rtrim($archivePath, '/') . '/' . $relativePath);
    }
}

/**
 * Remove a file or directory tree after it has been captured in the updater backup.
 *
 * @param string $path Filesystem path.
 */
function application_update_remove_path(string $path): void
{
    if (is_file($path) || is_link($path)) {
        if (!unlink($path)) {
            throw new RuntimeException('Could not remove misplaced updater artifact: ' . $path);
        }
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    // $iterator stores an intermediate value used by the surrounding gallery workflow.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            if (!rmdir($item->getPathname())) {
                throw new RuntimeException('Could not remove misplaced updater directory: ' . $item->getPathname());
            }
            continue;
        }
        if (!unlink($item->getPathname())) {
            throw new RuntimeException('Could not remove misplaced updater file: ' . $item->getPathname());
        }
    }
    if (!rmdir($path)) {
        throw new RuntimeException('Could not remove misplaced updater directory: ' . $path);
    }
}

/**
 * Remove stale temporary extraction directories from cache/updates.
 *
 * @param string $updateDir Update dir value.
 * @param string $activeExtractDir Active extract dir value.
 */
function application_update_cleanup_transient_extracts(string $updateDir, string $activeExtractDir = ''): void
{
    if (!is_dir($updateDir)) {
        return;
    }

    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = scandir($updateDir) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry === 'backups') {
            continue;
        }
        if (!preg_match('/^(extract|beta-extract|stable-restore)-[0-9]{8}-[0-9]{6}$/', $entry)) {
            continue;
        }
        // $path stores an intermediate value used by the surrounding gallery workflow.
        $path = $updateDir . '/' . $entry;
        // $activeExtractRealPath stores an intermediate value used by the surrounding gallery workflow.
        $activeExtractRealPath = $activeExtractDir !== '' ? realpath($activeExtractDir) : false;
        // $pathRealPath stores an intermediate value used by the surrounding gallery workflow.
        $pathRealPath = realpath($path);
        if ($activeExtractRealPath !== false && $pathRealPath !== false && $activeExtractRealPath === $pathRealPath) {
            continue;
        }
        if (is_dir($path)) {
            application_update_remove_path($path);
        }
    }
}

/**
 * Invalidate cached PHP bytecode for a freshly copied file when opcache is enabled.
 *
 * @param string $path Filesystem path.
 */
function application_update_invalidate_opcache_for_path(string $path): void
{
    if (!function_exists('opcache_invalidate')) {
        return;
    }
    if (is_file($path) && preg_match('/\.php$/i', $path)) {
        @opcache_invalidate($path, true);
    }
}

/**
 * Invalidate cached PHP bytecode for restored application files under a source tree.
 *
 * @param string $destinationRoot Destination root value.
 * @param string $sourceRoot Source root value.
 */
function application_update_invalidate_opcache(string $destinationRoot, string $sourceRoot): void
{
    if (!function_exists('opcache_invalidate')) {
        return;
    }
    // $iterator stores an intermediate value used by the surrounding gallery workflow.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() || $item->isLink()) {
            continue;
        }
        // $relativePath stores an intermediate value used by the surrounding gallery workflow.
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
        // $destination stores an intermediate value used by the surrounding gallery workflow.
        $destination = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        application_update_invalidate_opcache_for_path($destination);
    }
}

/**
 * Read the CMS version from a local bootstrap file.
 *
 * @param string $bootstrapPath Bootstrap path filesystem path.
 * @return ?string Text result for the caller.
 */
function application_update_version_from_local_bootstrap(string $bootstrapPath): ?string
{
    if (!is_file($bootstrapPath)) {
        return null;
    }
    // $bootstrap stores an intermediate value used by the surrounding gallery workflow.
    $bootstrap = (string) file_get_contents($bootstrapPath);
    return application_update_version_from_bootstrap($bootstrap);
}

/**
 * Keep local-only files and directories out of automated updates.
 *
 * @param string $relativePath Relative path filesystem path.
 * @return bool True when the condition matches.
 */
function application_update_path_is_protected(string $relativePath): bool
{
    // $relativePath stores an intermediate value used by the surrounding gallery workflow.
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    // $protectedFiles stores an intermediate value used by the surrounding gallery workflow.
    $protectedFiles = [
        'config.php',
        'public/assets/custom.css',
        '.user.ini',
        'php.ini',
        'robots.txt',
    ];
    if (in_array($relativePath, $protectedFiles, true)) {
        return true;
    }
    if ($relativePath === 'galleries/.htaccess') {
        return false;
    }
    foreach (['.git', '.well-known', 'cache', 'galleries', 'custom_css', '_for_codex'] as $directory) {
        if ($relativePath === $directory || str_starts_with($relativePath, $directory . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * Return whether the admin log table exists.
 */
/**
 * Ensure the admin log table has the workflow columns used by the log UI.
 */
/**
 * Store one admin-visible log entry for operational failures or notices.
 */
/**
 * Allowed workflow states for admin log entries.
 */
/**
 * Human label for one admin log status.
 */
/**
 * Return recent admin log entries for the dashboard.
 */
/**
 * Return admin log entries with optional status filtering.
 */
/**
 * Update the workflow status for one admin log entry.
 */
