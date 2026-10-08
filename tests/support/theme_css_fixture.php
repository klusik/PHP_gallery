<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/theme_css_fixture.php
 * Module Type: Browser Test Fixture
 * Purpose: Capture actual generated Theme CSS without application bootstrap or installation assets.
 * Responsibilities: Supply bounded absent-background/cache adapters and disposable Theme preferences.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Resolve an intentionally absent custom asset so the fixture cannot inspect installed user CSS.
     * @return string Nonexistent fixture-owned path outside the installation.
     */
    function custom_css_path(): string { return sys_get_temp_dir() . '/gallery-theme-fixture-absent-' . getmypid() . '.css'; }
    /**
     * Keep the isolated sample independent from installation background images.
     * @return string Empty background URL.
     */
    function theme_background_asset_url(): string { return ''; }
}
namespace Gallery\Controllers {
    /**
     * Suppress request cache headers while capturing CSS inside a disposable HTML document.
     * @param string $policy Production CSS cache-control value.
     * @return void Performs no transport operation in this fixture.
     */
    function send_asset_cache_control(string $policy): void {}
}
namespace {
    require_once __DIR__ . '/theme_appearance_fixture.php';
    require_once dirname(__DIR__, 2) . '/app/helpers_files.php';
    require_once dirname(__DIR__, 2) . '/app/controllers/theme_assets.php';
    /**
     * Capture the actual dynamic CSS controller with only disposable appearance values.
     * @param array<string,string> $values Stable Theme setting keys and scenario values.
     * @return string Complete production CSS; restores the fixture preference map even on refusal.
     */
    function theme_fixture_css(array $values): string {
        $previous = $GLOBALS['theme_fixture_values'];
        $GLOBALS['theme_fixture_values'] = $values;
        ob_start();
        try { \Gallery\Controllers\cms_theme_css(); return (string) ob_get_contents(); }
        finally { ob_end_clean(); $GLOBALS['theme_fixture_values'] = $previous; }
    }
}
