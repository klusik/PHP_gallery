<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_filesystem_ownership_test.php
 * Module Type: Regression Test
 * Purpose: Verify Theme and log filesystem ownership without editing installation assets.
 * Responsibilities:
 *   - Preserve unchanged presets, original backgrounds and unknown temporary files.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Read only this test's in-memory setting map.
     * @param string $key Requested setting identifier.
     * @param string $fallback Default when the fixture has no stored preference.
     * @return string Fixture setting value.
     */
    function app_setting(string $key, string $fallback = ''): string { return $GLOBALS['filesystem_owner_settings'][$key] ?? $fallback; }
    /**
     * Record one setting without database or configuration access.
     * @param string $key Setting identifier.
     * @param string $value New fixture value.
     * @return void
     */
    function set_app_setting(string $key, string $value): void { $GLOBALS['filesystem_owner_settings'][$key] = $value; }
}
namespace {
    /**
     * Fail a preservation assertion using fixture-only diagnostic text.
     * @param bool $condition Expected filesystem or setting postcondition.
     * @param string $message Safe failure description.
     * @return void
     */
    function filesystem_owner_assert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    $root = sys_get_temp_dir() . '/gallery-filesystem-owner-' . bin2hex(random_bytes(12));
    $directories = ['', '/app', '/app/services', '/public', '/public/assets', '/custom_css', '/cache', '/cache/theme-background'];
    $files = ['/app/services/custom_css.php', '/app/services/gallery_backgrounds.php', '/public/assets/custom.css',
        '/custom_css/fixture.css', '/cache/theme-background/original.png', '/cache/theme-background/optimized.webp', '/unrelated.txt'];
    $GLOBALS['filesystem_owner_settings'] = [];
    $allocatedExport = '';
    try {
        foreach ($directories as $directory) { mkdir($root . $directory); }
        foreach (['custom_css', 'gallery_backgrounds'] as $module) {
            copy(dirname(__DIR__) . '/app/services/' . $module . '.php', $root . '/app/services/' . $module . '.php');
            require $root . '/app/services/' . $module . '.php';
        }
        require_once dirname(__DIR__) . '/app/services/logs.php';
        file_put_contents($root . '/custom_css/fixture.css', 'body { color: black; }');
        filesystem_owner_assert(Gallery\Services\custom_css_apply_preset('fixture.css'), 'New preset was not installed.');
        file_put_contents($root . '/public/assets/custom.css', '/* retained local modification */');
        filesystem_owner_assert(!Gallery\Services\custom_css_apply_preset('fixture.css'), 'Unchanged preset was reinstalled.');
        filesystem_owner_assert(file_get_contents($root . '/public/assets/custom.css') === '/* retained local modification */', 'Unrelated Theme save discarded custom CSS.');
        filesystem_owner_assert(!Gallery\Services\custom_css_apply_preset('../fixture.css'), 'Traversal preset was admitted.');
        filesystem_owner_assert(!Gallery\Services\custom_css_store_uploaded(['name' => 'fake.css', 'tmp_name' => $root . '/custom_css/fixture.css']), 'Non-upload file was installed as an upload.');
        Gallery\Services\custom_css_reset();
        filesystem_owner_assert(!is_file($root . '/public/assets/custom.css') && Gallery\Services\app_setting('custom_css_preset') === '', 'Stylesheet reset left inconsistent state.');

        file_put_contents($root . '/cache/theme-background/original.png', 'fixture-original');
        file_put_contents($root . '/cache/theme-background/optimized.webp', 'fixture-derivative');
        file_put_contents($root . '/unrelated.txt', 'unrelated-owned-fixture');
        Gallery\Services\set_app_setting('theme_background_original_path', 'cache/theme-background/original.png');
        Gallery\Services\set_app_setting('theme_background_optimized_path', 'cache/theme-background/optimized.webp');
        Gallery\Services\theme_background_delete_optimized();
        filesystem_owner_assert(is_file($root . '/cache/theme-background/original.png') && !is_file($root . '/cache/theme-background/optimized.webp'), 'Derivative deletion changed the original.');
        foreach (['cache/theme-background/original.png', 'unrelated.txt'] as $unsafe) {
            Gallery\Services\set_app_setting('theme_background_optimized_path', $unsafe);
            $refused = false;
            try { Gallery\Services\theme_background_delete_optimized(); } catch (RuntimeException) { $refused = true; }
            filesystem_owner_assert($refused && is_file($root . '/' . $unsafe), 'Unverified derivative identity was deleted.');
        }
        Gallery\Services\admin_log_export_release($root . '/unrelated.txt');
        filesystem_owner_assert(is_file($root . '/unrelated.txt'), 'Unknown export path was deleted.');
        $allocatedExport = Gallery\Services\admin_log_export_temp_path();
        Gallery\Services\admin_log_export_release($allocatedExport);
        filesystem_owner_assert(!is_file($allocatedExport), 'Owned export was not released.');
        Gallery\Services\admin_log_export_release($allocatedExport);
        echo "Admin filesystem ownership checks passed.\n";
    } finally {
        if ($allocatedExport !== '') { Gallery\Services\admin_log_export_release($allocatedExport); }
        foreach ($files as $file) { if (is_file($root . $file)) { unlink($root . $file); } }
        foreach (array_reverse($directories) as $directory) { if (is_dir($root . $directory)) { rmdir($root . $directory); } }
    }
}
