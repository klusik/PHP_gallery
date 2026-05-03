<?php

declare(strict_types=1);

/**
 * Favicon service helpers.
 *
 * This module owns only favicon file discovery, storage, resizing, and reset
 * operations. It intentionally does not own theme colors, custom CSS, theme
 * backgrounds, or the admin theme screen. Those areas remain untouched so this
 * split cannot reset visual preferences.
 *
 * Important path note: this file lives in app/services/, one directory deeper
 * than the legacy app/services.php file. Therefore project-root paths must use
 * dirname(__DIR__, 2), not dirname(__DIR__). The previous attempted split used
 * the old relative expression and looked under app/cache/favicon instead of
 * cache/favicon, which made existing favicons appear missing.
 */

/**
 * Return the stored favicon file path, if present.
 */
function favicon_path(int $size = 32): ?string
{
    // $safeSize stores an intermediate value used by the surrounding gallery workflow.
    $safeSize = favicon_safe_size($size);
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = trim((string) app_setting('favicon_path_' . $safeSize, ''));
    if ($path === '') {
        return null;
    }
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    return is_file($absolute) ? $path : null;
}

/**
 * Return the public URL for the stored favicon asset.
 */
function favicon_asset_url(): string
{
    return favicon_path(32) !== null ? url_for('favicon_asset') : '';
}

/**
 * Return the storage directory for generated favicon assets.
 */
function favicon_storage_dir(): string
{
    // $dir stores an intermediate value used by the surrounding gallery workflow.
    $dir = dirname(__DIR__, 2) . '/cache/favicon';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Clamp favicon size requests to generated variants.
 */
function favicon_safe_size(int $size): int
{
    if ($size >= 180) {
        return 180;
    }
    if ($size >= 48) {
        return 48;
    }
    return 32;
}

/**
 * Store an uploaded favicon image after applying an optional square crop.
 */
function store_uploaded_favicon(array $file, ?string $croppedPngData): string
{
    if (!extension_loaded('gd')) {
        throw new RuntimeException('Favicon cropping requires the GD extension.');
    }
    foreach (glob(favicon_storage_dir() . DIRECTORY_SEPARATOR . 'favicon-*.*') ?: [] as $oldFile) {
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }

    // Variable $source stores this steps working value.
    $source = false;
    // Variable $sourceWidth stores this steps working value.
    $sourceWidth = 0;
    // Variable $sourceHeight stores this steps working value.
    $sourceHeight = 0;
    if ($croppedPngData !== null && preg_match('/^data:image\/png;base64,/', $croppedPngData)) {
        // $raw stores an intermediate value used by the surrounding gallery workflow.
        $raw = base64_decode(substr($croppedPngData, strpos($croppedPngData, ',') + 1), true);
        if ($raw !== false && strlen($raw) <= 2_000_000) {
            // $source stores an intermediate value used by the surrounding gallery workflow.
            $source = @imagecreatefromstring($raw);
            if ($source) {
                // $sourceWidth stores an intermediate value used by the surrounding gallery workflow.
                $sourceWidth = imagesx($source);
                // $sourceHeight stores an intermediate value used by the surrounding gallery workflow.
                $sourceHeight = imagesy($source);
            }
        }
    }

    if (!$source) {
        // $tmpPath stores an intermediate value used by the surrounding gallery workflow.
        $tmpPath = (string) ($file['tmp_name'] ?? '');
        // $info stores an intermediate value used by the surrounding gallery workflow.
        $info = @getimagesize($tmpPath);
        if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
            throw new RuntimeException('The uploaded favicon source is not a valid image.');
        }
        // $source stores an intermediate value used by the surrounding gallery workflow.
        $source = image_create_from_path($tmpPath, (string) $info['mime']);
        if (!$source) {
            throw new RuntimeException('Could not decode the uploaded favicon source. Use JPG, PNG, GIF, or WebP.');
        }
        // $sourceWidth stores an intermediate value used by the surrounding gallery workflow.
        $sourceWidth = (int) $info[0];
        // $sourceHeight stores an intermediate value used by the surrounding gallery workflow.
        $sourceHeight = (int) $info[1];
        // $cropSide stores an intermediate value used by the surrounding gallery workflow.
        $cropSide = min($sourceWidth, $sourceHeight);
        // $cropX stores an intermediate value used by the surrounding gallery workflow.
        $cropX = (int) floor(($sourceWidth - $cropSide) / 2);
        // $cropY stores an intermediate value used by the surrounding gallery workflow.
        $cropY = (int) floor(($sourceHeight - $cropSide) / 2);
        // $source stores an intermediate value used by the surrounding gallery workflow.
        $source = crop_image_square($source, $sourceWidth, $sourceHeight, $cropX, $cropY, $cropSide);
        // $sourceWidth stores an intermediate value used by the surrounding gallery workflow.
        $sourceWidth = imagesx($source);
        // $sourceHeight stores an intermediate value used by the surrounding gallery workflow.
        $sourceHeight = imagesy($source);
    }

    foreach ([32, 48, 180] as $size) {
        // $targetPath stores an intermediate value used by the surrounding gallery workflow.
        $targetPath = favicon_storage_dir() . DIRECTORY_SEPARATOR . 'favicon-' . $size . '.png';
        if (!write_resized_png($source, $sourceWidth, $sourceHeight, $size, $targetPath)) {
            imagedestroy($source);
            throw new RuntimeException('Could not write favicon image.');
        }
        set_app_setting('favicon_path_' . $size, 'cache/favicon/favicon-' . $size . '.png');
    }
    imagedestroy($source);
    set_app_setting('favicon_version', (string) time());
    return 'cache/favicon/favicon-32.png';
}

/**
 * Crop an image resource to a square GD image.
 */
function crop_image_square(GdImage $source, int $width, int $height, int $cropX, int $cropY, int $cropSide): GdImage
{
    // $side stores an intermediate value used by the surrounding gallery workflow.
    $side = max(1, min($cropSide, $width, $height));
    // $safeX stores an intermediate value used by the surrounding gallery workflow.
    $safeX = max(0, min($cropX, $width - $side));
    // $safeY stores an intermediate value used by the surrounding gallery workflow.
    $safeY = max(0, min($cropY, $height - $side));
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = imagecreatetruecolor($side, $side);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    // $transparent stores an intermediate value used by the surrounding gallery workflow.
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $side, $side, $transparent);
    imagecopyresampled($target, $source, 0, 0, $safeX, $safeY, $side, $side, $side, $side);
    imagedestroy($source);
    return $target;
}

/**
 * Resize an image to a square PNG while preserving alpha where possible.
 */
function write_resized_png(GdImage $source, int $width, int $height, int $targetSize, string $targetPath): bool
{
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = imagecreatetruecolor($targetSize, $targetSize);
    imagealphablending($target, false);
    imagesavealpha($target, true);
    // $transparent stores an intermediate value used by the surrounding gallery workflow.
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetSize, $targetSize, $transparent);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetSize, $targetSize, $width, $height);
    // $written stores an intermediate value used by the surrounding gallery workflow.
    $written = imagepng($target, $targetPath, 6);
    imagedestroy($target);
    return $written;
}

/**
 * Remove every stored favicon variant.
 */
function remove_stored_favicon(): void
{
    foreach (glob(favicon_storage_dir() . DIRECTORY_SEPARATOR . 'favicon-*.*') ?: [] as $oldFile) {
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }
    foreach ([32, 48, 180] as $size) {
        set_app_setting('favicon_path_' . $size, '');
    }
    set_app_setting('favicon_version', (string) time());
}
