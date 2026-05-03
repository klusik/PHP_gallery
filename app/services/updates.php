<?php

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
    $lastError = null;
    $latestStatus = null;
    foreach (application_update_branch_candidates() as $branch) {
        try {
            $versionCandidates = application_update_remote_version_candidates($branch);
            if ($versionCandidates === []) {
                $lastError = 'No version marker was found in app/bootstrap.php on branch ' . $branch . '.';
                continue;
            }
            $latestVersion = application_update_highest_version($versionCandidates);
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
                $latestStatus = $status;
            }
        } catch (Throwable $exception) {
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
    $commitId = strtolower(trim($commitId));
    if (!preg_match('/^[0-9a-f]{7,40}$/', $commitId)) {
        throw new RuntimeException('Enter a valid beta code.');
    }

    $root = dirname(__DIR__);
    $updateDir = $root . '/cache/updates';
    $backupDir = $updateDir . '/backups';
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($backupDir);

    $stamp = date('Ymd-His');
    $zipPath = $updateDir . '/beta-' . $stamp . '.zip';
    $extractDir = $updateDir . '/beta-extract-' . $stamp;
    application_update_ensure_dir($extractDir);

    $archive = http_fetch(application_update_commit_zip_url($commitId), 60);
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write beta archive into cache/updates.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded beta archive could not be opened.');
    }
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded beta archive could not be extracted.');
    }
    $zip->close();

    $sourceRoot = application_update_extracted_root($extractDir);
    $backupPath = $backupDir . '/before-beta-' . $stamp . '.zip';
    $copied = application_update_copy_files($sourceRoot, $root, $backupPath);
    $migrations = run_migrations();
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
    $root = dirname(__DIR__);
    $branch = application_update_branch_candidates()[0] ?? '';
    if ($branch === '') {
        throw new RuntimeException('No stable release branch is configured.');
    }
    $updateDir = $root . '/cache/updates';
    $stamp = date('Ymd-His');
    $restoreDir = $updateDir . '/stable-restore-' . $stamp;
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($restoreDir);

    $archive = http_fetch(application_update_zip_url($branch), 60);
    $zipPath = $updateDir . '/stable-restore-' . $stamp . '.zip';
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write stable restore archive into cache/updates.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded stable restore archive could not be opened.');
    }
    if (!$zip->extractTo($restoreDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded stable restore archive could not be extracted.');
    }
    $zip->close();

    $sourceRoot = application_update_extracted_root($restoreDir);
    $copied = application_update_copy_files($sourceRoot, $root, $root . '/cache/updates/rollback-' . date('Ymd-His') . '.zip');
    application_update_invalidate_opcache($root, $sourceRoot);
    delete_app_settings([
        'application_update_channel',
        'application_update_beta_commit',
        'application_update_beta_backup_path',
        'application_update_check_cache',
    ]);

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

    $status = check_application_update();
    if (!empty($status['error'])) {
        throw new RuntimeException((string) $status['error']);
    }
    if (empty($status['update_available'])) {
        throw new RuntimeException('No newer version is available.');
    }

    $root = dirname(__DIR__);
    $updateDir = $root . '/cache/updates';
    $backupDir = $updateDir . '/backups';
    application_update_ensure_dir($updateDir);
    application_update_ensure_dir($backupDir);

    $stamp = date('Ymd-His');
    $zipPath = $updateDir . '/update-' . $stamp . '.zip';
    $extractDir = $updateDir . '/extract-' . $stamp;
    application_update_ensure_dir($extractDir);

    $archive = http_fetch(application_update_zip_url((string) $status['branch']), 60);
    if (file_put_contents($zipPath, $archive, LOCK_EX) === false) {
        throw new RuntimeException('Could not write update archive into cache/updates.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Downloaded update archive could not be opened.');
    }
    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        throw new RuntimeException('Downloaded update archive could not be extracted.');
    }
    $zip->close();

    $sourceRoot = application_update_extracted_root($extractDir);
    $backupPath = $backupDir . '/before-update-' . $stamp . '.zip';
    $copied = application_update_copy_files($sourceRoot, $root, $backupPath);
    $migrations = run_migrations();
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
    $versionCandidates = [];
    try {
        $bootstrap = http_fetch(application_update_raw_url($branch, 'app/bootstrap.php'), 12);
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
    $highestVersion = null;
    foreach ($versionCandidates as $version) {
        $normalizedVersion = application_update_normalize_version((string) $version);
        if ($normalizedVersion === null) {
            continue;
        }
        if ($highestVersion === null || version_compare($normalizedVersion, $highestVersion, '>')) {
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
    $labels = [];
    foreach ($versionCandidates as $source => $version) {
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
    $version = trim($version);
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
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed with status ' . $status . '.');
        }
        return (string) $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: PHP-Gallery-CMS/" . cms_current_version() . "\r\n"
                . "Cache-Control: no-cache\r\n"
                . "Pragma: no-cache\r\n",
        ],
    ]);
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
 * Find the single root directory produced by GitHub zip extraction.
 */
function application_update_extracted_root(string $extractDir): string
{
    $entries = array_values(array_filter(scandir($extractDir) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..'));
    foreach ($entries as $entry) {
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
    $backup = new ZipArchive();
    if ($backup->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create update backup archive.');
    }

    $copied = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            continue;
        }
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
        if (application_update_path_is_protected($relativePath)) {
            continue;
        }

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
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() || $item->isLink()) {
            continue;
        }
        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($sourceRoot) + 1));
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
    $bootstrap = (string) file_get_contents($bootstrapPath);
    return application_update_version_from_bootstrap($bootstrap);
}

/**
 * Keep local-only files and directories out of automated updates.
 */
function application_update_path_is_protected(string $relativePath): bool
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
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
