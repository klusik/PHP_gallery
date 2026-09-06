<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/browser_uploads/payload_validation.php
 * Module Type: Service
 *
 * Purpose:
 *   Validates uploaded payload bytes against their declared filename and format.
 *
 * Responsibilities:
 *   - Detect the real image format from payload bytes before storage
 *   - Reject payloads whose signature contradicts the declared extension
 *   - Prepare a safe target filename from the detected format
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
 * Validate one original image payload before it is placed into a gallery.
 *
 * @param string $filename Filename value.
 * @param string $payload Payload value.
 */
function browser_upload_validate_original_payload(string $filename, string $payload): void
{
    browser_upload_prepare_original_filename($filename, $payload);
}

/**
 * Return a safe original filename whose extension matches the uploaded bytes.
 *
 * @param string $filename Filename value.
 * @param string $payload Payload value.
 * @param array<string,mixed> $context Diagnostic context.
 * @return string Filename with corrected extension when the bytes are valid.
 */
function browser_upload_prepare_original_filename(string $filename, string $payload, array $context = []): string
{
    $filename = safe_uploaded_image_filename($filename);
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $diagnostics = browser_upload_original_payload_diagnostics($filename, $payload, $context);

    if ($payload === '') {
        throw new BrowserUploadValidationException(
            t('browser_upload.error_invalid_original_empty', 'The prepared upload package contains an empty original image payload: {filename}.', ['filename' => $filename]),
            $diagnostics + ['validation_stage' => 'empty_payload']
        );
    }

    $detectedFormat = browser_upload_detect_payload_format($payload);
    if ($detectedFormat === null) {
        if (is_dng_image_path($filename)) {
            throw new BrowserUploadValidationException(
                t('browser_upload.error_browser_dng', 'The browser upload pipeline cannot accept DNG originals. Use the standard server-side fallback.'),
                $diagnostics + ['validation_stage' => 'dng_not_supported']
            );
        }
        throw new BrowserUploadValidationException(
            t('browser_upload.error_invalid_original_unknown', 'The prepared upload package contains an original image whose bytes do not look like JPG, PNG, GIF, or WebP: {filename}.', ['filename' => $filename]),
            $diagnostics + ['validation_stage' => 'unknown_signature']
        );
    }

    if (!is_supported_image_path($filename) || !browser_upload_extension_matches_detected_format($extension, $detectedFormat)) {
        $corrected = browser_upload_filename_with_detected_extension($filename, $detectedFormat);
        if ($corrected !== $filename && is_supported_image_path($corrected)) {
            return $corrected;
        }
        throw new BrowserUploadValidationException(
            t('browser_upload.error_invalid_original_mismatch', 'The prepared upload package contains an original image with mismatched bytes: {filename}. Extension says {extension}, but the payload looks like {detected}.', [
                'filename' => $filename,
                'extension' => $extension !== '' ? strtoupper($extension) : '(none)',
                'detected' => strtoupper($detectedFormat),
            ]),
            $diagnostics + [
                'validation_stage' => 'signature_extension_mismatch',
                'corrected_filename_candidate' => $corrected,
            ]
        );
    }

    return $filename;
}

/**
 * Build diagnostics for one original image payload failure.
 *
 * @param string $filename Filename value.
 * @param string $payload Payload value.
 * @param array<string,mixed> $context Caller context.
 * @return array<string,mixed> Diagnostic context.
 */
function browser_upload_original_payload_diagnostics(string $filename, string $payload, array $context = []): array
{
    $detectedFormat = browser_upload_detect_payload_format($payload);
    return array_filter($context + [
        'filename' => $filename,
        'extension' => strtolower(pathinfo($filename, PATHINFO_EXTENSION)),
        'payload_bytes' => strlen($payload),
        'detected_format' => $detectedFormat ?? '',
        'signature_hex' => bin2hex(substr($payload, 0, 16)),
    ], static fn ($value): bool => $value !== null && $value !== '');
}

/**
 * Return the image format identified by the file signature.
 *
 * @param string $payload Payload value.
 * @return string|null Detected image format or null.
 */
function browser_upload_detect_payload_format(string $payload): ?string
{
    if (str_starts_with($payload, "\xff\xd8\xff")) {
        return 'jpg';
    }
    if (str_starts_with($payload, "\x89PNG\r\n\x1a\n")) {
        return 'png';
    }
    if (str_starts_with($payload, 'GIF87a') || str_starts_with($payload, 'GIF89a')) {
        return 'gif';
    }
    if (strlen($payload) >= 12 && substr($payload, 0, 4) === 'RIFF' && substr($payload, 8, 4) === 'WEBP') {
        return 'webp';
    }
    return null;
}

/**
 * Return true when an extension is compatible with a detected format.
 *
 * @param string $extension Extension value.
 * @param string $detectedFormat Detected image format.
 * @return bool True when extension and signature are compatible.
 */
function browser_upload_extension_matches_detected_format(string $extension, string $detectedFormat): bool
{
    $extension = strtolower($extension);
    $detectedFormat = strtolower($detectedFormat);
    if ($detectedFormat === 'jpg') {
        return in_array($extension, ['jpg', 'jpeg'], true);
    }
    return $extension === $detectedFormat;
}

/**
 * Return a filename with the extension corrected to the detected format.
 *
 * @param string $filename Filename value.
 * @param string $detectedFormat Detected image format.
 * @return string Corrected filename.
 */
function browser_upload_filename_with_detected_extension(string $filename, string $detectedFormat): string
{
    $extension = $detectedFormat === 'jpg' ? 'jpg' : strtolower($detectedFormat);
    $base = pathinfo($filename, PATHINFO_FILENAME);
    if ($base === '') {
        $base = 'image';
    }
    return $base . '.' . $extension;
}

/**
 * Validate one browser-created thumbnail payload.
 *
 * @param string $format Format value.
 * @param string $payload Payload value.
 */
function browser_upload_validate_thumbnail_payload(string $format, string $payload): void
{
    if ($payload === '' || !browser_upload_payload_matches_format($format, $payload)) {
        throw new RuntimeException(t('browser_upload.error_thumbnail_format', 'The prepared upload package contains a thumbnail with the wrong format.'));
    }
}

/**
 * Return true when an image payload has the expected lightweight file signature.
 *
 * @param string $filename Filename value.
 * @param string $payload Payload value.
 * @return bool True when the extension and bytes are compatible.
 */
function browser_upload_payload_matches_image_signature(string $filename, string $payload): bool
{
    $detectedFormat = browser_upload_detect_payload_format($payload);
    if ($detectedFormat === null) {
        return false;
    }
    return browser_upload_extension_matches_detected_format(strtolower(pathinfo($filename, PATHINFO_EXTENSION)), $detectedFormat);
}

/**
 * Return true when a prepared thumbnail payload matches its manifest format.
 *
 * @param string $format Thumbnail format value.
 * @param string $payload Payload value.
 * @return bool True when the bytes match the expected thumbnail format.
 */
function browser_upload_payload_matches_format(string $format, string $payload): bool
{
    $detectedFormat = browser_upload_detect_payload_format($payload);
    if ($detectedFormat === null) {
        return false;
    }
    if ($format === 'jpg') {
        return $detectedFormat === 'jpg';
    }
    return $detectedFormat === $format;
}
