<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_backgrounds.php
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

use GdImage;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\url_for;

/**
 * Gallery and theme background service helpers.
 *
 * This module owns background-source resolution and the uploaded global theme
 * background storage path. It intentionally does not modify theme colors,
 * custom CSS, favicon settings, or the Admin -> Theme rendering controller.
 *
 * Path note: this file lives in app/services/, one level deeper than the old
 * app/services.php location. Any project-root path must use dirname(__DIR__, 2)
 * so stored values such as cache/theme-background/background.jpg still resolve
 * to the existing root cache folder.
 */

/**
 * Return true when the gallery background source column is available.
 *
 * @return bool True when the condition matches.
 */
function gallery_background_source_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'background_source'");
        // $ready stores an intermediate value used by the surrounding gallery workflow.
        $ready = (bool) $stmt->fetch();
    } catch (Throwable) {
        // $ready stores an intermediate value used by the surrounding gallery workflow.
        $ready = false;
    }
    return $ready;
}

/**
 * Return the theme fallback background source, if configured.
 *
 * @return ?string Text result for the caller.
 */
function theme_background_source(): ?string
{
    // $value stores an intermediate value used by the surrounding gallery workflow.
    $value = trim((string) app_setting('theme_background_source', ''));
    return in_array($value, ['upload', 'existing', 'collage'], true) ? $value : null;
}

/**
 * Return the gallery override background source, if configured.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return ?string Text result for the caller.
 */
function gallery_background_source(array $gallery): ?string
{
    // $value stores an intermediate value used by the surrounding gallery workflow.
    $value = trim((string) ($gallery['background_source'] ?? ''));
    return in_array($value, ['upload', 'existing', 'collage'], true) ? $value : null;
}

/**
 * Resolve the effective background source for a gallery.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function resolved_gallery_background_source(array $gallery): string
{
    return gallery_background_source($gallery)
        ?? theme_background_source()
        ?? '';
}

/**
 * Return the public background asset URL for a gallery, if one can be resolved.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @return string Text result for the caller.
 */
function gallery_background_asset_url(array $gallery, bool $publicOnly): string
{
    // $source stores an intermediate value used by the surrounding gallery workflow.
    $source = resolved_gallery_background_source($gallery);
    if ($source === '') {
        return '';
    }
    if ($source === 'upload') {
        return gallery_cover_asset_url($gallery, $publicOnly);
    }
    if ($source === 'existing') {
        // $image stores an intermediate value used by the surrounding gallery workflow.
        $image = gallery_cover_image((int) $gallery['id'], $publicOnly);
        return $image ? public_render_profile_with_thumbnail_purpose('gallery background existing 800', static fn (): string => thumbnail_url($image, 800)) : '';
    }
    if ($source === 'collage') {
        // $collage stores an intermediate value used by the surrounding gallery workflow.
        $collage = gallery_cover_collage_images((int) $gallery['id'], $publicOnly, 1);
        return $collage ? public_render_profile_with_thumbnail_purpose('gallery background collage 800', static fn (): string => thumbnail_url($collage[0], 800)) : '';
    }
    return '';
}

/**
 * Return the stored global theme background file path, if present.
 *
 * @return ?string Text result for the caller.
 */
function theme_background_path(): ?string
{
    return theme_background_existing_path((string) app_setting('theme_background_path', ''));
}

/**
 * Return the stored original global theme background file path, if present.
 *
 * @return ?string Text result for the caller.
 */
function theme_background_original_path(): ?string
{
    return theme_background_existing_path((string) app_setting('theme_background_original_path', ''));
}

/**
 * Return the stored optimized global theme background file path, if present.
 *
 * @return ?string Text result for the caller.
 */
function theme_background_optimized_path(): ?string
{
    return theme_background_existing_path((string) app_setting('theme_background_optimized_path', ''));
}

/**
 * Resolve a saved theme background path only when the file still exists.
 *
 * @param string $path Filesystem path.
 * @return ?string Text result for the caller.
 */
function theme_background_existing_path(string $path): ?string
{
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = trim($path);
    if ($path === '') {
        return null;
    }
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    return is_file($absolute) ? $path : null;
}

/**
 * Return the preferred public theme background path.
 *
 * @return ?string Text result for the caller.
 */
function theme_background_served_path(): ?string
{
    return theme_background_optimized_path() ?? theme_background_path();
}

/**
 * Return the public URL for the stored global theme background.
 *
 * @return string Text result for the caller.
 */
function theme_background_asset_url(): string
{
    // $servedPath stores whether an optimized or original background can be streamed.
    $servedPath = theme_background_served_path();
    if ($servedPath === null) {
        return '';
    }
    // $version stores a lightweight cache-busting value so replacing the background updates browsers quickly.
    $version = theme_background_served_version($servedPath);
    return url_for('theme_background_asset') . ($version !== '' ? '&v=' . rawurlencode($version) : '');
}

/**
 * Return a stable asset revision for the currently served theme background.
 *
 * @param string $relativePath Relative path filesystem path.
 * @return string Text result for the caller.
 */
function theme_background_served_version(string $relativePath): string
{
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($relativePath, '/');
    if (!is_file($absolute)) {
        return '';
    }
    return (string) filemtime($absolute);
}

/**
 * Normalize the maximum side used for optimized theme backgrounds.
 *
 * @param mixed $value Value to process.
 * @return int Integer result for the caller.
 */
function theme_background_optimized_max_side_value(mixed $value): int
{
    // $size stores the requested longest side in pixels before clamping.
    $size = (int) $value;
    if ($size <= 0) {
        return 1920;
    }
    return max(1024, min(3840, $size));
}

/**
 * Return true when the installed PHP runtime can create optimized WebP backgrounds.
 *
 * @return bool True when the condition matches.
 */
function theme_background_webp_generation_available(): bool
{
    return extension_loaded('gd') && function_exists('imagewebp') && function_exists('imagecreatefromstring');
}

/**
 * Return the storage directory for the global theme background asset.
 *
 * @return string Text result for the caller.
 */
function theme_background_storage_dir(): string
{
    // $dir stores an intermediate value used by the surrounding gallery workflow.
    $dir = dirname(__DIR__, 2) . '/cache/theme-background';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Store one uploaded global theme background image in private cache storage.
 *
 * @param array $file File value.
 * @param ?int $maxSide Max side value.
 * @return string Text result for the caller.
 */
function store_uploaded_theme_background(array $file, ?int $maxSide = null): string
{
    // $extension stores an intermediate value used by the surrounding gallery workflow.
    $extension = strtolower(pathinfo((string) ($file['name'] ?? 'background.jpg'), PATHINFO_EXTENSION));
    // $safeExtension stores an intermediate value used by the surrounding gallery workflow.
    $safeExtension = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $extension : 'jpg';
    // $filename stores an intermediate value used by the surrounding gallery workflow.
    $filename = 'background-original.' . $safeExtension;
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = theme_background_storage_dir() . DIRECTORY_SEPARATOR . $filename;
    theme_background_clear_stored_files($target);
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
        throw new RuntimeException(t('theme.background.error_store_failed', 'Could not store theme background image.'));
    }
    // $relative stores an intermediate value used by the surrounding gallery workflow.
    $relative = 'cache/theme-background/' . $filename;
    set_app_setting('theme_background_path', $relative);
    set_app_setting('theme_background_original_path', $relative);
    set_app_setting('theme_background_optimized_max_side', (string) theme_background_optimized_max_side_value($maxSide));
    theme_background_generate_optimized($target, theme_background_optimized_max_side_value($maxSide));
    return $relative;
}

/**
 * Remove old global theme background derivatives before storing a replacement.
 *
 * @param ?string $keepPath Keep path filesystem path.
 */
function theme_background_clear_stored_files(?string $keepPath = null): void
{
    foreach (glob(theme_background_storage_dir() . DIRECTORY_SEPARATOR . 'background*.*') ?: [] as $oldFile) {
        if (is_file($oldFile) && ($keepPath === null || $oldFile !== $keepPath)) {
            @unlink($oldFile);
        }
    }
    set_app_setting('theme_background_optimized_path', '');
}

/**
 * Rebuild the optimized WebP global theme background from the saved original.
 *
 * @param ?int $maxSide Max side value.
 * @return bool True when the condition matches.
 */
function theme_background_regenerate_optimized(?int $maxSide = null): bool
{
    // $relative stores an intermediate value used by the surrounding gallery workflow.
    $relative = theme_background_original_path() ?? theme_background_path();
    if ($relative === null) {
        set_app_setting('theme_background_optimized_path', '');
        return false;
    }
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    return theme_background_generate_optimized($absolute, theme_background_optimized_max_side_value($maxSide));
}

/**
 * Generate a resized WebP derivative for the global theme background.
 *
 * @param string $sourcePath Source filesystem path.
 * @param int $maxSide Max side value.
 * @return bool True when the condition matches.
 */
function theme_background_generate_optimized(string $sourcePath, int $maxSide): bool
{
    if (!theme_background_webp_generation_available() || !is_file($sourcePath)) {
        set_app_setting('theme_background_optimized_path', '');
        return false;
    }
    // $raw stores the uploaded image bytes so GD can decode the supported source type.
    $raw = @file_get_contents($sourcePath);
    if ($raw === false || $raw === '') {
        set_app_setting('theme_background_optimized_path', '');
        return false;
    }
    // $source stores the decoded image resource used for resizing.
    $source = @imagecreatefromstring($raw);
    if (!$source instanceof GdImage) {
        set_app_setting('theme_background_optimized_path', '');
        return false;
    }
    // $width stores the decoded source width.
    $width = imagesx($source);
    // $height stores the decoded source height.
    $height = imagesy($source);
    // $scale stores the resize factor. Upscaling is intentionally disabled.
    $scale = min(1.0, $maxSide / max(1, max($width, $height)));
    // $targetWidth stores the optimized derivative width.
    $targetWidth = max(1, (int) round($width * $scale));
    // $targetHeight stores the optimized derivative height.
    $targetHeight = max(1, (int) round($height * $scale));
    // $target stores the final transparent-safe image canvas.
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    // $transparent stores the transparent fill used before resampling.
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    imageinterlace($target, true);
    // $targetPath stores the optimized derivative filesystem path.
    $targetPath = theme_background_storage_dir() . DIRECTORY_SEPARATOR . 'background-optimized.webp';
    // $written stores whether the optimized derivative was created successfully.
    $written = imagewebp($target, $targetPath, 82);
    imagedestroy($target);
    imagedestroy($source);
    if (!$written || !is_file($targetPath)) {
        @unlink($targetPath);
        set_app_setting('theme_background_optimized_path', '');
        return false;
    }
    set_app_setting('theme_background_optimized_path', 'cache/theme-background/background-optimized.webp');
    return true;
}
