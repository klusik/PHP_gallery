<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_gallery_report_batching_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects bounded complete-report batching across browser, service, and model layers.
 *
 * Responsibilities:
 *   - Keep the requested report batch within the service limit
 *   - Prevent a lower model clamp from multiplying shared-hosting requests
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$serviceSource = (string) file_get_contents($root . '/app/services/admin_gallery_report.php');
$modelSource = (string) file_get_contents($root . '/app/models/admin_gallery_report.php');
$browserSource = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-gallery-report.js');

if (!str_contains($serviceSource, 'ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE = 250')) {
    throw new RuntimeException('Complete report must request batches of 250 rows by default.');
}
if (!str_contains($serviceSource, 'ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE = 500')) {
    throw new RuntimeException('Complete report service must allow bounded batches up to 500 rows.');
}
if (!str_contains($modelSource, '$limit = max(1, min(500, $limit));')) {
    throw new RuntimeException('Complete report model must not silently clamp batches below the service limit.');
}
if (!str_contains($browserSource, "body.set('batch_size', '250');")) {
    throw new RuntimeException('Complete report browser workflow must submit the intended 250-row batch size.');
}

echo "admin_gallery_report_batching_test: PASS\n";
