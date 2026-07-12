<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/upload_accept_and_dng_gps_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the mobile-friendly upload picker policy and DNG GPS metadata extraction helpers.
 *
 * Responsibilities:
 *   - Confirm that default upload accept values preserve historic server-supported formats
 *   - Confirm that phone-rendered upload mode avoids RAW/DNG accept filters
 *   - Confirm that DNG/TIFF GPS metadata can be read without PHP exif_read_data support
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

use function Gallery\Services\admin_upload_accept_value_for_mode;
use function Gallery\Services\extract_dng_gps_metadata;

require_once __DIR__ . '/../app/services/app_settings.php';
require_once __DIR__ . '/../app/services/uploads.php';

if (!function_exists('now_sql')) {
        /**
     * Minimal clock shim used by the isolated metadata test.
     *
     * @return string Text result for the caller.
     */
    function now_sql(): string
    {
        return '2026-06-04 12:00:00';
    }
}

require_once __DIR__ . '/support/fixed_clock_shim.php';
require_once __DIR__ . '/../app/services/exif.php';

/**
 * Assert that a condition is true.
 *
 * @param bool $condition Condition value.
 * @param string $message Message value.
 */
function assert_upload_dng_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

/**
 * Assert that two values are close enough for coordinate extraction.
 *
 * @param float $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $message Message value.
 */
function assert_upload_dng_float_close(float $expected, mixed $actual, string $message): void
{
    if (!is_float($actual) && !is_int($actual)) {
        fwrite(STDERR, $message . ' expected numeric value, got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
    if (abs($expected - (float) $actual) > 0.000001) {
        fwrite(STDERR, $message . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL);
        exit(1);
    }
}

/**
 * Write one byte string into a binary buffer.
 *
 * @param string $buffer Buffer value.
 * @param int $offset Starting offset.
 * @param string $value Value to process.
 */
function test_buffer_put(string &$buffer, int $offset, string $value): void
{
    $buffer = substr($buffer, 0, $offset) . $value . substr($buffer, $offset + strlen($value));
}

/**
 * Build one little-endian TIFF IFD entry.
 *
 * @param int $tag Tag value.
 * @param int $type Type value.
 * @param int $count Count value.
 * @param string $valueArea Value area value.
 * @return string Text result for the caller.
 */
function test_tiff_entry(int $tag, int $type, int $count, string $valueArea): string
{
    return pack('vvV', $tag, $type, $count) . str_pad($valueArea, 4, "\0");
}

/**
 * Build little-endian rational values.
 *
 * @param array $values Values value.
 * @return string Text result for the caller.
 */
function test_tiff_rationals(array $values): string
{
    $binary = '';
    foreach ($values as $value) {
        $binary .= pack('VV', $value[0], $value[1]);
    }
    return $binary;
}

/**
 * Build a tiny DNG-like TIFF file containing only a GPS IFD.
 *
 * @return string Text result for the caller.
 */
function make_test_dng_with_gps(): string
{
    $buffer = str_repeat("\0", 320);
    test_buffer_put($buffer, 0, 'II' . pack('vV', 42, 8));
    test_buffer_put($buffer, 8, pack('v', 1) . test_tiff_entry(0x8825, 4, 1, pack('V', 100)) . pack('V', 0));

    $gpsEntries = '';
    $gpsEntries .= test_tiff_entry(1, 2, 2, "N\0");
    $gpsEntries .= test_tiff_entry(2, 5, 3, pack('V', 200));
    $gpsEntries .= test_tiff_entry(3, 2, 2, "E\0");
    $gpsEntries .= test_tiff_entry(4, 5, 3, pack('V', 224));
    $gpsEntries .= test_tiff_entry(5, 1, 1, "\0");
    $gpsEntries .= test_tiff_entry(6, 5, 1, pack('V', 248));
    test_buffer_put($buffer, 100, pack('v', 6) . $gpsEntries . pack('V', 0));
    test_buffer_put($buffer, 200, test_tiff_rationals([[50, 1], [5, 1], [3000, 100]]));
    test_buffer_put($buffer, 224, test_tiff_rationals([[14, 1], [24, 1], [1200, 100]]));
    test_buffer_put($buffer, 248, test_tiff_rationals([[345, 10]]));
    return $buffer;
}

$historicAccept = admin_upload_accept_value_for_mode('server_supported', true, true);
assert_upload_dng_true(str_contains($historicAccept, '.dng'), 'Default server-supported accept mode must keep DNG when RAW is available.');
assert_upload_dng_true(str_contains($historicAccept, '.heic'), 'Default server-supported accept mode must keep HEIC when available.');
assert_upload_dng_true(str_contains($historicAccept, 'image/*'), 'Default server-supported accept mode must keep image/* compatibility.');

$phoneAccept = admin_upload_accept_value_for_mode('phone_jpeg', true, true);
assert_upload_dng_true(!str_contains($phoneAccept, '.dng'), 'Phone-rendered accept mode must not advertise DNG.');
assert_upload_dng_true(!str_contains($phoneAccept, '.heic'), 'Phone-rendered accept mode must not advertise HEIC.');
assert_upload_dng_true(!str_contains($phoneAccept, 'image/*'), 'Phone-rendered accept mode must avoid broad image/* when asking for rendered browser images.');
assert_upload_dng_true(str_contains($phoneAccept, 'image/jpeg'), 'Phone-rendered accept mode must explicitly request JPEG.');

$path = tempnam(sys_get_temp_dir(), 'php-gallery-dng-gps-');
if ($path === false) {
    throw new RuntimeException('Unable to create temporary DNG test file.');
}
try {
    file_put_contents($path, make_test_dng_with_gps());
    $metadata = extract_dng_gps_metadata($path);
    assert_upload_dng_float_close(50.0916667, $metadata['gps_lat'], 'DNG GPS latitude must be extracted.');
    assert_upload_dng_float_close(14.4033333, $metadata['gps_lng'], 'DNG GPS longitude must be extracted.');
    assert_upload_dng_float_close(34.5, $metadata['gps_altitude'], 'DNG GPS altitude must be extracted.');
    assert_upload_dng_true($metadata['gps_extracted_at'] === '2026-06-04 12:00:00', 'DNG GPS extraction timestamp must be set when coordinates exist.');
} finally {
    @unlink($path);
}

echo "Upload accept and DNG GPS tests passed.\n";
