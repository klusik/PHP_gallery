<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_photo_privacy_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protect privacy-safe photo-open telemetry context and lightbox ownership.
 *
 * Responsibilities:
 *   - Verify photo-open origin is constrained to the documented low-cardinality enum
 *   - Verify lightbox mode is normalized rather than accepting arbitrary text
 *   - Verify native/deferred lightbox code declares singular telemetry ownership
 *   - Verify exported anomaly queries never expose anonymous session hashes
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

use function Gallery\Services\telemetry_context_json;

/** Throw when one photo telemetry privacy contract fails. */
function telemetry_photo_privacy_contract_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
require_once $root . '/app/services/telemetry_privacy.php';

$allowed = json_decode((string) telemetry_context_json('public.photo.opened', [
    'lightbox_mode' => 'fullscreen',
    'trigger' => 'keyboard',
    'selector' => '#private-photo-card',
    'url' => 'https://example.invalid/private/path',
]), true);
telemetry_photo_privacy_contract_assert(
    $allowed === ['lightbox_mode' => 'fullscreen', 'trigger' => 'keyboard'],
    'Photo-open context must keep only bounded mode/origin fields.'
);

$normalized = json_decode((string) telemetry_context_json('public.photo.opened', [
    'lightbox_mode' => 'some-free-form-mode',
    'trigger' => 'https://example.invalid/not-an-origin',
]), true);
telemetry_photo_privacy_contract_assert(
    $normalized === ['lightbox_mode' => 'normal', 'trigger' => 'unknown'],
    'Unrecognized photo telemetry context values must normalize to bounded defaults.'
);

$usageSource = (string) file_get_contents($root . '/public/assets/usage.js');
$lightboxSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$deferredSource = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox-deferred.js');
$modelSource = (string) file_get_contents($root . '/app/models/telemetry.php');

telemetry_photo_privacy_contract_assert(
    str_contains($usageSource, "window.PHPGalleryTelemetryLightboxOwner === 'native'")
        && str_contains($lightboxSource, "window.PHPGalleryTelemetryLightboxOwner = 'native';")
        && str_contains($deferredSource, "window.PHPGalleryTelemetryLightboxOwner = 'native';"),
    'Native lightbox integration must suppress the independent fallback telemetry owner.'
);
telemetry_photo_privacy_contract_assert(
    !str_contains($usageSource, 'lastPhotoOpenedAt')
        && !str_contains($usageSource, 'openedAt - lastPhotoOpenedAt < 500'),
    'Photo-open deduplication must not regress to the historical 500 ms heuristic.'
);
telemetry_photo_privacy_contract_assert(
    str_contains($modelSource, 'function telemetry_model_report_gallery_photo_opens_per_session')
        && str_contains($modelSource, 'SELECT session_hash, gallery_id, COUNT(*) AS photo_opens')
        && str_contains($modelSource, 'SELECT g.id AS gallery_id, g.title,'),
    'Gallery opens/session diagnostics must aggregate session hashes only inside the model subquery.'
);

// The outer projection must not expose session_hash to controllers/views.
$functionStart = strpos($modelSource, 'function telemetry_model_report_gallery_photo_opens_per_session');
$functionEnd = $functionStart === false ? false : strpos($modelSource, 'function telemetry_model_report_photo_open_origins', $functionStart);
$functionSource = ($functionStart !== false && $functionEnd !== false)
    ? substr($modelSource, $functionStart, $functionEnd - $functionStart)
    : '';
telemetry_photo_privacy_contract_assert(
    $functionSource !== '' && !preg_match('/SELECT\s+g\.id[^;]+session_hash\s+AS/si', $functionSource),
    'Photo anomaly report must never project a session hash.'
);

echo "telemetry_photo_privacy_contract_test: ok\n";
