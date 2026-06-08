<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnail_generation.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Generates thumbnail files and resized image variants.
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
 * Return the JPEG quality used by generated thumbnail files.
 */
function thumbnail_jpeg_quality(): int
{
    return 82;
}

/**
 * Return the WebP quality used by generated thumbnail files.
 */
function thumbnail_webp_quality(): int
{
    return 82;
}

/**
 * Handles create gallery thumbnails logic for the gallery application.
 * @param mixed $galleryId Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function create_gallery_thumbnails(int $galleryId): int
{
    // Variable $galleryIds stores this steps working value.
    $galleryIds = gallery_subtree_ids($galleryId);
    if (!$galleryIds) {
        return 0;
    }

    // Variable $count stores this steps working value.
    $count = 0;
    foreach ($galleryIds as $currentGalleryId) {
        // Variable $gallery stores this steps working value.
        $gallery = find_gallery((int) $currentGalleryId);
        if (!$gallery) {
            continue;
        }
        foreach (gallery_images((int) $currentGalleryId, false) as $image) {
            $count += create_image_thumbnails($image, $gallery);
        }
    }
    return $count;
}

/**
 * Handles create all thumbnails logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function create_all_thumbnails(): int
{
    // Variable $count stores this steps working value.
    $count = 0;
    foreach (db()->query('SELECT id FROM galleries ORDER BY folder_path')->fetchAll(PDO::FETCH_COLUMN) as $galleryId) {
        $count += create_gallery_thumbnails((int) $galleryId);
    }
    return $count;
}

/**
 * Handles create image thumbnails logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function create_image_thumbnails(array $image, array $gallery): int
{
    return create_image_thumbnails_result($image, $gallery)['created'];
}

/**
 * Handles create image thumbnails result logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param array<int, int>|null $requestedSizes Optional thumbnail sizes to generate instead of the full standard set.
 * @return mixed Result produced by this operation.
 */
function create_image_thumbnails_result(array $image, array $gallery, ?array $requestedSizes = null): array
{
    // Variable $sourcePath stores this steps working value.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => [], 'thumbnail_policy' => null];
    }
    gallery_thumbs_dir($gallery, true);
    if (image_uses_dng_display_derivatives($image)) {
        return create_dng_image_derivatives_result($image, $gallery, $sourcePath, $requestedSizes);
    }
    // Variable $info stores this steps working value.
    $info = @getimagesize($sourcePath);
    if ($info === false || empty($info['mime'])) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => [], 'thumbnail_policy' => null];
    }
    // $mime stores the source MIME value used by the scanner and generator format decision.
    $mime = (string) $info['mime'];
    // $formats stores the variants this server can actually keep current for this source.
    $formats = thumbnail_target_formats_for_source($sourcePath, $mime);
    // Variable $sourceMtime stores this steps working value.
    $sourceMtime = filemtime($sourcePath) ?: time();
    // Variable $skipped stores this steps working value.
    $skipped = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = thumbnail_intentionally_skipped_webp_count($sourcePath, $mime);
    // $sizes stores the generated thumbnail sizes requested by this operation. Null means the full standard set.
    $sizes = $requestedSizes === null ? thumbnail_sizes() : array_values(array_unique(array_filter(array_map('intval', $requestedSizes), static fn (int $size): bool => in_array($size, thumbnail_sizes(), true))));
    // $thumbnailPolicy stores the exact generation policy for diagnostics and warmup logs.
    $thumbnailPolicy = function_exists('thumbnail_generation_policy_summary') ? thumbnail_generation_policy_summary($sourcePath, $mime, $sizes) : null;
    if (!$sizes) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy];
    }
    if (!$formats) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => [], 'thumbnail_policy' => $thumbnailPolicy];
    }
    // Variable $targets stores this steps working value.
    $targets = [];
    foreach ($sizes as $size) {
        foreach ($formats as $format) {
            // Variable $targetPath stores this steps working value.
            $targetPath = thumbnail_abs_path($image, $gallery, $size, $format);
            if (is_file($targetPath) && filemtime($targetPath) >= $sourceMtime) {
                $skipped++;
                continue;
            }
            $targets[$size][$format] = $targetPath;
        }
    }
    if (!$targets) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy];
    }
    if (!extension_loaded('gd')) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy];
    }
    // Variable $source stores this steps working value.
    $source = image_create_from_path($sourcePath, (string) $info['mime']);
    if (!$source) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => [], 'created_files' => [], 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy];
    }
    // Variable $created stores this steps working value.
    $created = 0;
    // $createdFiles stores generated thumbnail basenames for detailed warmup logging.
    $createdFiles = [];
    foreach ($targets as $size => $formatTargets) {
        if (isset($formatTargets['jpg']) && write_resized_jpeg($source, (int) $info[0], (int) $info[1], (int) $size, $formatTargets['jpg'])) {
            $created++;
            $createdFiles[] = basename($formatTargets['jpg']);
        }
        if (isset($formatTargets['webp'])) {
            // $webpWritten stores an intermediate value used by the surrounding gallery workflow.
            $webpWritten = write_resized_webp_preserving_exif_when_needed($sourcePath, $source, (int) $info[0], (int) $info[1], (int) $size, $formatTargets['webp'], $mime);
            if ($webpWritten) {
                $created++;
                $createdFiles[] = basename($formatTargets['webp']);
            } else {
                $webpSkipped++;
            }
        }
    }
    imagedestroy($source);
    return ['created' => $created, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped, 'failed' => 0, 'errors' => [], 'created_files' => $createdFiles, 'target_formats' => $formats, 'thumbnail_policy' => $thumbnailPolicy];
}

/**
 * Handles image ids for galleries logic for the gallery application.
 * @param mixed $galleryIds Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function image_ids_for_galleries(array $galleryIds): array
{
    // Variable $galleryIds stores this steps working value.
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if (!$galleryIds) {
        return [];
    }
    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT id FROM images WHERE gallery_id IN ($placeholders) AND relative_path NOT LIKE '%/%' ORDER BY gallery_id, sort_order, filename");
    $stmt->execute($galleryIds);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Handles all image ids logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function all_image_ids(): array
{
    // Variable $rows stores this steps working value.
    $rows = db()->query("SELECT i.id FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.sort_order, i.filename")->fetchAll(PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}

/**
 * Handles image create from path logic for the gallery application.
 * @param mixed $path Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function image_create_from_path(string $path, string $mime): GdImage|false
{
    return match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png' => imagecreatefrompng($path),
        'image/gif' => imagecreatefromgif($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
        default => false,
    };
}

/**
 * Handles write resized jpeg logic for the gallery application.
 * @param mixed $source Input used by this operation.
 * @param mixed $width Input used by this operation.
 * @param mixed $height Input used by this operation.
 * @param mixed $maxSide Input used by this operation.
 * @param mixed $targetPath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function write_resized_jpeg(GdImage $source, int $width, int $height, int $maxSide, string $targetPath): bool
{
    // Variable $scale stores this steps working value.
    $scale = min(1.0, $maxSide / max($width, $height));
    // Variable $targetWidth stores this steps working value.
    $targetWidth = max(1, (int) round($width * $scale));
    // Variable $targetHeight stores this steps working value.
    $targetHeight = max(1, (int) round($height * $scale));
    // Variable $target stores this steps working value.
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    // Variable $white stores this steps working value.
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    imageinterlace($target, true);
    // Variable $written stores this steps working value.
    $written = imagejpeg($target, $targetPath, thumbnail_jpeg_quality());
    imagedestroy($target);
    return $written;
}

/**
 * Handles image source has exif logic for the gallery application.
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function image_source_has_exif(string $sourcePath, string $mime): bool
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return false;
    }
    // $exif stores an intermediate value used by the surrounding gallery workflow.
    $exif = @exif_read_data($sourcePath, null, true, false);
    return is_array($exif) && $exif !== [];
}

/**
 * Handles write resized webp preserving exif when needed logic for the gallery application.
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $source Input used by this operation.
 * @param mixed $width Input used by this operation.
 * @param mixed $height Input used by this operation.
 * @param mixed $maxSide Input used by this operation.
 * @param mixed $targetPath Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function write_resized_webp_preserving_exif_when_needed(string $sourcePath, GdImage $source, int $width, int $height, int $maxSide, string $targetPath, string $mime): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    if (image_source_has_exif($sourcePath, $mime) && thumbnail_imagick_webp_available()) {
        // $imagickWritten stores whether the preferred metadata-preserving writer succeeded.
        $imagickWritten = write_resized_webp_with_imagick_exif($sourcePath, $maxSide, $targetPath);
        if ($imagickWritten) {
            return true;
        }

        // Some hosts expose WebP through Imagick, but individual panoramic JPEGs can still fail
        // because of pixel-cache or image-policy limits. Falling back to GD keeps the thumbnail
        // cache repairable instead of leaving one image permanently reported as missing.
        thumbnail_remove_partial_file($targetPath);
    }

    return write_resized_webp_with_gd($source, $width, $height, $maxSide, $targetPath);
}

/**
 * Remove a partially written target file after a failed writer attempt.
 */
function thumbnail_remove_partial_file(string $targetPath): void
{
    if (is_file($targetPath)) {
        @unlink($targetPath);
    }
}

/**
 * Handles write resized webp with gd logic for the gallery application.
 * @param mixed $source Input used by this operation.
 * @param mixed $width Input used by this operation.
 * @param mixed $height Input used by this operation.
 * @param mixed $maxSide Input used by this operation.
 * @param mixed $targetPath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function write_resized_webp_with_gd(GdImage $source, int $width, int $height, int $maxSide, string $targetPath): bool
{
    // Variable $scale stores this steps working value.
    $scale = min(1.0, $maxSide / max($width, $height));
    // Variable $targetWidth stores this steps working value.
    $targetWidth = max(1, (int) round($width * $scale));
    // Variable $targetHeight stores this steps working value.
    $targetHeight = max(1, (int) round($height * $scale));
    // Variable $target stores this steps working value.
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($target, true);
    imagesavealpha($target, true);
    // Variable $transparent stores this steps working value.
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
    // Variable $written stores this steps working value.
    $written = imagewebp($target, $targetPath, thumbnail_webp_quality());
    imagedestroy($target);
    return $written;
}

/**
 * Handles write resized webp with imagick exif logic for the gallery application.
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $maxSide Input used by this operation.
 * @param mixed $targetPath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function write_resized_webp_with_imagick_exif(string $sourcePath, int $maxSide, string $targetPath): bool
{
    if (!thumbnail_imagick_webp_available()) {
        return false;
    }

    // $image stores the Imagick instance so it can be cleaned up even after a failed write.
    $image = null;
    try {
        // $image stores an intermediate value used by the surrounding gallery workflow.
        $image = new Imagick($sourcePath);
        // $profiles stores an intermediate value used by the surrounding gallery workflow.
        $profiles = $image->getImageProfiles('exif', true);
        $image->thumbnailImage($maxSide, $maxSide, true, true);
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(thumbnail_webp_quality());
        if (isset($profiles['exif']) && $profiles['exif'] !== '') {
            $image->profileImage('exif', $profiles['exif']);
        }
        // $written stores an intermediate value used by the surrounding gallery workflow.
        $written = $image->writeImage($targetPath);
        $image->clear();
        $image->destroy();
        return $written && is_file($targetPath);
    } catch (Throwable) {
        thumbnail_remove_partial_file($targetPath);
        if ($image instanceof Imagick) {
            $image->clear();
            $image->destroy();
        }
        return false;
    }
}
