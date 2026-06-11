<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/upload_automation_sim_camera_metadata_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies upload automation parsing for Flight Simulator camera metadata.
 *
 * Responsibilities:
 *   - Accept valid SimConnect camera latitude, longitude, and altitude fields
 *   - Reject unsupported source labels and out-of-range coordinates
 *   - Remain executable without a live database
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
 *   2026-05-27
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/upload_automation.php';

/**
 * Throw when an expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Label value.
 */
function assert_upload_automation_camera_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$_POST = [
    'sim_location_source' => 'simconnect_camera',
    'sim_camera_latitude' => '48.123456789',
    'sim_camera_longitude' => '16.987654321',
    'sim_camera_altitude' => '1234.567',
];
$metadata = upload_automation_sim_camera_metadata();
assert_upload_automation_camera_same(48.1234568, $metadata['lat'] ?? null, 'valid latitude rounds to database precision');
assert_upload_automation_camera_same(16.9876543, $metadata['lng'] ?? null, 'valid longitude rounds to database precision');
assert_upload_automation_camera_same(1234.57, $metadata['altitude'] ?? null, 'valid altitude rounds to database precision');
assert_upload_automation_camera_same('simconnect_camera', $metadata['source'] ?? null, 'valid source is preserved');

$_POST = [
    'sim_location_source' => 'aircraft',
    'sim_camera_latitude' => '48.1',
    'sim_camera_longitude' => '16.9',
];
assert_upload_automation_camera_same(null, upload_automation_sim_camera_metadata(), 'unsupported source is rejected');

$_POST = [
    'sim_location_source' => 'simconnect_camera',
    'sim_camera_latitude' => '91',
    'sim_camera_longitude' => '16.9',
];
assert_upload_automation_camera_same(null, upload_automation_sim_camera_metadata(), 'out-of-range latitude is rejected');

$_POST = [
    'sim_location_source' => 'simconnect_camera',
    'sim_camera_latitude' => '48.1',
    'sim_camera_longitude' => '181',
];
assert_upload_automation_camera_same(null, upload_automation_sim_camera_metadata(), 'out-of-range longitude is rejected');

echo "upload automation SimConnect camera metadata tests passed\n";
