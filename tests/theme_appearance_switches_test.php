<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_appearance_switches_test.php
 * Module Type: Regression Test
 * Purpose: Exercise isolated Appearance writes through the guarded Theme route.
 * Responsibilities: Verify authority, canonical values and draft exclusion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Supply mutation transport without starting an HTTP server.
     * @return string POST for every fixture request.
     */
    function request_method(): string { return 'POST'; }

    /** Refuse an explicitly unauthenticated request before any service call.
     * @return void Throws when the disposable identity is denied.
     */
    function require_admin(): void
    {
        if (!empty($GLOBALS['appearance_denied'])) throw new \RuntimeException('auth refused');
    }

    /** Require the existing Theme CSRF boundary before any preference change.
     * @return void Throws for a missing or incorrect disposable token.
     */
    function verify_csrf(): void
    {
        if (($_POST['csrf_token'] ?? '') !== 'appearance-fixture') {
            throw new \RuntimeException('CSRF refused');
        }
    }

    /** Build the response fallback without installation configuration.
     * @param string $route Existing route identifier.
     * @return string Relative fixture URL.
     */
    function url_for(string $route): string { return '/index.php?page=' . $route; }
}

namespace Gallery\Services {
    /** Return safe response copy without loading installation translations.
     * @param string $key Stable translation identifier.
     * @param string $fallback Safe English text.
     * @return string The supplied fallback.
     */
    function t(string $key, string $fallback = ''): string { return $fallback; }

    /** Read the disposable settings map used by the real Theme service.
     * @param string $key Canonical preference name.
     * @param string|null $default Missing-value fallback.
     * @return string|null Current fixture value or its fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['appearance_values'][$key] ?? $default;
    }

    /** Persist one value in disposable memory instead of a live database.
     * @param string $key Canonical preference name.
     * @param string $value Serialized value.
     * @return void Records the write at the storage boundary.
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['appearance_writes'][] = $key;
        $GLOBALS['appearance_values'][$key] = $value;
    }

    /** Observe delegation to the existing layout policy without loading its grid dependencies.
     * @param string $key Existing safe layout preference.
     * @param scalar $value Canonical boolean digit from the real Appearance adapter.
     * @return void Records delegated writes or throws a simulated capability refusal.
     */
    function theme_layout_safe_save(string $key, mixed $value): void
    {
        if (!$GLOBALS['appearance_maps'] && str_starts_with($key, 'theme_gps_')) {
            throw new \InvalidArgumentException('capability unavailable');
        }
        $GLOBALS['appearance_delegated'][] = $key;
        set_app_setting($key, (string) $value);
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_mutation.php';
    require_once dirname(__DIR__) . '/app/services/theme.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_theme.php';

    /** Require one persistence or authority invariant.
     * @param bool $condition Required production behavior.
     * @param string $message Failure context.
     * @return void Throws on regression.
     */
    function appearance_switch_require(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    /** Invoke the real guarded Theme entrypoint and decode its private JSON.
     * @param array<string,string|list<string>|array<string,string>> $fields Submitted native fields.
     * @return array{ok:bool,message:string,mutation:array{type:string,entity:string,action:string,entity_ids:list<int>},contexts:array{},setting_values:array<string,bool>} Canonical response fields; guard exceptions propagate.
     */
    function appearance_switch_request(array $fields): array
    {
        $_POST = $fields;
        ob_start();
        try {
            \Gallery\Controllers\cms_admin_theme();
            return json_decode((string) ob_get_contents(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
    }

    $_GET = $_COOKIE = $_SESSION = [];
    $GLOBALS['appearance_maps'] = true;
    $GLOBALS['appearance_writes'] = $GLOBALS['appearance_delegated'] = [];
    $GLOBALS['appearance_values'] = ['theme_accent' => '#123456',
        'theme_gallery_grid_gap' => '24', 'theme_hero_tag_visible_limit' => '17'];
    $token = ['csrf_token' => 'appearance-fixture'];
    foreach (\Gallery\Services\theme_appearance_switch_defaults() as $key => $factory) {
        $before = $GLOBALS['appearance_values'];
        $result = appearance_switch_request($token + [
            'theme_appearance_switch' => $key, 'enabled' => $factory ? '0' : '1',
            'theme_accent' => '#abcdef', 'theme_gallery_grid_gap' => '60',
            'language_pack_json' => '{invalid',
        ]);
        appearance_switch_require($result['ok'] && http_response_code() === 200
            && $result['setting_values'] === [$key => !$factory]
            && $GLOBALS['appearance_values'] === array_replace($before, [$key => $factory ? '0' : '1'])
            && $result['mutation']['type'] === 'settings.update'
            && $result['contexts'] === [], 'Only the selected canonical binary preference changes: ' . $key);
    }
    appearance_switch_require(count($GLOBALS['appearance_delegated']) === 4,
        'Layout switches retain their existing owner; header uses the partial advanced owner.');
    $GLOBALS['appearance_maps'] = false;
    $before = $GLOBALS['appearance_values'];
    foreach ([
        ['theme_appearance_switch' => 'theme_accent', 'enabled' => '1'],
        ['theme_appearance_switch' => 'theme_gps_pin_enabled', 'enabled' => '1'],
        ['theme_appearance_switch' => 'theme_header_transparent', 'enabled' => ['1']],
    ] as $invalid) {
        $result = appearance_switch_request($token + $invalid);
        appearance_switch_require(!$result['ok'] && http_response_code() === 422
            && $GLOBALS['appearance_values'] === $before,
            'Unknown, unavailable or malformed preferences are refused without writes.');
    }
    foreach (['auth', 'CSRF'] as $guard) {
        $GLOBALS['appearance_denied'] = $guard === 'auth';
        try {
            appearance_switch_request(['theme_appearance_switch' => 'theme_header_transparent', 'enabled' => '0']);
            throw new RuntimeException('guard was skipped');
        } catch (RuntimeException $error) {
            appearance_switch_require($error->getMessage() === $guard . ' refused'
                && $GLOBALS['appearance_values'] === $before, 'Guard runs before writes: ' . $guard);
        }
    }
    echo "PASS Theme Appearance scoped switches\n";
}
