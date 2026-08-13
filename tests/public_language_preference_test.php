<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_language_preference_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verifies the public viewer language preference without a database or browser.
 *
 * Responsibilities:
 *   - Keep selectable language metadata limited to maintained complete packs
 *   - Verify query, session, cookie, and site-default preference precedence
 *   - Verify that reset clears both visitor persistence layers
 *   - Verify same-page language links preserve public route state
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
 *   - This test supplies bounded configuration stubs so it remains executable
 *     with plain PHP and does not connect to the application database.
 */

declare(strict_types=1);

namespace Gallery\Core {
    function cms_config(): array
    {
        return ['language' => ['default' => 'en', 'available' => ['en', 'cs', 'de', 'sv']]];
    }

    function current_user(): ?array
    {
        return null;
    }

    function request_is_https(): bool
    {
        return false;
    }
}

namespace Gallery\Services {
    $GLOBALS['public_language_test_settings'] = [];

    function app_setting(string $key, mixed $default = null): mixed
    {
        if ($key === 'public_language') {
            return $GLOBALS['public_language_test_settings'][$key] ?? 'en';
        }
        return $GLOBALS['public_language_test_settings'][$key] ?? $default;
    }

    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['public_language_test_settings'][$key] = $value;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/translations.php';

    use function Gallery\Services\translation_active_language;
    use function Gallery\Services\translation_bootstrap_request;
    use function Gallery\Services\translation_language_presentation;
    use function Gallery\Services\translation_public_language_override_active;
    use function Gallery\Services\translation_public_language_selector_enabled;
    use function Gallery\Services\translation_public_language_selector_languages;
    use function Gallery\Services\translation_public_language_url;
    use function Gallery\Services\translation_save_public_language_selector_settings;
    use function Gallery\Services\translation_supported_languages;

    /**
     * Fail with a focused diagnostic when an expectation differs.
     */
    function public_language_assert_same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    $_SESSION = [];
    $_COOKIE = [];
    $_GET = ['page' => 'gallery', 'id' => '42', 'sort' => 'newest', 'lang' => 'de'];
    $_SERVER['REQUEST_URI'] = '/index.php?page=gallery&id=42&sort=newest&lang=de';
    $_SERVER['SCRIPT_NAME'] = '/index.php';

    public_language_assert_same(['en', 'cs', 'de', 'sv'], translation_supported_languages(), 'Maintained selector order');
    public_language_assert_same(true, translation_public_language_selector_enabled(), 'Viewer selector defaults enabled');
    public_language_assert_same(['en', 'cs', 'de', 'sv'], translation_public_language_selector_languages(), 'All viewer languages default enabled');
    $languagePresentation = translation_language_presentation();
    public_language_assert_same(['en', 'cs', 'de', 'sv'], array_keys($languagePresentation), 'Presentation metadata coverage');
    public_language_assert_same(
        ['assets/flags/gb.svg', 'assets/flags/cz.svg', 'assets/flags/de.svg', 'assets/flags/se.svg'],
        array_column($languagePresentation, 'flag_asset'),
        'Bundled flag asset mapping'
    );
    foreach ($languagePresentation as $presentation) {
        $flagPath = dirname(__DIR__) . '/public/' . (string) ($presentation['flag_asset'] ?? '');
        public_language_assert_same(true, is_file($flagPath) && str_starts_with(trim((string) file_get_contents($flagPath)), '<svg'), 'Bundled SVG flag exists');
    }

    translation_bootstrap_request('gallery');
    public_language_assert_same('de', translation_active_language(), 'Query selection');
    public_language_assert_same('de', $_SESSION['cms_public_language_override'] ?? null, 'Session persistence');
    public_language_assert_same(true, translation_public_language_override_active(), 'Override active after selection');

    $swedishUrl = translation_public_language_url('sv');
    public_language_assert_same('/index.php?page=gallery&id=42&sort=newest&lang=sv', $swedishUrl, 'Same-page language URL');

    $_COOKIE['cms_public_language'] = 'de';
    $_GET['lang'] = 'default';
    translation_bootstrap_request('gallery');
    public_language_assert_same('en', translation_active_language(), 'Site default after reset');
    public_language_assert_same(false, isset($_SESSION['cms_public_language_override']), 'Session override cleared');
    public_language_assert_same(false, isset($_COOKIE['cms_public_language']), 'Request cookie cleared');
    public_language_assert_same(false, translation_public_language_override_active(), 'Override inactive after reset');

    $_GET = ['page' => 'gallery'];
    $_SESSION = [];
    $_COOKIE = ['cms_public_language' => 'sv'];
    translation_bootstrap_request('gallery');
    public_language_assert_same('sv', translation_active_language(), 'Cookie selection');

    $_GET = ['page' => 'gallery', 'lang' => 'fr'];
    $_SESSION = [];
    $_COOKIE = [];
    translation_bootstrap_request('gallery');
    public_language_assert_same('en', translation_active_language(), 'Unsupported language fallback');

    $_GET = ['page' => 'browser_i18n', 'lang' => 'de'];
    $_SESSION = [];
    $_COOKIE = [];
    translation_bootstrap_request('browser_i18n');
    public_language_assert_same('en', translation_active_language(), 'Browser i18n asset does not mutate viewer preference');
    public_language_assert_same(false, translation_public_language_override_active(), 'Browser i18n asset leaves override inactive');

    translation_save_public_language_selector_settings(true, ['de', 'en', 'de']);
    public_language_assert_same(['en', 'de'], translation_public_language_selector_languages(), 'Viewer language subset is normalized in maintained order');
    $_GET = ['page' => 'gallery', 'lang' => 'sv'];
    $_SESSION = [];
    $_COOKIE = [];
    translation_bootstrap_request('gallery');
    public_language_assert_same('en', translation_active_language(), 'Disabled viewer language cannot override public default');
    $_GET['lang'] = 'de';
    translation_bootstrap_request('gallery');
    public_language_assert_same('de', translation_active_language(), 'Enabled viewer language remains selectable');

    translation_save_public_language_selector_settings(false, ['en', 'de']);
    $_GET = ['page' => 'gallery', 'lang' => 'de'];
    $_SESSION = [];
    $_COOKIE = [\Gallery\Services\CMS_PUBLIC_LANGUAGE_COOKIE => 'de'];
    translation_bootstrap_request('gallery');
    public_language_assert_same(false, translation_public_language_selector_enabled(), 'Viewer selector persists disabled');
    public_language_assert_same('en', translation_active_language(), 'Disabled viewer selector ignores query and cookie overrides');
    public_language_assert_same(false, translation_public_language_override_active(), 'Disabled viewer selector reports no active override');

    try {
        translation_save_public_language_selector_settings(true, []);
        throw new RuntimeException('Empty viewer-language selection was accepted.');
    } catch (InvalidArgumentException) {
    }

    $layoutSource = (string) file_get_contents(dirname(__DIR__) . '/app/views/layout.php');
    $languageSettingsViewSource = (string) file_get_contents(dirname(__DIR__) . '/app/views/admin_language_settings.php');
    $settingsViewSource = (string) file_get_contents(dirname(__DIR__) . '/app/views/admin_settings.php');
    $publicCssSource = (string) file_get_contents(dirname(__DIR__) . '/public/assets/styles/public.css');
    public_language_assert_same(true, str_contains($layoutSource, 'view_render_public_language_selector();'), 'Public header selector registration');
    public_language_assert_same(true, str_contains($layoutSource, 'public-language-button') && str_contains($layoutSource, '<img class="public-language-flag"'), 'SVG flag-button control rendering');
    public_language_assert_same(true, str_contains($layoutSource, 'translation_public_language_selector_enabled()') && str_contains($layoutSource, 'translation_public_language_selector_languages()'), 'Public selector honors persisted viewer settings');
    public_language_assert_same(true, str_contains($languageSettingsViewSource, 'function view_render_public_language_selector_settings_panel') && str_contains($settingsViewSource, 'view_render_public_language_selector_settings_panel(['), 'Theme and centralized Settings share the language-selector panel module');
    public_language_assert_same(true, str_contains($publicCssSource, '.public-language-switcher') && str_contains($publicCssSource, '.public-language-button'), 'Public selector styling');

    fwrite(STDOUT, "Public language preference query, cookie, reset, metadata, URL, and header behavior passed.\n");
}
