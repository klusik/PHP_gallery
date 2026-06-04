<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/dng_conversion_policy_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies DNG conversion policy normalization and fallback ordering.
 *
 * Responsibilities:
 *   - Cover default DNG conversion behavior without a database
 *   - Cover explicit raw-first and preview-first policy selection
 *   - Cover fallback ordering across Imagick RAW, Imagick preview, and GD preview paths
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
 *   2026-06-04
 */

declare(strict_types=1);

$GLOBALS['dng_policy_test_settings'] = [];

/**
 * Translation shim used by isolated policy tests.
 *
 * @param array<string, string> $params
 */
function t(string $key, ?string $fallback = null, array $params = []): string
{
    $text = $fallback ?? $key;
    foreach ($params as $name => $value) {
        $text = str_replace('{' . $name . '}', $value, $text);
    }
    return $text;
}

/**
 * App setting shim used by isolated policy tests.
 */
function app_setting(string $key, ?string $default = null): ?string
{
    return array_key_exists($key, $GLOBALS['dng_policy_test_settings']) ? (string) $GLOBALS['dng_policy_test_settings'][$key] : $default;
}

require_once __DIR__ . '/../app/services/dng_derivatives.php';

/**
 * Throw when an expectation fails.
 */
function assert_dng_policy_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$GLOBALS['dng_policy_test_settings'] = [];
assert_dng_policy_same('auto_fallback', dng_conversion_source_policy(), 'default source policy');
assert_dng_policy_same('force_srgb', dng_conversion_color_policy(), 'default color policy');

assert_dng_policy_same('auto_fallback', dng_normalize_conversion_source_policy('broken'), 'invalid source policy fallback');
assert_dng_policy_same('force_srgb', dng_normalize_conversion_color_policy('broken'), 'invalid color policy fallback');

$GLOBALS['dng_policy_test_settings'] = [
    'dng_conversion_source_policy' => 'prefer_preview',
    'dng_conversion_color_policy' => 'preserve_look',
];
assert_dng_policy_same('prefer_preview', dng_conversion_source_policy(), 'stored preview source policy');
assert_dng_policy_same('preserve_look', dng_conversion_color_policy(), 'stored preserve color policy');

$allAvailable = ['raw' => true, 'preview_imagick' => true, 'preview_gd' => true];
assert_dng_policy_same(['raw', 'preview_imagick', 'preview_gd'], dng_conversion_attempt_order('auto_fallback', $allAvailable), 'default order keeps legacy raw first behavior');
assert_dng_policy_same(['raw', 'preview_imagick', 'preview_gd'], dng_conversion_attempt_order('prefer_raw', $allAvailable), 'raw policy order');
assert_dng_policy_same(['preview_imagick', 'preview_gd', 'raw'], dng_conversion_attempt_order('prefer_preview', $allAvailable), 'preview policy order');

$previewOnly = ['raw' => false, 'preview_imagick' => false, 'preview_gd' => true];
assert_dng_policy_same(['preview_gd'], dng_conversion_attempt_order('auto_fallback', $previewOnly), 'GD preview fallback when raw and Imagick preview are unavailable');

$rawOnly = ['raw' => true, 'preview_imagick' => false, 'preview_gd' => false];
assert_dng_policy_same(['raw'], dng_conversion_attempt_order('prefer_preview', $rawOnly), 'preview policy still falls back to raw when preview is unavailable');

$noneAvailable = ['raw' => false, 'preview_imagick' => false, 'preview_gd' => false];
assert_dng_policy_same([], dng_conversion_attempt_order('auto_fallback', $noneAvailable), 'no conversion path');

echo "DNG conversion policy tests passed.\n";
