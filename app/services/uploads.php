<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/uploads.php
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
 * Upload service model.
 * 
 * This module validates uploaded files, chooses safe target names, stores gallery images, and records the uploaded image IDs. It deliberately keeps thumbnail generation as a separate service concern.
 */

function gallery_upload_entries(?array $files): array
{
    if (!$files || empty($files['name']) || !is_array($files['name'])) {
        throw new RuntimeException('Choose at least one image to upload.');
    }
    // $entries stores an intermediate value used by the surrounding gallery workflow.
    $entries = [];
    foreach ($files['name'] as $index => $name) {
        // $error stores an intermediate value used by the surrounding gallery workflow.
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(upload_error_message($error));
        }
        // $tmpName stores an intermediate value used by the surrounding gallery workflow.
        $tmpName = (string) ($files['tmp_name'][$index] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('Uploaded file is not available.');
        }
        // $originalName stores an intermediate value used by the surrounding gallery workflow.
        $originalName = (string) $name;
        if (!is_supported_image_path($originalName)) {
            // $message stores an intermediate value used by the surrounding gallery workflow.
            $message = 'Only JPG, PNG, GIF, and WebP images can be uploaded.';
            if (heic_conversion_supported() && raw_conversion_supported()) {
                // $message stores an intermediate value used by the surrounding gallery workflow.
                $message = 'Only JPG, PNG, GIF, WebP, HEIC, HEIF, and DNG images can be uploaded.';
            } elseif (heic_conversion_supported()) {
                // $message stores an intermediate value used by the surrounding gallery workflow.
                $message = 'Only JPG, PNG, GIF, WebP, HEIC, and HEIF images can be uploaded.';
            } elseif (raw_conversion_supported()) {
                // $message stores an intermediate value used by the surrounding gallery workflow.
                $message = 'Only JPG, PNG, GIF, WebP, and DNG images can be uploaded.';
            }
            throw new RuntimeException($message . ' Offending file: ' . $originalName . '.');
        }
        // $info stores an intermediate value used by the surrounding gallery workflow.
        $info = @getimagesize($tmpName);
        if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
            throw new RuntimeException('One uploaded file is not a valid image.');
        }
        $entries[] = [
            'tmp_name' => $tmpName,
            'name' => $originalName,
            'size' => (int) ($files['size'][$index] ?? 0),
        ];
    }
    if (!$entries) {
        throw new RuntimeException('Choose at least one image to upload.');
    }
    return $entries;
}

/**
 * Handles heic conversion supported logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function heic_conversion_supported(): bool
{
    if (!extension_loaded('imagick') || !class_exists(Imagick::class)) {
        return false;
    }
    try {
        // $formats stores an intermediate value used by the surrounding gallery workflow.
        $formats = Imagick::queryFormats('HEIC');
        if ($formats) {
            return true;
        }
        // $formats stores an intermediate value used by the surrounding gallery workflow.
        $formats = Imagick::queryFormats('HEIF');
        return (bool) $formats;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Handles raw conversion supported logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function raw_conversion_supported(): bool
{
    if (!extension_loaded('imagick') || !class_exists(Imagick::class)) {
        return false;
    }
    try {
        // $formats stores an intermediate value used by the surrounding gallery workflow.
        $formats = Imagick::queryFormats('DNG');
        if ($formats) {
            return true;
        }
        // $formats stores an intermediate value used by the surrounding gallery workflow.
        $formats = Imagick::queryFormats('RAW');
        return (bool) $formats;
    } catch (Throwable) {
        return false;
    }
}

/**
 * Handles upload error message logic for the gallery application.
 * @param mixed $error Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function upload_error_message(int $error): string
{
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'An uploaded file is larger than the server allows.',
        UPLOAD_ERR_PARTIAL => 'An uploaded file was only partially received.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write an uploaded file.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
        default => 'Upload failed.',
    };
}

/**
 * Handles safe uploaded image filename logic for the gallery application.
 * @param mixed $name Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function safe_uploaded_image_filename(string $name): string
{
    // $extension stores an intermediate value used by the surrounding gallery workflow.
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    // $base stores an intermediate value used by the surrounding gallery workflow.
    $base = pathinfo($name, PATHINFO_FILENAME);
    return slugify($base) . '.' . $extension;
}

/**
 * Handles unique gallery upload target logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $filename Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function unique_gallery_upload_target(array $gallery, string $filename): array
{
    // $galleryRoot stores an intermediate value used by the surrounding gallery workflow.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    // $safeName stores an intermediate value used by the surrounding gallery workflow.
    $safeName = safe_uploaded_image_filename($filename);
    // $base stores an intermediate value used by the surrounding gallery workflow.
    $base = pathinfo($safeName, PATHINFO_FILENAME);
    // $extension stores an intermediate value used by the surrounding gallery workflow.
    $extension = pathinfo($safeName, PATHINFO_EXTENSION);
    // $candidate stores an intermediate value used by the surrounding gallery workflow.
    $candidate = $safeName;
    // $counter stores an intermediate value used by the surrounding gallery workflow.
    $counter = 2;
    while (file_exists($galleryRoot . DIRECTORY_SEPARATOR . $candidate) || find_image_by_path((int) $gallery['id'], $candidate)) {
        // $candidate stores an intermediate value used by the surrounding gallery workflow.
        $candidate = $base . '-' . $counter . '.' . $extension;
        $counter++;
    }
    return [$candidate, $galleryRoot . DIRECTORY_SEPARATOR . $candidate];
}

/**
 * Handles store uploaded gallery images logic for the gallery application.
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $entries Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function store_uploaded_gallery_images(int $galleryId, array $entries): array
{
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException('Gallery not found.');
    }
    // $galleryRoot stores an intermediate value used by the surrounding gallery workflow.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    if (!is_dir($galleryRoot) || !is_writable($galleryRoot)) {
        throw new RuntimeException('Gallery folder is not writable.');
    }

    // $stored stores an intermediate value used by the surrounding gallery workflow.
    $stored = [];
    foreach ($entries as $entry) {
        [$filename, $target] = unique_gallery_upload_target($gallery, (string) $entry['name']);
        if (!move_uploaded_file((string) $entry['tmp_name'], $target)) {
            throw new RuntimeException('Could not store uploaded image.');
        }
        $stored[] = $filename;
    }

    // $changed stores an intermediate value used by the surrounding gallery workflow.
    $changed = scan_gallery_images($galleryId);
    // $imageIds stores an intermediate value used by the surrounding gallery workflow.
    $imageIds = uploaded_gallery_image_ids($galleryId, $stored);
    return ['uploaded' => count($stored), 'filenames' => $stored, 'image_ids' => $imageIds, 'scanned' => $changed];
}

/**
 * Handles uploaded gallery image ids logic for the gallery application.
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $filenames Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function uploaded_gallery_image_ids(int $galleryId, array $filenames): array
{
    if (!$filenames) {
        return [];
    }

    // Variable $hashes stores this steps working value.
    $hashes = [];
    foreach ($filenames as $filename) {
        $hashes[] = hash('sha256', normalize_relative_path((string) $filename));
    }
    // $hashes stores an intermediate value used by the surrounding gallery workflow.
    $hashes = array_values(array_unique($hashes));
    if (!$hashes) {
        return [];
    }

    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($hashes), '?'));
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT id FROM images WHERE gallery_id = ? AND relative_path_hash IN ($placeholders) ORDER BY id");
    $stmt->execute(array_merge([$galleryId], $hashes));
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Handles store uploaded gallery cover logic for the gallery application.
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $file Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function store_uploaded_gallery_cover(int $galleryId, array $file): string
{
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException('Gallery not found.');
    }
    // $galleryRoot stores an intermediate value used by the surrounding gallery workflow.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    // $coverDir stores an intermediate value used by the surrounding gallery workflow.
    $coverDir = $galleryRoot . DIRECTORY_SEPARATOR . 'thumbnail';
    if (!is_dir($coverDir) && !mkdir($coverDir, 0775, true)) {
        throw new RuntimeException('Could not create thumbnail folder.');
    }
    if (!path_inside($galleryRoot, $coverDir)) {
        throw new RuntimeException('Thumbnail path is outside its gallery.');
    }
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = $coverDir . DIRECTORY_SEPARATOR . 'cover.jpg';
    foreach (glob($coverDir . DIRECTORY_SEPARATOR . 'cover.*') ?: [] as $oldFile) {
        if (is_file($oldFile) && $oldFile !== $target) {
            @unlink($oldFile);
        }
    }
    // $tmpPath stores an intermediate value used by the surrounding gallery workflow.
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    // $info stores an intermediate value used by the surrounding gallery workflow.
    $info = @getimagesize($tmpPath);
    if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
        throw new RuntimeException('Could not read the uploaded gallery thumbnail image.');
    }
    if (!extension_loaded('gd')) {
        throw new RuntimeException('Gallery thumbnail resizing requires the GD extension.');
    }
    // $source stores an intermediate value used by the surrounding gallery workflow.
    $source = image_create_from_path($tmpPath, (string) $info['mime']);
    if (!$source) {
        throw new RuntimeException('Could not decode the uploaded gallery thumbnail image.');
    }
    if (!write_resized_jpeg($source, (int) $info[0], (int) $info[1], 800, $target)) {
        imagedestroy($source);
        throw new RuntimeException('Could not store gallery thumbnail.');
    }
    imagedestroy($source);
    // $relative stores an intermediate value used by the surrounding gallery workflow.
    $relative = 'thumbnail/cover.jpg';
    if (!is_file($target)) {
        throw new RuntimeException('Could not store gallery thumbnail.');
    }
    set_gallery_cover_path($galleryId, $relative);
    return $relative;
}

