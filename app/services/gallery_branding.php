<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_branding.php
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
 *   2026-05-07
 */

declare(strict_types=1);

/**
 * Gallery branding asset helpers.
 *
 * The feature stores optional banner, logo, and separator images next to the
 * gallery folder and persists only relative paths in the database and sidecar.
 * Banner replaces the visible text title, logo remains supplementary, and the
 * separator renders below the public gallery title area.
 */

/**
 * Return the supported gallery branding asset definitions.
 *
 * @return array Structured result data for the caller.
 */
function gallery_branding_asset_types(): array
{
    return [
        'banner' => [
            'column' => 'banner_image_path',
            'filename' => 'banner',
            'label' => t('gallery.branding.banner.label'),
            'description' => t('gallery.branding.banner.description'),
        ],
        'logo' => [
            'column' => 'logo_image_path',
            'filename' => 'logo',
            'label' => t('gallery.branding.logo.label'),
            'description' => t('gallery.branding.logo.description'),
        ],
        'separator' => [
            'column' => 'separator_image_path',
            'filename' => 'separator',
            'label' => t('gallery.branding.separator.label'),
            'description' => t('gallery.branding.separator.description'),
        ],
    ];
}

/**
 * Normalize and validate a gallery branding asset kind.
 *
 * @param string $kind Kind value.
 * @return string Text result for the caller.
 */
function gallery_branding_asset_kind(string $kind): string
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = strtolower(trim($kind));
    if (!array_key_exists($kind, gallery_branding_asset_types())) {
        throw new InvalidArgumentException(t('gallery.branding.error_unknown_type'));
    }
    return $kind;
}

/**
 * Return the database column name for one branding asset kind.
 *
 * @param string $kind Kind value.
 * @return string Text result for the caller.
 */
function gallery_branding_asset_column(string $kind): string
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = gallery_branding_asset_kind($kind);
    // $types stores an intermediate value used by the surrounding gallery workflow.
    $types = gallery_branding_asset_types();
    return (string) $types[$kind]['column'];
}

/**
 * Return the storage filename stem for one branding asset kind.
 *
 * @param string $kind Kind value.
 * @return string Text result for the caller.
 */
function gallery_branding_asset_filename_stem(string $kind): string
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = gallery_branding_asset_kind($kind);
    // $types stores an intermediate value used by the surrounding gallery workflow.
    $types = gallery_branding_asset_types();
    return (string) $types[$kind]['filename'];
}

/**
 * Return the largest accepted upload size for gallery branding assets.
 *
 * @return int Integer result for the caller.
 */
function gallery_branding_uploaded_asset_max_bytes(): int
{
    return 8 * 1024 * 1024;
}

/**
 * Return true when the MIME type is a browser-safe gallery branding image.
 *
 * @param string $mime Mime value.
 * @return ?string Text result for the caller.
 */
function gallery_branding_mime_extension(string $mime): ?string
{
    return match (strtolower(trim($mime))) {
        'image/jpeg', 'image/pjpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => null,
    };
}

/**
 * Return true when the submitted filename has a browser-safe image extension.
 *
 * @param string $filename Filename value.
 * @return bool True when the condition matches.
 */
function gallery_branding_upload_extension_allowed(string $filename): bool
{
    return in_array(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

/**
 * Return true when all gallery branding columns are available.
 *
 * @return bool True when the condition matches.
 */
function gallery_branding_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        // $ready stores an intermediate value used by the surrounding gallery workflow.
        $ready = db_column_exists('galleries', 'banner_image_path')
            && db_column_exists('galleries', 'logo_image_path')
            && db_column_exists('galleries', 'separator_image_path');
    } catch (Throwable) {
        // $ready stores an intermediate value used by the surrounding gallery workflow.
        $ready = false;
    }
    return $ready;
}

/**
 * Return the stored relative path for one branding asset, if configured.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $kind Kind value.
 * @return ?string Text result for the caller.
 */
function gallery_branding_asset_path(array $gallery, string $kind): ?string
{
    // $column stores an intermediate value used by the surrounding gallery workflow.
    $column = gallery_branding_asset_column($kind);
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = trim((string) ($gallery[$column] ?? ''));
    if ($path === '') {
        return null;
    }
    try {
        // $normalized stores an intermediate value used by the surrounding gallery workflow.
        $normalized = normalize_relative_path($path);
    } catch (Throwable) {
        return null;
    }
    return $normalized !== '' ? $normalized : null;
}

/**
 * Return every configured gallery branding asset path keyed by asset kind.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array Structured result data for the caller.
 */
function gallery_branding_asset_paths(array $gallery): array
{
    // $paths stores an intermediate value used by the surrounding gallery workflow.
    $paths = [];
    foreach (array_keys(gallery_branding_asset_types()) as $kind) {
        $paths[$kind] = gallery_branding_asset_path($gallery, $kind);
    }
    return $paths;
}

/**
 * Update one gallery branding asset path in the database.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $kind Kind value.
 * @param ?string $relativePath Relative path filesystem path.
 */
function set_gallery_branding_asset_path(int $galleryId, string $kind, ?string $relativePath): void
{
    if (!gallery_branding_schema_ready()) {
        return;
    }
    // $column stores an intermediate value used by the surrounding gallery workflow.
    $column = gallery_branding_asset_column($kind);
    // $value stores an intermediate value used by the surrounding gallery workflow.
    $value = $relativePath !== null && trim($relativePath) !== '' ? normalize_relative_path($relativePath) : null;
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('UPDATE galleries SET ' . $column . ' = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$value, now_sql(), $galleryId]);
}

/**
 * Return the public route for one configured gallery branding asset.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $kind Kind value.
 * @param bool $publicOnly Public only value.
 * @return string Text result for the caller.
 */
function gallery_branding_asset_url(array $gallery, string $kind, bool $publicOnly): string
{
    if ($publicOnly && gallery_access_requirement($gallery) !== null && !visitor_can_access_gallery($gallery)) {
        return '';
    }
    if ($publicOnly && gallery_nsfw_requirement($gallery) !== null && !visitor_can_access_nsfw_content()) {
        return '';
    }
    if (gallery_branding_asset_abs_path($gallery, $kind) === null) {
        return '';
    }
    return url_for('gallery_branding_asset', ['id' => (int) $gallery['id'], 'kind' => gallery_branding_asset_kind($kind)]);
}

/**
 * Return the absolute path for one stored branding asset, if it is safe and present.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $kind Kind value.
 * @return ?string Text result for the caller.
 */
function gallery_branding_asset_abs_path(array $gallery, string $kind): ?string
{
    // $relativePath stores an intermediate value used by the surrounding gallery workflow.
    $relativePath = gallery_branding_asset_path($gallery, $kind);
    if ($relativePath === null) {
        return null;
    }
    // $galleryRoot stores an intermediate value used by the surrounding gallery workflow.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    // $absolutePath stores an intermediate value used by the surrounding gallery workflow.
    $absolutePath = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($absolutePath) || !path_inside($galleryRoot, $absolutePath)) {
        return null;
    }
    return $absolutePath;
}

/**
 * Delete one stored gallery branding asset and clear its database field.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $kind Kind value.
 */
function delete_gallery_branding_asset(int $galleryId, string $kind): void
{
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        return;
    }
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = gallery_branding_asset_abs_path($gallery, $kind);
    if ($path !== null) {
        @unlink($path);
    }
    set_gallery_branding_asset_path($galleryId, $kind, null);
}

/**
 * Return the supported global theme branding fallback definitions.
 *
 * @return array Structured result data for the caller.
 */
function theme_branding_asset_types(): array
{
    return [
        'banner' => [
            'setting' => 'theme_branding_banner_path',
            'filename' => 'banner',
            'label' => t('theme.branding.banner.label'),
            'description' => t('theme.branding.banner.description'),
        ],
        'separator' => [
            'setting' => 'theme_branding_separator_path',
            'filename' => 'separator',
            'label' => t('theme.branding.separator.label'),
            'description' => t('theme.branding.separator.description'),
        ],
    ];
}

/**
 * Normalize and validate a global theme branding fallback asset kind.
 *
 * @param string $kind Kind value.
 * @return string Text result for the caller.
 */
function theme_branding_asset_kind(string $kind): string
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = strtolower(trim($kind));
    if (!array_key_exists($kind, theme_branding_asset_types())) {
        throw new InvalidArgumentException(t('theme.branding.error_unknown_type'));
    }
    return $kind;
}

/**
 * Return the app setting key for one global theme branding fallback asset kind.
 *
 * @param string $kind Kind value.
 * @return string Text result for the caller.
 */
function theme_branding_asset_setting(string $kind): string
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = theme_branding_asset_kind($kind);
    // $types stores an intermediate value used by the surrounding gallery workflow.
    $types = theme_branding_asset_types();
    return (string) $types[$kind]['setting'];
}

/**
 * Return the storage filename stem for one global theme branding fallback asset kind.
 *
 * @param string $kind Kind value.
 * @return string Text result for the caller.
 */
function theme_branding_asset_filename_stem(string $kind): string
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = theme_branding_asset_kind($kind);
    // $types stores an intermediate value used by the surrounding gallery workflow.
    $types = theme_branding_asset_types();
    return (string) $types[$kind]['filename'];
}

/**
 * Return the stored relative path for one global theme branding fallback asset, if present.
 *
 * @param string $kind Kind value.
 * @return ?string Text result for the caller.
 */
function theme_branding_asset_path(string $kind): ?string
{
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = trim((string) app_setting(theme_branding_asset_setting($kind), ''));
    if ($path === '') {
        return null;
    }
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    return is_file($absolute) ? $path : null;
}

/**
 * Return the absolute path for one global theme branding fallback asset, if present.
 *
 * @param string $kind Kind value.
 * @return ?string Text result for the caller.
 */
function theme_branding_asset_abs_path(string $kind): ?string
{
    // $relative stores an intermediate value used by the surrounding gallery workflow.
    $relative = theme_branding_asset_path($kind);
    if ($relative === null) {
        return null;
    }
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    return is_file($absolute) ? $absolute : null;
}

/**
 * Return the public route for one global theme branding fallback asset.
 *
 * @param string $kind Kind value.
 * @return string Text result for the caller.
 */
function theme_branding_asset_url(string $kind): string
{
    return theme_branding_asset_path($kind) !== null ? url_for('theme_branding_asset', ['kind' => theme_branding_asset_kind($kind)]) : '';
}

/**
 * Return the configured gallery asset URL, falling back to the matching Theme asset when allowed.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $kind Kind value.
 * @param bool $publicOnly Public only value.
 * @return string Text result for the caller.
 */
function effective_gallery_branding_asset_url(array $gallery, string $kind, bool $publicOnly): string
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = gallery_branding_asset_kind($kind);
    // $galleryAssetUrl stores the per-gallery asset URL, if the gallery overrides the Theme fallback.
    $galleryAssetUrl = gallery_branding_schema_ready() ? gallery_branding_asset_url($gallery, $kind, $publicOnly) : '';
    if ($galleryAssetUrl !== '') {
        return $galleryAssetUrl;
    }
    if (!array_key_exists($kind, theme_branding_asset_types())) {
        return '';
    }
    return theme_branding_asset_url($kind);
}

/**
 * Return the storage directory for global theme branding fallback assets.
 *
 * @return string Text result for the caller.
 */
function theme_branding_storage_dir(): string
{
    // $dir stores an intermediate value used by the surrounding gallery workflow.
    $dir = dirname(__DIR__, 2) . '/cache/theme-branding';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Store one uploaded global theme branding fallback image.
 *
 * @param string $kind Kind value.
 * @param array $file File value.
 * @return string Text result for the caller.
 */
function store_uploaded_theme_branding_asset(string $kind, array $file): string
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = theme_branding_asset_kind($kind);
    // $uploadError stores an intermediate value used by the surrounding gallery workflow.
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(t('theme.branding.error_choose_upload'));
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($uploadError));
    }
    // $tmpPath stores an intermediate value used by the surrounding gallery workflow.
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException(t('theme.branding.error_upload_unavailable'));
    }
    // $originalName stores an intermediate value used by the surrounding gallery workflow.
    $originalName = (string) ($file['name'] ?? '');
    if (!gallery_branding_upload_extension_allowed($originalName)) {
        throw new RuntimeException(t('theme.branding.error_unsupported_type'));
    }
    // $size stores an intermediate value used by the surrounding gallery workflow.
    $size = (int) ($file['size'] ?? 0);
    if ($size > gallery_branding_uploaded_asset_max_bytes()) {
        throw new RuntimeException(t('theme.branding.error_too_large'));
    }
    // $info stores an intermediate value used by the surrounding gallery workflow.
    $info = @getimagesize($tmpPath);
    if ($info === false || empty($info['mime'])) {
        throw new RuntimeException(t('theme.branding.error_invalid_image'));
    }
    // $extension stores an intermediate value used by the surrounding gallery workflow.
    $extension = gallery_branding_mime_extension((string) $info['mime']);
    if ($extension === null) {
        throw new RuntimeException(t('theme.branding.error_unsupported_type'));
    }
    // $storageDir stores an intermediate value used by the surrounding gallery workflow.
    $storageDir = theme_branding_storage_dir();
    // $stem stores an intermediate value used by the surrounding gallery workflow.
    $stem = theme_branding_asset_filename_stem($kind);
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = $storageDir . DIRECTORY_SEPARATOR . $stem . '.' . $extension;
    // $stagedTarget stores the uploaded file before the previous asset is replaced.
    $stagedTarget = $storageDir . DIRECTORY_SEPARATOR . '.upload-' . $stem . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
    if (!move_uploaded_file($tmpPath, $stagedTarget)) {
        throw new RuntimeException(t('theme.branding.error_store_failed'));
    }
    foreach (glob($storageDir . DIRECTORY_SEPARATOR . $stem . '.*') ?: [] as $oldFile) {
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }
    if (!@rename($stagedTarget, $target)) {
        @unlink($stagedTarget);
        throw new RuntimeException(t('theme.branding.error_finalize_failed'));
    }
    // $relative stores an intermediate value used by the surrounding gallery workflow.
    $relative = 'cache/theme-branding/' . $stem . '.' . $extension;
    set_app_setting(theme_branding_asset_setting($kind), $relative);
    return $relative;
}

/**
 * Delete one global theme branding fallback asset and clear its app setting.
 *
 * @param string $kind Kind value.
 */
function delete_theme_branding_asset(string $kind): void
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = theme_branding_asset_kind($kind);
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = theme_branding_asset_abs_path($kind);
    if ($absolute !== null) {
        @unlink($absolute);
    }
    set_app_setting(theme_branding_asset_setting($kind), '');
}

