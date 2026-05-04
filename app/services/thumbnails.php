<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/thumbnails.php
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

/**
 * Thumbnail generation model.
 * 
 * This module owns thumbnail naming, thumbnail URLs, srcset generation, maintenance status, and image resize/write operations. It does not change gallery theme, favicon, or custom CSS settings.
 */

function thumbnail_sizes(): array
{
    return [300, 600, 800, 960, 1280, 1600];
}

/**
 * Handles thumbnail srcset logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @param mixed 600 Input used by this operation.
 * @param mixed 800] Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_srcset(array $image, array $sizes = [300, 600, 800]): string
{
    return thumbnail_srcset_for_format($image, $sizes, 'jpg');
}

/**
 * Handles thumbnail webp srcset logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @param mixed 600 Input used by this operation.
 * @param mixed 800] Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_webp_srcset(array $image, array $sizes = [300, 600, 800]): string
{
    return thumbnail_srcset_for_format($image, $sizes, 'webp');
}

/**
 * Handles thumbnail srcset for format logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_srcset_for_format(array $image, array $sizes, string $format): string
{
    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = [];
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || !in_array($format, ['jpg', 'webp'], true)) {
        return '';
    }
    foreach ($sizes as $size) {
        // $size stores an intermediate value used by the surrounding gallery workflow.
        $size = (int) $size;
        if (!in_array($size, thumbnail_sizes(), true)) {
            continue;
        }
        try {
            if (!is_file(thumbnail_abs_path($image, $gallery, $size, $format))) {
                continue;
            }
        } catch (RuntimeException) {
            continue;
        }
        $entries[] = thumbnail_url($image, $size, $format) . ' ' . $size . 'w';
    }
    return implode(', ', $entries);
}

/**
 * Handles gallery thumbs dir logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $create Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_thumbs_dir(array $gallery, bool $create = false): string
{
    // Variable $path stores this steps working value.
    $path = gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . 'thumbs';
    if ($create && !is_dir($path)) {
        mkdir($path, 0775, true);
    }
    if (!path_inside(gallery_abs_path((string) $gallery['folder_path']), $path)) {
        throw new RuntimeException('Thumbnail path is outside its gallery.');
    }
    return $path;
}

/**
 * Handles thumbnail filename logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $size Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_filename(array $image, int $size, string $format = 'jpg'): string
{
    if (!in_array($format, ['jpg', 'webp'], true)) {
        throw new RuntimeException('Unsupported thumbnail format.');
    }
    return pathinfo((string) $image['filename'], PATHINFO_FILENAME) . '_thumb' . $size . '.' . $format;
}

/**
 * Handles thumbnail abs path logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $size Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_abs_path(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    if (!in_array($size, thumbnail_sizes(), true)) {
        throw new RuntimeException('Unsupported thumbnail size.');
    }
    return gallery_thumbs_dir($gallery, false) . DIRECTORY_SEPARATOR . thumbnail_filename($image, $size, $format);
}

/**
 * Handles thumbnail can use static public url logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_can_use_static_public_url(array $image, array $gallery): bool
{
    if ((string) ($image['visibility'] ?? '') !== 'public' || gallery_access_requirement($gallery) !== null) {
        return false;
    }
    // $configuredRoot stores an intermediate value used by the surrounding gallery workflow.
    $configuredRoot = realpath(galleries_root());
    // $defaultRoot stores an intermediate value used by the surrounding gallery workflow.
    $defaultRoot = realpath(dirname(__DIR__) . '/galleries');
    return $configuredRoot !== false && $defaultRoot !== false && $configuredRoot === $defaultRoot;
}

/**
 * Handles gallery static file url logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $relativeFilePath Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_static_file_url(array $gallery, string $relativeFilePath): string
{
    // $galleryPath stores an intermediate value used by the surrounding gallery workflow.
    $galleryPath = normalize_relative_path((string) $gallery['folder_path']);
    // $filePath stores an intermediate value used by the surrounding gallery workflow.
    $filePath = normalize_relative_path($relativeFilePath);
    // $segments stores an intermediate value used by the surrounding gallery workflow.
    $segments = array_filter(explode('/', trim($galleryPath . '/' . $filePath, '/')), static fn (string $segment): bool => $segment !== '');
    // $encoded stores an intermediate value used by the surrounding gallery workflow.
    $encoded = array_map('rawurlencode', $segments);
    return base_url('galleries/' . implode('/', $encoded));
}

/**
 * Handles thumbnail url logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $size Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_url(array $image, int $size, string $format = 'jpg'): string
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if ($gallery) {
        try {
            // $path stores an intermediate value used by the surrounding gallery workflow.
            $path = thumbnail_abs_path($image, $gallery, $size, $format);
            if (is_file($path)) {
                return thumbnail_serving_url($image, $gallery, $size, $format);
            }
            // $fallback stores an intermediate value used by the surrounding gallery workflow.
            $fallback = thumbnail_existing_fallback($image, $gallery, $size, $format);
            if ($fallback !== null) {
                return thumbnail_serving_url($image, $gallery, $fallback['size'], $fallback['format']);
            }
        } catch (RuntimeException) {
            return public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
        }
        return public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
    }
    return url_for('media', ['id' => $image['id']]);
}

/**
 * Handles thumbnail serving url logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $size Input used by this operation.
 * @param mixed $format Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_serving_url(array $image, array $gallery, int $size, string $format = 'jpg'): string
{
    if (public_path_schema_ready()) {
        return image_public_thumbnail_url($image, $gallery, $size, $format);
    }
    if (thumbnail_can_use_static_public_url($image, $gallery)) {
        return gallery_static_file_url($gallery, 'thumbs/' . thumbnail_filename($image, $size, $format));
    }
    return url_for('thumb', ['id' => $image['id'], 'size' => $size, 'format' => $format]);
}

/**
 * Handles thumbnail existing fallback logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $preferredSize Input used by this operation.
 * @param mixed $preferredFormat Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_existing_fallback(array $image, array $gallery, int $preferredSize, string $preferredFormat = 'jpg'): ?array
{
    // Variable $sizes stores this steps working value.
    $sizes = thumbnail_sizes();
    usort($sizes, static function (int $left, int $right) use ($preferredSize): int {
        return abs($left - $preferredSize) <=> abs($right - $preferredSize);
    });
    // Variable $formats stores this steps working value.
    $formats = array_values(array_unique([$preferredFormat, 'jpg', 'webp']));
    foreach ($sizes as $size) {
        foreach ($formats as $format) {
            if (!in_array($format, ['jpg', 'webp'], true)) {
                continue;
            }
            if (is_file(thumbnail_abs_path($image, $gallery, (int) $size, $format))) {
                return ['size' => (int) $size, 'format' => $format];
            }
        }
    }
    return null;
}

/**
 * Handles thumbnail picture html logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $fallbackSize Input used by this operation.
 * @param mixed $srcsetSizes Input used by this operation.
 * @param mixed $sizes Input used by this operation.
 * @param mixed $alt Input used by this operation.
 * @param mixed $extraAttributes Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_picture_html(array $image, int $fallbackSize, array $srcsetSizes, string $sizes, string $alt, string $extraAttributes = ''): string
{
    // $fallbackUrl stores an intermediate value used by the surrounding gallery workflow.
    $fallbackUrl = thumbnail_url($image, $fallbackSize);
    // $webpSrcset stores an intermediate value used by the surrounding gallery workflow.
    $webpSrcset = thumbnail_webp_srcset($image, $srcsetSizes);
    // $jpegSrcset stores an intermediate value used by the surrounding gallery workflow.
    $jpegSrcset = thumbnail_srcset($image, $srcsetSizes);
    // $attributes stores an intermediate value used by the surrounding gallery workflow.
    $attributes = trim($extraAttributes);
    // $html stores an intermediate value used by the surrounding gallery workflow.
    $html = '<picture>';
    if ($webpSrcset !== '') {
        $html .= '<source type="image/webp" srcset="' . e($webpSrcset) . '" sizes="' . e($sizes) . '">';
    }
    $html .= '<img decoding="async" ' . ($attributes === '' ? '' : $attributes . ' ') . 'src="' . e($fallbackUrl) . '"';
    if ($jpegSrcset !== '') {
        $html .= ' srcset="' . e($jpegSrcset) . '"';
    }
    $html .= ' sizes="' . e($sizes) . '" alt="' . e($alt) . '"></picture>';
    return $html;
}

/**
 * Handles thumbnail webp required for source logic for the gallery application.
 * @param mixed $sourcePath Input used by this operation.
 * @param mixed $mime Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_webp_required_for_source(string $sourcePath, string $mime): bool
{
    if (!function_exists('imagewebp')) {
        return false;
    }
    if (!image_source_has_exif($sourcePath, $mime)) {
        return true;
    }
    return class_exists('Imagick');
}

/**
 * Handles thumbnail maintenance status logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_maintenance_status(array $image, array $gallery): array
{
    // Variable $sourcePath stores this steps working value.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        return ['required' => 0, 'missing' => 0, 'webp_skipped' => 0];
    }
    // Variable $sourceMtime stores this steps working value.
    $sourceMtime = filemtime($sourcePath) ?: 0;
    // Variable $info stores this steps working value.
    $info = @getimagesize($sourcePath);
    // $mime stores an intermediate value used by the surrounding gallery workflow.
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    // $formats stores an intermediate value used by the surrounding gallery workflow.
    $formats = ['jpg'];
    // $webpSkipped stores an intermediate value used by the surrounding gallery workflow.
    $webpSkipped = 0;
    if ($mime !== '' && thumbnail_webp_required_for_source($sourcePath, $mime)) {
        $formats[] = 'webp';
    } elseif ($mime === 'image/jpeg' && function_exists('imagewebp') && image_source_has_exif($sourcePath, $mime) && !class_exists('Imagick')) {
        // $webpSkipped stores an intermediate value used by the surrounding gallery workflow.
        $webpSkipped = count(thumbnail_sizes());
    }
    // Variable $required stores this steps working value.
    $required = 0;
    // Variable $missing stores this steps working value.
    $missing = 0;
    foreach (thumbnail_sizes() as $size) {
        foreach ($formats as $format) {
            $required++;
            try {
                // $targetPath stores an intermediate value used by the surrounding gallery workflow.
                $targetPath = thumbnail_abs_path($image, $gallery, (int) $size, $format);
            } catch (RuntimeException) {
                $missing++;
                continue;
            }
            if (!is_file($targetPath) || filemtime($targetPath) < $sourceMtime) {
                $missing++;
            }
        }
    }
    return ['required' => $required, 'missing' => $missing, 'webp_skipped' => $webpSkipped];
}

/**
 * Handles thumbnail maintenance summary logic for the gallery application.
 * @param mixed $galleryIds Input used by this operation.
 * @param mixed $maxImagesToScan Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_maintenance_summary(?array $galleryIds = null, int $maxImagesToScan = 1000): array
{
    // Variable $params stores this steps working value.
    $params = [];
    // $where stores an intermediate value used by the surrounding gallery workflow.
    $where = "i.relative_path NOT LIKE '%/%'";
    if ($galleryIds !== null) {
        // $galleryIds stores an intermediate value used by the surrounding gallery workflow.
        $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
        if (!$galleryIds) {
            return ['images_scanned' => 0, 'images_with_missing' => 0, 'missing_variants' => 0, 'webp_skipped' => 0, 'limited' => false];
        }
        $where .= ' AND i.gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        // $params stores an intermediate value used by the surrounding gallery workflow.
        $params = $galleryIds;
    }
    // $limit stores an intermediate value used by the surrounding gallery workflow.
    $limit = max(1, $maxImagesToScan + 1);
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare("SELECT i.*, g.folder_path AS gallery_folder_path FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE $where ORDER BY g.folder_path, i.sort_order, i.filename LIMIT $limit");
    $stmt->execute($params);
    // $rows stores an intermediate value used by the surrounding gallery workflow.
    $rows = $stmt->fetchAll();
    // $limited stores an intermediate value used by the surrounding gallery workflow.
    $limited = count($rows) > $maxImagesToScan;
    if ($limited) {
        array_pop($rows);
    }
    // Variable $galleryCache stores this steps working value.
    $galleryCache = [];
    // Variable $imagesWithMissing stores this steps working value.
    $imagesWithMissing = 0;
    // Variable $missingVariants stores this steps working value.
    $missingVariants = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = 0;
    foreach ($rows as $image) {
        // $galleryId stores an intermediate value used by the surrounding gallery workflow.
        $galleryId = (int) $image['gallery_id'];
        if (!isset($galleryCache[$galleryId])) {
            $galleryCache[$galleryId] = find_gallery($galleryId);
        }
        if (!$galleryCache[$galleryId]) {
            continue;
        }
        // $status stores an intermediate value used by the surrounding gallery workflow.
        $status = thumbnail_maintenance_status($image, $galleryCache[$galleryId]);
        if ($status['missing'] > 0) {
            $imagesWithMissing++;
            $missingVariants += $status['missing'];
        }
        $webpSkipped += $status['webp_skipped'];
    }
    return [
        'images_scanned' => count($rows),
        'images_with_missing' => $imagesWithMissing,
        'missing_variants' => $missingVariants,
        'webp_skipped' => $webpSkipped,
        'limited' => $limited,
    ];
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
 * @return mixed Result produced by this operation.
 */
function create_image_thumbnails_result(array $image, array $gallery): array
{
    // Variable $sourcePath stores this steps working value.
    $sourcePath = image_abs_path($image, $gallery);
    if (!is_file($sourcePath)) {
        return ['created' => 0, 'skipped' => 0, 'webp_skipped' => 0];
    }
    gallery_thumbs_dir($gallery, true);
    // Variable $sourceMtime stores this steps working value.
    $sourceMtime = filemtime($sourcePath) ?: time();
    // Variable $targets stores this steps working value.
    $targets = [];
    // Variable $skipped stores this steps working value.
    $skipped = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = 0;
    foreach (thumbnail_sizes() as $size) {
        foreach (['jpg', 'webp'] as $format) {
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
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => 0];
    }
    // Variable $info stores this steps working value.
    $info = @getimagesize($sourcePath);
    if ($info === false || empty($info['mime'])) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped];
    }
    if (!extension_loaded('gd')) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped];
    }
    // Variable $source stores this steps working value.
    $source = image_create_from_path($sourcePath, (string) $info['mime']);
    if (!$source) {
        return ['created' => 0, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped];
    }
    // Variable $created stores this steps working value.
    $created = 0;
    foreach ($targets as $size => $formatTargets) {
        if (isset($formatTargets['jpg']) && write_resized_jpeg($source, (int) $info[0], (int) $info[1], (int) $size, $formatTargets['jpg'])) {
            $created++;
        }
        if (isset($formatTargets['webp'])) {
            // $webpWritten stores an intermediate value used by the surrounding gallery workflow.
            $webpWritten = write_resized_webp_preserving_exif_when_needed($sourcePath, $source, (int) $info[0], (int) $info[1], (int) $size, $formatTargets['webp'], (string) $info['mime']);
            if ($webpWritten) {
                $created++;
            } else {
                $webpSkipped++;
            }
        }
    }
    imagedestroy($source);
    return ['created' => $created, 'skipped' => $skipped, 'webp_skipped' => $webpSkipped];
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
    $written = imagejpeg($target, $targetPath, 82);
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
    if (image_source_has_exif($sourcePath, $mime)) {
        return write_resized_webp_with_imagick_exif($sourcePath, $maxSide, $targetPath);
    }
    return write_resized_webp_with_gd($source, $width, $height, $maxSide, $targetPath);
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
    $written = imagewebp($target, $targetPath, 82);
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
    if (!class_exists('Imagick')) {
        return false;
    }
    try {
        // $image stores an intermediate value used by the surrounding gallery workflow.
        $image = new Imagick($sourcePath);
        // $profiles stores an intermediate value used by the surrounding gallery workflow.
        $profiles = $image->getImageProfiles('exif', true);
        $image->thumbnailImage($maxSide, $maxSide, true, true);
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(82);
        if (isset($profiles['exif']) && $profiles['exif'] !== '') {
            $image->profileImage('exif', $profiles['exif']);
        }
        // $written stores an intermediate value used by the surrounding gallery workflow.
        $written = $image->writeImage($targetPath);
        $image->clear();
        $image->destroy();
        return $written;
    } catch (Throwable) {
        return false;
    }
}

