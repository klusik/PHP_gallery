<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/browser_uploads/zip_parsing.php
 * Module Type: Service
 *
 * Purpose:
 *   Parses the stored-entry ZIP container produced by browser upload code.
 *
 * Responsibilities:
 *   - Read little-endian ZIP header fields without external libraries
 *   - Enumerate stored entries under an explicit byte budget
 *   - Reject containers that do not match the expected stored layout
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
 *   - Loaded by app/services/browser_uploads.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/browser_uploads.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_runtime_limit;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\is_dng_image_path;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\url_for;

/**
 * Read a little-endian unsigned 16-bit value from binary data.
 *
 * @param string $data Input data.
 * @param int $offset Starting offset.
 * @return int Integer result for the caller.
 */
function browser_upload_zip_uint16(string $data, int $offset): int
{
    $value = unpack('v', substr($data, $offset, 2));
    return is_array($value) ? (int) $value[1] : 0;
}

/**
 * Read a little-endian unsigned 32-bit value from binary data.
 *
 * @param string $data Input data.
 * @param int $offset Starting offset.
 * @return int Integer result for the caller.
 */
function browser_upload_zip_uint32(string $data, int $offset): int
{
    $value = unpack('V', substr($data, $offset, 4));
    return is_array($value) ? (int) $value[1] : 0;
}

/**
 * Parse a browser-created store-only ZIP file into safe named entries.
 *
 * @param string $zipPath Zip path filesystem path.
 * @param int $maxBytes Max bytes value.
 * @return array<string string>.
 */
function browser_upload_parse_store_zip(string $zipPath, int $maxBytes): array
{
    if (!is_file($zipPath)) {
        throw new RuntimeException(t('browser_upload.error_missing_zip', 'The prepared upload package is missing.'));
    }
    $fileSize = (int) (filesize($zipPath) ?: 0);
    if ($fileSize <= 0) {
        throw new RuntimeException(t('browser_upload.error_empty_zip', 'The prepared upload package is empty.'));
    }
    if ($maxBytes > 0 && $fileSize > $maxBytes) {
        throw new RuntimeException(t('browser_upload.error_zip_too_large', 'The prepared upload package is larger than the configured upload limit.'));
    }

    $data = @file_get_contents($zipPath);
    if (!is_string($data) || $data === '') {
        throw new RuntimeException(t('browser_upload.error_zip_read_failed', 'Could not read the prepared upload package.'));
    }

    $length = strlen($data);
    $offset = 0;
    $entries = [];
    $entryCount = 0;
    while ($offset + 4 <= $length) {
        $signature = substr($data, $offset, 4);
        if ($signature === "\x50\x4b\x01\x02" || $signature === "\x50\x4b\x05\x06") {
            break;
        }
        if ($signature !== "\x50\x4b\x03\x04") {
            throw new RuntimeException(t('browser_upload.error_zip_structure', 'The prepared upload package is not a supported store-only ZIP.'));
        }
        if ($offset + 30 > $length) {
            throw new RuntimeException(t('browser_upload.error_zip_truncated', 'The prepared upload package is truncated.'));
        }
        $flags = browser_upload_zip_uint16($data, $offset + 6);
        $method = browser_upload_zip_uint16($data, $offset + 8);
        $compressedSize = browser_upload_zip_uint32($data, $offset + 18);
        $uncompressedSize = browser_upload_zip_uint32($data, $offset + 22);
        $nameLength = browser_upload_zip_uint16($data, $offset + 26);
        $extraLength = browser_upload_zip_uint16($data, $offset + 28);
        if (($flags & 0x08) !== 0 || $method !== 0) {
            throw new RuntimeException(t('browser_upload.error_zip_store_only', 'Only store-only ZIP upload packages are accepted.'));
        }
        $nameOffset = $offset + 30;
        $dataOffset = $nameOffset + $nameLength + $extraLength;
        if ($nameLength <= 0 || $dataOffset < $nameOffset || $dataOffset + $compressedSize > $length || $compressedSize !== $uncompressedSize) {
            throw new RuntimeException(t('browser_upload.error_zip_entry_invalid', 'The prepared upload package contains an invalid entry.'));
        }
        $name = normalize_relative_path(substr($data, $nameOffset, $nameLength));
        if ($name !== '' && !str_ends_with($name, '/')) {
            $entries[$name] = substr($data, $dataOffset, $compressedSize);
            $entryCount++;
        }
        if ($entryCount > max(1, (int) cms_runtime_limit('browser_upload.max_zip_entries'))) {
            throw new RuntimeException(t('browser_upload.error_zip_entry_count', 'The prepared upload package contains too many files.'));
        }
        $offset = $dataOffset + $compressedSize;
    }

    if (!$entries) {
        throw new RuntimeException(t('browser_upload.error_zip_no_entries', 'The prepared upload package does not contain any upload entries.'));
    }
    return $entries;
}
