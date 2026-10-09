<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_backgrounds/visual_css_save.php
 * Module Type: Service Module Part
 * Purpose: Stage and revision-check pending Theme backgrounds for the explicit visual CSS save.
 * Responsibilities: Own opaque revisions, writer locking, safe image validation, optimization staging and cleanup.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

use GdImage;
use RuntimeException;
use function Gallery\Models\app_settings_model_get;

/**
 * Theme background storage paths are installation-root relative; this part is
 * one directory below app/services, so project-root paths use dirname(__DIR__, 3).
 */
/**
 * Serialize every administrator write to the global Theme background and its settings.
 *
 * @param callable():void $operation One complete background mutation; nested background mutation helpers must use their unlocked internal operation.
 * @return void Completes the operation while holding the shared background writer lock.
 * @throws RuntimeException When the owned storage directory or writer lock is unavailable.
 */
function theme_background_with_writer_lock(callable $operation): void
{
    $storage = theme_background_storage_dir();
    $lockPath = $storage . DIRECTORY_SEPARATOR . '.theme-background.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('The Theme background writer lock is unsafe.');
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('The Theme background writer lock is unavailable.');
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another Theme background write is in progress.');
        }
        $operation();
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Return an opaque optimistic revision for the selected Theme background and its source settings.
 *
 * @return string SHA-256 digest of private settings and verified current file contents; no storage identity is exposed.
 * @throws RuntimeException When a configured owned asset cannot be read for revision calculation.
 */
function theme_background_revision(): string
{
    $originalSetting = app_settings_model_get('theme_background_original_path');
    $pathSetting = app_settings_model_get('theme_background_path');
    $optimizedSetting = app_settings_model_get('theme_background_optimized_path');
    $sourceSetting = app_settings_model_get('theme_background_source');
    $opacitySetting = app_settings_model_get('theme_background_opacity');
    $maxSideSetting = app_settings_model_get('theme_background_optimized_max_side');
    $originalPath = theme_background_existing_path(is_string($originalSetting) ? $originalSetting : '');
    $path = theme_background_existing_path(is_string($pathSetting) ? $pathSetting : '');
    $optimizedPath = theme_background_existing_path(is_string($optimizedSetting) ? $optimizedSetting : '');
    $identity = [
        'source' => is_string($sourceSetting) ? $sourceSetting : '',
        'original_setting' => is_string($originalSetting) ? $originalSetting : '',
        'path_setting' => is_string($pathSetting) ? $pathSetting : '',
        'optimized_setting' => is_string($optimizedSetting) ? $optimizedSetting : '',
        'opacity' => is_string($opacitySetting) ? $opacitySetting : '',
        'max_side' => is_string($maxSideSetting) ? $maxSideSetting : '1920',
        'original_sha256' => theme_background_revision_file_digest($originalPath),
        'path_sha256' => theme_background_revision_file_digest($path),
        'optimized_sha256' => theme_background_revision_file_digest($optimizedPath),
    ];
    return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
}

/**
 * Prepare the safe Theme background state consumed by the visual CSS editor.
 *
 * @return array{available:bool,revision:string,source:string,url:string} Availability, opaque optimistic revision, normalized source and same-origin public asset URL.
 * @throws RuntimeException When the writer lock or a configured asset is unavailable for the snapshot.
 */
function theme_background_editor_state(): array
{
    $state = [];
    theme_background_with_writer_lock(static function () use (&$state): void {
        $url = theme_background_asset_url();
        $source = theme_background_source() ?? '';
        $state = [
            'available' => $url !== '',
            'revision' => theme_background_revision(),
            'source' => $source,
            'url' => $url,
        ];
    });
    return $state;
}

/**
 * Classify the background URL actually selected by the public layout during visual preview.
 *
 * @param array<string,scalar|null>|null $gallery Authorized current gallery row, or null for the public homepage.
 * @param bool $publicOnly Whether the existing gallery resolver must hide visitor-inaccessible cover assets.
 * @return array{target:'theme'|'none',owner:'theme_image'|'theme_gallery_fallback'|'gallery_override'|'none',mode:'theme_image'|'upload'|'existing'|'collage'|'none',url:string} Path-free edit target and source metadata matching the public URL actually rendered.
 */
function theme_background_visual_editor_context(?array $gallery, bool $publicOnly): array
{
    $galleryUrl = $gallery === null ? '' : gallery_background_asset_url($gallery, $publicOnly);
    if ($galleryUrl !== '' && $gallery !== null) {
        $gallerySource = gallery_background_source($gallery);
        $mode = $gallerySource ?? theme_background_source() ?? 'none';
        return [
            'target' => 'none',
            'owner' => $gallerySource !== null ? 'gallery_override' : 'theme_gallery_fallback',
            'mode' => in_array($mode, ['upload', 'existing', 'collage'], true) ? $mode : 'none',
            'url' => $galleryUrl,
        ];
    }

    $themeUrl = theme_background_asset_url();
    if ($themeUrl !== '') {
        return ['target' => 'theme', 'owner' => 'theme_image', 'mode' => 'theme_image', 'url' => $themeUrl];
    }
    return ['target' => 'theme', 'owner' => 'none', 'mode' => 'none', 'url' => ''];
}

/**
 * Verify and list every currently configured owned global Theme image file.
 *
 * @return list<string> Unique validated gallery-relative paths from the global image setting triplet.
 * @throws RuntimeException When a configured path is missing, linked or outside owned storage.
 */
function theme_background_verified_current_paths(): array
{
    $paths = [];
    foreach (['theme_background_path', 'theme_background_original_path', 'theme_background_optimized_path'] as $settingKey) {
        $configured = app_settings_model_get($settingKey);
        if (!is_string($configured) || trim($configured) === '') {
            continue;
        }
        $resolved = theme_background_existing_path($configured);
        $absolute = dirname(__DIR__, 3) . '/' . ltrim($configured, '/');
        if ($resolved === null || is_link($absolute)) {
            throw new RuntimeException('A configured Theme background image could not be verified for removal.');
        }
        $paths[$resolved] = $resolved;
    }
    return array_values($paths);
}

/**
 * Hash a verified configured background file for an opaque revision snapshot.
 *
 * @param ?string $relativePath Previously validated relative path or null when unset or unavailable.
 * @return ?string SHA-256 file digest, or null when no owned file is configured.
 * @throws RuntimeException When a configured file disappears or cannot be read during the snapshot.
 */
function theme_background_revision_file_digest(?string $relativePath): ?string
{
    if ($relativePath === null) {
        return null;
    }
    $absolute = dirname(__DIR__, 3) . '/' . ltrim($relativePath, '/');
    $digest = @hash_file('sha256', $absolute);
    if (!is_string($digest)) {
        throw new RuntimeException('The configured Theme background changed while its revision was read.');
    }
    return $digest;
}

/**
 * Validate and copy one pending upload into unique private staging paths without changing live settings.
 *
 * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file PHP upload descriptor received by the existing protected CSS Save route.
 * @param int $maxSide Normalized optimized derivative longest side in pixels.
 * @return array{original_stage:string,original_final:string,optimized_stage:string|null,optimized_final:string|null} Unique staged/final identities owned by the Theme background directory.
 * @throws \InvalidArgumentException When upload status, filename, raster MIME, or dimensions are invalid.
 * @throws RuntimeException When staging or optional derivative generation fails.
 */
function theme_background_stage_pending_upload(array $file, int $maxSide): array
{
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    $filename = (string) ($file['name'] ?? '');
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new \InvalidArgumentException('Choose a complete uploaded image before saving the Theme background.');
    }
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        throw new \InvalidArgumentException('The Theme background must be a JPEG, PNG, GIF, or WebP image.');
    }
    $info = @getimagesize($tmpPath);
    $mimeExtension = is_array($info) ? gallery_branding_mime_extension((string) ($info['mime'] ?? '')) : null;
    $normalizedExtension = $extension === 'jpeg' ? 'jpg' : $extension;
    if (!is_array($info) || $mimeExtension === null || $mimeExtension !== $normalizedExtension
        || (int) ($info[0] ?? 0) < 1 || (int) ($info[1] ?? 0) < 1) {
        throw new \InvalidArgumentException('The uploaded Theme background is not a supported image with valid dimensions.');
    }

    $storage = theme_background_storage_dir();
    $token = bin2hex(random_bytes(16));
    $originalStage = $storage . DIRECTORY_SEPARATOR . '.pending-original-' . $token . '.' . $mimeExtension;
    $originalFinal = $storage . DIRECTORY_SEPARATOR . 'background-original-' . $token . '.' . $mimeExtension;
    $optimizedStage = null;
    $optimizedFinal = null;
    if (!move_uploaded_file($tmpPath, $originalStage)) {
        @unlink($originalStage);
        throw new RuntimeException('The Theme background upload could not be staged.');
    }
    try {
        if (theme_background_webp_generation_available()) {
            $optimizedStage = $storage . DIRECTORY_SEPARATOR . '.pending-optimized-' . $token . '.webp';
            $optimizedFinal = $storage . DIRECTORY_SEPARATOR . 'background-optimized-' . $token . '.webp';
            if (!theme_background_write_optimized_derivative($originalStage, $maxSide, $optimizedStage)) {
                @unlink($optimizedStage);
                $optimizedStage = null;
                $optimizedFinal = null;
            }
        }
    } catch (\Throwable $exception) {
        @unlink($originalStage);
        if ($optimizedStage !== null) {
            @unlink($optimizedStage);
        }
        throw new RuntimeException('The Theme background derivative could not be prepared.', 0, $exception);
    }
    return [
        'original_stage' => $originalStage,
        'original_final' => $originalFinal,
        'optimized_stage' => $optimizedStage,
        'optimized_final' => $optimizedFinal,
    ];
}

/**
 * Activate unique staged Theme background files without replacing any prior asset.
 *
 * @param array{original_stage:string,original_final:string,optimized_stage:string|null,optimized_final:string|null} $staged Validated staged identities from theme_background_stage_pending_upload().
 * @return array{original_path:string,optimized_path:string} Gallery-relative paths for the activated original and optional optimized derivative.
 * @throws RuntimeException When any staged file cannot be atomically activated; already activated new files are removed.
 */
function theme_background_activate_staged_upload(array $staged): array
{
    $storage = realpath(theme_background_storage_dir());
    $activated = [];
    foreach ([['original_stage', 'original_final'], ['optimized_stage', 'optimized_final']] as [$stageKey, $finalKey]) {
        $stagePath = $staged[$stageKey];
        $finalPath = $staged[$finalKey];
        if ($stagePath === null || $finalPath === null) {
            continue;
        }
        if ($storage === false || realpath(dirname($stagePath)) !== $storage || realpath(dirname($finalPath)) !== $storage
            || is_link($stagePath) || is_link($finalPath) || file_exists($finalPath) || !is_file($stagePath) || !@rename($stagePath, $finalPath)) {
            foreach ($activated as $path) {
                @unlink($path);
            }
            throw new RuntimeException('A staged Theme background asset could not be activated safely.');
        }
        $activated[] = $finalPath;
    }
    return [
        'original_path' => 'cache/theme-background/' . basename($staged['original_final']),
        'optimized_path' => $staged['optimized_final'] !== null ? 'cache/theme-background/' . basename($staged['optimized_final']) : '',
    ];
}

/**
 * Remove remaining staging files and optionally activated immutable assets after a failed composite save.
 *
 * @param array{original_stage:string,original_final:string,optimized_stage:string|null,optimized_final:string|null} $staged File identities returned by theme_background_stage_pending_upload().
 * @param bool $removeActivated Whether to remove the unique final files created by this request.
 * @return void Removes only regular files directly owned by the Theme background storage directory.
 */
function theme_background_discard_staged_upload(array $staged, bool $removeActivated = false): void
{
    $storage = realpath(theme_background_storage_dir());
    if ($storage === false) {
        return;
    }
    foreach (['original_stage', 'original_final', 'optimized_stage', 'optimized_final'] as $key) {
        $path = $staged[$key] ?? null;
        if (!is_string($path) || realpath(dirname($path)) !== $storage || is_link($path)) {
            continue;
        }
        $isFinal = str_ends_with($key, '_final');
        if ((!$isFinal || $removeActivated) && is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * Remove obsolete owned Theme background files after new settings have committed.
 *
 * @param list<string> $keepPaths Gallery-relative original and optimized asset identities now referenced by settings.
 * @return void Best-effort cleanup leaves only the current assets and never follows symbolic links.
 */
function theme_background_cleanup_replaced_assets(array $keepPaths): void
{
    $storage = realpath(theme_background_storage_dir());
    if ($storage === false) {
        return;
    }
    $keep = array_fill_keys(array_map('basename', $keepPaths), true);
    foreach (glob($storage . DIRECTORY_SEPARATOR . 'background*.*') ?: [] as $path) {
        if (is_link($path) || !is_file($path) || dirname($path) !== $storage || isset($keep[basename($path)])) {
            continue;
        }
        @unlink($path);
    }
}

/**
 * Write one resized WebP derivative to a caller-owned path without changing live Theme settings.
 *
 * @param string $sourcePath Verified image file used as the optimization source.
 * @param int $maxSide Maximum output side length in pixels.
 * @param string $targetPath Staging destination inside the owned Theme background directory.
 * @return bool True when a complete WebP file was written and can be verified.
 * @throws RuntimeException When the destination escapes the owned directory.
 */
function theme_background_write_optimized_derivative(string $sourcePath, int $maxSide, string $targetPath): bool
{
    $storage = realpath(theme_background_storage_dir());
    if ($storage === false || realpath(dirname($targetPath)) !== $storage || is_link($targetPath)) {
        throw new RuntimeException('The optimized Theme background destination is unsafe.');
    }
    if (!theme_background_webp_generation_available() || !is_file($sourcePath)) {
        return false;
    }
    $raw = @file_get_contents($sourcePath);
    if (!is_string($raw) || $raw === '') {
        return false;
    }
    $source = @imagecreatefromstring($raw);
    if (!$source instanceof GdImage) {
        return false;
    }
    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1.0, $maxSide / max(1, max($width, $height)));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    if (!$target instanceof GdImage) {
        imagedestroy($source);
        return false;
    }
    imagealphablending($target, false);
    imagesavealpha($target, true);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    imageinterlace($target, true);
    $written = imagewebp($target, $targetPath, 82);
    imagedestroy($target);
    imagedestroy($source);
    if (!$written || !is_file($targetPath) || filesize($targetPath) < 1) {
        @unlink($targetPath);
        return false;
    }
    return true;
}
