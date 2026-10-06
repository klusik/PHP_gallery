<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/release_file_policy.php
 * Module Type: Core Policy Module
 * Purpose: Read and validate the checked-in production file inventory for packaging and updates.
 * Responsibilities: Resolve fixed production paths, validate safe relative paths and compare staged trees.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core;

use RuntimeException;

/**
 * Return the installation root associated with a policy root.
 *
 * @param string $rootPath Absolute or relative application root.
 * @return string Canonical root directory.
 * @throws RuntimeException When the root does not exist as a directory.
 */
function release_file_policy_root(string $rootPath): string
{
    $resolved = realpath($rootPath);
    if ($resolved === false || !is_dir($resolved)) {
        throw new RuntimeException('Production file policy root is unavailable.');
    }
    return $resolved;
}

/**
 * Read and validate the static production file inventory.
 *
 * @param string $rootPath Application root containing app/production-files.json.
 * @return array{schema_version:int,production_files:list<string>,updater_files:list<string>,source_review_files:list<string>} Validated inventory.
 * @throws RuntimeException When the inventory is missing, malformed, unsafe, duplicated, or unsorted.
 */
function release_file_policy_read(string $rootPath): array
{
    $root = release_file_policy_root($rootPath);
    $manifestPath = $root . '/app/production-files.json';
    if (is_link($manifestPath) || !is_file($manifestPath) || !is_readable($manifestPath)
        || !release_file_policy_resolves_to_expected_path($root, 'app/production-files.json', $manifestPath)) {
        throw new RuntimeException('Production file inventory is missing or unsafe.');
    }

    $contents = file_get_contents($manifestPath);
    $manifest = $contents === false ? null : json_decode($contents, true);
    if (!is_array($manifest)
        || ($manifest['schema_version'] ?? null) !== 1
        || !isset($manifest['production_files'], $manifest['updater_files'], $manifest['source_review_files'])
        || !is_array($manifest['production_files'])
        || !is_array($manifest['updater_files'])
        || !is_array($manifest['source_review_files'])) {
        throw new RuntimeException('Production file inventory has an unsupported or invalid schema.');
    }

    $productionFiles = release_file_policy_validate_path_list($manifest['production_files']);
    $updaterFiles = release_file_policy_validate_path_list($manifest['updater_files']);
    $sourceReviewFiles = release_file_policy_validate_path_list($manifest['source_review_files']);
    $allPackagePaths = array_merge($productionFiles, $sourceReviewFiles);
    sort($allPackagePaths, SORT_STRING);
    release_file_policy_validate_path_list($allPackagePaths);
    $productionSet = array_fill_keys($productionFiles, true);
    $updaterSet = array_fill_keys($updaterFiles, true);
    foreach ($productionFiles as $path) {
        if (!release_file_policy_is_production_path($path)) {
            throw new RuntimeException('Production inventory contains a private or unapproved path.');
        }
    }
    foreach ($updaterFiles as $path) {
        if (!isset($productionSet[$path]) || !release_file_policy_is_updater_path($path)) {
            throw new RuntimeException('Updater inventory entries must be production package files.');
        }
    }
    if (!isset($productionSet['app/production-files.json']) || !isset($updaterSet['app/production-files.json'])) {
        throw new RuntimeException('The current package inventory must update its own sidecar.');
    }
    foreach ($sourceReviewFiles as $path) {
        if (isset($productionSet[$path]) || isset($updaterSet[$path])) {
            throw new RuntimeException('Production and source-review inventory entries must be disjoint.');
        }
        if (!str_starts_with($path, 'tests/')) {
            throw new RuntimeException('Source-review inventory entries must remain under tests/.');
        }
    }

    return [
        'schema_version' => 1,
        'production_files' => $productionFiles,
        'updater_files' => $updaterFiles,
        'source_review_files' => $sourceReviewFiles,
    ];
}

/**
 * Return whether the reviewed policy permits a path in a production package.
 *
 * @param string $relativePath Candidate project-relative path.
 * @return bool True when the path belongs to an approved product surface.
 */
function release_file_policy_is_production_path(string $relativePath): bool
{
    if (in_array($relativePath, release_file_policy_server_paths(), true)) {
        return true;
    }
    if (in_array($relativePath, [
        '.htaccess', 'index.php', 'install.php', 'reset.php', 'setup-gallery.php', 'config.example.php',
        'deploy.bat', 'deploy.sh', 'README.md', 'PATCH_NOTES.md', 'ARCHITECTURE.md', 'CODEMAP.md',
        'DATABASE.md', 'TESTING.md', 'LICENSE', 'CONTRIBUTING.md', 'ACCESSIBILITY.md', 'release-metadata.json',
    ], true)) {
        return true;
    }
    if (in_array($relativePath, ['config.php', 'app/bootstrap/config.php', 'public/assets/custom.css'], true)
        || str_starts_with($relativePath, 'app/_for_codex/')) {
        return false;
    }
    $segments = explode('/', $relativePath);
    foreach ($segments as $segment) {
        if (str_starts_with($segment, '.') && $segment !== '.htaccess') {
            return false;
        }
    }
    return str_starts_with($relativePath, 'app/')
        || str_starts_with($relativePath, 'public/')
        || str_starts_with($relativePath, 'scripts/')
        || str_starts_with($relativePath, 'docs/')
        || (str_starts_with($relativePath, 'database/migrations/'))
        || (str_starts_with($relativePath, 'custom_css/') && strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) === 'css');
}

/**
 * Validate path entries and require stable sorted unique ordering.
 *
 * @param array<int,mixed> $paths Raw path list from the JSON inventory.
 * @return list<string> Safe normalized relative paths.
 * @throws RuntimeException When a path is unsafe or the list is not sorted and unique.
 */
function release_file_policy_validate_path_list(array $paths): array
{
    $validated = [];
    $portableKeys = [];
    foreach ($paths as $path) {
        if (!is_string($path) || !release_file_policy_is_safe_relative_path($path)) {
            throw new RuntimeException('Production file inventory contains an unsafe path.');
        }
        $portableKey = release_file_policy_portable_path_key($path);
        if (isset($portableKeys[$portableKey])) {
            throw new RuntimeException('Production file inventory contains paths that collide on Windows.');
        }
        $portableKeys[$portableKey] = true;
        $validated[] = $path;
    }

    $sorted = $validated;
    sort($sorted, SORT_STRING);
    if ($validated !== $sorted || count($validated) !== count(array_unique($validated))) {
        throw new RuntimeException('Production file inventory must be sorted and contain no duplicates.');
    }

    return $validated;
}

/**
 * Return a Windows-compatible case-fold key for a UTF-8 relative path.
 *
 * @param string $relativePath Validated UTF-8 project-relative path.
 * @return string Case-folded key used to reject Windows aliases on every host.
 * @throws RuntimeException When non-ASCII case folding cannot be performed safely.
 */
function release_file_policy_portable_path_key(string $relativePath): string
{
    if (preg_match('/[^\x00-\x7F]/', $relativePath) !== 1) {
        return strtolower($relativePath);
    }
    if (!function_exists('mb_strtolower') || !function_exists('mb_check_encoding') || !mb_check_encoding($relativePath, 'UTF-8')) {
        throw new RuntimeException('Non-ASCII package paths require UTF-8 case-fold support.');
    }
    return strtolower(mb_strtolower($relativePath, 'UTF-8'));
}

/**
 * Return a path-comparison key that follows the active installation filesystem rules.
 *
 * @param string $relativePath Validated project-relative path.
 * @return string Exact key on case-sensitive hosts or Windows-compatible key on Windows.
 */
function release_file_policy_path_comparison_key(string $relativePath): string
{
    return DIRECTORY_SEPARATOR === '\\'
        ? release_file_policy_portable_path_key($relativePath)
        : $relativePath;
}

/**
 * Return whether a path is a safe canonical project-relative file name.
 *
 * @param string $relativePath Candidate relative path.
 * @return bool True when the path has no traversal, platform separator, or control characters.
 */
function release_file_policy_is_safe_relative_path(string $relativePath): bool
{
    if ($relativePath === ''
        || str_contains($relativePath, "\0")
        || str_contains($relativePath, "\r")
        || str_contains($relativePath, "\n")
        || str_contains($relativePath, '\\')
        || str_starts_with($relativePath, '/')
        || str_contains($relativePath, ':')) {
        return false;
    }

    foreach (explode('/', $relativePath) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            return false;
        }
        if ($segment !== rtrim($segment, " .")) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $segment) === 1) {
            return false;
        }
        $deviceStem = strtoupper(explode('.', $segment, 2)[0]);
        if (in_array($deviceStem, ['CON', 'PRN', 'AUX', 'NUL'], true)
            || preg_match('/^(COM|LPT)[1-9]$/', $deviceStem) === 1) {
            return false;
        }
    }

    return true;
}

/**
 * Return the sorted expected package paths for a named static profile.
 *
 * @param string $rootPath Application root containing the inventory.
 * @param string $profile Production, updater, or source-review package profile.
 * @param bool $includeMedia Whether to append files under the explicit galleries media root.
 * @return list<string> Sorted project-relative package paths.
 * @throws RuntimeException When the profile is invalid or media paths are unsafe.
 */
function release_file_policy_paths(string $rootPath, string $profile = 'production', bool $includeMedia = false): array
{
    $inventory = release_file_policy_read($rootPath);
    if ($profile === 'production') {
        $paths = $inventory['production_files'];
    } elseif ($profile === 'updater') {
        $paths = $inventory['updater_files'];
    } elseif ($profile === 'source-review') {
        $paths = array_merge($inventory['production_files'], $inventory['source_review_files']);
        sort($paths, SORT_STRING);
    } else {
        throw new RuntimeException('Unknown production file inventory profile.');
    }

    if ($includeMedia) {
        $mediaPaths = release_file_policy_media_paths($rootPath);
        $paths = array_values(array_unique(array_merge($paths, $mediaPaths)));
        sort($paths, SORT_STRING);
    }

    $paths = release_file_policy_validate_path_list($paths);

    release_file_policy_assert_paths_exist($rootPath, $paths);
    return $paths;
}

/**
 * Return the canonical exact Apache policy paths shipped with the application.
 *
 * @return list<string> Known project-relative server policy files.
 */
function release_file_policy_server_paths(): array
{
    return [
        '.htaccess', 'app/.htaccess', 'database/.htaccess', 'scripts/.htaccess',
        'public/.htaccess', 'galleries/.htaccess', 'cache/.htaccess', 'data/.htaccess',
        'logs/.htaccess', 'tmp/.htaccess', 'tests/.htaccess', 'deploy/.htaccess',
        'docs/.htaccess', '.github/.htaccess', '.agents/.htaccess', 'winapp/.htaccess',
        'data/admin-log-archives/.htaccess', 'data/gallery-trash/.htaccess',
    ];
}

/**
 * Return whether the canonical updater policy owns a project-relative file.
 *
 * @param string $relativePath Candidate project-relative path.
 * @return bool True when the updater owns replacement and prior-version removal for the path.
 */
function release_file_policy_is_updater_path(string $relativePath): bool
{
    if (in_array($relativePath, ['config.php', 'app/bootstrap/config.php', 'public/assets/custom.css'], true)
        || str_starts_with($relativePath, 'app/_for_codex/')) {
        return false;
    }
    if (in_array($relativePath, release_file_policy_server_paths(), true)) {
        return true;
    }
    foreach (['app/', 'public/', 'scripts/', 'database/migrations/'] as $prefix) {
        if (str_starts_with($relativePath, $prefix)) {
            return true;
        }
    }
    return in_array($relativePath, [
        'index.php', 'install.php', 'reset.php', 'setup-gallery.php', 'deploy.bat',
        'README.md', 'PATCH_NOTES.md', 'ARCHITECTURE.md', 'config.example.php',
    ], true);
}

/**
 * Return whether an updater or rollback operation must preserve installation-owned state.
 *
 * The explicit server guard allowlist takes precedence, so protected data/cache roots may
 * still restore their reviewed .htaccess files.
 *
 * @param string $relativePath Candidate project-relative path.
 * @return bool True when updater cleanup or rollback must preserve the path.
 */
function release_file_policy_is_protected_path(string $relativePath): bool
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (in_array($relativePath, release_file_policy_server_paths(), true)) {
        return false;
    }
    $portablePath = strtolower($relativePath);
    if (in_array($portablePath, [
        'config.php', 'app/bootstrap/config.php', 'public/assets/custom.css',
        '.user.ini', 'php.ini', 'robots.txt',
    ], true)) {
        return true;
    }
    foreach ([
        '.git', '.well-known', 'cache', 'data', 'galleries', 'logs', 'tmp', 'custom_css', '_for_codex',
        'app/_for_codex',
    ] as $directory) {
        $portableDirectory = strtolower($directory);
        if ($portablePath === $portableDirectory || str_starts_with($portablePath, $portableDirectory . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * Return whether a production path belongs in core-manifest.json integrity hashes.
 *
 * This preserves the original hash extension/root contract while applying the
 * reviewed static production inventory as the positive membership boundary.
 *
 * @param string $relativePath Candidate project-relative path.
 * @return bool True when the integrity manifest should hash the path.
 */
function release_file_policy_is_integrity_path(string $relativePath): bool
{
    $relativePath = str_replace('\\', '/', ltrim($relativePath, '/'));
    if ($relativePath === ''
        || $relativePath === 'app/core-manifest.json'
        || $relativePath === 'config.php'
        || $relativePath === 'app/bootstrap/config.php'
        || $relativePath === 'public/assets/custom.css'
        || str_starts_with($relativePath, 'app/_for_codex/')
        || preg_match('#^(cache|data|galleries|custom_css|\.git|\.idea|\.vscode)/#', $relativePath) === 1
        || preg_match('#(^|/)(\.DS_Store|Thumbs\.db|error_log)$#', $relativePath) === 1) {
        return false;
    }
    $rootFiles = [
        '.htaccess', 'index.php', 'install.php', 'reset.php', 'setup-gallery.php',
        'config.example.php', 'deploy.bat', 'README.md', 'PATCH_NOTES.md', 'ARCHITECTURE.md',
    ];
    if (in_array($relativePath, $rootFiles, true)) {
        return true;
    }
    if (!preg_match('#^(app|database|public|scripts)/#', $relativePath)) {
        return false;
    }
    $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
    return basename($relativePath) === '.htaccess'
        || in_array($extension, ['php', 'js', 'css', 'svg', 'md', 'bat', 'ps1', 'sh', 'json', 'htaccess'], true);
}

/**
 * Resolve incoming updater files from a current sidecar or a bounded legacy core manifest.
 *
 * Current archives must include every sidecar-listed updater path and may not introduce
 * unlisted files inside immutable updater roots. Older stable archives remain usable by
 * deriving their bounded activation list from core-manifest.json and known server guards.
 *
 * @param string $sourceRoot Extracted application source root.
 * @return array{files:list<string>,legacy:bool} Safe activation paths and compatibility mode.
 * @throws RuntimeException When the source inventory is malformed or unsafe.
 */
function release_file_policy_archive_updater_paths(string $sourceRoot): array
{
    $root = release_file_policy_root($sourceRoot);
    $sidecar = $root . '/app/production-files.json';
    if (is_file($sidecar) && !is_link($sidecar)) {
        $expected = release_file_policy_paths($root, 'updater');
        $ownedActual = release_file_policy_archive_owned_candidates($root);
        $unexpected = array_values(array_diff($ownedActual, $expected));
        if ($unexpected !== []) {
            throw new RuntimeException('Update archive contains unexpected updater-owned files.');
        }
        return ['files' => $expected, 'legacy' => false];
    }

    $manifestPath = $root . '/app/core-manifest.json';
    if (is_link($manifestPath) || !is_file($manifestPath) || !is_readable($manifestPath)) {
        throw new RuntimeException('Update archive has neither a current package inventory nor a legacy core manifest.');
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
        throw new RuntimeException('Legacy update archive core manifest is invalid.');
    }
    $files = [];
    foreach (array_keys($manifest['files']) as $path) {
        if (!is_string($path) || !release_file_policy_is_safe_relative_path($path)) {
            throw new RuntimeException('Legacy update archive core manifest contains an unsafe path.');
        }
        if (release_file_policy_is_updater_path($path)) {
            $files[] = $path;
        }
    }
    if ($files === []) {
        throw new RuntimeException('Legacy update archive core manifest contains no updater-owned paths.');
    }
    foreach (release_file_policy_server_paths() as $path) {
        if (is_file($root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path))) {
            $files[] = $path;
        }
    }
    if (is_file($root . '/app/core-manifest.json')) {
        $files[] = 'app/core-manifest.json';
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    $files = release_file_policy_validate_path_list($files);
    release_file_policy_assert_paths_exist($root, $files);
    if ($files === []) {
        throw new RuntimeException('Legacy update archive does not contain updater-managed files.');
    }
    return ['files' => $files, 'legacy' => true];
}

/**
 * Read paths known to belong to the currently installed updater generation.
 *
 * Invalid or absent inventories return an empty set so normal updates never infer
 * ownership of unknown installation files. Legacy ownership comes from core-manifest.
 *
 * @param string $installationRoot Active installation root.
 * @return list<string> Sorted previously managed paths, or an empty list if untrusted.
 */
function release_file_policy_prior_updater_paths(string $installationRoot): array
{
    $root = release_file_policy_root($installationRoot);
    $sidecar = $root . '/app/production-files.json';
    if (is_file($sidecar) && !is_link($sidecar)) {
        try {
            return release_file_policy_read($root)['updater_files'];
        } catch (RuntimeException $exception) {
            return [];
        }
    }
    $manifestPath = $root . '/app/core-manifest.json';
    if (is_link($manifestPath) || !is_file($manifestPath) || !is_readable($manifestPath)) {
        return [];
    }
    $manifest = json_decode((string) file_get_contents($manifestPath), true);
    if (!is_array($manifest) || !isset($manifest['files']) || !is_array($manifest['files'])) {
        return [];
    }
    $files = [];
    foreach (array_keys($manifest['files']) as $path) {
        if (is_string($path) && release_file_policy_is_safe_relative_path($path) && release_file_policy_is_updater_path($path)) {
            $files[] = $path;
        }
    }
    foreach (release_file_policy_server_paths() as $path) {
        $absolute = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($absolute) && !is_link($absolute)) {
            $files[] = $path;
        }
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    return release_file_policy_validate_path_list($files);
}

/**
 * List files in a local rollback snapshot using its server-written backup index.
 *
 * The snapshot is intentionally partial, unlike a release ZIP. Only actual files
 * named by its activation/obsolete checkpoint may be restored.
 *
 * @param string $snapshotRoot Directory containing the original rollback files.
 * @param list<string> $indexedPaths Trusted activation and obsolete paths from rollback metadata.
 * @return list<string> Sorted present snapshot files authorized by the trusted backup index.
 * @throws RuntimeException When the index or snapshot contains an unsafe or unindexed path.
 */
function release_file_policy_rollback_snapshot_paths(string $snapshotRoot, array $indexedPaths): array
{
    $root = release_file_policy_root($snapshotRoot);
    $allowedFiles = [];
    $allowedDirectories = [];
    foreach ($indexedPaths as $path) {
        if (!is_string($path) || !release_file_policy_is_safe_relative_path($path)
            || release_file_policy_is_protected_path($path)) {
            throw new RuntimeException('Rollback metadata contains an unsafe or protected path.');
        }
        $key = release_file_policy_path_comparison_key($path);
        $absolute = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_link($absolute)) {
            throw new RuntimeException('Rollback metadata points through a symbolic link.');
        }
        if (file_exists($absolute)) {
            if (!release_file_policy_resolves_to_expected_path($root, $path, $absolute)) {
                throw new RuntimeException('Rollback metadata points through a redirected directory.');
            }
            if (is_dir($absolute)) {
                $allowedDirectories[$key] = true;
            } elseif (is_file($absolute)) {
                $allowedFiles[$key] = true;
            } else {
                throw new RuntimeException('Rollback metadata points to a non-regular path.');
            }
        }
    }

    $files = release_file_policy_enumerate_files($root);
    foreach ($files as $path) {
        if (release_file_policy_is_protected_path($path)) {
            throw new RuntimeException('Rollback snapshot contains an installation-owned or protected path.');
        }
        $key = release_file_policy_path_comparison_key($path);
        $indexed = isset($allowedFiles[$key]);
        $segments = explode('/', $path);
        array_pop($segments);
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix . '/' . $segment;
            if (isset($allowedDirectories[release_file_policy_path_comparison_key($prefix)])) {
                $indexed = true;
                break;
            }
        }
        if (!$indexed) {
            throw new RuntimeException('Rollback snapshot contains a path absent from its trusted backup index.');
        }
    }
    return $files;
}

/**
 * Enumerate files inside immutable updater roots and exact updater-owned root entries.
 *
 * @param string $root Canonical source root.
 * @return list<string> Sorted archive paths subject to the static updater inventory.
 * @throws RuntimeException When a managed source subtree contains an unsafe path.
 */
function release_file_policy_archive_owned_candidates(string $root): array
{
    $files = [];
    foreach (['app', 'public', 'scripts', 'database/migrations'] as $directory) {
        $absolute = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (!file_exists($absolute) && !is_link($absolute)) {
            continue;
        }
        if (is_link($absolute) || !is_dir($absolute)) {
            throw new RuntimeException('Update archive contains an unsafe updater root.');
        }
        foreach (release_file_policy_enumerate_files($root, $directory) as $path) {
            if ($path === 'public/assets/custom.css'
                || str_starts_with($path, 'app/_for_codex/')
                || in_array(basename($path), ['.DS_Store', 'Thumbs.db'], true)) {
                continue;
            }
            $files[] = $path;
        }
    }
    foreach (['index.php', 'install.php', 'reset.php', 'setup-gallery.php', 'deploy.bat', 'README.md', 'PATCH_NOTES.md', 'ARCHITECTURE.md', 'config.example.php'] as $path) {
        $absolute = $root . '/' . $path;
        if (is_link($absolute)) {
            throw new RuntimeException('Update archive contains an unsafe updater root file.');
        }
        if (is_file($absolute)) {
            $files[] = $path;
        }
    }
    foreach (release_file_policy_server_paths() as $path) {
        $absolute = $root . '/' . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_link($absolute)) {
            throw new RuntimeException('Update archive contains an unsafe server policy file.');
        }
        if (is_file($absolute)) {
            $files[] = $path;
        }
    }
    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    return release_file_policy_validate_path_list($files);
}

/**
 * Return regular media files beneath galleries/ when the caller explicitly opted in.
 *
 * @param string $rootPath Application root containing galleries/.
 * @return list<string> Sorted gallery-relative package paths.
 * @throws RuntimeException When the media root or any descendant is unsafe.
 */
function release_file_policy_media_paths(string $rootPath): array
{
    $root = release_file_policy_root($rootPath);
    $mediaRoot = $root . '/galleries';
    if (!file_exists($mediaRoot) && !is_link($mediaRoot)) {
        return [];
    }
    if (is_link($mediaRoot) || !is_dir($mediaRoot)) {
        throw new RuntimeException('The optional media root is unsafe.');
    }

    $paths = [];
    foreach (release_file_policy_enumerate_files($root, 'galleries') as $relative) {
        if (in_array(basename($relative), ['.DS_Store', 'Thumbs.db'], true)) {
            continue;
        }
        $paths[] = $relative;
    }

    sort($paths, SORT_STRING);
    return release_file_policy_validate_path_list($paths);
}

/**
 * Enumerate files below one validated project directory without following links or junctions.
 *
 * @param string $rootPath Canonical project root.
 * @param string $relativeDirectory Project-relative directory, or empty for the full root.
 * @return list<string> Sorted project-relative regular-file paths.
 * @throws RuntimeException When traversal finds links, path aliases, special files, or unsafe names.
 */
function release_file_policy_enumerate_files(string $rootPath, string $relativeDirectory = ''): array
{
    $root = release_file_policy_root($rootPath);
    if ($relativeDirectory !== '' && !release_file_policy_is_safe_relative_path($relativeDirectory)) {
        throw new RuntimeException('The package traversal directory is unsafe.');
    }

    $start = $relativeDirectory === ''
        ? $root
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
    if (is_link($start) || !is_dir($start) || !release_file_policy_resolves_to_expected_path($root, $relativeDirectory, $start)) {
        throw new RuntimeException('The package traversal directory is missing or unsafe.');
    }

    $pending = [[$start, $relativeDirectory]];
    $files = [];
    while ($pending !== []) {
        [$directory, $relativeParent] = array_pop($pending);
        $entries = scandir($directory);
        if (!is_array($entries)) {
            throw new RuntimeException('Could not enumerate the package tree.');
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $relative = $relativeParent === '' ? $entry : $relativeParent . '/' . $entry;
            if (!release_file_policy_is_safe_relative_path($relative)) {
                throw new RuntimeException('The package tree contains an unsafe path.');
            }
            $absolute = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_link($absolute) || !release_file_policy_resolves_to_expected_path($root, $relative, $absolute)) {
                throw new RuntimeException('The package tree contains a symbolic link or redirected directory.');
            }
            if (is_dir($absolute)) {
                $pending[] = [$absolute, $relative];
                continue;
            }
            if (!is_file($absolute)) {
                throw new RuntimeException('The package tree contains a non-regular filesystem entry.');
            }
            $files[] = $relative;
        }
    }

    sort($files, SORT_STRING);
    return release_file_policy_validate_path_list($files);
}

/**
 * Confirm an existing filesystem path resolves to its exact project-relative location.
 *
 * @param string $root Canonical project root.
 * @param string $relativePath Project-relative path, or empty for the root itself.
 * @param string $absolutePath Existing path to inspect.
 * @return bool True when realpath matches the expected path and remains below the root.
 */
function release_file_policy_resolves_to_expected_path(string $root, string $relativePath, string $absolutePath): bool
{
    $resolved = realpath($absolutePath);
    if ($resolved === false) {
        return false;
    }
    $expected = $relativePath === ''
        ? $root
        : $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (DIRECTORY_SEPARATOR === '\\') {
        $matches = strcasecmp($resolved, $expected) === 0;
        $contained = $relativePath === '' || strncasecmp($resolved, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0;
    } else {
        $matches = $resolved === $expected;
        $contained = $relativePath === '' || str_starts_with($resolved, $root . DIRECTORY_SEPARATOR);
    }
    return $matches && $contained;
}

/**
 * Confirm every expected path exists as a regular file without traversing symlinks.
 *
 * @param string $rootPath Root containing the files.
 * @param list<string> $paths Expected relative file paths.
 * @return void No value is returned when every path is present and safe.
 * @throws RuntimeException When a path is missing, unreadable, linked, or escapes the root.
 */
function release_file_policy_assert_paths_exist(string $rootPath, array $paths): void
{
    $root = release_file_policy_root($rootPath);
    foreach ($paths as $relativePath) {
        if (!release_file_policy_is_safe_relative_path($relativePath)) {
            throw new RuntimeException('Production file inventory contains an unsafe path.');
        }

        $candidate = $root;
        $segments = explode('/', $relativePath);
        foreach ($segments as $index => $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($candidate)) {
                throw new RuntimeException('A production file path contains a symbolic link.');
            }
            if ($index < count($segments) - 1 && !is_dir($candidate)) {
                throw new RuntimeException('A required production file parent directory is missing.');
            }
        }

        if (!is_file($candidate) || !is_readable($candidate)) {
            throw new RuntimeException('A required production file is missing or unreadable.');
        }
        if (!release_file_policy_resolves_to_expected_path($root, $relativePath, $candidate)) {
            throw new RuntimeException('A production file path resolves outside the project root.');
        }
    }
}

/**
 * Compare a staged package tree against the exact static policy profile.
 *
 * @param string $stageRoot Directory containing a completed package.
 * @param string $sourceRoot Source checkout used only to resolve an explicit media opt-in.
 * @param string $profile Production, updater, or source-review package profile.
 * @param bool $includeMedia Whether explicitly selected source galleries are part of the package.
 * @return array{expected_count:int,actual_count:int,unexpected:list<string>,missing:list<string>} Exact comparison result.
 * @throws RuntimeException When a staged path is unsafe or cannot be enumerated.
 */
function release_file_policy_verify_tree(
    string $stageRoot,
    string $sourceRoot,
    string $profile = 'production',
    bool $includeMedia = false
): array {
    $root = release_file_policy_root($stageRoot);
    $expected = release_file_policy_paths($sourceRoot, $profile, $includeMedia);
    $actual = release_file_policy_enumerate_files($root);
    $unexpected = array_values(array_diff($actual, $expected));
    $missing = array_values(array_diff($expected, $actual));

    return [
        'expected_count' => count($expected),
        'actual_count' => count($actual),
        'unexpected' => $unexpected,
        'missing' => $missing,
    ];
}
