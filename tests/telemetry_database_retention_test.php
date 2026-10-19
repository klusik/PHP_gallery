<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/telemetry_database_retention_test.php
 * Module Type: Regression Test
 * Purpose: Verify independent SQL retention and fail-closed destructive reads.
 * Responsibilities:
 *   - Reject invalid persisted retention and propagate unavailable storage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Models {
    /**
     * Return isolated retention state or simulate a failed settings read.
     * @param string $key Requested canonical setting key.
     * @return string|false Stored value, or false for a confirmed missing key.
     */
    function telemetry_model_setting(string $key): string|false
    {
        if (!empty($GLOBALS['retention_failure'])) {
            throw new \RuntimeException('fixture unavailable');
        }
        return $GLOBALS['retention_settings'][$key] ?? false;
    }
}
namespace {
    require_once dirname(__DIR__) . '/app/services/telemetry_settings.php';
    use function Gallery\Services\telemetry_database_retention_days;
    /**
     * Assert a retention invariant without any live settings mutation.
     * @param bool $condition Whether the tested invariant holds.
     * @param string $message Safe failure explanation.
     * @return void Throws when the invariant is false.
     */
    function telemetry_retention_assert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    $GLOBALS['retention_settings'] = ['telemetry_hourly_retention_days' => '90'];
    telemetry_retention_assert(telemetry_database_retention_days(true) === 7,
        'Missing independent setting uses seven days regardless of hourly retention.');
    foreach (['1', '14', '90'] as $value) {
        $GLOBALS['retention_settings']['telemetry_database_retention_days'] = $value;
        telemetry_retention_assert(telemetry_database_retention_days(true) === (int) $value,
            'Explicit supported diagnostic retention must be preserved.');
    }
    foreach (['0', '91', '-1', '', 'invalid'] as $value) {
        $GLOBALS['retention_settings']['telemetry_database_retention_days'] = $value;
        $refused = false;
        try { telemetry_database_retention_days(true); }
        catch (RuntimeException) { $refused = true; }
        telemetry_retention_assert($refused, 'Invalid persisted retention must stop deletion.');
    }
    $GLOBALS['retention_failure'] = true;
    $refused = false;
    try { telemetry_database_retention_days(true); }
    catch (RuntimeException) { $refused = true; }
    telemetry_retention_assert($refused, 'Unavailable settings must never authorize a shorter default.');
    echo "telemetry_database_retention_test: ok\n";
}
