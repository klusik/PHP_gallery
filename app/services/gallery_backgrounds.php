<?php

declare(strict_types=1);

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
 */
function gallery_background_source_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'background_source'");
        $ready = (bool) $stmt->fetch();
    } catch (Throwable) {
        $ready = false;
    }
    return $ready;
}

/**
 * Return the theme fallback background source, if configured.
 */
function theme_background_source(): ?string
{
    $value = trim((string) app_setting('theme_background_source', ''));
    return in_array($value, ['upload', 'existing', 'collage'], true) ? $value : null;
}

/**
 * Return the gallery override background source, if configured.
 */
function gallery_background_source(array $gallery): ?string
{
    $value = trim((string) ($gallery['background_source'] ?? ''));
    return in_array($value, ['upload', 'existing', 'collage'], true) ? $value : null;
}

/**
 * Resolve the effective background source for a gallery.
 */
function resolved_gallery_background_source(array $gallery): string
{
    return gallery_background_source($gallery)
        ?? theme_background_source()
        ?? '';
}

/**
 * Return the public background asset URL for a gallery, if one can be resolved.
 */
function gallery_background_asset_url(array $gallery, bool $publicOnly): string
{
    $source = resolved_gallery_background_source($gallery);
    if ($source === '') {
        return '';
    }
    if ($source === 'upload') {
        return gallery_cover_asset_url($gallery, $publicOnly);
    }
    if ($source === 'existing') {
        $image = gallery_cover_image((int) $gallery['id'], $publicOnly);
        return $image ? thumbnail_url($image, 800) : '';
    }
    if ($source === 'collage') {
        $collage = gallery_cover_collage_images((int) $gallery['id'], $publicOnly, 1);
        return $collage ? thumbnail_url($collage[0], 800) : '';
    }
    return '';
}

/**
 * Return the stored global theme background file path, if present.
 */
function theme_background_path(): ?string
{
    $path = trim((string) app_setting('theme_background_path', ''));
    if ($path === '') {
        return null;
    }
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    return is_file($absolute) ? $path : null;
}

/**
 * Return the public URL for the stored global theme background.
 */
function theme_background_asset_url(): string
{
    return theme_background_path() !== null ? url_for('theme_background_asset') : '';
}

/**
 * Return the storage directory for the global theme background asset.
 */
function theme_background_storage_dir(): string
{
    $dir = dirname(__DIR__, 2) . '/cache/theme-background';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Store one uploaded global theme background image in private cache storage.
 */
function store_uploaded_theme_background(array $file): string
{
    $extension = strtolower(pathinfo((string) ($file['name'] ?? 'background.jpg'), PATHINFO_EXTENSION));
    $safeExtension = in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? $extension : 'jpg';
    $filename = 'background.' . $safeExtension;
    $target = theme_background_storage_dir() . DIRECTORY_SEPARATOR . $filename;
    foreach (glob(theme_background_storage_dir() . DIRECTORY_SEPARATOR . 'background.*') ?: [] as $oldFile) {
        if (is_file($oldFile) && $oldFile !== $target) {
            @unlink($oldFile);
        }
    }
    if (!move_uploaded_file((string) ($file['tmp_name'] ?? ''), $target)) {
        throw new RuntimeException('Could not store theme background image.');
    }
    $relative = 'cache/theme-background/' . $filename;
    set_app_setting('theme_background_path', $relative);
    return $relative;
}
