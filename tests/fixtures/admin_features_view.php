<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/admin_features_view.php
 * Module Type: Render Fixture
 * Purpose: Exercise real capability persistence and presentation without installation data.
 * Responsibilities: Supply isolated settings, request labels and deterministic HTML snapshots.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Escape prepared display text. @param string $value Plain text. @return string Safe HTML. */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    /** Supply an isolated CSRF field. @return string Deterministic fixture token markup. */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="fixture-csrf">';
    }
    /** Build a confined settings destination. @param string $page Registered route. @param array<string,string|int|bool> $params Query parameters. @return string Relative route URL. */
    function url_for(string $page, array $params = []): string
    {
        return $page === 'admin_features'
            ? '/features'
            : '/index.php?' . http_build_query(['page' => $page] + $params);
    }
}
namespace Gallery\Services {
    /** Resolve English fixture text. @param string $key Catalog key. @param string $fallback Text used only without a catalog entry. @param array<string,string> $parameters Placeholder replacements. @return string Interpolated text. */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        // The fixture is two directories below the repository's active catalogs.
        static $catalog = null;
        $catalog ??= json_decode((string) file_get_contents(
            dirname(__DIR__, 2) . '/app/lang/en.json',
        ), true, flags: JSON_THROW_ON_ERROR);
        return strtr($catalog[$key] ?? ($fallback ?: $key), array_combine(
            array_map(static fn(string $key): string => '{' . $key . '}', array_keys($parameters)),
            array_values($parameters),
        ));
    }
    /** Read isolated app settings. @param string $key Setting identity. @param ?string $default Absent-setting value. @return ?string Stored value or supplied default. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['feature_fixture_settings'][$key] ?? $default;
    }
    /** Persist isolated app settings. @param string $key Setting identity. @param string $value Canonical stored text. @return void Updates only the fixture map. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['feature_fixture_settings'][$key] = $value;
    }
    /** Read the isolated thumbnail domain master. @return bool Whether thumbnail repair is configured on. */
    function thumbnail_warmup_enabled(): bool
    {
        return app_setting('thumbnail_background_warmup_enabled', '1') === '1';
    }
    /** Persist through the isolated domain adapter. @param bool $enabled Requested thumbnail master. @return void Updates the owned setting only. */
    function set_thumbnail_warmup_enabled(bool $enabled): void
    {
        set_app_setting('thumbnail_background_warmup_enabled', $enabled ? '1' : '0');
    }

}
namespace {
    require_once __DIR__ . '/../../app/services/feature_flags.php';
    require_once __DIR__ . '/../../app/controllers/admin_features.php';
    require_once __DIR__ . '/../../app/views/admin_features.php';
    require_once __DIR__ . '/../../app/views/admin_auth.php';
    require_once __DIR__ . '/../../app/views/admin_chrome.php';
    require_once __DIR__ . '/../../app/helpers_mutation.php';
    require_once __DIR__ . '/../../app/controllers/shared_layout.php';
    $input = json_decode($argv[1] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
    $GLOBALS['feature_fixture_settings'] = $input['settings'] ?? [];
    $message = '';
    if (isset($input['post'])) {
        try {
            \Gallery\Services\save_feature_flags_from_post($input['post']);
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();
        }
    }
    $model = \Gallery\Controllers\admin_features_view_model(
        \Gallery\Services\grouped_feature_flag_definitions(),
        \Gallery\Services\feature_capability_admin_health_snapshot(),
        \Gallery\Services\feature_flag_summary_counts(),
        '',
        $message,
    );
    ob_start();
    \Gallery\Views\view_render_admin_features_page($model);
    $html = (string) ob_get_clean();
    $releaseEmailEnabled = \Gallery\Services\feature_capability_configured_enabled(
        'release_email_notifications',
    );
    ob_start();
    \Gallery\Views\view_render_admin_release_email_settings([
        'enabled' => $releaseEmailEnabled,
        'endpoint' => '/account',
        'csrf_html' => \Gallery\Core\csrf_field(),
        'factory_label' => 'Factory default: Enabled',
    ]);
    $accountHtml = (string) ob_get_clean();
    ob_start();
    \Gallery\Views\view_render_missing_admin_email_notice(
        ['id' => 1, 'role' => 'admin', 'email' => null], 'home', '/account#account-email',
    );
    $noticeHtml = (string) ob_get_clean();
    ob_start();
    \Gallery\Views\view_render_admin_feature_groups($model['groups']);
    echo json_encode([
        'ok' => $message === '',
        'message' => $message,
        'registry_revision' => $model['registry_revision'],
        'summary' => $model['summary'],
        'groups_html' => (string) ob_get_clean(),
        'html' => $html,
        'stylesheet_files' => \Gallery\Controllers\shared_layout_admin_stylesheet_files(),
        'account_html' => $accountHtml,
        'notice_html' => $noticeHtml,
        'account_state' => \Gallery\Core\admin_mutation_success_envelope(
            'Saved', \Gallery\Core\admin_mutation_descriptor('settings.update', 'settings', 'update'),
        ) + ['enabled' => $releaseEmailEnabled],
        'settings' => $GLOBALS['feature_fixture_settings'],
    ], JSON_THROW_ON_ERROR);
}
