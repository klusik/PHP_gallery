<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/translations.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides the first lightweight translation layer for user-facing text.
 *
 * Responsibilities:
 *   - Resolve the active language for the current request
 *   - Load JSON and legacy PHP language files from app/lang
 *   - Return translated strings with safe fallback behavior
 *   - Record admin-visible diagnostics for missing translation keys
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
 *   2026-05-10
 */

declare(strict_types=1);

const CMS_LANGUAGE_COOKIE = 'cms_language';
const CMS_ADMIN_LANGUAGE_COOKIE = 'cms_admin_language';
const CMS_PUBLIC_LANGUAGE_COOKIE = 'cms_public_language';

/**
 * Return the directory where application language files are stored.
 *
 * @return string Text result for the caller.
 */
function translation_language_dir(): string
{
    return dirname(__DIR__) . '/lang';
}

/**
 * Normalize a language code into the conservative format used for file lookup.
 *
 * @param string $language Language value.
 * @return string Text result for the caller.
 */
function translation_normalize_language_code(string $language): string
{
    $language = strtolower(trim(str_replace('_', '-', $language)));
    if ($language === '' || preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/', $language) !== 1) {
        return '';
    }
    return $language;
}

/**
 * Return the configured default language, falling back to English.
 *
 * @return string Text result for the caller.
 */
function translation_default_language(): string
{
    $config = cms_config();
    $language = '';
    if (isset($config['language']) && is_array($config['language'])) {
        $language = translation_normalize_language_code((string) ($config['language']['default'] ?? ''));
    }
    if ($language === '') {
        $language = translation_normalize_language_code((string) ($config['default_language'] ?? ''));
    }
    return $language !== '' ? $language : 'en';
}

/**
 * Return detected language packs from app/lang.
 */


/**
 * Return the JSON language-pack path for one normalized language code.
 *
 * @param string $language Language value.
 * @return string Text result for the caller.
 */
function translation_language_json_path(string $language): string
{
    $language = translation_normalize_language_code($language);
    if ($language === '') {
        return '';
    }
    return translation_language_dir() . '/' . $language . '.json';
}

/**
 * Clear the in-request language and pack discovery caches after admin edits.
 */
function translation_clear_runtime_cache(): void
{
    $GLOBALS['cms_translation_cache_version'] = (int) ($GLOBALS['cms_translation_cache_version'] ?? 0) + 1;
}

/**
 * Handle translation detected language packs.
 *
 * Part of the related application service.
 *
 * @return array Structured result data for the caller.
 */
function translation_detected_language_packs(): array
{
    static $cache = null;
    static $cacheVersion = -1;
    $currentCacheVersion = (int) ($GLOBALS['cms_translation_cache_version'] ?? 0);
    if ($cache !== null && $cacheVersion === $currentCacheVersion) {
        return $cache;
    }
    $cacheVersion = $currentCacheVersion;

    // $directory stores the application language-pack directory.
    $directory = translation_language_dir();
    if (!is_dir($directory)) {
        return $cache = [];
    }

    $packs = [];
    foreach (glob($directory . '/*.{json,php}', GLOB_BRACE) ?: [] as $path) {
        // $code stores the language code inferred from the file name.
        $code = translation_normalize_language_code(pathinfo((string) $path, PATHINFO_FILENAME));
        if ($code === '') {
            continue;
        }
        if (!isset($packs[$code])) {
            $packs[$code] = [
                'code' => $code,
                'name' => strtoupper($code),
                'has_json' => false,
                'has_php' => false,
                'string_count' => 0,
                'loaded' => false,
            ];
        }
        if (strtolower(pathinfo((string) $path, PATHINFO_EXTENSION)) === 'json') {
            $packs[$code]['has_json'] = true;
        } else {
            $packs[$code]['has_php'] = true;
        }
    }

    foreach (array_keys($packs) as $code) {
        // $strings stores the currently loadable dictionary for one detected language.
        $strings = translation_load_language((string) $code);
        $packs[$code]['string_count'] = count($strings);
        $packs[$code]['loaded'] = $strings !== [];
        if (isset($strings['_language_name']) && is_string($strings['_language_name']) && trim($strings['_language_name']) !== '') {
            $packs[$code]['name'] = trim($strings['_language_name']);
        }
    }

    ksort($packs);
    return $cache = array_values($packs);
}

/**
 * Return the configured and detected list of languages that may be selected.
 *
 * @return array Structured result data for the caller.
 */
function translation_supported_languages(): array
{
    $config = cms_config();
    $configured = [];
    if (isset($config['language']) && is_array($config['language'])) {
        $configured = $config['language']['available'] ?? [];
    }
    if (!is_array($configured)) {
        $configured = [];
    }

    $languages = [];
    foreach ($configured as $language) {
        $normalized = translation_normalize_language_code((string) $language);
        if ($normalized !== '') {
            $languages[$normalized] = true;
        }
    }
    foreach (translation_detected_language_packs() as $pack) {
        $normalized = translation_normalize_language_code((string) ($pack['code'] ?? ''));
        if ($normalized !== '') {
            $languages[$normalized] = true;
        }
    }

    $default = translation_default_language();
    $languages[$default] = true;
    return array_keys($languages);
}

/**
 * Return true when a language code may be used for the current installation.
 *
 * @param string $language Language value.
 * @return bool True when the condition matches.
 */
function translation_language_allowed(string $language): bool
{
    $language = translation_normalize_language_code($language);
    if ($language === '') {
        return false;
    }
    return in_array($language, translation_supported_languages(), true);
}

/**
 * Return true when a route belongs to the administration interface.
 *
 * @param string $route Route value.
 * @return bool True when the condition matches.
 */
function translation_route_is_admin(string $route): bool
{
    return $route === 'setup' || $route === 'admin' || str_starts_with($route, 'admin_');
}

/**
 * Resolve the persisted public visitor language.
 *
 * @return string Text result for the caller.
 */
function translation_public_language(): string
{
    $candidate = '';
    if (function_exists('app_setting')) {
        $candidate = translation_normalize_language_code((string) app_setting('public_language', ''));
    }
    if ($candidate !== '' && translation_language_allowed($candidate)) {
        return $candidate;
    }

    // New installations and upgraded installations without an explicit public
    // language setting should show the public site in English. Existing saved
    // values in app_settings.public_language are still honored above.
    if (translation_language_allowed('en')) {
        return 'en';
    }

    return translation_default_language();
}

/**
 * Resolve the current admin interface language.
 *
 * @return string Text result for the caller.
 */
function translation_admin_language(): string
{
    $candidate = translation_normalize_language_code((string) ($_SESSION['cms_admin_language'] ?? ''));
    if ($candidate !== '' && translation_language_allowed($candidate)) {
        return $candidate;
    }

    $candidate = translation_normalize_language_code((string) ($_COOKIE[CMS_ADMIN_LANGUAGE_COOKIE] ?? $_COOKIE[CMS_LANGUAGE_COOKIE] ?? ''));
    if ($candidate !== '' && translation_language_allowed($candidate)) {
        return $candidate;
    }

    return translation_default_language();
}

/**
 * Resolve and persist the active language for this request.
 *
 * Admin routes use a private admin language preference. Public routes use the
 * site-wide public language setting, with an optional visitor override through
 * ?lang= and a visitor cookie. This lets the administrator keep the backend in
 * one language while anonymous users see another language.
 *
 * @param ?string $route Route value.
 */
function translation_bootstrap_request(?string $route = null): void
{
    $route = (string) ($route ?? ($_GET['page'] ?? ''));
    $isAdminRoute = translation_route_is_admin($route);
    $_SESSION['cms_translation_context'] = $isAdminRoute ? 'admin' : 'public';

    if ($isAdminRoute) {
        $selected = translation_admin_language();
        $_SESSION['cms_admin_language'] = $selected;
        $_SESSION['cms_language'] = $selected;
        return;
    }

    $selected = '';
    if (isset($_GET['lang'])) {
        $candidate = translation_normalize_language_code((string) $_GET['lang']);
        if ($candidate !== '' && translation_language_allowed($candidate)) {
            $selected = $candidate;
            $_SESSION['cms_public_language_override'] = $selected;
            if (!headers_sent()) {
                setcookie(CMS_PUBLIC_LANGUAGE_COOKIE, $selected, [
                    'expires' => time() + 31536000,
                    'path' => '/',
                    'secure' => request_is_https(),
                    'httponly' => false,
                    'samesite' => 'Lax',
                ]);
            }
        }
    }

    if ($selected === '') {
        $candidate = translation_normalize_language_code((string) ($_SESSION['cms_public_language_override'] ?? ''));
        if ($candidate !== '' && translation_language_allowed($candidate)) {
            $selected = $candidate;
        }
    }

    if ($selected === '') {
        $candidate = translation_normalize_language_code((string) ($_COOKIE[CMS_PUBLIC_LANGUAGE_COOKIE] ?? ''));
        if ($candidate !== '' && translation_language_allowed($candidate)) {
            $selected = $candidate;
            $_SESSION['cms_public_language_override'] = $selected;
        }
    }

    if ($selected === '') {
        $selected = translation_public_language();
    }

    $_SESSION['cms_language'] = $selected;
}

/**
 * Return the currently active language for translation lookup.
 *
 * @return string Text result for the caller.
 */
function translation_active_language(): string
{
    $context = (string) ($_SESSION['cms_translation_context'] ?? 'public');
    if ($context === 'admin') {
        return translation_admin_language();
    }

    $candidate = translation_normalize_language_code((string) ($_SESSION['cms_language'] ?? ''));
    if ($candidate !== '' && translation_language_allowed($candidate)) {
        return $candidate;
    }
    return translation_public_language();
}

/**
 * Load one language file. Invalid or missing files produce an empty dictionary.
 *
 * @param string $language Language value.
 * @return array Structured result data for the caller.
 */
function translation_load_language(string $language): array
{
    static $cache = [];
    static $cacheVersion = -1;
    $currentCacheVersion = (int) ($GLOBALS['cms_translation_cache_version'] ?? 0);
    if ($cacheVersion !== $currentCacheVersion) {
        $cache = [];
        $cacheVersion = $currentCacheVersion;
    }

    $language = translation_normalize_language_code($language);
    if ($language === '') {
        return [];
    }
    if (array_key_exists($language, $cache)) {
        return $cache[$language];
    }

    // $jsonPath stores the preferred editable language-pack path.
    $jsonPath = translation_language_dir() . '/' . $language . '.json';
    if (is_file($jsonPath)) {
        // $decoded stores the parsed JSON language dictionary.
        $decoded = json_decode((string) file_get_contents($jsonPath), true);
        $cache[$language] = is_array($decoded) ? $decoded : [];
        return $cache[$language];
    }

    // $phpPath stores the legacy native fallback dictionary path.
    $phpPath = translation_language_dir() . '/' . $language . '.php';
    if (!is_file($phpPath)) {
        $cache[$language] = [];
        return [];
    }

    $strings = require $phpPath;
    $cache[$language] = is_array($strings) ? $strings : [];
    return $cache[$language];
}



/**
 * Return a pretty JSON representation of the editable language pack.
 *
 * @param string $language Language value.
 * @return string Text result for the caller.
 */
function translation_language_pack_json_text(string $language): string
{
    $language = translation_normalize_language_code($language);
    if ($language === '') {
        return "{}\n";
    }

    $jsonPath = translation_language_json_path($language);
    if (is_file($jsonPath)) {
        $raw = (string) file_get_contents($jsonPath);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return translation_encode_language_json($decoded);
        }
        return $raw;
    }

    $strings = translation_load_language($language);
    if ($strings === []) {
        return "{}\n";
    }
    return translation_encode_language_json($strings);
}

/**
 * Encode a language dictionary in the maintained JSON format.
 *
 * @param array $strings Strings value.
 * @return string Text result for the caller.
 */
function translation_encode_language_json(array $strings): string
{
    $encoded = json_encode($strings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded)) {
        return "{}\n";
    }
    return $encoded . "\n";
}

/**
 * Validate editable language-pack JSON and return normalized strings or errors.
 *
 * @param string $json Json JSON data.
 * @return array Structured result data for the caller.
 */
function translation_validate_language_json(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || array_keys($decoded) === range(0, count($decoded) - 1)) {
        return [
            'valid' => false,
            'strings' => [],
            'errors' => [t('admin.theme.language.error_json_object', 'Language JSON must be an object with translation keys and string values.')],
        ];
    }

    $errors = [];
    $strings = [];
    foreach ($decoded as $key => $value) {
        if (!is_string($key) || trim($key) === '') {
            $errors[] = t('admin.theme.language.error_empty_key', 'Every translation key must be a non-empty string.');
            continue;
        }
        if (!is_string($value)) {
            $errors[] = t('admin.theme.language.error_string_value', 'Translation key "{key}" must contain a string value.', ['key' => (string) $key]);
            continue;
        }
        $strings[$key] = $value;
    }

    ksort($strings, SORT_NATURAL);
    return [
        'valid' => $errors === [],
        'strings' => $strings,
        'errors' => $errors,
    ];
}

/**
 * Save one editable JSON language pack after validation.
 *
 * @param string $language Language value.
 * @param string $json Json JSON data.
 * @return array Structured result data for the caller.
 */
function translation_save_language_json(string $language, string $json): array
{
    $language = translation_normalize_language_code($language);
    if ($language === '') {
        return [
            'saved' => false,
            'errors' => [t('admin.theme.language.error_invalid_code', 'Invalid language code.')],
        ];
    }

    $validation = translation_validate_language_json($json);
    if (empty($validation['valid'])) {
        return [
            'saved' => false,
            'errors' => $validation['errors'],
        ];
    }

    $directory = translation_language_dir();
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $path = translation_language_json_path($language);
    if ($path === '' || file_put_contents($path, translation_encode_language_json((array) $validation['strings']), LOCK_EX) === false) {
        return [
            'saved' => false,
            'errors' => [t('admin.theme.language.error_write_failed', 'Language file could not be written.')],
        ];
    }

    translation_clear_runtime_cache();
    return [
        'saved' => true,
        'errors' => [],
    ];
}

/**
 * Compare a language pack against the default English dictionary.
 *
 * @param string $language Language value.
 * @return array Structured result data for the caller.
 */
function translation_language_coverage(string $language): array
{
    $language = translation_normalize_language_code($language);
    $defaultStrings = translation_load_language(translation_default_language());
    $languageStrings = translation_load_language($language);

    $defaultKeys = array_values(array_filter(array_keys($defaultStrings), static fn ($key): bool => is_string($key) && $key !== '_language_name'));
    $languageKeys = array_values(array_filter(array_keys($languageStrings), static fn ($key): bool => is_string($key) && $key !== '_language_name'));
    $missing = array_values(array_diff($defaultKeys, $languageKeys));
    $extra = array_values(array_diff($languageKeys, $defaultKeys));
    sort($missing, SORT_NATURAL);
    sort($extra, SORT_NATURAL);

    return [
        'language' => $language,
        'translated_count' => count(array_intersect($defaultKeys, $languageKeys)),
        'default_count' => count($defaultKeys),
        'missing_count' => count($missing),
        'extra_count' => count($extra),
        'missing_keys' => $missing,
        'extra_keys' => $extra,
    ];
}

/**
 * Replace simple {placeholder} values in a translated string.
 *
 * @param string $text Text value.
 * @param array $parameters Parameters value.
 * @return string Text result for the caller.
 */
function translation_interpolate(string $text, array $parameters): string
{
    if (!$parameters) {
        return $text;
    }

    $replacements = [];
    foreach ($parameters as $name => $value) {
        $replacements['{' . (string) $name . '}'] = (string) $value;
    }
    return strtr($text, $replacements);
}

/**
 * Persist the selected language for the current admin/browser session.
 *
 * @param string $language Language value.
 * @return bool True when the condition matches.
 */
function translation_set_active_language(string $language): bool
{
    $language = translation_normalize_language_code($language);
    if ($language === '' || !translation_language_allowed($language)) {
        return false;
    }

    $_SESSION['cms_admin_language'] = $language;
    $_SESSION['cms_language'] = $language;
    if (!headers_sent()) {
        setcookie(CMS_ADMIN_LANGUAGE_COOKIE, $language, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => request_is_https(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
        setcookie(CMS_LANGUAGE_COOKIE, $language, [
            'expires' => time() + 31536000,
            'path' => '/',
            'secure' => request_is_https(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
    return true;
}

/**
 * Persist the site-wide language shown to anonymous/public visitors by default.
 *
 * @param string $language Language value.
 * @return bool True when the condition matches.
 */
function translation_set_public_language(string $language): bool
{
    $language = translation_normalize_language_code($language);
    if ($language === '' || !translation_language_allowed($language)) {
        return false;
    }

    if (function_exists('set_app_setting')) {
        set_app_setting('public_language', $language);
    }
    return true;
}

/**
 * Return whether the current request may collect admin translation diagnostics.
 *
 * @return bool True when the condition matches.
 */
function translation_diagnostics_enabled(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }
    if (!function_exists('current_user')) {
        return false;
    }
    // $user stores the authenticated account when one exists.
    $user = current_user();
    return is_array($user) && ($user['role'] ?? '') === 'admin';
}

/**
 * Store one missing translation detail for display in the admin language tab.
 *
 * @param string $key Lookup key.
 * @param string $active Active value.
 * @param string $fallbackUsed Fallback used value.
 */
function translation_record_missing_key(string $key, string $active, string $fallbackUsed): void
{
    if (!translation_diagnostics_enabled()) {
        return;
    }
    if (!isset($_SESSION['cms_translation_missing']) || !is_array($_SESSION['cms_translation_missing'])) {
        $_SESSION['cms_translation_missing'] = [];
    }

    // $diagnosticKey keeps repeated missing keys compact in the session.
    $diagnosticKey = $active . '|' . $key . '|' . $fallbackUsed;
    $_SESSION['cms_translation_missing'][$diagnosticKey] = [
        'key' => $key,
        'active_language' => $active,
        'fallback_used' => $fallbackUsed,
        'last_seen' => date('Y-m-d H:i:s'),
    ];
}

/**
 * Return collected missing translation diagnostics for the current admin session.
 *
 * @return array Structured result data for the caller.
 */
function translation_missing_diagnostics(): array
{
    // $rows stores the missing-key diagnostics collected for this session.
    $rows = $_SESSION['cms_translation_missing'] ?? [];
    if (!is_array($rows)) {
        return [];
    }
    return array_values(array_filter($rows, static fn ($row): bool => is_array($row)));
}

/**
 * Clear collected missing translation diagnostics for the current admin session.
 */
function translation_clear_missing_diagnostics(): void
{
    unset($_SESSION['cms_translation_missing']);
}

/**
 * Return whether admin-visible missing key markers should be appended.
 *
 * @return bool True when the condition matches.
 */
function translation_missing_key_markers_enabled(): bool
{
    $config = cms_config();
    if (!isset($config['language']) || !is_array($config['language'])) {
        return false;
    }

    // Missing keys are still recorded in the language diagnostics table. Visual
    // key suffixes are deliberately opt-in because they can leak into normal
    // admin pages during updates when PHP files and JSON packs are temporarily
    // out of sync.
    return !empty($config['language']['append_missing_keys_to_ui']);
}

/**
 * Mark fallback text for admins when the active language is missing a key.
 *
 * @param string $text Text value.
 * @param string $key Lookup key.
 * @return string Text result for the caller.
 */
function translation_admin_missing_key_text(string $text, string $key): string
{
    if (!translation_diagnostics_enabled() || !translation_missing_key_markers_enabled()) {
        return $text;
    }
    return $text . ' [' . $key . ']';
}

/**
 * Translate a string key using the active language with safe fallbacks.
 *
 * @param string $key Lookup key.
 * @param string|array|null $fallback Fallback value.
 * @param array $parameters Parameters value.
 * @return string Text result for the caller.
 */
function t(string $key, string|array|null $fallback = null, array $parameters = []): string
{
    if (is_array($fallback)) {
        $parameters = $fallback;
        $fallback = null;
    }

    $active = translation_active_language();
    $default = translation_default_language();
    $activeStrings = translation_load_language($active);

    if (array_key_exists($key, $activeStrings) && is_string($activeStrings[$key])) {
        return translation_interpolate($activeStrings[$key], $parameters);
    }

    if ($active !== $default) {
        $defaultStrings = translation_load_language($default);
        if (array_key_exists($key, $defaultStrings) && is_string($defaultStrings[$key])) {
            translation_record_missing_key($key, $active, $default);
            return translation_admin_missing_key_text(translation_interpolate($defaultStrings[$key], $parameters), $key);
        }
    }

    if ($fallback !== null) {
        translation_record_missing_key($key, $active, 'provided fallback');
        return translation_admin_missing_key_text(translation_interpolate($fallback, $parameters), $key);
    }

    translation_record_missing_key($key, $active, 'key');
    return translation_admin_missing_key_text($key, $key);
}
