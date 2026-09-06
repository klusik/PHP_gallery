<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/versions.php
 * Module Type: Service
 *
 * Purpose:
 *   Protocol version compatibility, timeouts, and instance identity.
 *
 * Responsibilities:
 *   - Decide whether a source and target instance may exchange a migration
 *   - Resolve request and transfer timeouts from configuration
 *   - Expose the stable local instance identifier and translated strings
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
 *   - Path note: this file lives one directory deeper than the module entry file,
 *     so project-root paths must use dirname(__DIR__, 3), not dirname(__DIR__, 2).
 *   - Loaded by app/services/gallery_migration.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/gallery_migration.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use CURLFile;
use RuntimeException;
use Throwable;
use ZipArchive;
use const Gallery\Core\CMS_VERSION;
use function Gallery\Controllers\admin_edit_gallery_tab_url;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;
use function Gallery\Core\unique_slug;

/**
 * Return a translated migration message while allowing isolated tests to run.
 *
 * @param string $key Translation key.
 * @param string $fallback English fallback text.
 * @param array $parameters Parameters value.
 * @return string Resolved text.
 */
function gallery_migration_t(string $key, string $fallback, array $parameters = []): string
{
    if (function_exists('Gallery\\Services\\t')) {
        return t($key, $fallback, $parameters);
    }

    foreach ($parameters as $name => $value) {
        $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
    }
    return $fallback;
}

/**
 * Return compatibility details for a source and target version pair.
 *
 * @param string $sourceVersion Source version value.
 * @param string $targetVersion Target version value.
 * @return array{ok:bool,source_version:string,target_version:string,policy:string,message:string} Structured result data for the caller.
 */
function gallery_migration_compatibility_result(string $sourceVersion, string $targetVersion): array
{
    $sourceVersion = trim($sourceVersion);
    $targetVersion = trim($targetVersion);
    $ok = $sourceVersion !== '' && $targetVersion !== '' && $sourceVersion === $targetVersion;

    return [
        'ok' => $ok,
        'source_version' => $sourceVersion,
        'target_version' => $targetVersion,
        'policy' => 'exact',
        'message' => $ok
            ? gallery_migration_t('gallery_migration.compatibility_ok', 'Source and target versions match.')
            : gallery_migration_t(
                'gallery_migration.compatibility_failed',
                'Migration requires identical PHP Gallery versions for now. Source: {source}. Target: {target}.',
                ['source' => $sourceVersion !== '' ? $sourceVersion : 'unknown', 'target' => $targetVersion !== '' ? $targetVersion : 'unknown']
            ),
    ];
}

/**
 * Return true when a source version can migrate into a target version.
 *
 * @param string $sourceVersion Source version value.
 * @param string $targetVersion Target version value.
 * @return bool True when the condition matches.
 */
function gallery_migration_versions_compatible(string $sourceVersion, string $targetVersion): bool
{
    return gallery_migration_compatibility_result($sourceVersion, $targetVersion)['ok'];
}

/**
 * Return the app version sent in migration manifests and API responses.
 *
 * @return string Text result for the caller.
 */
function gallery_migration_current_version(): string
{
    return function_exists('Gallery\\Core\\cms_current_version') ? cms_current_version() : (defined('Gallery\\Core\\CMS_VERSION') ? CMS_VERSION : '');
}

/**
 * Clamp a reconnect or HTTP timeout value to a safe server-side range.
 *
 * @param ?int $seconds Seconds value.
 * @return int Integer result for the caller.
 */
function gallery_migration_timeout_seconds(?int $seconds = null): int
{
    if ($seconds === null || $seconds <= 0) {
        return GALLERY_MIGRATION_RECONNECT_SECONDS;
    }

    return max(5, min(300, $seconds));
}

/**
 * Read the admin-selected reconnect interval from the current request.
 *
 * @return int Integer result for the caller.
 */
function gallery_migration_request_timeout_seconds(): int
{
    return gallery_migration_timeout_seconds((int) ($_POST['reconnect_seconds'] ?? GALLERY_MIGRATION_RECONNECT_SECONDS));
}

/**
 * Return a per-install source identifier that does not expose secrets.
 *
 * @return string Text result for the caller.
 */
function gallery_migration_instance_id(): string
{
    $base = '';
    try {
        $base = (string) (cms_config()['base_url'] ?? '');
    } catch (Throwable) {
        $base = '';
    }

    return substr(hash('sha256', $base . '|' . dirname(__DIR__, 3)), 0, 16);
}
