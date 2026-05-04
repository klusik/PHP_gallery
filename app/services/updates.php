<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
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
 * Return the configured upstream project URL.
 */
function cms_github_project_url(): string
{
    return 'https://github.com/' . CMS_GITHUB_REPOSITORY;
}

/**
 * Check GitHub release metadata for the newest published application version.
 */
function check_application_update(): array
{
    // $lastError stores an intermediate value used by the surrounding gallery workflow.
    $lastError = null;
    // $latestStatus stores an intermediate value used by the surrounding gallery workflow.
    $latestStatus = null;
    foreach (application_update_branch_candidates() as $branch) {
        try {
            // $versionCandidates stores an intermediate value used by the surrounding gallery workflow.
            $versionCandidates = application_update_remote_version_candidates($branch);
            if ($versionCandidates === []) {
                // $lastError stores an intermediate value used by the surrounding gallery workflow.
                $lastError = 'No version marker was found in app/bootstrap.php on branch ' . $branch . '.';
                continue;
            }
            // $latestVersion stores an intermediate value used by the surrounding gallery workflow.
            $latestVersion = application_update_highest_version($versionCandidates);
            // $status stores an intermediate value used by the surrounding gallery workflow.
            $status = [
                'current_version' => cms_current_version(),
                'latest_version' => $latestVersion,
                'branch' => $branch,
                'repository' => CMS_GITHUB_REPOSITORY,
                'update_available' => version_compare($latestVersion, cms_current_version(), '>'),
                'version_sources' => $versionCandidates,
                'version_source' => application_update_version_source_label($versionCandidates, $latestVersion),
                'error' => null,
            ];
            if ($latestStatus === null || version_compare($latestVersion, (string) $latestStatus['latest_version'], '>')) {
                // $latestStatus stores an intermediate value used by the surrounding gallery workflow.
                $latestStatus = $status;
            }
        } catch (Throwable $exception) {
            // $lastError stores an intermediate value used by the surrounding gallery workflow.
            $lastError = $exception->getMessage();
        }
    }

    if ($latestStatus !== null) {
        return $latestStatus;
    }

    return [
        'current_version' => cms_current_version(),
        'latest_version' => null,
        'branch' => implode(' or ', application_update_branch_candidates()),
        'repository' => CMS_GITHUB_REPOSITORY,
        'update_available' => false,
        'version_sources' => [],
        'version_source' => '',
        'error' => $lastError ?? 'Could not contact GitHub.',
    ];
}

/**
 * Return a cached update check for small UI badges.
 */
function cached_application_update_check(int $ttlSeconds = 3600): array
{
    return check_application_update();
}

/**
 * Store an update check result for badge rendering.
 */
function cache_application_update_check(array $status): void
{
    // Update checks are intentionally uncached now.
}

/**
 * Return true when the cached update check says a newer version is available.
 */
function application_update_pending(): bool
{
    // $status stores an intermediate value used by the surrounding gallery workflow.
    $status = check_application_update();
    return empty($status['error']) && !empty($status['update_available']);
}

/**
 * Return true when the application is currently on a beta/manual commit install.
 */
function application_update_beta_active(): bool
{
    return app_setting('application_update_channel', 'stable') === 'beta' && app_setting('application_update_beta_commit', '') !== '';
}

/**
 * Return the currently installed beta code, if any.
 */
function application_update_beta_commit(): string
{
    return (string) app_setting('application_update_beta_commit', '');
}

/**
 * Return the stored beta backup archive path.
 */
function application_update_beta_backup_path(): string
{
    return (string) app_setting('application_update_beta_backup_path', '');
}

/**
 * Install a beta/manual code archive over the current application files.
 */
function install_application_beta(string $commitId): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP ZipArchive extension is required for one-button updates.');
    }
    // $commitId stores an intermediate value used by the surrounding gallery workflow.
    $commitId = strtolower(trim($commitId));
    if (!preg_match('/^[0-9a-f]{7,40}$/', $commitId)) {
        throw new RuntimeException('Enter a valid beta code.');
    }

    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = application_update_project_root();
    // $updateDir stores an intermediate value used by the surrounding gallery workflow.
    $updateDir = $root . '/cache/updates';
    // $backupDir stores an intermediate value used by the surrounding gallery workflow.
    $backupDir = $updateDir . '/backups';
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($backupDir);

    // $stamp stores an intermediate value used by the surrounding gallery workflow.
    $stamp = date('Ymd-His');
    // $zipPath stores an intermediate value used by the surrounding gallery workflow.
    $zipPath = $updateDir . '/beta-' . $stamp . '.zip';
    // $extractDir stores an intermediate value used by the surrounding gallery workflow.
    $extractDir = $updateDir . '/beta-extract-' . $stamp;
    application_update_ensure_dir($extractDir);

    // $archive stores an intermediate value used by the surrounding gallery workflow.
    $archive = http_fetch(application_update_commit_zip_url($commitId), 60);
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write beta archive into cache/updates.');
    }

    // $zip stores an intermediate value used by the surrounding gallery workflow.
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded beta archive could not be opened.');
    }
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded beta archive could not be extracted.');
    }
    $zip->close();

    // $sourceRoot stores an intermediate value used by the surrounding gallery workflow.
    $sourceRoot = application_update_extracted_root($extractDir);
    application_update_assert_source_root($sourceRoot);
    application_update_cleanup_transient_extracts($updateDir, $extractDir);
    // $backupPath stores an intermediate value used by the surrounding gallery workflow.
    $backupPath = $backupDir . '/before-beta-' . $stamp . '.zip';
    // $copied stores an intermediate value used by the surrounding gallery workflow.
    $copied = application_update_copy_files($sourceRoot, $root, $backupPath);
    // $migrations stores an intermediate value used by the surrounding gallery workflow.
    $migrations = run_migrations();
    application_update_invalidate_opcache($root, $sourceRoot);
    cache_application_update_check(check_application_update());
    set_app_setting('application_update_channel', 'beta');
    set_app_setting('application_update_beta_commit', $commitId);
    set_app_setting('application_update_beta_backup_path', str_replace('\\', '/', substr($backupPath, strlen($root) + 1)));
    delete_app_settings(['application_update_check_cache']);

    return [
        'version' => $commitId,
        'branch' => 'beta',
        'files_copied' => $copied,
        'backup' => str_replace('\\', '/', substr($backupPath, strlen($root) + 1)),
        'migrations' => $migrations,
    ];
}

/**
 * Restore the stable release from the GitHub branch head.
 */
function restore_application_stable_release(): array
{
    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = application_update_project_root();
    // $branch stores an intermediate value used by the surrounding gallery workflow.
    $branch = application_update_branch_candidates()[0] ?? '';
    if ($branch === '') {
        throw new RuntimeException('No stable release branch is configured.');
    }
    // $updateDir stores an intermediate value used by the surrounding gallery workflow.
    $updateDir = $root . '/cache/updates';
    // $stamp stores an intermediate value used by the surrounding gallery workflow.
    $stamp = date('Ymd-His');
    // $restoreDir stores an intermediate value used by the surrounding gallery workflow.
    $restoreDir = $updateDir . '/stable-restore-' . $stamp;
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($restoreDir);

    // $archive stores an intermediate value used by the surrounding gallery workflow.
    $archive = http_fetch(application_update_zip_url($branch), 60);
    // $zipPath stores an intermediate value used by the surrounding gallery workflow.
    $zipPath = $updateDir . '/stable-restore-' . $stamp . '.zip';
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write stable restore archive into cache/updates.');
    }

    // $zip stores an intermediate value used by the surrounding gallery workflow.
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded stable restore archive could not be opened.');
    }
    if (!$zip->extractTo($restoreDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded stable restore archive could not be extracted.');
    }
    $zip->close();

    // $sourceRoot stores an intermediate value used by the surrounding gallery workflow.
    $sourceRoot = application_update_extracted_root($restoreDir);
    application_update_assert_source_root($sourceRoot);
    application_update_cleanup_transient_extracts($updateDir, $restoreDir);
    // $copied stores an intermediate value used by the surrounding gallery workflow.
    $copied = application_update_copy_files($sourceRoot, $root, $root . '/cache/updates/rollback-' . date('Ymd-His') . '.zip');
    application_update_invalidate_opcache($root, $sourceRoot);
    delete_app_settings([
        'application_update_channel',
        'application_update_beta_commit',
        'application_update_beta_backup_path',
        'application_update_check_cache',
    ]);

    // $restoredVersion stores an intermediate value used by the surrounding gallery workflow.
    $restoredVersion = application_update_version_from_local_bootstrap($root . '/app/bootstrap.php') ?? cms_current_version();

    return [
        'version' => $restoredVersion,
        'branch' => 'stable',
        'files_copied' => $copied,
        'archive' => str_replace('\\', '/', substr($zipPath, strlen($root) + 1)),
        'migrations' => [],
    ];
}

/**
 * Backward-compatible wrapper for the stable release restore.
 */
function restore_application_stable_backup(): array
{
    return restore_application_stable_release();
}

/**
 * Return the admin label for links that point to the update screen.
 */
function application_update_nav_label(bool $pending): string
{
    return $pending ? 'Update(1)' : 'Updates';
}

/**
 * Install the newest GitHub branch archive over application-managed files.
 */
function install_application_update(): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('The PHP ZipArchive extension is required for one-button updates.');
    }

    // $status stores an intermediate value used by the surrounding gallery workflow.
    $status = check_application_update();
    if (!empty($status['error'])) {
        throw new RuntimeException((string) $status['error']);
    }
    if (empty($status['update_available'])) {
        throw new RuntimeException('No newer version is available.');
    }

    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = application_update_project_root();
    // $updateDir stores an intermediate value used by the surrounding gallery workflow.
    $updateDir = $root . '/cache/updates';
    // $backupDir stores an intermediate value used by the surrounding gallery workflow.
    $backupDir = $updateDir . '/backups';
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($backupDir);

    // $stamp stores an intermediate value used by the surrounding gallery workflow.
    $stamp = date('Ymd-His');
    // $zipPath stores an intermediate value used by the surrounding gallery workflow.
    $zipPath = $updateDir . '/update-' . $stamp . '.zip';
    // $extractDir stores an intermediate value used by the surrounding gallery workflow.
    $extractDir = $updateDir . '/extract-' . $stamp;
    application_update_ensure_dir($extractDir);

    // $archive stores an intermediate value used by the surrounding gallery workflow.
    $archive = http_fetch(application_update_zip_url((string) $status['branch']), 60);
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write update archive into cache/updates.');
    }

    // $zip stores an intermediate value used by the surrounding gallery workflow.
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded update archive could not be opened.');
    }
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded update archive could not be extracted.');
    }
    $zip->close();

    // $sourceRoot stores an intermediate value used by the surrounding gallery workflow.
    $sourceRoot = application_update_extracted_root($extractDir);
    application_update_assert_source_root($sourceRoot);
    application_update_cleanup_transient_extracts($updateDir, $extractDir);
    // $backupPath stores an intermediate value used by the surrounding gallery workflow.
    $backupPath = $backupDir . '/before-update-' . $stamp . '.zip';
    // $copied stores an intermediate value used by the surrounding gallery workflow.
    $copied = application_update_copy_files($sourceRoot, $root, $backupPath);
    // $migrations stores an intermediate value used by the surrounding gallery workflow.
    $migrations = run_migrations();
    application_update_invalidate_opcache($root, $sourceRoot);
    delete_app_settings(['application_update_check_cache']);

    return [
        'version' => (string) $status['latest_version'],
        'branch' => (string) $status['branch'],
        'files_copied' => $copied,
        'backup' => str_replace('\\', '/', substr($backupPath, strlen($root) + 1)),
        'migrations' => $migrations,
    ];
}

/**
 * Return the branch names the updater should try, newest preference first.
 */
function application_update_branch_candidates(): array
{
    return CMS_UPDATE_BRANCHES;
}

/**
 * Build a GitHub archive URL for one code snapshot.
 */
function application_update_commit_zip_url(string $commitId): string
{
    if (!preg_match('/^[0-9a-f]{7,40}$/', $commitId)) {
        throw new RuntimeException('Enter a valid beta code.');
    }
    [$owner, $repo] = explode('/', CMS_GITHUB_REPOSITORY, 2);
    return 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/archive/' . rawurlencode($commitId) . '.zip';
}

/**
 * Build a GitHub raw-content URL for a branch file.
 */
function application_update_raw_url(string $branch, string $path): string
{
    application_update_assert_allowed_branch($branch);
    return 'https://raw.githubusercontent.com/' . CMS_GITHUB_REPOSITORY . '/' . rawurlencode($branch) . '/' . ltrim($path, '/') . '?nocache=' . rawurlencode((string) time());
}

/**
 * Build a GitHub branch zip URL.
 */
function application_update_zip_url(string $branch): string
{
    application_update_assert_allowed_branch($branch);
    [$owner, $repo] = explode('/', CMS_GITHUB_REPOSITORY, 2);
    return 'https://codeload.github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zip/refs/heads/' . rawurlencode($branch) . '?nocache=' . rawurlencode((string) time());
}

/**
 * Reject update sources outside the stable GitHub branches.
 */
function application_update_assert_allowed_branch(string $branch): void
{
    if (!in_array($branch, CMS_UPDATE_BRANCHES, true)) {
        throw new RuntimeException('Updates are allowed only from the main or master GitHub branch.');
    }
}

/**
 * Read the remote version marker that identifies the newest branch version.
 */
function application_update_remote_version_candidates(string $branch): array
{
    // $versionCandidates stores an intermediate value used by the surrounding gallery workflow.
    $versionCandidates = [];
    try {
        // $bootstrap stores an intermediate value used by the surrounding gallery workflow.
        $bootstrap = http_fetch(application_update_raw_url($branch, 'app/bootstrap.php'), 12);
        // $bootstrapVersion stores an intermediate value used by the surrounding gallery workflow.
        $bootstrapVersion = application_update_version_from_bootstrap($bootstrap);
        if ($bootstrapVersion !== null) {
            $versionCandidates['app/bootstrap.php'] = $bootstrapVersion;
        }
    } catch (Throwable $exception) {
        $versionCandidates['app/bootstrap.php error'] = $exception->getMessage();
    }

    return array_filter($versionCandidates ?? [], static fn ($value): bool => is_string($value) && application_update_normalize_version($value) !== null);
}

/**
 * Return the highest semantic version from remote version candidates.
 */
function application_update_highest_version(array $versionCandidates): string
{
    // $highestVersion stores an intermediate value used by the surrounding gallery workflow.
    $highestVersion = null;
    foreach ($versionCandidates as $version) {
        // $normalizedVersion stores an intermediate value used by the surrounding gallery workflow.
        $normalizedVersion = application_update_normalize_version((string) $version);
        if ($normalizedVersion === null) {
            continue;
        }
        if ($highestVersion === null || version_compare($normalizedVersion, $highestVersion, '>')) {
            // $highestVersion stores an intermediate value used by the surrounding gallery workflow.
            $highestVersion = $normalizedVersion;
        }
    }

    if ($highestVersion === null) {
        throw new RuntimeException('Remote version candidates did not contain a valid version number.');
    }

    return $highestVersion;
}

/**
 * Return a readable label for the remote source that provided the selected version.
 */
function application_update_version_source_label(array $versionCandidates, string $latestVersion): string
{
    // $labels stores an intermediate value used by the surrounding gallery workflow.
    $labels = [];
    foreach ($versionCandidates as $source => $version) {
        // $normalizedVersion stores an intermediate value used by the surrounding gallery workflow.
        $normalizedVersion = application_update_normalize_version((string) $version);
        if ($normalizedVersion === $latestVersion) {
            $labels[] = (string) $source;
        }
    }
    return implode(', ', $labels);
}

/**
 * Parse the CMS_VERSION constant from a remote bootstrap file.
 */
function application_update_version_from_bootstrap(string $bootstrap): ?string
{
    if (preg_match("/const\s+CMS_VERSION\s*=\s*['\"]([^'\"]+)['\"]\s*;/i", $bootstrap, $match)) {
        return application_update_normalize_version((string) $match[1]);
    }
    return null;
}

/**
 * Normalize version strings used in notes, tags, and constants.
 */
function application_update_normalize_version(string $version): ?string
{
    // $version stores an intermediate value used by the surrounding gallery workflow.
    $version = trim($version);
    // $version stores an intermediate value used by the surrounding gallery workflow.
    $version = preg_replace('/^v[_-]?/i', '', $version) ?? $version;
    if (preg_match('/^[0-9]+(?:\.[0-9]+){1,2}$/', $version)) {
        return $version;
    }
    return null;
}

/**
 * Fetch a small trusted remote URL with a bounded timeout.
 */
function http_fetch(string $url, int $timeoutSeconds): string
{
    if (function_exists('curl_init')) {
        // $handle stores an intermediate value used by the surrounding gallery workflow.
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 15),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . cms_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Cache-Control: no-cache',
                'Pragma: no-cache',
            ],
        ]);
        // $body stores an intermediate value used by the surrounding gallery workflow.
        $body = curl_exec($handle);
        // $status stores an intermediate value used by the surrounding gallery workflow.
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        // $error stores an intermediate value used by the surrounding gallery workflow.
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed with status ' . $status . '.');
        }
        return (string) $body;
    }

    // $context stores an intermediate value used by the surrounding gallery workflow.
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: PHP-Gallery-CMS/" . cms_current_version() . "\r\n"
                . "Cache-Control: no-cache\r\n"
                . "Pragma: no-cache\r\n",
        ],
    ]);
    // $body stores an intermediate value used by the surrounding gallery workflow.
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP request failed. Enable curl or allow_url_fopen for update checks.');
    }
    return $body;
}

/**
 * Create an updater working directory when needed.
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
 */
function application_update_assert_source_root(string $sourceRoot): void
{
    // $requiredPaths stores an intermediate value used by the surrounding gallery workflow.
    $requiredPaths = [
        'index.php',
        'app/bootstrap.php',
        'app/services/updates.php',
        'public/assets/styles.css',
    ];
    foreach ($requiredPaths as $requiredPath) {
        // $absolutePath stores an intermediate value used by the surrounding gallery workflow.
        $absolutePath = $sourceRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $requiredPath);
        if (!is_file($absolutePath)) {
            throw new RuntimeException('Downloaded update archive is not a valid PHP Gallery repository snapshot. Missing: ' . $requiredPath);
        }
    }
}

/**
 * Find the single root directory produced by GitHub zip extraction.
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
 */
function application_update_copy_files(string $sourceRoot, string $destinationRoot, string $backupPath): int
{
    // $backup stores an intermediate value used by the surrounding gallery workflow.
    $backup = new ZipArchive();
    if ($backup->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create update backup archive.');
    }

    application_update_assert_project_root($destinationRoot);
    application_update_backup_and_remove_misplaced_project_copy($destinationRoot, $backup);

    // $copied stores an intermediate value used by the surrounding gallery workflow.
    $copied = 0;
    // $iterator stores an intermediate value used by the surrounding gallery workflow.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            continue;
        }
        // $relativePath stores an intermediate value used by the surrounding gallery workflow.
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
        if (application_update_path_is_protected($relativePath)) {
            continue;
        }

        // $destination stores an intermediate value used by the surrounding gallery workflow.
        $destination = $destinationRoot . '/' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if ($item->isDir()) {
            application_update_ensure_dir($destination);
            continue;
        }
        if (is_dir($destination)) {
            throw new RuntimeException('Cannot replace directory with file during update: ' . $relativePath);
        }
        // $parent stores an intermediate value used by the surrounding gallery workflow.
        $parent = dirname($destination);
        application_update_ensure_dir($parent);
        if (is_file($destination)) {
            $backup->addFile($destination, $relativePath);
        }
        if (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException('Could not copy update file: ' . $relativePath);
        }
        application_update_invalidate_opcache_for_path($destination);
        $copied++;
    }

    $backup->close();
    return $copied;
}


/**
 * Back up and remove a full project copy that was accidentally written inside app.
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
 */
function application_update_misplaced_project_paths(string $root): array
{
    // $knownMisplacedPaths stores an intermediate value used by the surrounding gallery workflow.
    $knownMisplacedPaths = [
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

    // $appDirectory stores an intermediate value used by the surrounding gallery workflow.
    $appDirectory = $root . '/app';
    if (!is_dir($appDirectory)) {
        return $knownMisplacedPaths;
    }

    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = scandir($appDirectory) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        // $relativePath stores an intermediate value used by the surrounding gallery workflow.
        $relativePath = 'app/' . $entry;
        if (application_update_app_entry_is_expected($entry)) {
            continue;
        }
        if (!in_array($relativePath, $knownMisplacedPaths, true)) {
            $knownMisplacedPaths[] = $relativePath;
        }
    }

    return $knownMisplacedPaths;
}

/**
 * Return true for normal entries that belong directly inside the app directory.
 */
function application_update_app_entry_is_expected(string $entry): bool
{
    // $expectedEntries stores an intermediate value used by the surrounding gallery workflow.
    $expectedEntries = [
        'bootstrap.php',
        'controllers.php',
        'controllers',
        'core-manifest.json',
        'database.php',
        'helpers.php',
        'integrity.php',
        'migrations.php',
        'security.php',
        'services.php',
        'services',
    ];
    return in_array($entry, $expectedEntries, true);
}

/**
 * Add one file or directory tree to the updater backup archive.
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
 */
function application_update_path_is_protected(string $relativePath): bool
{
    // $relativePath stores an intermediate value used by the surrounding gallery workflow.
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    // $protectedFiles stores an intermediate value used by the surrounding gallery workflow.
    $protectedFiles = [
        'config.php',
        'public/assets/custom.css',
    ];
    if (in_array($relativePath, $protectedFiles, true)) {
        return true;
    }
    foreach (['.git', 'cache', 'galleries', 'custom_css', '_for_codex'] as $directory) {
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
