<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/app_settings.php
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

namespace Gallery\Services;

use PDOException;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

/**
 * Application settings service.
 *
 * This module contains the small DB-backed settings helpers that were previously
 * defined in app/services.php. Keeping them in their own file makes dependencies
 * explicit for theme, favicon, updater, and admin UI code while preserving all
 * original public function names.
 */

/**
 * Return all DB gallery rows represented by one filesystem subtree.
 */


/**
 * Delete selected gallery folder subtrees from disk and the database.
 */


/**
 * Remove one directory tree while refusing to operate outside the configured root.
 */


/**
 * Physically move one gallery folder subtree and then make DB paths follow it.
 */


/**
 * Ensure all filesystem ancestors of one gallery folder exist as gallery rows.
 *
 * This prevents a third-level gallery from becoming a top-level gallery when it
 * was imported before its intermediate parent. The hierarchy is filesystem-first:
 * galleries/A/B/C should always be represented as A -> B -> C when the folders
 * exist under the configured gallery root.
 */


/**
 * Create gallery rows for selected discovered folders.
 */


/**
 * Import and scan selected folders, returning imported gallery IDs for follow-up work.
 */


/**
 * Import/update image rows for images directly inside one gallery folder.
 *
 * Child-folder images are intentionally ignored here because child folders are
 * represented as subgalleries with their own scans.
 */


/**
 * Normalize a PHP multi-upload array into validated image upload entries.
 */


/**
 * Return whether the server can decode HEIC/HEIF images for conversion.
 */


/**
 * Return whether the server can decode RAW/DNG images for conversion.
 */


/**
 * Human-readable upload error for admin notices and JSON responses.
 */


/**
 * Build a safe stored filename while keeping the original image extension.
 */


/**
 * Return an unused filename and absolute target path inside one gallery folder.
 */


/**
 * Move validated uploaded images into the gallery folder and scan the result.
 */


/**
 * Return database image ids for uploaded direct gallery filenames.
 */


/**
 * Store one uploaded gallery thumbnail outside the indexed image set.
 */


/**
 * Scan every imported gallery folder for new or changed direct images.
 */


/**
 * Thumbnail variants generated for web views.
 */


/**
 * Build a responsive srcset for the supported thumbnail sizes.
 */


/**
 * Build a responsive WebP srcset for thumbnails when WebP variants exist.
 */


/**
 * Build a responsive srcset for one thumbnail format.
 */


/**
 * Resolve the thumbs folder for a gallery and create it when requested.
 */


/**
 * Build the generated thumbnail filename for an image, size, and format.
 */


/**
 * Resolve one generated thumbnail path.
 */


/**
 * Return true when a thumbnail may be safely served directly as a static file.
 */


/**
 * Return the public URL for a gallery file when the configured gallery root is web-visible.
 */


/**
 * Return the best public URL for an image thumbnail, falling back to the source.
 */


/**
 * Return the URL that serves an already existing thumbnail without creating files.
 */


/**
 * Find an existing thumbnail to use when the requested variant has not been generated yet.
 */

/**
 * Build image markup with WebP source when the WebP thumbnails exist.
 */


/**
 * Return true when the server can create WebP thumbnails for this source without losing required EXIF data.
 */


/**
 * Return missing or stale thumbnail variant counts for one image without creating files.
 */


/**
 * Summarize pending thumbnail maintenance for the admin area without generating thumbnails.
 */

/**
 * Generate all configured thumbnails for direct images in one gallery.
 */


/**
 * Generate all configured thumbnails for every imported image.
 */


/**
 * Rebuild web-optimized thumbnails for one source image.
 */


/**
 * Rebuild missing or stale thumbnails and report created/skipped variants.
 */



/**
 * Return image IDs directly owned by the selected galleries.
 */


/**
 * Return every imported direct image ID in stable dashboard order.
 */


/**
 * Load a GD image resource from the supported source MIME types.
 */


/**
 * Resize an image to a maximum longer side and write a progressive JPEG.
 */


/**
 * Return true when the source contains EXIF that must survive WebP conversion.
 */


/**
 * Resize an image to WebP, preserving EXIF when the source has EXIF metadata.
 */


/**
 * Resize an image to WebP with GD for sources that do not need EXIF copying.
 */


/**
 * Resize a JPEG to WebP with Imagick while copying the EXIF profile.
 */


/**
 * Pick the first direct image as cover when the gallery has no explicit cover.
 */


/**
 * Return the gallery thumbnail asset path, if one was uploaded.
 */


/**
 * Return true when the uploaded gallery thumbnail column is available.
 */


/**
 * Gallery and theme background helpers are loaded from app/services/gallery_backgrounds.php.
 */

/**
 * Update a gallery's uploaded thumbnail asset path.
 */


/**
 * Rebuild parent_id links from filesystem folder nesting.
 */


/**
 * Return one gallery ID plus all descendant gallery IDs.
 */


/**
 * Return whether the admin features added after the initial schema are ready.
 */


/**
 * Return whether password-protected gallery columns are available.
 */


/**
 * Return true when one gallery has its own password policy.
 */


/**
 * Return the protected gallery that controls public access to this gallery.
 */


/**
 * Build the session key that records a public gallery unlock.
 */


/**
 * Return how long a public gallery unlock should last in this browser session.
 */


/**
 * Store a successful public unlock for this browser session.
 */


/**
 * Return true while a public gallery unlock is still fresh.
 */


/**
 * Return true when the current request token unlocks the controlling gallery.
 */


/**
 * Return whether an anonymous visitor may view one gallery branch now.
 */


/**
 * Return true when a public listing may include this gallery.
 */


/**
 * Create a share token, store its lookup hash, and keep an encrypted admin copy.
 */


/**
 * Revoke the share token for one gallery.
 */


/**
 * Return whether the encrypted share token display column exists.
 */


/**
 * Return the current share token for admin display, upgrading legacy plaintext rows.
 */


/**
 * Encrypt one share token using the local config secret.
 */


/**
 * Decrypt one stored share token, or null when it is not an encrypted value.
 */


/**
 * Derive a stable encryption key from the local application secret.
 */


/**
 * Public wrapper for the preferred direct cover image.
 */


/**
 * Return the explicit cover image or first direct image for one gallery.
 */


/**
 * Return a gallery thumbnail URL from an uploaded asset, if present.
 */


/**
 * Function `gallery_cover_collage_images` handles this scoped operation.
 */


/**
 * Build visual cover choices from the gallery subtree.
 */


/**
 * Apply a cover image path from gallery.json after images have been scanned.
 */



/**
 * Reset request-local application-setting state.
 *
 * Long-running maintenance/update requests can call this after schema changes or
 * fixture replacement. Normal web requests start with an empty process-local cache.
 */
function app_settings_reset_request_cache(): void
{
    $GLOBALS['cms_app_settings_cache'] = [];
    $GLOBALS['cms_app_settings_cache_loaded'] = false;
}

/**
 * Prime the request-local settings cache with one bounded table read.
 *
 * Public rendering reads many independent settings. Loading the small settings
 * table once prevents dozens of one-row round trips while keeping all values
 * request-local and preserving same-request writes through set_app_setting().
 */
function app_settings_prime_request_cache(): void
{
    if (!isset($GLOBALS['cms_app_settings_cache']) || !is_array($GLOBALS['cms_app_settings_cache'])) {
        $GLOBALS['cms_app_settings_cache'] = [];
    }
    if (($GLOBALS['cms_app_settings_cache_loaded'] ?? false) === true) {
        return;
    }

    try {
        $stmt = db()->query('SELECT setting_key, setting_value FROM app_settings');
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                $settingKey = (string) ($row['setting_key'] ?? '');
                if ($settingKey === '') {
                    continue;
                }
                $GLOBALS['cms_app_settings_cache'][$settingKey] = (string) ($row['setting_value'] ?? '');
            }
        }
        $GLOBALS['cms_app_settings_cache_loaded'] = true;
    } catch (PDOException) {
        // Installation/migration compatibility: fall back to the old one-key read
        // path instead of treating a temporary preload failure as an empty table.
        $GLOBALS['cms_app_settings_cache_loaded'] = false;
    }
}

/**
 * Read one application setting with a fallback.
 *
 * @param string $key Lookup key.
 * @param ?string $default Default value when no explicit value is available.
 * @return ?string Text result for the caller.
 */
function app_setting(string $key, ?string $default = null): ?string
{
    if (!isset($GLOBALS['cms_app_settings_cache']) || !is_array($GLOBALS['cms_app_settings_cache'])) {
        // $GLOBALS entry stores DB setting values already read during this request.
        $GLOBALS['cms_app_settings_cache'] = [];
    }

    if (array_key_exists($key, $GLOBALS['cms_app_settings_cache'])) {
        // $cachedValue stores null when the setting was already checked and not found.
        $cachedValue = $GLOBALS['cms_app_settings_cache'][$key];
        return $cachedValue === null ? $default : (string) $cachedValue;
    }

    app_settings_prime_request_cache();
    if (($GLOBALS['cms_app_settings_cache_loaded'] ?? false) === true) {
        return array_key_exists($key, $GLOBALS['cms_app_settings_cache'])
            ? (string) $GLOBALS['cms_app_settings_cache'][$key]
            : $default;
    }

    try {
        // Preload failure is deliberately recoverable through the legacy one-key query.
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $GLOBALS['cms_app_settings_cache'][$key] = $value === false ? null : (string) $value;
        return $value === false ? $default : (string) $value;
    } catch (PDOException) {
        return $default;
    }
}

/**
 * Upsert one application setting.
 *
 * @param string $key Lookup key.
 * @param string $value Value to process.
 */
function set_app_setting(string $key, string $value): void
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $stmt->execute([$key, $value, now_sql()]);

    if (!isset($GLOBALS['cms_app_settings_cache']) || !is_array($GLOBALS['cms_app_settings_cache'])) {
        $GLOBALS['cms_app_settings_cache'] = [];
    }
    $GLOBALS['cms_app_settings_cache'][$key] = $value;
}

/**
 * Remove one or more application settings.
 *
 * @param array $keys Keys value.
 */
function delete_app_settings(array $keys): void
{
    // $keys stores an intermediate value used by the surrounding gallery workflow.
    $keys = array_values(array_unique(array_filter($keys, static fn (string $key): bool => $key !== '')));
    if ($keys === []) {
        return;
    }
    // $placeholders stores an intermediate value used by the surrounding gallery workflow.
    $placeholders = implode(', ', array_fill(0, count($keys), '?'));
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('DELETE FROM app_settings WHERE setting_key IN (' . $placeholders . ')');
    $stmt->execute($keys);

    if (!isset($GLOBALS['cms_app_settings_cache']) || !is_array($GLOBALS['cms_app_settings_cache'])) {
        $GLOBALS['cms_app_settings_cache'] = [];
    }
    foreach ($keys as $key) {
        $GLOBALS['cms_app_settings_cache'][$key] = null;
    }
}


/**
 * Return true when generated public links should prefer clean rewritten URLs.
 *
 * The default is intentionally enabled to preserve the historic application
 * behavior. Administrators can store url_rewrite_enabled = 0 when their hosting
 * cannot route clean URLs reliably.
 *
 * @return bool True when the condition matches.
 */
function url_rewrite_enabled(): bool
{
    return app_setting('url_rewrite_enabled', '1') !== '0';
}

/**
 * Persist the clean URL rewrite preference.
 *
 * @param bool $enabled Enabled flag.
 */
function set_url_rewrite_enabled(bool $enabled): void
{
    set_app_setting('url_rewrite_enabled', $enabled ? '1' : '0');
}

/**
 * Return whether one .htaccess file contains rewrite rules for this app.
 *
 * @param string $path Filesystem path.
 * @return bool True when the condition matches.
 */
function url_rewrite_marker_file_ok(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }

    $contents = (string) file_get_contents($path);
    return stripos($contents, 'mod_rewrite.c') !== false
        && stripos($contents, 'RewriteEngine On') !== false
        && stripos($contents, 'RewriteRule') !== false;
}

/**
 * Inspect the current runtime for practical URL rewrite compatibility signals.
 *
 * This is deliberately a confidence model, not a hosting-specific guarantee. The
 * app can prove rewrite support only after a clean URL was routed to PHP. The
 * remaining checks look for the files and server indicators that this project
 * needs on typical Apache, LiteSpeed, and compatible shared hosting.
 *
 * @param ?array $server Server value.
 * @param string|null $root Override used by tests.
 * @return array{enabled: bool, status: string, supported: bool, confidence: string, reasons: array<int, string>, details: array<string, mixed>}.
 */
function url_rewrite_compatibility(?array $server = null, ?string $root = null): array
{
    $server = $server ?? $_SERVER;
    $root = $root ?? dirname(__DIR__, 2);
    $enabled = url_rewrite_enabled();

    $rootHtaccess = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . '.htaccess';
    $publicHtaccess = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.htaccess';
    $rootMarker = url_rewrite_marker_file_ok($rootHtaccess);
    $publicMarker = url_rewrite_marker_file_ok($publicHtaccess);
    $serverSoftware = strtolower((string) ($server['SERVER_SOFTWARE'] ?? ''));
    $requestUri = (string) ($server['REQUEST_URI'] ?? '');
    $scriptName = (string) ($server['SCRIPT_NAME'] ?? '');
    $redirectUrl = (string) ($server['REDIRECT_URL'] ?? '');
    $reasons = [];
    $confidence = 'medium';
    $status = 'unknown';
    $supported = false;

    if (!$enabled) {
        return [
            'enabled' => false,
            'status' => 'disabled',
            'supported' => false,
            'confidence' => 'manual',
            'reasons' => ['URL rewrite is disabled by admin setting.'],
            'details' => [
                'root_htaccess' => $rootMarker,
                'public_htaccess' => $publicMarker,
                'server_software' => $serverSoftware,
            ],
        ];
    }

    if ($redirectUrl !== '' || ((string) parse_url($requestUri, PHP_URL_PATH) !== '' && !str_contains((string) parse_url($requestUri, PHP_URL_PATH), 'index.php') && basename($scriptName) === 'index.php')) {
        $status = 'supported';
        $supported = true;
        $confidence = 'high';
        $reasons[] = 'This request reached PHP through a clean rewritten URL.';
    }

    if (!$supported && function_exists('apache_get_modules')) {
        $modules = array_map('strtolower', apache_get_modules());
        if (in_array('mod_rewrite', $modules, true)) {
            $status = 'supported';
            $supported = true;
            $confidence = 'high';
            $reasons[] = 'Apache reports that mod_rewrite is loaded.';
        }
    }

    if (!$supported && ($rootMarker || $publicMarker) && ($serverSoftware === '' || str_contains($serverSoftware, 'apache') || str_contains($serverSoftware, 'litespeed'))) {
        $status = 'likely_supported';
        $supported = true;
        $confidence = 'medium';
        $reasons[] = 'Rewrite rules are present and the reported server is Apache/LiteSpeed-compatible.';
    }

    if (!$supported && !$rootMarker && !$publicMarker) {
        $status = 'unsupported';
        $confidence = 'high';
        $reasons[] = 'No readable project .htaccess rewrite rules were found.';
    } elseif (!$supported && str_contains($serverSoftware, 'iis')) {
        $status = 'unsupported';
        $confidence = 'medium';
        $reasons[] = 'The server reports IIS and no compatible rewrite signal was detected.';
    } elseif (!$supported) {
        $status = 'unknown';
        $confidence = 'low';
        $reasons[] = 'Rewrite support could not be proven from this request.';
    }

    return [
        'enabled' => true,
        'status' => $status,
        'supported' => $supported,
        'confidence' => $confidence,
        'reasons' => $reasons,
        'details' => [
            'root_htaccess' => $rootMarker,
            'public_htaccess' => $publicMarker,
            'server_software' => $serverSoftware,
            'request_uri' => $requestUri,
            'script_name' => $scriptName,
            'redirect_url' => $redirectUrl,
        ],
    ];
}

/**
 * Return true when pretty public URLs should be emitted for the current request.
 *
 * @return bool True when the condition matches.
 */
function url_rewrite_should_emit_clean_urls(): bool
{
    if (!url_rewrite_enabled()) {
        return false;
    }

    $compatibility = url_rewrite_compatibility();
    return in_array((string) $compatibility['status'], ['supported', 'likely_supported', 'unknown'], true);
}

/**
 * Public site name shown in the header and browser title.
 *
 * @return string Text result for the caller.
 */
function site_name(): string
{
    // Variable $name stores this steps working value.
    $name = trim((string) app_setting('site_name', 'Gallery CMS'));
    return $name !== '' ? $name : 'Gallery CMS';
}

/**
 * Persist the normalized public site name used by Theme and centralized Settings.
 *
 * @param string $name Submitted site name.
 * @return string Normalized value that was persisted.
 */
function set_site_name(string $name): string
{
    $normalized = trim($name);
    $normalized = $normalized !== '' ? substr($normalized, 0, 120) : 'Gallery CMS';
    set_app_setting('site_name', $normalized);
    return $normalized;
}

/**
 * Return true when admin-only JavaScript diagnostics should be rendered.
 *
 * @return bool True when the condition matches.
 */
function dev_mode_enabled(): bool
{
    return app_setting('dev_mode_enabled', '0') === '1';
}

/**
 * Persist the admin-only JavaScript diagnostics switch.
 *
 * @param bool $enabled Enabled flag.
 */
function set_dev_mode_enabled(bool $enabled): void
{
    set_app_setting('dev_mode_enabled', $enabled ? '1' : '0');
}

/**
 * Return gallery IDs whose admin tree rows should start collapsed.
 *
 * @return array Structured result data for the caller.
 */
function collapsed_gallery_ids(): array
{
    // Variable $decoded stores this steps working value.
    $decoded = json_decode((string) app_setting('admin_collapsed_gallery_ids', '[]'), true);
    if (!is_array($decoded)) {
        return [];
    }
    return array_values(array_unique(array_map('intval', $decoded)));
}

/**
 * Persist the admin gallery tree collapse state.
 *
 * @param array $ids Ids value.
 */
function set_collapsed_gallery_ids(array $ids): void
{
    // Variable $ids stores this steps working value.
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    set_app_setting('admin_collapsed_gallery_ids', json_encode($ids, JSON_THROW_ON_ERROR));
}
