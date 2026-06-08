<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/thumbnail_warmup_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Validates pure thumbnail warmup helpers without a browser or database fixture.
 *
 * Responsibilities:
 *   - Check warmup size normalization
 *   - Check browser item normalization and duplicate merging
 *   - Check token generation is stable and rejects changed image metadata
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
 *   2026-06-08
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

/**
 * Assert a condition and stop the script with a readable message when it fails.
 */
function thumbnail_warmup_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$sizes = thumbnail_warmup_normalize_sizes([300, '600', 600, 9999, 0, 'abc']);
thumbnail_warmup_test_assert($sizes === [300, 600], 'Warmup size normalization failed.');

$items = thumbnail_warmup_normalize_items([
    ['id' => 10, 'token' => 'abc', 'sizes' => [300, 600]],
    ['id' => 10, 'token' => 'abc', 'sizes' => [800, 600]],
    ['id' => 0, 'token' => 'ignored', 'sizes' => [300]],
    ['id' => 11, 'token' => '', 'sizes' => [300]],
]);
thumbnail_warmup_test_assert(count($items) === 1, 'Warmup item de-duplication failed.');
thumbnail_warmup_test_assert($items[0]['id'] === 10, 'Warmup item id normalization failed.');
thumbnail_warmup_test_assert($items[0]['sizes'] === [300, 600, 800], 'Warmup item size merge failed.');

$candidateSummary = thumbnail_warmup_log_candidate_summary($items);
thumbnail_warmup_test_assert($candidateSummary === [['id' => 10, 'sizes' => [300, 600, 800]]], 'Warmup log candidate summary failed.');
thumbnail_warmup_test_assert(!array_key_exists('token', $candidateSummary[0]), 'Warmup log candidate summary must not expose tokens.');

$modernModeLabel = thumbnail_compatibility_mode_log_value(THUMBNAIL_COMPATIBILITY_MODERN);
thumbnail_warmup_test_assert($modernModeLabel === 'webp_only', 'Modern thumbnail compatibility mode should log as webp_only.');
thumbnail_warmup_test_assert(thumbnail_policy_requested_formats(THUMBNAIL_COMPATIBILITY_MODERN) === ['webp'], 'Modern thumbnail compatibility mode must request only WebP.');
thumbnail_warmup_test_assert(thumbnail_policy_requested_formats(THUMBNAIL_COMPATIBILITY_LEGACY) === ['jpg', 'webp'], 'Legacy thumbnail compatibility mode must request JPEG plus WebP.');

$policySummary = thumbnail_warmup_request_policy_summary($items);
thumbnail_warmup_test_assert(isset($policySummary['formats_requested']) && is_array($policySummary['formats_requested']), 'Warmup policy summary must include requested formats.');
thumbnail_warmup_test_assert(isset($policySummary['enabled_sizes']) && in_array(300, $policySummary['enabled_sizes'], true), 'Warmup policy summary must include enabled sizes.');
thumbnail_warmup_test_assert(isset($policySummary['jpg_quality']) && isset($policySummary['webp_quality']), 'Warmup policy summary must include thumbnail quality values.');

$image = ['id' => 5, 'gallery_id' => 7, 'filename' => 'photo.jpg', 'relative_path' => 'photo.jpg'];
$gallery = ['id' => 7, 'folder_path' => 'Trips/Test'];
$token = thumbnail_warmup_token($image, $gallery);
thumbnail_warmup_test_assert(thumbnail_warmup_token_is_valid($image, $gallery, $token), 'Warmup token validation failed.');
$image['filename'] = 'renamed.jpg';
thumbnail_warmup_test_assert(!thumbnail_warmup_token_is_valid($image, $gallery, $token), 'Warmup token should fail after source metadata changes.');

echo 'thumbnail_warmup_model_test passed' . PHP_EOL;
