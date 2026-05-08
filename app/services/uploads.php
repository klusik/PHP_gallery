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
        if (is_dng_image_path($originalName)) {
            // $dngMetadata stores the readable DNG dimensions reported by Imagick.
            $dngMetadata = dng_image_metadata($tmpName);
            if ($dngMetadata === null) {
                throw new RuntimeException('One uploaded DNG file could not be decoded by the server.');
            }
        } else {
            // $info stores an intermediate value used by the surrounding gallery workflow.
            $info = @getimagesize($tmpName);
            if ($info === false || empty($info['mime']) || !str_starts_with((string) $info['mime'], 'image/')) {
                throw new RuntimeException('One uploaded file is not a valid image.');
            }
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
    return dng_conversion_supported();
}

/**
 * Return whether one Imagick format delegate is available.
 */
function imagick_format_supported(string $format): bool
{
    if (!extension_loaded('imagick') || !class_exists(Imagick::class)) {
        return false;
    }
    try {
        // $formats stores the formats reported by the installed ImageMagick delegates.
        $formats = Imagick::queryFormats(strtoupper($format));
        return is_array($formats) && in_array(strtoupper($format), array_map('strtoupper', $formats), true);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Return whether this server can decode DNG originals and write WebP display masters.
 */
function dng_conversion_supported(): bool
{
    return imagick_format_supported('DNG') && imagick_format_supported('WEBP') && imagick_format_supported('JPEG');
}

/**
 * Return whether this server can use an embedded DNG JPEG preview as a derivative source.
 */
function dng_embedded_preview_supported(): bool
{
    // $imagickPreviewSupported stores whether Imagick can decode JPEG previews and write WebP derivatives.
    $imagickPreviewSupported = imagick_format_supported('JPEG') && imagick_format_supported('WEBP');
    // $gdPreviewSupported stores whether GD can decode JPEG previews and write WebP derivatives.
    $gdPreviewSupported = extension_loaded('gd') && function_exists('imagecreatefromjpeg') && function_exists('imagewebp');
    return $imagickPreviewSupported || $gdPreviewSupported;
}

/**
 * Read one unsigned 16-bit TIFF value from a binary string.
 */
function dng_tiff_uint16(string $data, int $offset, string $endian): ?int
{
    if ($offset < 0 || $offset + 2 > strlen($data)) {
        return null;
    }
    // $format stores the unpack format for this file byte order.
    $format = $endian === 'II' ? 'v' : 'n';
    // $value stores the decoded integer value.
    $value = unpack($format, substr($data, $offset, 2));
    return is_array($value) ? (int) $value[1] : null;
}

/**
 * Read one unsigned 32-bit TIFF value from a binary string.
 */
function dng_tiff_uint32(string $data, int $offset, string $endian): ?int
{
    if ($offset < 0 || $offset + 4 > strlen($data)) {
        return null;
    }
    // $format stores the unpack format for this file byte order.
    $format = $endian === 'II' ? 'V' : 'N';
    // $value stores the decoded integer value.
    $value = unpack($format, substr($data, $offset, 4));
    return is_array($value) ? (int) $value[1] : null;
}

/**
 * Return the byte width for a TIFF field type.
 */
function dng_tiff_type_size(int $type): int
{
    return match ($type) {
        1, 2, 6, 7 => 1,
        3, 8 => 2,
        4, 9, 11 => 4,
        5, 10, 12 => 8,
        default => 0,
    };
}

/**
 * Read unsigned SHORT or LONG values from a TIFF IFD entry.
 *
 * @return array<int, int>
 */
function dng_tiff_entry_values(string $data, int $entryOffset, string $endian, int $type, int $count, int $valueOffset): array
{
    // $typeSize stores the byte length of one TIFF value for this entry.
    $typeSize = dng_tiff_type_size($type);
    if ($typeSize <= 0 || $count <= 0 || $count > 64) {
        return [];
    }
    // $totalSize stores the combined byte length of all values in this entry.
    $totalSize = $typeSize * $count;
    // $dataOffset stores the in-entry value area or an external value offset.
    $dataOffset = $totalSize <= 4 ? $entryOffset + 8 : $valueOffset;
    // $values stores the decoded values.
    $values = [];
    for ($index = 0; $index < $count; $index++) {
        // $offset stores the byte position for the current value.
        $offset = $dataOffset + ($index * $typeSize);
        if ($type === 3) {
            $value = dng_tiff_uint16($data, $offset, $endian);
        } elseif ($type === 4) {
            $value = dng_tiff_uint32($data, $offset, $endian);
        } else {
            continue;
        }
        if ($value !== null) {
            $values[] = $value;
        }
    }
    return $values;
}

/**
 * Find embedded JPEG preview byte ranges inside a DNG/TIFF container.
 *
 * @return array<int, array{offset:int,length:int,source:string}>
 */
function dng_embedded_jpeg_candidates(string $data): array
{
    if (strlen($data) < 8) {
        return [];
    }
    // $endian stores the TIFF byte order marker.
    $endian = substr($data, 0, 2);
    if (!in_array($endian, ['II', 'MM'], true)) {
        return [];
    }
    // $magic stores the TIFF magic value.
    $magic = dng_tiff_uint16($data, 2, $endian);
    if ($magic !== 42) {
        return [];
    }
    // $firstIfdOffset stores the byte offset of the first image file directory.
    $firstIfdOffset = dng_tiff_uint32($data, 4, $endian);
    if ($firstIfdOffset === null || $firstIfdOffset <= 0) {
        return [];
    }

    // $queue stores IFD offsets waiting to be inspected.
    $queue = [$firstIfdOffset];
    // $visited stores IFD offsets already inspected.
    $visited = [];
    // $candidates stores validated JPEG preview ranges.
    $candidates = [];
    // $fileLength stores the total byte length for range validation.
    $fileLength = strlen($data);

    while ($queue) {
        // $ifdOffset stores the current IFD byte offset.
        $ifdOffset = array_shift($queue);
        if (!is_int($ifdOffset) || $ifdOffset <= 0 || isset($visited[$ifdOffset]) || $ifdOffset + 2 > $fileLength) {
            continue;
        }
        $visited[$ifdOffset] = true;
        // $entryCount stores the number of entries in this IFD.
        $entryCount = dng_tiff_uint16($data, $ifdOffset, $endian);
        if ($entryCount === null || $entryCount <= 0 || $entryCount > 2048) {
            continue;
        }
        // $jpegOffset stores the JPEGInterchangeFormat offset, when present.
        $jpegOffset = null;
        // $jpegLength stores the JPEGInterchangeFormatLength value, when present.
        $jpegLength = null;

        for ($index = 0; $index < $entryCount; $index++) {
            // $entryOffset stores the byte offset of this 12-byte IFD entry.
            $entryOffset = $ifdOffset + 2 + ($index * 12);
            if ($entryOffset + 12 > $fileLength) {
                break;
            }
            // $tag stores the TIFF tag identifier.
            $tag = dng_tiff_uint16($data, $entryOffset, $endian);
            // $type stores the TIFF entry type.
            $type = dng_tiff_uint16($data, $entryOffset + 2, $endian);
            // $count stores the TIFF entry item count.
            $count = dng_tiff_uint32($data, $entryOffset + 4, $endian);
            // $valueOffset stores either the inline value or an offset to the value array.
            $valueOffset = dng_tiff_uint32($data, $entryOffset + 8, $endian);
            if ($tag === null || $type === null || $count === null || $valueOffset === null) {
                continue;
            }
            if ($tag === 0x0201) {
                $values = dng_tiff_entry_values($data, $entryOffset, $endian, $type, $count, $valueOffset);
                $jpegOffset = $values[0] ?? null;
            } elseif ($tag === 0x0202) {
                $values = dng_tiff_entry_values($data, $entryOffset, $endian, $type, $count, $valueOffset);
                $jpegLength = $values[0] ?? null;
            } elseif ($tag === 0x014A) {
                $values = dng_tiff_entry_values($data, $entryOffset, $endian, $type, $count, $valueOffset);
                foreach ($values as $subIfdOffset) {
                    if ($subIfdOffset > 0 && !isset($visited[$subIfdOffset])) {
                        $queue[] = $subIfdOffset;
                    }
                }
            }
        }

        if (is_int($jpegOffset) && is_int($jpegLength) && $jpegOffset > 0 && $jpegLength > 32 && $jpegOffset + $jpegLength <= $fileLength) {
            if (substr($data, $jpegOffset, 2) === "\xFF\xD8") {
                $candidates[] = ['offset' => $jpegOffset, 'length' => $jpegLength, 'source' => 'tiff_ifd'];
            }
        }

        // $nextOffsetPosition stores the 4-byte pointer after the current IFD entries.
        $nextOffsetPosition = $ifdOffset + 2 + ($entryCount * 12);
        $nextIfdOffset = dng_tiff_uint32($data, $nextOffsetPosition, $endian);
        if (is_int($nextIfdOffset) && $nextIfdOffset > 0 && !isset($visited[$nextIfdOffset])) {
            $queue[] = $nextIfdOffset;
        }
    }

    usort($candidates, static fn (array $left, array $right): int => $right['length'] <=> $left['length']);
    return $candidates;
}


/**
 * Return the first JPEG start-of-frame marker that determines decoder compatibility.
 */
function dng_jpeg_sof_marker(string $jpeg): ?int
{
    // $length stores the JPEG byte length used for marker bounds checks.
    $length = strlen($jpeg);
    if ($length < 4 || substr($jpeg, 0, 2) !== "\xFF\xD8") {
        return null;
    }
    // $offset stores the current marker search position after SOI.
    $offset = 2;
    while ($offset + 3 < $length) {
        if ($jpeg[$offset] !== "\xFF") {
            $offset++;
            continue;
        }
        while ($offset < $length && $jpeg[$offset] === "\xFF") {
            $offset++;
        }
        if ($offset >= $length) {
            return null;
        }
        // $marker stores the marker byte after one or more marker prefixes.
        $marker = ord($jpeg[$offset]);
        $offset++;
        if ($marker === 0xD9 || $marker === 0xDA) {
            return null;
        }
        if ($marker >= 0xD0 && $marker <= 0xD7) {
            continue;
        }
        if ($marker === 0x01) {
            continue;
        }
        if ($offset + 2 > $length) {
            return null;
        }
        // $segmentLength stores the marker segment length including the two length bytes.
        $segmentLength = unpack('n', substr($jpeg, $offset, 2))[1] ?? 0;
        if ($segmentLength < 2 || $offset + $segmentLength > $length) {
            return null;
        }
        if (in_array($marker, [0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF], true)) {
            return $marker;
        }
        $offset += $segmentLength;
    }
    return null;
}

/**
 * Return whether a JPEG candidate is suitable for browser display derivative generation.
 */
function dng_jpeg_preview_candidate_is_safe(string $jpeg): bool
{
    // $sofMarker stores the JPEG coding process marker.
    $sofMarker = dng_jpeg_sof_marker($jpeg);
    if ($sofMarker === null) {
        return false;
    }
    // GD and most browser-facing pipelines reliably handle baseline, extended sequential, and progressive JPEG.
    // Apple ProRAW may also contain lossless JPEG-coded raw strips using SOF3, which must not be treated as previews.
    if (!in_array($sofMarker, [0xC0, 0xC1, 0xC2], true)) {
        return false;
    }
    return @getimagesizefromstring($jpeg) !== false;
}

/**
 * Find JPEG preview byte ranges by scanning for JPEG markers.
 *
 * @return array<int, array{offset:int,length:int,source:string}>
 */
function dng_jpeg_signature_candidates(string $data): array
{
    // $candidates stores marker-derived JPEG ranges.
    $candidates = [];
    // $offset stores the current search position.
    $offset = 0;
    while (($start = strpos($data, "\xFF\xD8", $offset)) !== false) {
        // $end stores the first JPEG end marker after the start marker.
        $end = strpos($data, "\xFF\xD9", $start + 2);
        if ($end === false) {
            break;
        }
        // $length stores the candidate byte length including the end marker.
        $length = $end + 2 - $start;
        if ($length > 1024) {
            $candidates[] = ['offset' => $start, 'length' => $length, 'source' => 'marker_scan'];
        }
        $offset = $end + 2;
    }
    usort($candidates, static fn (array $left, array $right): int => $right['length'] <=> $left['length']);
    return $candidates;
}

/**
 * Extract the largest readable embedded JPEG preview from a DNG file.
 */
function dng_extract_embedded_jpeg_preview(string $sourcePath, string $targetPath): bool
{
    if (!is_file($sourcePath)) {
        return false;
    }
    // $fileSize stores the source size so the fallback does not load extreme files into memory.
    $fileSize = filesize($sourcePath) ?: 0;
    if ($fileSize <= 0 || $fileSize > 220 * 1024 * 1024) {
        return false;
    }
    // $data stores the DNG/TIFF bytes for preview extraction.
    $data = @file_get_contents($sourcePath);
    if (!is_string($data) || $data === '') {
        return false;
    }
    // $candidates stores TIFF-declared previews first, then marker-derived fallbacks.
    $candidates = array_merge(dng_embedded_jpeg_candidates($data), dng_jpeg_signature_candidates($data));
    foreach ($candidates as $candidate) {
        // $jpeg stores one candidate preview image.
        $jpeg = substr($data, (int) $candidate['offset'], (int) $candidate['length']);
        if (substr($jpeg, 0, 2) !== "\xFF\xD8") {
            continue;
        }
        if (!dng_jpeg_preview_candidate_is_safe($jpeg)) {
            continue;
        }
        if (@file_put_contents($targetPath, $jpeg) !== false && is_file($targetPath)) {
            return true;
        }
    }
    return false;
}

/**
 * Read dimensions from an embedded DNG preview if RAW decoding is unavailable.
 *
 * @return array{width:int,height:int,mime:string}|null
 */
function dng_embedded_preview_metadata(string $path): ?array
{
    // $temporaryPath stores the extracted preview while its dimensions are read.
    $temporaryPath = tempnam(sys_get_temp_dir(), 'php_gallery_dng_preview_');
    if ($temporaryPath === false) {
        return null;
    }
    try {
        if (!dng_extract_embedded_jpeg_preview($path, $temporaryPath)) {
            @unlink($temporaryPath);
            return null;
        }
        // $info stores the embedded preview dimensions.
        $info = @getimagesize($temporaryPath);
        @unlink($temporaryPath);
        if ($info === false || empty($info[0]) || empty($info[1])) {
            return null;
        }
        return ['width' => (int) $info[0], 'height' => (int) $info[1], 'mime' => 'image/x-adobe-dng'];
    } catch (Throwable) {
        @unlink($temporaryPath);
        return null;
    }
}

/**
 * Read basic dimensions for a DNG file without treating browser display support as available.
 *
 * @return array{width:int,height:int,mime:string}|null
 */
function dng_image_metadata(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    if (dng_conversion_supported()) {
        // $image stores the Imagick probe so it can be cleared after reading dimensions.
        $image = null;
        try {
            $image = new Imagick();
            $image->pingImage($path);
            // $width stores the DNG pixel width reported by ImageMagick.
            $width = (int) $image->getImageWidth();
            // $height stores the DNG pixel height reported by ImageMagick.
            $height = (int) $image->getImageHeight();
            $image->clear();
            $image->destroy();
            if ($width > 0 && $height > 0) {
                return ['width' => $width, 'height' => $height, 'mime' => 'image/x-adobe-dng'];
            }
        } catch (Throwable) {
            if ($image instanceof Imagick) {
                $image->clear();
                $image->destroy();
            }
        }
    }

    return dng_embedded_preview_supported() ? dng_embedded_preview_metadata($path) : null;
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

/**
 * Store one uploaded gallery branding asset inside the gallery folder.
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $kind Input used by this operation.
 * @param mixed $file Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function store_uploaded_gallery_branding_asset(int $galleryId, string $kind, array $file): string
{
    // $kind stores an intermediate value used by the surrounding gallery workflow.
    $kind = gallery_branding_asset_kind($kind);
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        throw new RuntimeException('Gallery not found.');
    }
    // $uploadError stores an intermediate value used by the surrounding gallery workflow.
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException('Choose a branding image to upload.');
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new RuntimeException(upload_error_message($uploadError));
    }
    // $tmpPath stores an intermediate value used by the surrounding gallery workflow.
    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Uploaded branding image is not available.');
    }
    // $originalName stores an intermediate value used by the surrounding gallery workflow.
    $originalName = (string) ($file['name'] ?? '');
    if (!gallery_branding_upload_extension_allowed($originalName)) {
        throw new RuntimeException('Only JPG, PNG, GIF, and WebP branding images can be uploaded.');
    }
    // $size stores an intermediate value used by the surrounding gallery workflow.
    $size = (int) ($file['size'] ?? 0);
    if ($size > gallery_branding_uploaded_asset_max_bytes()) {
        throw new RuntimeException('The branding image is larger than 8 MB.');
    }
    // $info stores an intermediate value used by the surrounding gallery workflow.
    $info = @getimagesize($tmpPath);
    if ($info === false || empty($info['mime'])) {
        throw new RuntimeException('The uploaded branding image is not a valid image.');
    }
    // $extension stores an intermediate value used by the surrounding gallery workflow.
    $extension = gallery_branding_mime_extension((string) $info['mime']);
    if ($extension === null) {
        throw new RuntimeException('Only JPG, PNG, GIF, and WebP branding images can be uploaded.');
    }
    // $galleryRoot stores an intermediate value used by the surrounding gallery workflow.
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    if (!is_dir($galleryRoot) || !is_writable($galleryRoot)) {
        throw new RuntimeException('Gallery folder is not writable.');
    }
    // $brandingDir stores an intermediate value used by the surrounding gallery workflow.
    $brandingDir = $galleryRoot . DIRECTORY_SEPARATOR . 'branding';
    if (!is_dir($brandingDir) && !mkdir($brandingDir, 0775, true)) {
        throw new RuntimeException('Could not create branding folder.');
    }
    if (!path_inside($galleryRoot, $brandingDir)) {
        throw new RuntimeException('Branding path is outside its gallery.');
    }
    // $stem stores an intermediate value used by the surrounding gallery workflow.
    $stem = gallery_branding_asset_filename_stem($kind);
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = $brandingDir . DIRECTORY_SEPARATOR . $stem . '.' . $extension;
    // $stagedTarget stores the uploaded file before the previous asset is replaced.
    $stagedTarget = $brandingDir . DIRECTORY_SEPARATOR . '.upload-' . $stem . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
    if (!move_uploaded_file($tmpPath, $stagedTarget)) {
        throw new RuntimeException('Could not store gallery branding image.');
    }
    foreach (glob($brandingDir . DIRECTORY_SEPARATOR . $stem . '.*') ?: [] as $oldFile) {
        if (is_file($oldFile)) {
            @unlink($oldFile);
        }
    }
    if (!@rename($stagedTarget, $target)) {
        @unlink($stagedTarget);
        throw new RuntimeException('Could not finalize gallery branding image.');
    }
    // $relative stores an intermediate value used by the surrounding gallery workflow.
    $relative = 'branding/' . $stem . '.' . $extension;
    set_gallery_branding_asset_path($galleryId, $kind, $relative);
    return $relative;
}

