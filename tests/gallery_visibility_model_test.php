<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_visibility_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the simplified gallery visibility model without requiring a live database.
 *
 * Responsibilities:
 *   - Cover the public, unpublished, and private visibility matrix
 *   - Cover legacy draft and unlisted translation behavior
 *   - Cover password-lock thumbnail/listing expectations
 *   - Remain executable with plain PHP on shared-hosting style environments
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
 *   2026-05-07
 */

declare(strict_types=1);

// These tests intentionally mirror the public visibility contract and avoid a
// database bootstrap so they can run in any checkout with: php tests/gallery_visibility_model_test.php

/**
 * Throw when a visibility expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Label value.
 */
function assert_same_value(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Normalize one visibility value exactly like the application helper.
 *
 * @param string $visibility Visibility value.
 * @return string Text result for the caller.
 */
function test_normalize_gallery_visibility(string $visibility): string
{
    $visibility = strtolower(trim($visibility));
    if ($visibility === 'draft' || $visibility === 'unlisted') {
        return 'unpublished';
    }
    return in_array($visibility, ['public', 'unpublished', 'private'], true) ? $visibility : 'unpublished';
}

/**
 * Return one gallery's effective visibility, including legacy access_listing rows.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function test_gallery_effective_visibility(array $gallery): string
{
    $visibility = test_normalize_gallery_visibility((string) ($gallery['visibility'] ?? 'unpublished'));
    if ($visibility === 'public' && (string) ($gallery['access_listing'] ?? 'listed') === 'unlisted') {
        return 'unpublished';
    }
    return $visibility;
}

/**
 * Return whether a gallery appears in normal listing pages.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function test_gallery_is_listed(array $gallery): bool
{
    return test_gallery_effective_visibility($gallery) === 'public';
}

/**
 * Return whether a gallery opens by normal public URL for anonymous visitors.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function test_gallery_allows_direct_url(array $gallery): bool
{
    return in_array(test_gallery_effective_visibility($gallery), ['public', 'unpublished'], true);
}

/**
 * Return whether a listing card must hide the real cover thumbnail.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function test_gallery_card_uses_locked_cover(array $gallery): bool
{
    return test_gallery_is_listed($gallery) && (string) ($gallery['access_mode'] ?? 'normal') === 'password';
}

$cases = [
    'public gallery listed and visible' => [
        'gallery' => ['visibility' => 'public', 'access_listing' => 'listed', 'access_mode' => 'normal'],
        'listed' => true,
        'direct_url' => true,
        'locked_cover' => false,
        'effective' => 'public',
    ],
    'unpublished gallery hidden from listings but accessible by direct link' => [
        'gallery' => ['visibility' => 'unpublished', 'access_listing' => 'unlisted', 'access_mode' => 'normal'],
        'listed' => false,
        'direct_url' => true,
        'locked_cover' => false,
        'effective' => 'unpublished',
    ],
    'private gallery hidden from listings' => [
        'gallery' => ['visibility' => 'private', 'access_listing' => 'unlisted', 'access_mode' => 'normal'],
        'listed' => false,
        'direct_url' => false,
        'locked_cover' => false,
        'effective' => 'private',
    ],
    'password-protected public gallery shows covered thumbnail' => [
        'gallery' => ['visibility' => 'public', 'access_listing' => 'listed', 'access_mode' => 'password'],
        'listed' => true,
        'direct_url' => true,
        'locked_cover' => true,
        'effective' => 'public',
    ],
    'password-protected unpublished gallery stays hidden but direct-linkable' => [
        'gallery' => ['visibility' => 'unpublished', 'access_listing' => 'unlisted', 'access_mode' => 'password'],
        'listed' => false,
        'direct_url' => true,
        'locked_cover' => false,
        'effective' => 'unpublished',
    ],
    'password-protected private gallery stays hidden and not normally direct-linkable' => [
        'gallery' => ['visibility' => 'private', 'access_listing' => 'unlisted', 'access_mode' => 'password'],
        'listed' => false,
        'direct_url' => false,
        'locked_cover' => false,
        'effective' => 'private',
    ],
    'legacy draft maps to unpublished' => [
        'gallery' => ['visibility' => 'draft', 'access_listing' => 'listed', 'access_mode' => 'normal'],
        'listed' => false,
        'direct_url' => true,
        'locked_cover' => false,
        'effective' => 'unpublished',
    ],
    'legacy public unlisted maps to unpublished' => [
        'gallery' => ['visibility' => 'public', 'access_listing' => 'unlisted', 'access_mode' => 'normal'],
        'listed' => false,
        'direct_url' => true,
        'locked_cover' => false,
        'effective' => 'unpublished',
    ],
];

foreach ($cases as $label => $case) {
    assert_same_value($case['effective'], test_gallery_effective_visibility($case['gallery']), $label . ': effective visibility');
    assert_same_value($case['listed'], test_gallery_is_listed($case['gallery']), $label . ': listing visibility');
    assert_same_value($case['direct_url'], test_gallery_allows_direct_url($case['gallery']), $label . ': direct URL access');
    assert_same_value($case['locked_cover'], test_gallery_card_uses_locked_cover($case['gallery']), $label . ': locked cover behavior');
}

echo "Gallery visibility model tests passed.\n";
