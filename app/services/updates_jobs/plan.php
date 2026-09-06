<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs/plan.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds the file replacement plan and stages backups.
 *
 * Responsibilities:
 *   - Enumerate release files and detect which target files change
 *   - Identify obsolete paths eligible for removal
 *   - Stage files and back up every path the activation will replace
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
 *   - Loaded by app/services/updates_jobs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/updates_jobs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\run_migrations_bounded;

/**
 * Return incoming release files in deterministic activation order.
 *
 * @param string $sourceRoot Extracted source root.
 * @return array<int,string> Project-relative files.
 */
function application_update_release_files(string $sourceRoot): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if (!$item->isFile() || $item->isLink()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
        if (application_update_path_is_protected($relative)
            || !application_update_path_is_managed_by_updater($relative, false)
            || in_array(basename($relative), ['.DS_Store', 'Thumbs.db'], true)) {
            continue;
        }
        $files[] = $relative;
    }

    usort($files, static function (string $left, string $right): int {
        $priority = static function (string $path): int {
            return match ($path) {
                'index.php' => 50,
                'public/index.php' => 40,
                'app/bootstrap.php' => 30,
                'app/services/updates.php' => 20,
                default => 0,
            };
        };
        $comparison = $priority($left) <=> $priority($right);
        return $comparison !== 0 ? $comparison : strcmp($left, $right);
    });
    return $files;
}

/**
 * Filter an incoming release to files that would actually change the active tree.
 *
 * Hash comparisons happen before backup/activation so the activation critical section
 * contains only new or changed files. Managed symbolic-link destinations are rejected
 * rather than followed or replaced implicitly.
 *
 * @param string $sourceRoot Extracted or rollback source root.
 * @param string $destinationRoot Active project root.
 * @param array<int,string> $files Incoming project-relative files.
 * @return array<int,string> New or changed files, preserving activation order.
 */
function application_update_changed_release_files(string $sourceRoot, string $destinationRoot, array $files): array
{
    $changed = [];
    foreach ($files as $relative) {
        $relative = (string) $relative;
        $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destination = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException('Prepared update source file is missing or unsafe.');
        }
        if (is_link($destination)) {
            throw new RuntimeException('Updater refuses symbolic links in managed activation paths.');
        }
        if (is_dir($destination)) {
            throw new RuntimeException('Update cannot replace an active directory with a file.');
        }
        if (is_file($destination)) {
            $sourceSize = filesize($source);
            $destinationSize = filesize($destination);
            if ($sourceSize !== false && $destinationSize !== false && $sourceSize === $destinationSize) {
                $sourceHash = hash_file('sha256', $source);
                $destinationHash = hash_file('sha256', $destination);
                if (is_string($sourceHash) && is_string($destinationHash) && hash_equals($sourceHash, $destinationHash)) {
                    continue;
                }
            }
        }
        $changed[] = $relative;
    }
    return $changed;
}

/**
 * Return obsolete managed destination paths that the incoming release does not contain.
 *
 * Protected directories are pruned before recursive traversal. Normal updates inspect
 * only updater-owned application roots instead of walking galleries/cache/custom data.
 * Clean reinstall may inspect additional root entries, but still never descends into
 * protected directories.
 *
 * @param string $sourceRoot Extracted source root.
 * @param string $destinationRoot Active project root.
 * @param bool $cleanUnexpectedFiles Whether clean reinstall owns all non-protected paths.
 * @return array<int,string> Paths ordered deepest first.
 */
function application_update_obsolete_paths(string $sourceRoot, string $destinationRoot, bool $cleanUnexpectedFiles): array
{
    $removed = [];
    $roots = [];
    if ($cleanUnexpectedFiles) {
        foreach (new FilesystemIterator($destinationRoot, FilesystemIterator::SKIP_DOTS) as $entry) {
            $relative = str_replace('\\', '/', $entry->getFilename());
            if ($relative !== '' && !application_update_path_is_protected($relative)) {
                $roots[] = $entry->getPathname();
            }
        }
    } else {
        foreach (['app', 'public', 'database/migrations', 'scripts'] as $relativeRoot) {
            $path = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
            if (file_exists($path) || is_link($path)) {
                $roots[] = $path;
            }
        }
        foreach (['index.php', 'install.php', 'reset.php', 'setup-gallery.php', 'deploy.bat', 'README.md', 'PATCH_NOTES.md', 'ARCHITECTURE.md', 'config.example.php'] as $relativeFile) {
            $path = $destinationRoot . '/' . $relativeFile;
            if (file_exists($path) || is_link($path)) {
                $roots[] = $path;
            }
        }
    }

    // Exact application-owned server-policy files remain updater-managed even when
    // their parent directories are protected from recursive cleanup. Add them for
    // both normal updates and clean reinstalls without traversing gallery/cache data.
    foreach (application_update_managed_server_policy_files() as $relativeFile) {
        $path = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeFile);
        if (file_exists($path) || is_link($path)) {
            $roots[] = $path;
        }
    }

    $roots = array_values(array_unique($roots));
    foreach ($roots as $rootPath) {
        $relativeRoot = str_replace('\\', '/', substr($rootPath, strlen($destinationRoot) + 1));
        if ($relativeRoot === '' || application_update_path_is_protected($relativeRoot)) {
            continue;
        }
        if (is_link($rootPath)) {
            continue;
        }
        if (is_file($rootPath)) {
            $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
            if (!file_exists($source) && application_update_path_is_managed_by_updater($relativeRoot, $cleanUnexpectedFiles)) {
                $removed[] = $relativeRoot;
            }
            continue;
        }
        if (!is_dir($rootPath)) {
            continue;
        }

        $directory = new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            static function ($current) use ($destinationRoot): bool {
                $relative = str_replace('\\', '/', substr($current->getPathname(), strlen($destinationRoot) + 1));
                return $relative !== '' && !$current->isLink() && !application_update_path_is_protected($relative);
            }
        );
        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($destinationRoot) + 1));
            if ($relative === '' || !application_update_path_is_managed_by_updater($relative, $cleanUnexpectedFiles)) {
                continue;
            }
            $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!file_exists($source)) {
                $removed[] = $relative;
            }
        }

        if (application_update_path_is_managed_by_updater($relativeRoot, $cleanUnexpectedFiles)) {
            $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativeRoot);
            if (!file_exists($source)) {
                $removed[] = $relativeRoot;
            }
        }
    }

    return array_values(array_unique($removed));
}

/**
 * Build the bounded rollback-item list for one activation plan.
 *
 * Obsolete directories do not need recursive snapshot copies because their files
 * are already present as individual child entries in the obsolete-path plan.
 * Empty directories contain no rollback data. A file-vs-directory replacement is
 * rejected here before backup or activation begins.
 *
 * @param string $root Active project root.
 * @param array<int,string> $files Incoming activation files.
 * @param array<int,string> $obsolete Obsolete destination paths.
 * @return array<int,string> File-level paths to snapshot or mark as newly created.
 */
function application_update_backup_items_for_plan(string $root, array $files, array $obsolete): array
{
    $items = [];
    foreach ($files as $relative) {
        $relative = (string) $relative;
        $destination = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_link($destination)) {
            throw new RuntimeException('Updater refuses symbolic links in managed activation paths.');
        }
        if (is_dir($destination)) {
            throw new RuntimeException('Update cannot replace an active directory with a file.');
        }
        $items[$relative] = true;
    }
    foreach ($obsolete as $relative) {
        $relative = (string) $relative;
        $destination = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($destination) || is_link($destination)) {
            $items[$relative] = true;
        }
    }
    return array_keys($items);
}

/**
 * Build and persist the complete activation plan before active files are touched.
 *
 * @param array $job Job state, updated by reference.
 */
function application_update_job_build_plan(array &$job): void
{
    $operation = (string) ($job['operation'] ?? '');
    if ($operation === 'rollback') {
        $sourceJobId = (string) ($job['parameters']['source_job_id'] ?? '');
        $sourceJobDir = application_update_job_dir($sourceJobId);
        $metadata = application_update_read_json($sourceJobDir . '/rollback/metadata.json');
        if ($metadata === [] || !is_dir($sourceJobDir . '/rollback/original')) {
            throw new RuntimeException('Rollback source snapshot is incomplete.');
        }
        $sourceRoot = $sourceJobDir . '/rollback/original';
        $job['checkpoints']['source_root'] = $sourceRoot;
        $files = application_update_changed_release_files(
            $sourceRoot,
            application_update_project_root(),
            application_update_release_files($sourceRoot)
        );
        $obsolete = [];
        foreach ((array) ($metadata['created_paths'] ?? []) as $relative) {
            $relative = application_update_safe_zip_entry((string) $relative);
            if ($relative !== '' && !application_update_path_is_protected($relative)) {
                $obsolete[] = $relative;
            }
        }
        $job['checkpoints']['activation_files'] = array_values(array_unique($files));
        $job['checkpoints']['obsolete_paths'] = array_values(array_unique($obsolete));
        $job['checkpoints']['stage_index'] = (int) ($job['checkpoints']['stage_index'] ?? 0);
        $job['checkpoints']['backup_index'] = (int) ($job['checkpoints']['backup_index'] ?? 0);
        $job['checkpoints']['backup_items'] = application_update_backup_items_for_plan(application_update_project_root(), $files, $obsolete);
        $job['checkpoints']['rollback_source_job_id'] = $sourceJobId;
        $job['progress'] = ['current' => 0, 'total' => count($files), 'message' => 'Rollback activation plan prepared.', 'unit' => 'files'];
        application_update_assert_activation_schema_known('application_update.job_rollback_activation');
        application_update_save_job($job);
        return;
    }

    $sourceRoot = (string) ($job['checkpoints']['source_root'] ?? '');
    if ($sourceRoot === '') {
        throw new RuntimeException('Validated update source is missing.');
    }
    $root = application_update_project_root();
    application_update_assert_activation_schema_known('application_update.job_activation');
    $releaseFiles = application_update_release_files($sourceRoot);
    if ($releaseFiles === []) {
        throw new RuntimeException('Validated update package contains no installable files.');
    }
    $files = application_update_changed_release_files($sourceRoot, $root, $releaseFiles);
    $cleanUnexpected = !empty($job['parameters']['clean_unexpected_files']);
    $obsolete = application_update_obsolete_paths($sourceRoot, $root, $cleanUnexpected);

    $job['checkpoints']['activation_files'] = $files;
    $job['checkpoints']['obsolete_paths'] = $obsolete;
    $job['checkpoints']['stage_index'] = (int) ($job['checkpoints']['stage_index'] ?? 0);
    $job['checkpoints']['backup_index'] = (int) ($job['checkpoints']['backup_index'] ?? 0);
    $job['checkpoints']['backup_items'] = application_update_backup_items_for_plan(application_update_project_root(), $files, $obsolete);
    $job['progress'] = ['current' => 0, 'total' => count($files), 'message' => 'Activation plan prepared.', 'unit' => 'files'];
    application_update_save_job($job);
}

/**
 * Copy and verify release files into the job ready tree in bounded batches.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when every activation file is staged.
 */
function application_update_job_stage_files_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $sourceRoot = (string) ($job['checkpoints']['source_root'] ?? '');
    $files = (array) ($job['checkpoints']['activation_files'] ?? []);
    $index = (int) ($job['checkpoints']['stage_index'] ?? 0);
    $processed = 0;

    while ($index < count($files) && $processed < 40 && application_update_budget_allows($budget, 0.7)) {
        $relative = (string) $files[$index];
        $source = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $destination = $jobDir . '/ready/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        application_update_ensure_dir(dirname($destination));
        if (!is_file($destination) || filesize($destination) !== filesize($source) || !hash_equals(hash_file('sha256', $source), hash_file('sha256', $destination))) {
            $temporary = $destination . '.part';
            if (!copy($source, $temporary)) {
                throw new RuntimeException('Could not stage a prepared update file.');
            }
            if (!hash_equals(hash_file('sha256', $source), hash_file('sha256', $temporary))) {
                @unlink($temporary);
                throw new RuntimeException('Prepared update file failed integrity verification.');
            }
            if (!rename($temporary, $destination)) {
                @unlink($temporary);
                throw new RuntimeException('Could not commit a prepared update file.');
            }
        }
        $index++;
        $processed++;
        $job['checkpoints']['stage_index'] = $index;
        $job['progress'] = ['current' => $index, 'total' => count($files), 'message' => 'Staging release files outside the active installation.', 'unit' => 'files'];
        application_update_save_job($job);
    }
    return $index >= count($files);
}

/**
 * Copy one existing active path into the rollback snapshot.
 *
 * Directories are represented by their contained files; created-path metadata
 * records paths that did not exist before activation so rollback can remove them.
 *
 * @param string $root Active project root.
 * @param string $backupRoot Rollback snapshot root.
 * @param string $relative Project-relative path.
 * @return bool True when the path existed before activation.
 */
function application_update_backup_path_to_directory(string $root, string $backupRoot, string $relative): bool
{
    $source = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!file_exists($source) && !is_link($source)) {
        return false;
    }
    if (is_link($source)) {
        throw new RuntimeException('Updater refuses to back up symbolic links in managed paths.');
    }
    if (is_file($source)) {
        $size = filesize($source);
        if ($size === false || $size > 128 * 1024 * 1024) {
            throw new RuntimeException('Managed active file is too large for a bounded rollback snapshot.');
        }
        $destination = $backupRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        application_update_ensure_dir(dirname($destination));
        if (!is_file($destination)) {
            if (!copy($source, $destination)) {
                throw new RuntimeException('Could not prepare rollback snapshot file.');
            }
        }
        return true;
    }
    if (is_dir($source)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                throw new RuntimeException('Updater refuses symbolic links in managed rollback paths.');
            }
            $suffix = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));
            $destination = $backupRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relative . '/' . $suffix);
            if ($item->isDir()) {
                application_update_ensure_dir($destination);
            } else {
                application_update_ensure_dir(dirname($destination));
                if (!is_file($destination) && !copy($item->getPathname(), $destination)) {
                    throw new RuntimeException('Could not prepare rollback snapshot directory.');
                }
            }
        }
        return true;
    }
    return false;
}

/**
 * Build the durable rollback snapshot in bounded batches before activation.
 *
 * @param array $job Job state, updated by reference.
 * @param array $budget Worker budget.
 * @return bool True when rollback data is complete and closed on disk.
 */
function application_update_job_backup_slice(array &$job, array $budget): bool
{
    $jobDir = application_update_job_dir((string) $job['id']);
    $root = application_update_project_root();
    $backupRoot = $jobDir . '/rollback/original';
    $items = (array) ($job['checkpoints']['backup_items'] ?? []);
    $index = (int) ($job['checkpoints']['backup_index'] ?? 0);
    $createdPaths = (array) ($job['checkpoints']['created_paths'] ?? []);
    $processed = 0;

    while ($index < count($items) && $processed < 30 && application_update_budget_allows($budget, 0.8)) {
        $relative = (string) $items[$index];
        $existed = application_update_backup_path_to_directory($root, $backupRoot, $relative);
        if (!$existed) {
            $createdPaths[$relative] = true;
        }
        $index++;
        $processed++;
        $job['checkpoints']['backup_index'] = $index;
        $job['checkpoints']['created_paths'] = $createdPaths;
        $job['progress'] = ['current' => $index, 'total' => count($items), 'message' => 'Preparing durable rollback snapshot.', 'unit' => 'paths'];
        application_update_save_job($job);
    }

    if ($index >= count($items)) {
        $metadata = [
            'job_id' => (string) $job['id'],
            'created_at' => time(),
            'created_paths' => array_keys($createdPaths),
            'activation_files' => array_values((array) ($job['checkpoints']['activation_files'] ?? [])),
            'obsolete_paths' => array_values((array) ($job['checkpoints']['obsolete_paths'] ?? [])),
            'settings_before' => [
                'channel' => (string) app_setting('application_update_channel', 'stable'),
                'beta_commit' => (string) app_setting('application_update_beta_commit', ''),
                'beta_backup_path' => (string) app_setting('application_update_beta_backup_path', ''),
            ],
        ];
        application_update_write_json_atomic($jobDir . '/rollback/metadata.json', $metadata);
        $job['checkpoints']['backup_complete'] = true;
        application_update_save_job($job);
        return true;
    }
    return false;
}
