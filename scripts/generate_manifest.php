<?php

declare(strict_types=1);

/**
 * Generate the core integrity manifest for a PHP Gallery release.
 *
 * Run this script from the project root or from the scripts directory:
 * php scripts/generate_manifest.php
 * php scripts/generate_manifest.php --version=0.45
 */

/**
 * Return the project root directory.
 */
function manifest_root_path(): string
{
    return dirname(__DIR__);
}

/**
 * Return true when a command-line flag was provided.
 */
function manifest_has_flag(string $flag): bool
{
    global $argv;

    foreach ($argv as $argument) {
        if ($argument === $flag) {
            return true;
        }
    }

    return false;
}

/**
 * Return a command-line option value or null when the option was not provided.
 */
function manifest_option_value(string $name): ?string
{
    global $argv;

    $prefix = $name . '=';
    foreach ($argv as $argument) {
        if (str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }

    return null;
}

/**
 * Return the current CMS version from app/bootstrap.php.
 */
function manifest_detect_version(string $rootPath): string
{
    $bootstrapPath = $rootPath . '/app/bootstrap.php';
    if (!is_file($bootstrapPath)) {
        return '';
    }

    $bootstrap = file_get_contents($bootstrapPath);
    if ($bootstrap === false) {
        return '';
    }

    if (preg_match("/const\s+CMS_VERSION\s*=\s*['\"]([^'\"]+)['\"]\s*;/i", $bootstrap, $match) === 1) {
        return (string) $match[1];
    }

    return '';
}

/**
 * Return paths that are part of the immutable release surface.
 */
function manifest_allowed_roots(): array
{
    return [
        'app',
        'database',
        'public',
        'scripts',
    ];
}

/**
 * Return root-level files that are part of the immutable release surface.
 */
function manifest_allowed_root_files(): array
{
    return [
        '.htaccess' => true,
        'index.php' => true,
        'install.php' => true,
        'reset.php' => true,
        'deploy.bat' => true,
        'README.md' => true,
        'PATCH_NOTES.md' => true,
        'ARCHITECTURE.md' => true,
    ];
}

/**
 * Return file extensions that are part of the immutable release surface.
 */
function manifest_allowed_extensions(): array
{
    return [
        'php' => true,
        'js' => true,
        'css' => true,
        'md' => true,
        'bat' => true,
        'ps1' => true,
        'htaccess' => true,
    ];
}

/**
 * Return paths that should never be written into the manifest.
 */
function manifest_ignored_patterns(): array
{
    return [
        '#^app/core-manifest\.json$#',
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
 * Return true when a relative path should be ignored by the manifest generator.
 */
function manifest_is_ignored_path(string $relativePath): bool
{
    $normalizedPath = str_replace('\\', '/', ltrim($relativePath, '/'));
    foreach (manifest_ignored_patterns() as $pattern) {
        if (preg_match($pattern, $normalizedPath) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Return true when a path is part of the immutable release surface.
 */
function manifest_is_core_like_path(string $relativePath): bool
{
    $normalizedPath = str_replace('\\', '/', ltrim($relativePath, '/'));
    if ($normalizedPath === '' || manifest_is_ignored_path($normalizedPath)) {
        return false;
    }

    $allowedRootFiles = manifest_allowed_root_files();
    if (isset($allowedRootFiles[$normalizedPath])) {
        return true;
    }

    $firstSegment = explode('/', $normalizedPath, 2)[0];
    if (!in_array($firstSegment, manifest_allowed_roots(), true)) {
        return false;
    }

    $extension = strtolower(pathinfo($normalizedPath, PATHINFO_EXTENSION));
    $basename = basename($normalizedPath);
    $allowedExtensions = manifest_allowed_extensions();

    return $basename === '.htaccess' || isset($allowedExtensions[$extension]);
}

/**
 * Return a sorted list of files that should be written into the manifest.
 */
function manifest_discover_files(string $rootPath): array
{
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
        if (!manifest_is_core_like_path($relativePath)) {
            continue;
        }

        $files[] = $relativePath;
    }

    sort($files, SORT_STRING);
    return $files;
}

/**
 * Calculate the same normalized hash that the runtime integrity checker uses.
 */
function manifest_hash_file(string $absolutePath): string
{
    $contents = file_get_contents($absolutePath);
    if ($contents === false) {
        throw new RuntimeException('Unable to read file: ' . $absolutePath);
    }

    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    }

    $contents = str_replace(["\r\n", "\r"], "\n", $contents);
    return 'sha256:' . hash('sha256', $contents);
}

/**
 * Print CLI usage information.
 */
function manifest_print_usage(): void
{
    echo "Usage: php scripts/generate_manifest.php [--version=0.45] [--check]\n";
    echo "\n";
    echo "Options:\n";
    echo "  --version=VERSION  Override the version detected from app/bootstrap.php.\n";
    echo "  --check            Exit with code 1 if the generated manifest differs.\n";
}

if (manifest_has_flag('--help') || manifest_has_flag('-h')) {
    manifest_print_usage();
    exit(0);
}

$rootPath = manifest_root_path();
$version = manifest_option_value('--version') ?? manifest_detect_version($rootPath);
if ($version === '') {
    fwrite(STDERR, "Unable to detect CMS version. Use --version=VERSION.\n");
    exit(1);
}

$files = manifest_discover_files($rootPath);
$manifest = [
    'version' => $version,
    'generated_at' => gmdate('c'),
    'algorithm' => 'sha256',
    'hash_mode' => 'normalized-text-sha256',
    'files' => [],
];

foreach ($files as $relativePath) {
    $manifest['files'][$relativePath] = manifest_hash_file($rootPath . '/' . $relativePath);
}

$outputPath = $rootPath . '/app/core-manifest.json';
$output = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if ($output === false) {
    fwrite(STDERR, "Unable to encode manifest JSON.\n");
    exit(1);
}

if (manifest_has_flag('--check')) {
    $currentJson = is_file($outputPath) ? file_get_contents($outputPath) : '';
    $currentManifest = $currentJson === false ? null : json_decode($currentJson, true);
    if (!is_array($currentManifest)) {
        fwrite(STDERR, "Manifest is missing or invalid. Run php scripts/generate_manifest.php.\n");
        exit(1);
    }

    $currentComparable = [
        'version' => (string) ($currentManifest['version'] ?? ''),
        'algorithm' => (string) ($currentManifest['algorithm'] ?? ''),
        'hash_mode' => (string) ($currentManifest['hash_mode'] ?? ''),
        'files' => $currentManifest['files'] ?? [],
    ];
    $generatedComparable = [
        'version' => $manifest['version'],
        'algorithm' => $manifest['algorithm'],
        'hash_mode' => $manifest['hash_mode'],
        'files' => $manifest['files'],
    ];

    if ($currentComparable !== $generatedComparable) {
        fwrite(STDERR, "Manifest is not current. Run php scripts/generate_manifest.php.\n");
        exit(1);
    }

    echo "Manifest is current: " . count($manifest['files']) . " files.\n";
    exit(0);
}

if (file_put_contents($outputPath, $output, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write manifest: $outputPath\n");
    exit(1);
}

echo "Manifest generated for version $version: " . count($manifest['files']) . " files.\n";
