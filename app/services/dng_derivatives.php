<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/dng_derivatives.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Creates and serves DNG display derivatives for browser-compatible output.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   2026-05-12
 */

declare(strict_types=1);

/**
 * Return whether one image row represents a DNG original that needs display derivatives.
 */
function image_uses_dng_display_derivatives(array $image): bool
{
    return is_dng_image_path((string) ($image['relative_path'] ?? $image['filename'] ?? ''));
}


/**
 * Return supported DNG source policy values.
 *
 * @return array<int, string>
 */
function dng_conversion_source_policy_options(): array
{
    return ['auto_fallback', 'prefer_raw', 'prefer_preview'];
}

/**
 * Return supported DNG color policy values.
 *
 * @return array<int, string>
 */
function dng_conversion_color_policy_options(): array
{
    return ['force_srgb', 'preserve_look', 'camera_white_balance'];
}

/**
 * Normalize one DNG source policy value.
 */
function dng_normalize_conversion_source_policy(?string $value): string
{
    $value = strtolower(trim((string) $value));
    return in_array($value, dng_conversion_source_policy_options(), true) ? $value : 'auto_fallback';
}

/**
 * Normalize one DNG color policy value.
 */
function dng_normalize_conversion_color_policy(?string $value): string
{
    $value = strtolower(trim((string) $value));
    return in_array($value, dng_conversion_color_policy_options(), true) ? $value : 'force_srgb';
}

/**
 * Return the configured DNG source conversion policy.
 */
function dng_conversion_source_policy(): string
{
    return dng_normalize_conversion_source_policy(function_exists('app_setting') ? app_setting('dng_conversion_source_policy', 'auto_fallback') : 'auto_fallback');
}

/**
 * Return the configured DNG color handling policy.
 */
function dng_conversion_color_policy(): string
{
    return dng_normalize_conversion_color_policy(function_exists('app_setting') ? app_setting('dng_conversion_color_policy', 'force_srgb') : 'force_srgb');
}

/**
 * Return available DNG derivative source paths for the current runtime.
 *
 * @return array{raw:bool,preview_imagick:bool,preview_gd:bool}
 */
function dng_conversion_runtime_capabilities(): array
{
    return [
        'raw' => function_exists('dng_conversion_supported') && dng_conversion_supported(),
        'preview_imagick' => class_exists('Imagick') && function_exists('imagick_format_supported') && imagick_format_supported('JPEG') && imagick_format_supported('WEBP'),
        'preview_gd' => extension_loaded('gd') && function_exists('imagecreatefromjpeg') && function_exists('imagewebp'),
    ];
}

/**
 * Build the ordered source attempts for one configured DNG policy.
 *
 * @param array{raw?:bool,preview_imagick?:bool,preview_gd?:bool} $capabilities
 * @return array<int, string>
 */
function dng_conversion_attempt_order(string $sourcePolicy, array $capabilities): array
{
    $sourcePolicy = dng_normalize_conversion_source_policy($sourcePolicy);
    $rawAvailable = !empty($capabilities['raw']);
    $previewImagickAvailable = !empty($capabilities['preview_imagick']);
    $previewGdAvailable = !empty($capabilities['preview_gd']);

    $previewAttempts = [];
    if ($previewImagickAvailable) {
        $previewAttempts[] = 'preview_imagick';
    }
    if ($previewGdAvailable) {
        $previewAttempts[] = 'preview_gd';
    }

    if ($sourcePolicy === 'prefer_preview') {
        return array_values(array_unique(array_merge($previewAttempts, $rawAvailable ? ['raw'] : [])));
    }

    return array_values(array_unique(array_merge($rawAvailable ? ['raw'] : [], $previewAttempts)));
}

/**
 * Store the most recent DNG conversion diagnostic for this request.
 */
function dng_set_last_conversion_error(string $code, string $message, array $context = []): void
{
    $GLOBALS['cms_last_dng_conversion_error'] = ['code' => $code, 'message' => $message, 'context' => $context];
}

/**
 * Return the most recent DNG conversion diagnostic for this request.
 *
 * @return array{code:string,message:string,context:array<string,mixed>}|null
 */
function dng_last_conversion_error(): ?array
{
    return isset($GLOBALS['cms_last_dng_conversion_error']) && is_array($GLOBALS['cms_last_dng_conversion_error']) ? $GLOBALS['cms_last_dng_conversion_error'] : null;
}

/**
 * Clear the most recent DNG conversion diagnostic for this request.
 */
function dng_clear_last_conversion_error(): void
{
    unset($GLOBALS['cms_last_dng_conversion_error']);
}

/**
 * Return whether DNG display derivative generation is available.
 */
function dng_derivative_generation_supported(): bool
{
    if (function_exists('dng_conversion_supported') && dng_conversion_supported()) {
        return true;
    }
    return function_exists('dng_embedded_preview_supported') && dng_embedded_preview_supported();
}

/**
 * Return a readable status explaining whether DNG derivative generation can run.
 */
function dng_derivative_generation_status(): array
{
    $capabilities = dng_conversion_runtime_capabilities();
    $attempts = dng_conversion_attempt_order(dng_conversion_source_policy(), $capabilities);
    if ($attempts !== []) {
        $labels = array_map(static fn (string $attempt): string => match ($attempt) {
            'raw' => t('thumbnail.dng_support.path_raw', 'full RAW decode'),
            'preview_imagick' => t('thumbnail.dng_support.path_preview_imagick', 'embedded preview through Imagick'),
            'preview_gd' => t('thumbnail.dng_support.path_preview_gd', 'embedded preview through GD'),
            default => $attempt,
        }, $attempts);
        return ['supported' => true, 'reason' => t('thumbnail.dng_support.policy_paths', 'DNG conversion is available. Active order: {paths}.', ['paths' => implode(', ', $labels)])];
    }
    if (!extension_loaded('imagick') || !class_exists(Imagick::class)) {
        return ['supported' => false, 'reason' => t('thumbnail.dng_support.imagick_missing')];
    }
    foreach (['DNG', 'WEBP', 'JPEG'] as $format) {
        if (!imagick_format_supported($format)) {
            return ['supported' => false, 'reason' => t('thumbnail.dng_support.format_missing', ['format' => $format])];
        }
    }
    return ['supported' => false, 'reason' => t('thumbnail.dng_support.preview_decode_unavailable')];
}

/**
 * Return the generated WebP master filename for one DNG source.
 */
function dng_display_master_filename(array $image): string
{
    // $base stores the readable part of the derivative filename.
    $base = pathinfo((string) ($image['filename'] ?? 'image'), PATHINFO_FILENAME);
    if ($base === '') {
        $base = 'image';
    }
    return $base . '_display_' . (int) ($image['id'] ?? 0) . '.webp';
}

/**
 * Return the absolute generated WebP master path for one DNG source.
 */
function dng_display_master_abs_path(array $image, array $gallery, bool $create = false): string
{
    return gallery_thumbs_dir($gallery, $create) . DIRECTORY_SEPARATOR . dng_display_master_filename($image);
}

/**
 * Return a stable source MIME value for derivative decisions.
 */
function image_source_mime_for_derivatives(string $sourcePath, array $image = []): string
{
    if (image_uses_dng_display_derivatives($image) || is_dng_image_path($sourcePath)) {
        return 'image/x-adobe-dng';
    }

    // $info stores PHP image metadata for ordinary browser-displayable images.
    $info = @getimagesize($sourcePath);
    return is_array($info) ? (string) ($info['mime'] ?? '') : '';
}

/**
 * Return the file that public media routes are allowed to stream for visible display.
 *
 * @return array{path:string,mime:string,filename:string,variant:string}|null
 */
function image_public_display_file(array $image, array $gallery, bool $createIfMissing = false): ?array
{
    // $sourcePath stores the original uploaded source file.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        return null;
    }

    if (image_uses_dng_display_derivatives($image)) {
        try {
            // $masterPath stores the generated browser-displayable WebP master.
            $masterPath = dng_display_master_abs_path($image, $gallery, $createIfMissing);
        } catch (RuntimeException) {
            return null;
        }
        // $sourceMtime stores the original DNG timestamp used to refresh stale derivatives.
        $sourceMtime = filemtime($sourcePath) ?: 0;
        if ($createIfMissing && (!is_file($masterPath) || filemtime($masterPath) < $sourceMtime)) {
            create_dng_display_master($sourcePath, $masterPath);
        }
        if (!is_file($masterPath)) {
            return null;
        }
        return [
            'path' => $masterPath,
            'mime' => 'image/webp',
            'filename' => dng_display_master_filename($image),
            'variant' => 'dng_master',
        ];
    }

    // $finfo stores an intermediate value used by the surrounding gallery workflow.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    // $mime stores an intermediate value used by the surrounding gallery workflow.
    $mime = (string) ($finfo->file($sourcePath) ?: mime_content_type($sourcePath));
    if (!str_starts_with($mime, 'image/')) {
        return null;
    }

    return [
        'path' => $sourcePath,
        'mime' => $mime,
        'filename' => basename((string) ($image['filename'] ?? basename($sourcePath))),
        'variant' => 'original',
    ];
}

/**
 * Create or refresh the full-size WebP display master for a DNG source.
 */
function create_dng_display_master(string $sourcePath, string $targetPath): bool
{
    return write_dng_derivative($sourcePath, $targetPath, 'webp', null);
}


/**
 * Apply the configured color policy to an Imagick DNG or preview image.
 */
function dng_apply_imagick_color_policy(Imagick $image): void
{
    $colorPolicy = dng_conversion_color_policy();
    if ($colorPolicy === 'preserve_look') {
        return;
    }

    if ($colorPolicy === 'camera_white_balance') {
        try {
            if (method_exists($image, 'autoLevelImage')) {
                $image->autoLevelImage();
            }
        } catch (Throwable) {
            // Keep conversion resilient when the host Imagick build cannot apply this operation.
        }
    }

    try {
        if (method_exists($image, 'transformImageColorspace')) {
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        } else {
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        }
    } catch (Throwable) {
        try {
            $image->setImageColorspace(Imagick::COLORSPACE_SRGB);
        } catch (Throwable) {
            // Color conversion is best-effort. Failed color conversion must not block fallback paths.
        }
    }
}

/**
 * Write one DNG derivative through Imagick.
 */
function write_dng_imagick_derivative(string $sourcePath, string $targetPath, string $format, ?int $maxSide): bool
{
    if (!function_exists('dng_conversion_supported') || !dng_conversion_supported()) {
        dng_set_last_conversion_error('raw_delegate_missing', t('thumbnail.dng_error.raw_delegate_missing', 'Imagick is available, but the required DNG, JPEG, or WebP delegate support is missing.'));
        return false;
    }
    if (!in_array($format, ['jpg', 'webp'], true)) {
        return false;
    }

    // $image stores the Imagick object so all code paths can release it.
    $image = null;
    try {
        $image = new Imagick($sourcePath);
        if ($image->getNumberImages() > 1) {
            $image->setIteratorIndex(0);
        }
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        } elseif (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }
        if ($maxSide !== null) {
            $image->thumbnailImage($maxSide, $maxSide, true, true);
        }
        dng_apply_imagick_color_policy($image);
        if ($format === 'jpg') {
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageFormat('jpeg');
        } else {
            $image->setImageFormat('webp');
        }
        $image->setImageCompressionQuality($format === 'jpg' ? 86 : 88);
        // $written stores whether Imagick successfully wrote the derivative file.
        $written = $image->writeImage($targetPath);
        $image->clear();
        $image->destroy();
        return $written && is_file($targetPath);
    } catch (Throwable $exception) {
        dng_set_last_conversion_error('raw_decode_failed', t('thumbnail.dng_error.raw_decode_failed', 'Full DNG RAW decode failed: {error}', ['error' => $exception->getMessage()]));
        thumbnail_remove_partial_file($targetPath);
        if ($image instanceof Imagick) {
            $image->clear();
            $image->destroy();
        }
        return false;
    }
}

/**
 * Write one resized derivative from an extracted DNG JPEG preview through Imagick.
 */
function write_dng_preview_derivative_with_imagick(string $previewPath, string $targetPath, string $format, int $maxSide): bool
{
    if (!class_exists(Imagick::class)) {
        return false;
    }
    if ($format === 'webp' && !thumbnail_imagick_webp_available()) {
        return false;
    }
    if ($format === 'jpg' && !imagick_format_supported('JPEG')) {
        return false;
    }
    // $image stores the preview decoder instance so it can always be released.
    $image = null;
    try {
        $image = new Imagick($previewPath);
        if (method_exists($image, 'autoOrient')) {
            $image->autoOrient();
        } elseif (method_exists($image, 'autoOrientImage')) {
            $image->autoOrientImage();
        }
        $image->thumbnailImage($maxSide, $maxSide, true, true);
        dng_apply_imagick_color_policy($image);
        if ($format === 'jpg') {
            $image->setImageBackgroundColor('white');
            $image = $image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(86);
        } else {
            $image->setImageFormat('webp');
            $image->setImageCompressionQuality(88);
        }
        // $written stores whether Imagick successfully wrote the preview derivative.
        $written = $image->writeImage($targetPath);
        $image->clear();
        $image->destroy();
        return $written && is_file($targetPath);
    } catch (Throwable $exception) {
        dng_set_last_conversion_error('preview_imagick_failed', t('thumbnail.dng_error.preview_imagick_failed', 'Embedded DNG preview conversion through Imagick failed: {error}', ['error' => $exception->getMessage()]));
        thumbnail_remove_partial_file($targetPath);
        if ($image instanceof Imagick) {
            $image->clear();
            $image->destroy();
        }
        return false;
    }
}

/**
 * Write one DNG derivative from the embedded JPEG preview fallback.
 */
function write_dng_embedded_preview_derivative(string $sourcePath, string $targetPath, string $format, ?int $maxSide, ?string $forcedPreviewPath = null): bool
{
    if (!function_exists('dng_extract_embedded_jpeg_preview') || !dng_embedded_preview_supported()) {
        dng_set_last_conversion_error('preview_decoder_missing', t('thumbnail.dng_error.preview_decoder_missing', 'No usable embedded DNG preview decoder is available through Imagick or GD.'));
        return false;
    }
    if (!in_array($format, ['jpg', 'webp'], true)) {
        return false;
    }

    // $temporaryPath stores the extracted JPEG preview used as the resize source.
    $temporaryPath = tempnam(sys_get_temp_dir(), 'php_gallery_dng_preview_');
    if ($temporaryPath === false) {
        return false;
    }

    try {
        if (!dng_extract_embedded_jpeg_preview($sourcePath, $temporaryPath)) {
            dng_set_last_conversion_error('preview_extraction_failed', t('thumbnail.dng_error.preview_extraction_failed', 'No usable embedded JPEG preview could be extracted from the DNG file.'));
            @unlink($temporaryPath);
            return false;
        }
        // $info stores the extracted JPEG preview dimensions.
        $info = @getimagesize($temporaryPath);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            @unlink($temporaryPath);
            return false;
        }
        // $effectiveMaxSide stores the requested thumbnail size or the full preview side for the display master.
        $effectiveMaxSide = $maxSide ?? max((int) $info[0], (int) $info[1]);
        // $written stores whether the selected preview decoder successfully wrote the derivative.
        $written = false;
        if ($forcedPreviewPath !== 'preview_gd') {
            $written = write_dng_preview_derivative_with_imagick($temporaryPath, $targetPath, $format, $effectiveMaxSide);
        }
        if (!$written && $forcedPreviewPath !== 'preview_imagick') {
            // $source stores the GD image created from the embedded JPEG preview.
            $source = @imagecreatefromjpeg($temporaryPath);
            if (!$source) {
                dng_set_last_conversion_error('preview_gd_decode_failed', t('thumbnail.dng_error.preview_gd_decode_failed', 'GD could not decode the extracted DNG JPEG preview.'));
                @unlink($temporaryPath);
                return false;
            }
            if ($format === 'jpg') {
                $written = write_resized_jpeg($source, (int) $info[0], (int) $info[1], $effectiveMaxSide, $targetPath);
            } else {
                $written = write_resized_webp_with_gd($source, (int) $info[0], (int) $info[1], $effectiveMaxSide, $targetPath);
            }
            imagedestroy($source);
        }
        @unlink($temporaryPath);
        if (!$written || !is_file($targetPath)) {
            dng_set_last_conversion_error('write_failed', t('thumbnail.dng_error.write_failed', 'DNG derivative write failed for {file}.', ['file' => basename($targetPath)]));
            thumbnail_remove_partial_file($targetPath);
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        dng_set_last_conversion_error('preview_conversion_failed', t('thumbnail.dng_error.preview_conversion_failed', 'Embedded DNG preview conversion failed: {error}', ['error' => $exception->getMessage()]));
        thumbnail_remove_partial_file($targetPath);
        @unlink($temporaryPath);
        return false;
    }
}

/**
 * Write one DNG derivative through the strongest available source path.
 */
function write_dng_derivative(string $sourcePath, string $targetPath, string $format, ?int $maxSide): bool
{
    dng_clear_last_conversion_error();
    $attempts = dng_conversion_attempt_order(dng_conversion_source_policy(), dng_conversion_runtime_capabilities());
    if ($attempts === []) {
        dng_set_last_conversion_error('no_conversion_path', t('thumbnail.dng_error.no_conversion_path', 'No usable DNG conversion path is available on this server.'));
        return false;
    }

    $errors = [];
    foreach ($attempts as $attempt) {
        $written = false;
        if ($attempt === 'raw') {
            $written = write_dng_imagick_derivative($sourcePath, $targetPath, $format, $maxSide);
        } elseif ($attempt === 'preview_imagick' || $attempt === 'preview_gd') {
            $written = write_dng_embedded_preview_derivative($sourcePath, $targetPath, $format, $maxSide, $attempt);
        }
        if ($written) {
            dng_clear_last_conversion_error();
            return true;
        }
        $lastError = dng_last_conversion_error();
        if ($lastError !== null) {
            $errors[] = (string) $lastError['message'];
        }
    }

    dng_set_last_conversion_error('all_paths_failed', t('thumbnail.dng_error.all_paths_failed', 'All DNG conversion paths failed: {errors}', ['errors' => implode(' | ', array_values(array_unique($errors)))]));
    return false;
}

/**
 * Create thumbnails plus the WebP display master for one DNG source.
 */
function create_dng_image_derivatives_result(array $image, array $gallery, string $sourcePath): array
{
    if (!is_file($sourcePath)) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 1, 'errors' => [t('thumbnails.dng.error_original_missing')]];
    }
    // $generationStatus stores the concrete DNG converter availability state for user-facing diagnostics.
    $generationStatus = dng_derivative_generation_status();
    if (empty($generationStatus['supported'])) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 1, 'errors' => [(string) $generationStatus['reason']]];
    }
    gallery_thumbs_dir($gallery, true);
    // $sourceMtime stores the original DNG timestamp used to detect stale generated files.
    $sourceMtime = filemtime($sourcePath) ?: time();
    // $created stores the number of generated or refreshed derivatives.
    $created = 0;
    // $skipped stores the number of already fresh derivatives.
    $skipped = 0;
    // $webpSkipped stores the number of WebP derivatives that failed to generate.
    $webpSkipped = 0;
    // $failed stores derivatives that could not be generated and are required for DNG display.
    $failed = 0;
    // $errors stores concise diagnostic messages for the admin upload and thumbnail progress UI.
    $errors = [];

    // $masterPath stores the browser-displayable full-size WebP master.
    $masterPath = dng_display_master_abs_path($image, $gallery, true);
    if (is_file($masterPath) && filemtime($masterPath) >= $sourceMtime) {
        $skipped++;
    } elseif (create_dng_display_master($sourcePath, $masterPath)) {
        $created++;
    } else {
        $webpSkipped++;
        $failed++;
        $lastError = dng_last_conversion_error();
        $errors[] = $lastError !== null ? (string) $lastError['message'] : t('thumbnails.dng.error_master_failed');
    }

    foreach (thumbnail_sizes() as $size) {
        foreach (['jpg', 'webp'] as $format) {
            // $targetPath stores the derivative path for this size and format.
            $targetPath = thumbnail_abs_path($image, $gallery, (int) $size, $format);
            if (is_file($targetPath) && filemtime($targetPath) >= $sourceMtime) {
                $skipped++;
                continue;
            }
            // $written stores whether the DNG derivative was created successfully.
            $written = write_dng_derivative($sourcePath, $targetPath, $format, (int) $size);
            if ($written) {
                $created++;
            } else {
                $failed++;
                if ($format === 'webp') {
                    $webpSkipped++;
                }
                $lastError = dng_last_conversion_error();
                if ($lastError !== null) {
                    $errors[] = (string) $lastError['message'];
                }
            }
        }
    }

    if ($failed > 0 && !$errors) {
        $errors[] = t('thumbnails.dng.error_derivatives_failed');
    }

    return ['created' => $created, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => $failed, 'errors' => array_values(array_unique($errors))];
}
