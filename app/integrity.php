<?php

declare(strict_types=1);

const CMS_INTEGRITY_CACHE_TTL = 86400;

/**
 * Return the application root directory used by the integrity checker.
 */
function integrity_root_path(): string
{
    return dirname(__DIR__);
}

/**
 * Return the expected core manifest path.
 */
function integrity_manifest_path(): string
{
    return __DIR__ . '/core-manifest.json';
}

/**
 * Return the cached integrity status path.
 */
function integrity_cache_path(): string
{
    return integrity_root_path() . '/cache/integrity-status.json';
}

/**
 * Return paths that should never be reported as unknown installation files.
 */
function integrity_ignored_unknown_patterns(): array
{
    return [
        '#^cache/#',
        '#^data/#',
        '#^galleries/#',
        '#^custom_css/#',
        '#^config\.php$#',
        '#^config\.example\.php$#',
        '#^\.git/#',
        '#^\.idea/#',
        '#^\.vscode/#',
        '#^public/assets/custom\.css$#',
        '#(^|/)\.DS_Store$#',
        '#(^|/)Thumbs\.db$#',
        '#(^|/)error_log$#',
    ];
}

/**
 * Return true when a relative path should be ignored as an unknown extra file.
 */
function integrity_is_ignored_unknown_path(string $relativePath): bool
{
    $normalizedPath = str_replace('\\', '/', ltrim($relativePath, '/'));
    foreach (integrity_ignored_unknown_patterns() as $pattern) {
        if (preg_match($pattern, $normalizedPath) === 1) {
            return true;
        }
    }
    return false;
}


/**
 * Calculate a stable SHA-256 hash for text-like core files.
 *
 * Shared hosting deployments and FTP clients may convert CRLF line endings to LF.
 * The integrity checker intentionally normalizes line endings so equivalent text files
 * do not appear modified only because they were deployed from Windows to Linux.
 */
function integrity_hash_file(string $absolutePath): string
{
    $contents = file_get_contents($absolutePath);
    if ($contents === false) {
        return '';
    }

    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    }

    $contents = str_replace(["\r\n", "\r"], "\n", $contents);
    return 'sha256:' . hash('sha256', $contents);
}

/**
 * Return a fingerprint of the manifest content for cache invalidation.
 */
function integrity_manifest_fingerprint(): string
{
    $manifestPath = integrity_manifest_path();
    if (!is_file($manifestPath)) {
        return '';
    }

    $contents = file_get_contents($manifestPath);
    if ($contents === false) {
        return '';
    }

    return hash('sha256', $contents);
}

/**
 * Load the core manifest from disk.
 */
function integrity_load_manifest(): array
{
    $manifestPath = integrity_manifest_path();
    if (!is_file($manifestPath)) {
        return [
            'ok' => false,
            'error' => 'Core manifest is missing.',
            'manifest' => [],
        ];
    }

    $manifestJson = file_get_contents($manifestPath);
    if ($manifestJson === false || trim($manifestJson) === '') {
        return [
            'ok' => false,
            'error' => 'Core manifest is empty or unreadable.',
            'manifest' => [],
        ];
    }

    $manifest = json_decode($manifestJson, true);
    if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
        return [
            'ok' => false,
            'error' => 'Core manifest is invalid JSON or does not contain a files object.',
            'manifest' => [],
        ];
    }

    return [
        'ok' => true,
        'error' => '',
        'manifest' => $manifest,
    ];
}

/**
 * Return a sorted list of core-like files currently present in the installation.
 */
function integrity_discover_core_like_files(): array
{
    $rootPath = integrity_root_path();
    $allowedRoots = [
        'app',
        'database',
        'public',
        'scripts',
    ];
    $allowedRootFiles = [
        '.htaccess' => true,
        'index.php' => true,
        'install.php' => true,
        'reset.php' => true,
        'deploy.bat' => true,
        'README.md' => true,
        'PATCH_NOTES.md' => true,
        'ARCHITECTURE.md' => true,
    ];
    $allowedExtensions = [
        'php' => true,
        'js' => true,
        'css' => true,
        'md' => true,
        'bat' => true,
        'ps1' => true,
        'htaccess' => true,
    ];

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        $absolutePath = str_replace('\\', '/', $fileInfo->getPathname());
        $relativePath = ltrim(substr($absolutePath, strlen(str_replace('\\', '/', $rootPath))), '/');
        if ($relativePath === '') {
            continue;
        }

        $firstSegment = explode('/', $relativePath, 2)[0];
        if (isset($allowedRootFiles[$relativePath])) {
            $files[] = $relativePath;
            continue;
        }

        if (!in_array($firstSegment, $allowedRoots, true)) {
            continue;
        }

        if (integrity_is_ignored_unknown_path($relativePath)) {
            continue;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $basename = basename($relativePath);
        if ($basename === '.htaccess' || isset($allowedExtensions[$extension])) {
            $files[] = $relativePath;
        }
    }

    sort($files, SORT_STRING);
    return $files;
}

/**
 * Calculate the current integrity status against the manifest.
 */
function integrity_calculate_status(): array
{
    $loadedManifest = integrity_load_manifest();
    $checkedAt = time();
    $status = [
        'checked_at' => $checkedAt,
        'checked_at_iso' => gmdate('c', $checkedAt),
        'status' => 'ok',
        'version' => '',
        'hash_mode' => 'normalized-text-sha256',
        'manifest_fingerprint' => integrity_manifest_fingerprint(),
        'manifest_error' => '',
        'modified' => [],
        'missing' => [],
        'unknown' => [],
        'ignored_unknown_count' => 0,
    ];

    if (!$loadedManifest['ok']) {
        $status['status'] = 'error';
        $status['manifest_error'] = (string) $loadedManifest['error'];
        return $status;
    }

    $manifest = $loadedManifest['manifest'];
    $status['version'] = (string) ($manifest['version'] ?? '');
    $manifestFiles = $manifest['files'];
    $rootPath = integrity_root_path();

    foreach ($manifestFiles as $relativePath => $expectedHash) {
        $normalizedPath = str_replace('\\', '/', ltrim((string) $relativePath, '/'));
        $absolutePath = $rootPath . '/' . $normalizedPath;
        if (!is_file($absolutePath)) {
            $status['missing'][] = $normalizedPath;
            continue;
        }

        $actualHash = integrity_hash_file($absolutePath);
        if (!hash_equals((string) $expectedHash, $actualHash)) {
            $status['modified'][] = $normalizedPath;
        }
    }

    $manifestPathSet = array_fill_keys(array_map(
        static fn (string $path): string => str_replace('\\', '/', ltrim($path, '/')),
        array_keys($manifestFiles)
    ), true);

    foreach (integrity_discover_core_like_files() as $relativePath) {
        if (isset($manifestPathSet[$relativePath])) {
            continue;
        }
        if ($relativePath === 'app/core-manifest.json') {
            continue;
        }
        if (integrity_is_ignored_unknown_path($relativePath)) {
            $status['ignored_unknown_count']++;
            continue;
        }
        $status['unknown'][] = $relativePath;
    }

    if ($status['missing'] || $status['modified']) {
        $status['status'] = 'modified';
    } elseif ($status['unknown']) {
        $status['status'] = 'warning';
    }

    return $status;
}

/**
 * Save the integrity status cache if the cache directory is writable.
 */
function integrity_write_cached_status(array $status): void
{
    $cachePath = integrity_cache_path();
    $cacheDir = dirname($cachePath);
    if (!is_dir($cacheDir)) {
        return;
    }

    @file_put_contents(
        $cachePath,
        json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        LOCK_EX
    );
}

/**
 * Return the cached integrity status or calculate a fresh status when needed.
 */
function integrity_status(bool $forceRefresh = false): array
{
    $cachePath = integrity_cache_path();
    $currentManifestFingerprint = integrity_manifest_fingerprint();
    if (!$forceRefresh && is_file($cachePath)) {
        $cacheJson = file_get_contents($cachePath);
        $cachedStatus = $cacheJson === false ? null : json_decode($cacheJson, true);
        if (is_array($cachedStatus) && isset($cachedStatus['checked_at'])) {
            $age = time() - (int) $cachedStatus['checked_at'];
            $cachedManifestFingerprint = (string) ($cachedStatus['manifest_fingerprint'] ?? '');
            $manifestMatchesCache = $currentManifestFingerprint !== ''
                && hash_equals($currentManifestFingerprint, $cachedManifestFingerprint);

            if ($manifestMatchesCache && $age >= 0 && $age < CMS_INTEGRITY_CACHE_TTL) {
                return $cachedStatus;
            }
        }
    }

    $status = integrity_calculate_status();
    integrity_write_cached_status($status);
    return $status;
}

/**
 * Return the human readable label for an integrity status code.
 */
function integrity_status_label(string $status): string
{
    return match ($status) {
        'ok' => 'OK',
        'warning' => 'Warning',
        'modified' => 'Modified core files',
        'error' => 'Integrity check error',
        default => 'Unknown',
    };
}
