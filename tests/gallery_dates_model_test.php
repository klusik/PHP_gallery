<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_dates_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies gallery date-range normalization and display behavior without a live database.
 *
 * Responsibilities:
 *   - Cover single-date and date-range storage validation
 *   - Cover invalid reversed range rejection
 *   - Cover public display formatting for single dates, ranges, and end-only dates
 *   - Cover machine-readable date attributes emitted by the public renderer
 *   - Cover scoped branch matching used by EXIF-derived suggestion reviews
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
 *   2026-06-07
 */

declare(strict_types=1);

if (!function_exists('db_column_exists')) {
        /**
     * Schema shim for gallery date service tests.
     *
     * @param string $table Table value.
     * @param string $column Column value.
     * @return bool True when the condition matches.
     */
    function db_column_exists(string $table, string $column): bool
    {
        return $table === 'galleries' && in_array($column, ['gallery_date', 'gallery_date_end'], true);
    }
}

if (!function_exists('e')) {
        /**
     * Minimal HTML escaping shim for renderer tests.
     *
     * @param ?string $value Value to process.
     * @return string Text result for the caller.
     */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('t')) {
        /**
     * Minimal translation shim for service tests.
     *
     * @param string $key Lookup key.
     * @param string|array|null $fallback Fallback value.
     * @param array $parameters Parameters value.
     * @return string Text result for the caller.
     */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        $text = is_string($fallback) ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

require_once __DIR__ . '/../app/services/gallery_dates.php';

/**
 * Throw when a gallery date expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Label value.
 */
function assert_gallery_dates_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Throw when a rendered string does not contain an expected substring.
 *
 * @param string $needle Needle value.
 * @param string $haystack Haystack value.
 * @param string $label Label value.
 */
function assert_gallery_dates_contains(string $needle, string $haystack, string $label): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($label . ' did not contain ' . var_export($needle, true) . '. HTML: ' . $haystack);
    }
}

$range = gallery_date_range_storage_values('2026-05-01', '2026-05-03');
assert_gallery_dates_same(['start' => '2026-05-01', 'end' => '2026-05-03'], $range, 'valid range normalizes both dates');
assert_gallery_dates_same(['start' => '2026-05-01', 'end' => null], gallery_date_range_storage_values('2026-05-01', ''), 'empty end keeps a single-date range');
assert_gallery_dates_same(['start' => null, 'end' => null], gallery_date_range_storage_values('', ''), 'empty range is allowed');

$reversedRangeRejected = false;
try {
    gallery_date_range_storage_values('2026-05-04', '2026-05-03');
} catch (InvalidArgumentException) {
    $reversedRangeRejected = true;
}
assert_gallery_dates_same(true, $reversedRangeRejected, 'range end before start is rejected');

assert_gallery_dates_same('1. 5. 2026', gallery_date_range_display_value('2026-05-01', null), 'single date displays as one date');
assert_gallery_dates_same('1. 5. 2026', gallery_date_range_display_value('2026-05-01', '2026-05-01'), 'same start and end displays as one date');
assert_gallery_dates_same('1. 5. 2026 – 3. 5. 2026', gallery_date_range_display_value('2026-05-01', '2026-05-03'), 'date range displays both endpoints');
assert_gallery_dates_same('Until 3. 5. 2026', gallery_date_range_display_value(null, '2026-05-03'), 'end-only date displays as until label');
assert_gallery_dates_same('2026-05-01 – 2026-05-03', gallery_date_range_storage_label('2026-05-01', '2026-05-03'), 'storage label displays normalized range');

assert_gallery_dates_same(true, gallery_date_gallery_is_in_branch([
    1 => ['parent_id' => 0],
    2 => ['parent_id' => 1],
    3 => ['parent_id' => 2],
], 3, 1), 'grandchild gallery belongs to root branch');
assert_gallery_dates_same(false, gallery_date_gallery_is_in_branch([
    1 => ['parent_id' => 0],
    2 => ['parent_id' => 1],
    3 => ['parent_id' => 0],
], 3, 1), 'sibling gallery does not belong to branch');

ob_start();
render_gallery_date(['gallery_date' => '2026-05-01', 'gallery_date_end' => '2026-05-03'], 'test-gallery-date');
$html = (string) ob_get_clean();
assert_gallery_dates_contains('class="test-gallery-date"', $html, 'rendered date uses custom CSS class');
assert_gallery_dates_contains('datetime="2026-05-01"', $html, 'rendered date keeps start datetime');
assert_gallery_dates_contains('data-date-start="2026-05-01"', $html, 'rendered date exposes start data attribute');
assert_gallery_dates_contains('data-date-end="2026-05-03"', $html, 'rendered date exposes end data attribute');
assert_gallery_dates_contains('1. 5. 2026 – 3. 5. 2026', $html, 'rendered date displays range');

echo "Gallery date range model tests passed.\n";
