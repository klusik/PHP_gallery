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
 * @return array<string,string> Store-only file entries keyed by unique normalized path.
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
    $localRecords = [];
    $entryCount = 0;
    $maxEntries = max(1, (int) cms_runtime_limit('browser_upload.max_zip_entries'));
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
        $expectedCrc = browser_upload_zip_uint32($data, $offset + 14);
        $compressedSize = browser_upload_zip_uint32($data, $offset + 18);
        $uncompressedSize = browser_upload_zip_uint32($data, $offset + 22);
        $nameLength = browser_upload_zip_uint16($data, $offset + 26);
        $extraLength = browser_upload_zip_uint16($data, $offset + 28);
        // The browser writer uses only the UTF-8 filename flag. Encryption, data
        // descriptors and unsupported flags have no valid store-only layout here.
        if (($flags & ~0x0800) !== 0 || $method !== 0) {
            throw new RuntimeException(t('browser_upload.error_zip_store_only', 'Only store-only ZIP upload packages are accepted.'));
        }
        $nameOffset = $offset + 30;
        $dataOffset = $nameOffset + $nameLength + $extraLength;
        if ($nameLength <= 0 || $dataOffset < $nameOffset || $dataOffset + $compressedSize > $length || $compressedSize !== $uncompressedSize) {
            throw new RuntimeException(t('browser_upload.error_zip_entry_invalid', 'The prepared upload package contains an invalid entry.'));
        }
        $rawName = substr($data, $nameOffset, $nameLength);
        $localRecords[] = [
            'name' => $rawName,
            'offset' => $offset,
            'flags' => $flags,
            'crc' => $expectedCrc,
            'size' => $compressedSize,
        ];
        // Directory records still count towards the archive budget and must be
        // matched to the central directory, even though they are not ingested.
        if (count($localRecords) > $maxEntries) {
            throw new RuntimeException(t('browser_upload.error_zip_entry_count', 'The prepared upload package contains too many files.'));
        }
        // Normalize paths only after determining whether the original ZIP entry denotes a directory.
        // normalize_relative_path() removes trailing slashes, so testing the normalized name would accept directories as files.
        $isDirectory = str_ends_with(str_replace('\\', '/', $rawName), '/');
        $name = normalize_relative_path($rawName);
        if ($name !== '' && !$isDirectory) {
            // Two ZIP entries can differ textually while normalizing to the same path.
            // Never silently replace a previous image, thumbnail, or manifest payload.
            if (array_key_exists($name, $entries)) {
                throw new RuntimeException(t('browser_upload.error_zip_entry_invalid', 'The prepared upload package contains an invalid entry.'));
            }
            $payload = substr($data, $dataOffset, $compressedSize);
            // A valid size does not prove that transported bytes still match their ZIP header.
            // Validate the same CRC-32 that the browser's store-only ZIP worker emits.
            if ((int) hexdec(hash('crc32b', $payload)) !== $expectedCrc) {
                throw new RuntimeException(t('browser_upload.error_zip_entry_invalid', 'The prepared upload package contains an invalid entry.'));
            }
            $entries[$name] = $payload;
            $entryCount++;
        }
        if ($entryCount > $maxEntries) {
            throw new RuntimeException(t('browser_upload.error_zip_entry_count', 'The prepared upload package contains too many files.'));
        }
        $offset = $dataOffset + $compressedSize;
    }

    // A local header stream alone is not a complete ZIP. Require the matching
    // central directory and EOCD before passing any parsed entries to ingestion.
    browser_upload_zip_validate_central_directory($data, $offset, $localRecords);
    if (!$entries) {
        throw new RuntimeException(t('browser_upload.error_zip_no_entries', 'The prepared upload package does not contain any upload entries.'));
    }
    return $entries;
}

/**
 * Validate every central ZIP record against the sequential local records and EOCD.
 *
 * The browser worker writes classic single-disk stored ZIPs, so mismatched
 * offsets, names, sizes, checksums, counts, truncated trailers and extra trailing
 * bytes are corruption rather than supported alternative archive layouts.
 *
 * @param string $data Entire bounded ZIP byte stream.
 * @param int $centralOffset First byte following the parsed local entries.
 * @param list<array{name:string,offset:int,flags:int,crc:int,size:int}> $localRecords Parsed local-header metadata, including directories.
 * @return void No value for a complete matching archive.
 */
function browser_upload_zip_validate_central_directory(string $data, int $centralOffset, array $localRecords): void
{
    $length = strlen($data);
    $offset = $centralOffset;
    $recordCount = count($localRecords);
    for ($index = 0; $index < $recordCount; $index++) {
        if ($offset + 46 > $length || substr($data, $offset, 4) !== "\x50\x4b\x01\x02") {
            throw new RuntimeException(t('browser_upload.error_zip_structure', 'The prepared upload package is not a supported store-only ZIP.'));
        }
        $nameLength = browser_upload_zip_uint16($data, $offset + 28);
        $extraLength = browser_upload_zip_uint16($data, $offset + 30);
        $commentLength = browser_upload_zip_uint16($data, $offset + 32);
        $nextOffset = $offset + 46 + $nameLength + $extraLength + $commentLength;
        if ($nextOffset > $length) {
            throw new RuntimeException(t('browser_upload.error_zip_truncated', 'The prepared upload package is truncated.'));
        }
        $local = $localRecords[$index];
        if (browser_upload_zip_uint16($data, $offset + 8) !== $local['flags']
            || browser_upload_zip_uint16($data, $offset + 10) !== 0
            || browser_upload_zip_uint32($data, $offset + 16) !== $local['crc']
            || browser_upload_zip_uint32($data, $offset + 20) !== $local['size']
            || browser_upload_zip_uint32($data, $offset + 24) !== $local['size']
            || browser_upload_zip_uint16($data, $offset + 34) !== 0
            || browser_upload_zip_uint32($data, $offset + 42) !== $local['offset']
            || substr($data, $offset + 46, $nameLength) !== $local['name']) {
            throw new RuntimeException(t('browser_upload.error_zip_entry_invalid', 'The prepared upload package contains an invalid entry.'));
        }
        $offset = $nextOffset;
    }

    // An EOCD must follow exactly the declared central directory. Neither a
    // truncated directory nor an arbitrary PK signature can end an upload.
    if ($offset + 22 > $length || substr($data, $offset, 4) !== "\x50\x4b\x05\x06") {
        throw new RuntimeException(t('browser_upload.error_zip_structure', 'The prepared upload package is not a supported store-only ZIP.'));
    }
    if (browser_upload_zip_uint16($data, $offset + 4) !== 0
        || browser_upload_zip_uint16($data, $offset + 6) !== 0
        || browser_upload_zip_uint16($data, $offset + 8) !== $recordCount
        || browser_upload_zip_uint16($data, $offset + 10) !== $recordCount
        || browser_upload_zip_uint32($data, $offset + 12) !== $offset - $centralOffset
        || browser_upload_zip_uint32($data, $offset + 16) !== $centralOffset
        || $offset + 22 + browser_upload_zip_uint16($data, $offset + 20) !== $length) {
        throw new RuntimeException(t('browser_upload.error_zip_structure', 'The prepared upload package is not a supported store-only ZIP.'));
    }
}
