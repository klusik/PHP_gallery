<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_language_preferences_test.php
 * Module Type: Regression Test
 * Purpose: Exercise scoped Language writes through the authenticated Theme owner.
 * Responsibilities: Verify CSRF, canonical values and unrelated draft isolation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Supply maintained-language defaults without installation configuration.
     * @return array{} Empty configuration using the production defaults.
     */
    function cms_config(): array { return []; }

    /** Provide the fixture transport method.
     * @return string POST for all isolated mutation requests.
     */
    function request_method(): string { return 'POST'; }

    /** Enforce authentication before the real Theme dispatcher runs.
     * @return void Throws on an explicitly denied fixture identity.
     */
    function require_admin(): void
    {
        if (!empty($GLOBALS['language_denied'])) {
            throw new \RuntimeException('authentication refused');
        }
    }

    /** Require the disposable token before any production service write.
     * @return void Throws on a missing or incorrect token.
     */
    function verify_csrf(): void
    {
        if (($_POST['csrf_token'] ?? '') !== 'language-fixture') {
            throw new \RuntimeException('CSRF refused');
        }
    }

    /** Resolve the existing route without configuration or an HTTP server.
     * @param string $route Registered route identifier.
     * @return string Relative fixture destination.
     */
    function url_for(string $route): string { return '/index.php?page=' . $route; }

    /** Capture the real translation owner's cookie instructions.
     * @param list<array{name:string,value:string,expires:int,path:string,httponly:bool,samesite:string}> $intents Cookie transport records.
     * @return void Stores the instructions without changing browser cookies.
     */
    function apply_cookie_intents(array $intents): void
    {
        $GLOBALS['language_cookies'] = $intents;
    }
}

namespace Gallery\Services {
    /** Read the isolated translation settings bag.
     * @param string $key Existing canonical setting name.
     * @param string|null $default Missing-setting fallback.
     * @return string|null Current fixture value or its fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['language_settings'][$key] ?? $default;
    }

    /** Persist through the service boundary without touching a live database.
     * @param string $key Existing canonical setting name.
     * @param string $value Serialized setting value.
     * @return void Records the changed key and value in disposable state.
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['language_writes'][] = $key;
        $GLOBALS['language_settings'][$key] = $value;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_mutation.php';
    require_once dirname(__DIR__) . '/app/services/translations.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_theme.php';

    /** Require a request-boundary or persistence invariant.
     * @param bool $condition Required behavior.
     * @param string $message Regression context.
     * @return void Throws when the production behavior changes.
     */
    function language_preference_require(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    /** Run the real guarded request against disposable service storage.
     * @param array<string,string|list<string>|array<string,string>> $post Native submitted fields.
     * @return array{ok:bool,message:string,setting_values:array<string,string|bool|list<string>>,mutation:array{type:string,entity:string,action:string,entity_ids:list<int>},contexts:list<array<string,string>>,fallback:array{redirect_url:string}} Decoded canonical response; guard exceptions propagate.
     */
    function language_preference_request(array $post): array
    {
        $_POST = $post;
        ob_start();
        try {
            \Gallery\Controllers\cms_admin_theme();
            return json_decode((string) ob_get_contents(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
    }

    $_GET = $_COOKIE = [];
    $_SESSION = ['cms_admin_language' => 'en', 'cms_language' => 'en'];
    $GLOBALS['language_settings'] = [
        'public_language' => 'de',
        'public_language_selector_enabled' => '1',
        'public_language_selector_languages' => '["en","de"]',
        'public_language_selector_design' => 'unchanged design',
        'theme_accent' => '#123456',
    ];
    $GLOBALS['language_writes'] = $GLOBALS['language_cookies'] = [];
    $authority = ['csrf_token' => 'language-fixture'];
    $drafts = ['theme_accent' => '#abcdef', 'language_pack_json' => '{invalid',
        'public_language_selector_design' => ['preset' => 'minimal']];
    $result = language_preference_request($authority + $drafts + [
        'theme_language_preference_scope' => 'public', 'public_language' => ' CS ',
    ]);
    language_preference_require($result['ok'] && http_response_code() === 200
        && $result['setting_values'] === ['public_language' => 'cs']
        && $GLOBALS['language_writes'] === ['public_language'],
        'Public scope writes only its canonical normalized preference.');
    language_preference_require($result['mutation']['type'] === 'settings.update'
        && $result['contexts'] === [] && $result['fallback']['redirect_url']
            === '/index.php?page=admin_theme#admin-theme-tab-language',
        'Success retains the canonical completion envelope.');
    $result = language_preference_request($authority + $drafts + [
        'theme_language_preference_scope' => 'admin', 'cms_language' => 'sv',
    ]);
    language_preference_require($result['ok']
        && $result['setting_values'] === ['cms_language' => 'sv']
        && $_SESSION['cms_admin_language'] === 'sv'
        && $_SESSION['cms_language'] === 'sv'
        && array_column($GLOBALS['language_cookies'], 'name')
            === ['cms_admin_language', 'cms_language']
        && array_column($GLOBALS['language_cookies'], 'value') === ['sv', 'sv']
        && $GLOBALS['language_settings']['public_language'] === 'cs',
        'Admin scope preserves both established session/cookie keys and public default.');
    $result = language_preference_request($authority + $drafts + [
        'theme_language_preference_scope' => 'viewer',
        'public_language_selector_languages' => ['sv', 'en', 'sv'],
    ]);
    language_preference_require($result['ok'] && $result['setting_values'] === [
        'public_language_selector_enabled' => false,
        'public_language_selector_languages' => ['en', 'sv'],
    ] && $GLOBALS['language_settings']['public_language_selector_design'] === 'unchanged design'
        && $GLOBALS['language_settings']['theme_accent'] === '#123456',
        'Viewer scope normalizes its coupled fields without saving design or Theme drafts.');
    $snapshot = $GLOBALS['language_settings'];
    foreach (['public' => ['public_language' => 'fr'], 'viewer' => [
        'public_language_selector_languages' => [],
    ], 'unknown' => []] as $scope => $fields) {
        $result = language_preference_request($authority + [
            'theme_language_preference_scope' => $scope,
        ] + $fields);
        language_preference_require(!$result['ok'] && http_response_code() === 422
            && $GLOBALS['language_settings'] === $snapshot,
            'Invalid scope/value is refused before any storage change: ' . $scope);
        if ($scope === 'viewer') language_preference_require(
            $result['setting_values']['public_language_selector_languages'] === ['en', 'sv'],
            'Empty viewer list returns authoritative nonempty saved choices.');
    }
    foreach (['authentication', 'CSRF'] as $denial) {
        $GLOBALS['language_denied'] = $denial === 'authentication';
        try {
            language_preference_request([
                'theme_language_preference_scope' => 'public', 'public_language' => 'en',
            ]);
            throw new RuntimeException('Guard did not refuse ' . $denial);
        } catch (RuntimeException $error) {
            language_preference_require($error->getMessage() === $denial . ' refused'
                && $GLOBALS['language_settings'] === $snapshot,
                'Guard prevents all preference writes: ' . $denial);
        }
    }
    echo "PASS Theme Language scoped preferences\n";
}
