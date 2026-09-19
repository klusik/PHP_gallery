<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/legacy_download_cache_health_test.php
 *
 * Purpose:
 *   Protects the explicit legacy server ZIP cache capability probe.
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap/configuration.php';
require_once dirname(__DIR__) . '/app/models/downloads.php';
require_once dirname(__DIR__) . '/app/services/downloads.php';
require_once dirname(__DIR__) . '/app/services/download_artifact_cache.php';

use function Gallery\Services\legacy_download_artifact_cache_status_for_path;
use function Gallery\Services\legacy_download_artifact_health_exception_reason;

/** Fail this deterministic fixture with one readable assertion. */
function legacy_cache_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

/** Remove one fixture directory tree without following links. */
function legacy_cache_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($path);
}

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-gallery-legacy-cache-health-' . bin2hex(random_bytes(6));
@mkdir($fixtureRoot, 0775, true);

try {
    $missingCache = $fixtureRoot . DIRECTORY_SEPARATOR . 'missing-cache';
    $healthy = legacy_download_artifact_cache_status_for_path($missingCache, static fn(string $path): int => 987654321);
    legacy_cache_expect(!empty($healthy['legacy_server_build_capable']), 'A missing cache directory that can be created must become capable.');
    legacy_cache_expect(($healthy['reason'] ?? '') === 'ok', 'Healthy cache probe must return reason=ok when free space is known.');
    legacy_cache_expect(is_dir($missingCache), 'Health probe must create the configured cache directory when possible.');
    legacy_cache_expect(is_dir((string) ($healthy['artifact_root'] ?? '')), 'Health probe must create the managed artifact root.');
    legacy_cache_expect(is_dir((string) ($healthy['state_dir'] ?? '')), 'Health probe must create the artifact state directory.');
    legacy_cache_expect(is_dir((string) ($healthy['coordination_dir'] ?? '')), 'Health probe must create the coordination directory.');
    legacy_cache_expect(($healthy['free_bytes'] ?? null) === 987654321, 'Health probe must expose known free bytes.');

    $unknownFree = legacy_download_artifact_cache_status_for_path(
        $fixtureRoot . DIRECTORY_SEPARATOR . 'unknown-free',
        static fn(string $path): false => false
    );
    legacy_cache_expect(!empty($unknownFree['legacy_server_build_capable']), 'Unavailable free-space telemetry alone must not disable the legacy fallback.');
    legacy_cache_expect(empty($unknownFree['free_space_known']), 'Unavailable free-space telemetry must be marked unknown.');
    legacy_cache_expect(($unknownFree['reason'] ?? '') === 'ok_free_space_unknown', 'Unknown free-space state must have a machine-readable reason.');

    $filePath = $fixtureRoot . DIRECTORY_SEPARATOR . 'configured-as-file';
    file_put_contents($filePath, 'not a directory');
    $fileStatus = legacy_download_artifact_cache_status_for_path($filePath);
    legacy_cache_expect(empty($fileStatus['legacy_server_build_capable']), 'A configured cache path that is a file must be unavailable.');
    legacy_cache_expect(($fileStatus['reason'] ?? '') === 'configured_path_is_file', 'File-path failure must expose the stable reason code.');

    $emptyStatus = legacy_download_artifact_cache_status_for_path('');
    legacy_cache_expect(empty($emptyStatus['legacy_server_build_capable']), 'An empty cache configuration must be unavailable.');
    legacy_cache_expect(($emptyStatus['reason'] ?? '') === 'path_not_configured', 'Missing configuration must expose the stable reason code.');

    $readOnly = $fixtureRoot . DIRECTORY_SEPARATOR . 'read-only';
    @mkdir($readOnly, 0555, true);
    @chmod($readOnly, 0555);
    if (!is_writable($readOnly)) {
        $readOnlyStatus = legacy_download_artifact_cache_status_for_path($readOnly);
        legacy_cache_expect(empty($readOnlyStatus['legacy_server_build_capable']), 'A non-writable cache directory must be unavailable when permissions can be simulated.');
        legacy_cache_expect(($readOnlyStatus['reason'] ?? '') === 'cache_not_writable', 'Non-writable cache must expose the stable reason code.');
    }
    @chmod($readOnly, 0775);

    legacy_cache_expect(legacy_download_artifact_health_exception_reason('configured_path_is_file') === 'legacy_cache_configured_path_is_file', 'Health reasons must map to bounded exception reasons.');
} finally {
    legacy_cache_remove_tree($fixtureRoot);
}

echo "legacy_download_cache_health_test: ok\n";
