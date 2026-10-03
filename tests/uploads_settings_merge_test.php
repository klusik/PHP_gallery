<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/uploads_settings_merge_test.php
 * Module Type: Test Script
 * Purpose: Verify canonical upload settings consolidation without a database.
 * Responsibilities:
 *   - Check legacy navigation defaults and persistence
 *   - Exercise strict field validation and coupled worker configuration
 *   - Preserve unchanged browser settings across partial central submissions
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Resolve canonical runtime limits for the isolated settings fixture.
     * @param string $key Runtime limit identifier.
     * @return int|float Configured numeric runtime limit.
     */
    function cms_runtime_limit(string $key): int|float
    {
        static $configuration = null;
        $configuration ??= require dirname(__DIR__) . '/app/configuration_defaults.php';
        return $configuration['runtime_limits'][$key];
    }
}

namespace Gallery\Services {
    $GLOBALS['uploads_merge_settings'] = [];
    $GLOBALS['uploads_merge_writes'] = [];

    /**
     * Read an in-memory canonical app setting.
     * @param string $key Persisted setting identifier.
     * @param string $fallback Value returned when unset.
     * @return string Stored value or fallback.
     */
    function app_setting(string $key, string $fallback = ''): string
    {
        return $GLOBALS['uploads_merge_settings'][$key] ?? $fallback;
    }

    /**
     * Capture canonical persistence writes for settings assertions.
     * @param string $key Persisted setting identifier.
     * @param string $value Canonical scalar value.
     * @return void
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['uploads_merge_settings'][$key] = $value;
        $GLOBALS['uploads_merge_writes'][] = $key;
    }

    /**
     * Return untranslated fixture messages.
     * @param string $key Translation identifier.
     * @param string $fallback Readable message.
     * @return string Fixture message.
     */
    function t(string $key, string $fallback): string
    {
        return $fallback;
    }

    require_once dirname(__DIR__) . '/app/services/browser_uploads.php';
    require_once dirname(__DIR__) . '/app/services/browser_thumbnail_rebuild.php';
    require_once dirname(__DIR__) . '/app/services/uploads.php';
    require_once dirname(__DIR__) . '/app/services/admin_settings_registry.php';

    /**
     * Assert the central settings behavior under test.
     * @param bool $condition Expected condition.
     * @param string $message Failure explanation.
     * @return void
     */
    function uploads_merge_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    uploads_merge_assert(!admin_legacy_upload_navigation_enabled(), 'Legacy upload navigation must default off.');
    admin_settings_save_editable_value('admin_legacy_upload_navigation_enabled', '1');
    uploads_merge_assert(admin_legacy_upload_navigation_enabled(), 'Preference must use its canonical storage key.');
    admin_settings_save_editable_value('admin_legacy_upload_navigation_enabled', '0');
    uploads_merge_assert(!admin_legacy_upload_navigation_enabled(), 'Preference must be reversible.');

    set_app_setting('browser_upload_default_worker_count', '3');
    set_app_setting('browser_upload_max_worker_count', '6');
    set_app_setting('browser_upload_hard_worker_cap', '9');
    set_app_setting('browser_upload_max_zip_batch_bytes', (string) (13 * 1048576));
    set_app_setting('browser_thumbnail_rebuild_source_chunk_bytes', (string) (96 * 1048576));
    $before = browser_upload_settings();
    $GLOBALS['uploads_merge_writes'] = [];
    $input = admin_settings_browser_upload_values(['browser_upload_zip_size_threshold_ratio' => '0.72']);
    set_browser_upload_settings($input);
    $after = browser_upload_settings();
    foreach ($before as $key => $value) {
        if ($key !== 'zip_size_threshold_ratio') {
            uploads_merge_assert($after[$key] === $value, 'Partial save changed omitted setting ' . $key);
        }
    }
    uploads_merge_assert($after['zip_size_threshold_ratio'] === 0.72, 'Ratio should support decimal input.');
    uploads_merge_assert(count($GLOBALS['uploads_merge_writes']) === 9 && count(array_unique($GLOBALS['uploads_merge_writes'])) === 9, 'Bulk save must write each canonical browser key exactly once.');

    $input = admin_settings_browser_upload_values(['browser_upload_default_worker_count' => '12', 'browser_upload_max_worker_count' => '16', 'browser_upload_hard_worker_cap' => '20', 'browser_upload_max_zip_batch_bytes' => '25.5']);
    set_browser_upload_settings($input);
    $after = browser_upload_settings();
    uploads_merge_assert($after['default_worker_count'] === 12 && $after['max_worker_count'] === 16 && $after['hard_worker_cap'] === 20, 'Coupled tuning must avoid sequential cap clamping.');
    uploads_merge_assert($after['max_zip_batch_bytes'] === (int) (25.5 * 1048576), 'Human MB value must persist as canonical bytes.');

    foreach ([['browser_upload_default_worker_count' => '21'], ['browser_upload_zip_size_threshold_ratio' => 'NaN'], ['browser_upload_max_items_per_batch' => '2.5'], ['browser_upload_max_zip_batch_bytes' => '129'], ['browser_thumbnail_rebuild_source_chunk_bytes' => '0'], ['browser_upload_batch_size_policy' => 'unknown'], ['unknown' => '1']] as $invalid) {
        $rejected = false;
        try {
            admin_settings_browser_upload_values($invalid);
        } catch (\InvalidArgumentException $exception) {
            $rejected = true;
        }
        uploads_merge_assert($rejected, 'Malformed or inconsistent settings must be rejected.');
    }
    $rejected = false;
    try {
        admin_settings_normalize_editable_value(['id' => 'browser_upload_enabled', 'central_editable' => false], '1');
    } catch (\InvalidArgumentException $exception) {
        $rejected = true;
    }
    uploads_merge_assert($rejected, 'Summary-only entries must remain non-editable.');
    $controller = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/admin_settings.php');
    uploads_merge_assert(substr_count($controller, 'set_browser_upload_settings($browserInput)') === 1, 'Central controller must use one bulk browser-settings save.');
    uploads_merge_assert(str_contains($controller, "\$payload['sidebar_html']") && str_contains($controller, "\$payload['legacy_nav_changed']"), 'Navigation changes must expose in-place sidebar refresh metadata.');
    echo "Uploads settings merge tests passed.\n";
}
