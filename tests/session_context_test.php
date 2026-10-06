<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/session_context_test.php
 * Module Type: Regression Test
 * Purpose: Verify the Core session adapter and its real language, navigation, and gallery-access consumers.
 * Responsibilities: Preserve request-local session semantics, OAuth state handling, and gallery unlock lifetimes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * This isolated semantic fixture uses no live database or external OAuth service.
 * Domain services are loaded directly with bounded Core/schema stubs, and the
 * clock and OAuth POST transport are supplied as narrow test dependencies.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return configuration supplied by the session-context fixture.
     *
     * @return array<string,mixed> Test-local application configuration.
     */
    function cms_config(): array
    {
        $config = $GLOBALS['session_context_test_config'] ?? [];
        return is_array($config) ? $config : [];
    }

    /**
     * Return the administrator identity configured by the fixture.
     *
     * @return array<string,mixed>|false Test user record, or false when anonymous.
     */
    function current_user(): array|false
    {
        $user = $GLOBALS['session_context_test_user'] ?? false;
        return is_array($user) ? $user : false;
    }

    /**
     * Return the stable timestamp used by the navigation fixture.
     *
     * @return string Deterministic SQL timestamp.
     */
    function now_sql(): string
    {
        return '2026-10-05 12:00:00';
    }
}

namespace Gallery\Services {
    /**
     * Return the fixture clock for namespaced service calls.
     *
     * @return int Current deterministic Unix timestamp.
     */
    function time(): int
    {
        return (int) ($GLOBALS['session_context_test_clock'] ?? 1_797_000_000);
    }

    /**
     * Return one fixture application setting.
     *
     * @param string $key Setting key to read.
     * @param array<array-key,mixed>|bool|float|int|object|string|null $default Session-compatible fallback value used when the fixture has no setting.
     * @return array<array-key,mixed>|bool|float|int|object|string|null Fixture value or the supplied default when session-compatible.
     */
    function app_setting(string $key, mixed $default = null): mixed
    {
        $settings = $GLOBALS['session_context_test_settings'] ?? [];
        return is_array($settings) && array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    /**
     * Report a confirmed legacy schema with no persisted Navigraph account table.
     *
     * @return array{state:string,feature:string} Bounded account schema state.
     */
    function presentation_navigation_account_schema_status(): array
    {
        return [
            'state' => (string) ($GLOBALS['session_context_account_schema_state'] ?? 'missing'),
            'feature' => 'navigation_account',
        ];
    }

    /**
     * Report the same confirmed legacy state for narrow credential revocation.
     *
     * @return array{state:string,feature:string} Bounded account-delete schema state.
     */
    function presentation_navigation_account_delete_schema_status(): array
    {
        return [
            'state' => (string) ($GLOBALS['session_context_account_delete_schema_state'] ?? 'missing'),
            'feature' => 'navigation_account_delete',
        ];
    }

    /**
     * Accept known schema states and fail if a fixture accidentally requests unknown state.
     *
     * @param array<string,mixed> $status Structured schema state.
     * @param string $operation Bounded operation identifier.
     * @param string $message Safe refusal message.
     * @return void Does not return a value.
     */
    function presentation_schema_assert_known(array $status, string $operation, string $message): void
    {
        if (($status['state'] ?? '') === 'unknown') {
            throw new \RuntimeException($operation . ': ' . $message);
        }
    }

    /**
     * Return whether a presentation schema state may be used by its consumer.
     *
     * @param array<string,mixed> $status Structured capability inspection result.
     * @param string $operation Bounded presentation operation identifier.
     * @return bool True only when the fixture confirms availability.
     */
    function presentation_schema_render_available(array $status, string $operation): bool
    {
        return schema_inspection_is_available($status);
    }

    /**
     * Return whether a schema fixture reports confirmed availability.
     *
     * @param array<string,mixed> $status Structured schema state.
     * @return bool True only for the available state.
     */
    function schema_inspection_is_available(array $status): bool
    {
        return ($status['state'] ?? '') === 'available';
    }
}

namespace Gallery\Models {
    /**
     * Return the account row held by this isolated persistence fixture.
     *
     * @param int $userId Administrator whose Navigraph row is requested.
     * @return array<string,mixed>|null Fixture row, or null when no row exists.
     */
    function navigation_data_model_account(int $userId): ?array
    {
        $GLOBALS['session_context_account_reads'][] = $userId;
        $row = $GLOBALS['session_context_account_row'] ?? null;
        return is_array($row) ? $row : null;
    }

    /**
     * Record one encrypted account update without opening a database connection.
     *
     * @param int $userId Administrator whose Navigraph row is updated.
     * @param array<string,mixed> $values Encrypted token and provider metadata fields.
     * @param string $now Timestamp supplied by the application service.
     * @return void Does not return a value.
     */
    function navigation_data_model_account_upsert(int $userId, array $values, string $now): void
    {
        $GLOBALS['session_context_account_upserts'][] = ['user_id' => $userId, 'values' => $values, 'now' => $now];
    }

    /**
     * Record deletion of one persisted Navigraph account row.
     *
     * @param int $userId Administrator whose Navigraph row is deleted.
     * @return void Does not return a value.
     */
    function navigation_data_model_account_delete(int $userId): void
    {
        $GLOBALS['session_context_account_deletes'][] = $userId;
    }
}

namespace {
    use function Gallery\Core\session_context_active;
    use function Gallery\Core\session_context_get;
    use function Gallery\Core\session_context_remove;
    use function Gallery\Core\session_context_set;
    use function Gallery\Services\gallery_access_session_key;
    use function Gallery\Services\grant_gallery_public_access;
    use function Gallery\Services\grant_nsfw_guard_access;
    use function Gallery\Services\navigation_data_navigraph_authorization_url;
    use function Gallery\Services\navigation_data_navigraph_disconnect;
    use function Gallery\Services\navigation_data_navigraph_exchange_code;
    use function Gallery\Services\navigation_data_navigraph_session;
    use function Gallery\Services\navigation_data_encrypt_secret;
    use function Gallery\Services\nsfw_guard_session_is_valid;
    use function Gallery\Services\nsfw_guard_session_key;
    use function Gallery\Services\gallery_public_access_session_is_valid;
    use function Gallery\Services\translation_active_language;
    use function Gallery\Services\translation_bootstrap_request;
    use function Gallery\Services\translation_clear_missing_diagnostics;
    use function Gallery\Services\translation_missing_diagnostic_rows_updated;
    use function Gallery\Services\translation_missing_diagnostics;
    use function Gallery\Services\translation_record_missing_key;
    use function Gallery\Services\translation_set_active_language;
    use function Gallery\Services\time as session_context_fixture_time;

    $root = dirname(__DIR__);
    $sessionContextPath = $root . '/app/session_context.php';
    $sessionStatusBeforeInclude = session_status();
    ob_start();
    require_once $sessionContextPath;
    $adapterIncludeOutput = (string) ob_get_clean();

    require_once $root . '/app/services/translations.php';
    require_once $root . '/app/services/navigation_data.php';
    require_once $root . '/app/services/gallery_access.php';

    /**
     * Stop the fixture with a precise diagnostic when a semantic expectation differs.
     *
     * @param array<array-key,mixed>|bool|float|int|object|string|null $expected Expected fixture value, including arrays, scalar values, objects, or null.
     * @param array<array-key,mixed>|bool|float|int|object|string|null $actual Actual fixture value, including arrays, scalar values, objects, or null.
     * @param string $label Description of the checked behavior.
     * @return void Does not return a value.
     */
    function session_context_assert_same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    /**
     * Stop the fixture when a boolean behavioral expectation is false.
     *
     * @param bool $condition Condition that must be true.
     * @param string $label Description of the checked behavior.
     * @return void Does not return a value.
     */
    function session_context_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    /**
     * Capture a required refusal from a callback and verify its safe message fragment.
     *
     * @param callable():mixed $operation Operation expected to throw.
     * @param string $messageFragment Required stable message fragment.
     * @param string $label Description of the expected refusal.
     * @return void Does not return a value.
     */
    function session_context_assert_throws(callable $operation, string $messageFragment, string $label): void
    {
        try {
            $operation();
        } catch (\RuntimeException $exception) {
            session_context_assert(str_contains($exception->getMessage(), $messageFragment), $label . ' should report its stable refusal.');
            return;
        }
        throw new \RuntimeException($label . ' did not refuse the operation.');
    }

    $GLOBALS['session_context_test_clock'] = 1_797_000_000;
    $GLOBALS['session_context_test_config'] = [
        'language' => ['default' => 'en', 'available' => ['en', 'cs', 'de', 'sv']],
        'navigation_data' => [
            'navigraph' => [
                'enabled' => true,
                'client_id' => 'fixture-client',
                'client_secret' => '',
                'scope' => 'openid profile offline_access',
                'redirect_uri' => 'https://gallery.invalid/admin_navigraph_callback',
                'authorization_endpoint' => 'https://identity.invalid/connect/authorize',
                'token_endpoint' => 'https://identity.invalid/connect/token',
            ],
        ],
    ];
    $GLOBALS['session_context_test_settings'] = ['public_language' => 'en'];
    $GLOBALS['session_context_test_user'] = ['id' => 17, 'role' => 'admin'];
    $GLOBALS['session_context_account_schema_state'] = 'missing';
    $GLOBALS['session_context_account_delete_schema_state'] = 'missing';
    $GLOBALS['session_context_account_reads'] = [];
    $GLOBALS['session_context_account_upserts'] = [];
    $GLOBALS['session_context_account_deletes'] = [];

    session_context_assert_same(PHP_SESSION_NONE, $sessionStatusBeforeInclude, 'Fixture starts without an active PHP session');
    session_context_assert_same(PHP_SESSION_NONE, session_status(), 'Including the adapter must not start a PHP session');
    session_context_assert_same('', $adapterIncludeOutput, 'Including the adapter must not emit output');
    session_context_assert_same(false, session_context_active(), 'Inactive session state is reported accurately');

    $_SESSION = ['sibling' => ['retained' => true]];
    session_context_assert_same(null, session_context_get('missing'), 'Missing values default to null');
    session_context_set('explicit_null', null);
    session_context_assert_same(null, session_context_get('explicit_null'), 'Explicit null retains the compatible null read result');
    session_context_set('false_value', false);
    session_context_set('zero_value', 0);
    session_context_set('empty_string', '');
    session_context_set('empty_array', []);
    session_context_set('nested', ['list' => [0, false, null], 'map' => ['language' => 'sv']]);
    session_context_assert_same(false, session_context_get('false_value'), 'False value is not replaced by the default');
    session_context_assert_same(0, session_context_get('zero_value'), 'Zero value is not replaced by the default');
    session_context_assert_same('', session_context_get('empty_string'), 'Empty string is not replaced by the default');
    session_context_assert_same([], session_context_get('empty_array'), 'Empty array shape is retained');
    session_context_assert_same(['list' => [0, false, null], 'map' => ['language' => 'sv']], session_context_get('nested'), 'Nested array shape and falsey members are retained');
    session_context_set('zero_value', 7);
    session_context_remove('explicit_null', 'empty_string');
    session_context_assert_same(false, array_key_exists('explicit_null', $_SESSION), 'Removing a key removes only its slot');
    session_context_assert_same(false, array_key_exists('empty_string', $_SESSION), 'Removing multiple keys removes each requested slot');
    session_context_assert_same(7, session_context_get('zero_value'), 'Overwriting retains the new value');
    session_context_assert_same(['retained' => true], session_context_get('sibling'), 'Unrelated namespace data survives writes and removal');

    $originalSessionName = session_name();
    $originalSessionUseCookies = (string) ini_get('session.use_cookies');
    $originalSessionCacheLimiter = (string) ini_get('session.cache_limiter');
    $originalSessionSavePath = (string) session_save_path();
    try {
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        session_save_path(sys_get_temp_dir());
        session_name('PGSessionContextTest');
        session_id('context-' . bin2hex(random_bytes(8)));
        session_context_assert_same(true, session_start(), 'The isolated test can start a local PHP session');
        session_context_assert_same(true, session_context_active(), 'Active session state is reported accurately');
        session_context_set('active_nested', ['ready' => true]);
        session_context_assert_same(['ready' => true], session_context_get('active_nested'), 'Active-session reads return the stored value');
        session_context_remove('active_nested');
        session_context_assert_same(null, session_context_get('active_nested'), 'Active-session removal clears the selected key');
    } finally {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        session_id('');
        session_name($originalSessionName);
        ini_set('session.use_cookies', $originalSessionUseCookies);
        ini_set('session.cache_limiter', $originalSessionCacheLimiter);
        if ($originalSessionSavePath !== '') {
            session_save_path($originalSessionSavePath);
        }
    }
    session_context_assert_same(false, session_context_active(), 'Session abort returns the fixture to inactive state');

    $_SESSION = ['sentinel' => 'kept', 'cms_admin_language' => ''];
    translation_bootstrap_request('admin_dashboard', ['query' => [], 'admin_cookie' => 'cs']);
    session_context_assert_same('admin', session_context_get('cms_translation_context'), 'Admin route owns the active translation context');
    session_context_assert_same('cs', session_context_get('cms_admin_language'), 'Admin cookie selection is persisted through the session context');
    session_context_assert_same('cs', translation_active_language(), 'Admin language lookup uses the private admin preference');
    session_context_assert_same(true, translation_set_active_language('sv'), 'A valid active-language update succeeds');
    session_context_assert_same('sv', session_context_get('cms_admin_language'), 'Admin language update writes the established session key');
    session_context_assert_same('sv', session_context_get('cms_language'), 'Legacy active-language mirror remains synchronized');

    translation_bootstrap_request('gallery', ['query' => ['lang' => 'de'], 'public_cookie' => '']);
    session_context_assert_same('public', session_context_get('cms_translation_context'), 'Public route replaces the translation context');
    session_context_assert_same('de', session_context_get('cms_public_language_override'), 'Public route persists its language override');
    session_context_assert_same('de', translation_active_language(), 'Public language lookup uses the visitor override');
    session_context_assert_same('sv', session_context_get('cms_admin_language'), 'Switching to public context preserves the admin preference');
    session_context_assert_same('kept', session_context_get('sentinel'), 'Language changes preserve unrelated session state');
    $resetIntents = translation_bootstrap_request('gallery', ['query' => ['lang' => 'default'], 'public_cookie' => 'de']);
    session_context_assert_same(null, session_context_get('cms_public_language_override'), 'Default selection clears only the visitor override');
    session_context_assert_same('public', session_context_get('cms_translation_context'), 'Public context remains explicit after selection reset');
    session_context_assert_same('sv', session_context_get('cms_admin_language'), 'Resetting visitor language preserves the admin preference');
    session_context_assert_same(true, (int) ($resetIntents[0]['expires'] ?? PHP_INT_MAX) < session_context_fixture_time(), 'Reset still returns an expired public cookie instruction');

    $diagnosticKey = 'cs|missing.example|English fallback';
    $priorRows = [
        $diagnosticKey => [
            'key' => 'missing.example',
            'active_language' => 'cs',
            'fallback_used' => 'English fallback',
            'last_seen' => 'old-time',
        ],
        'unrelated' => ['key' => 'unrelated', 'active_language' => 'en', 'fallback_used' => '', 'last_seen' => 'keep-time'],
        'legacy_malformed' => 'retained-for-reader-filtering',
    ];
    $updatedRows = translation_missing_diagnostic_rows_updated(
        $priorRows,
        'missing.example',
        'cs',
        'English fallback',
        'new-time'
    );
    session_context_assert_same([
        'key' => 'missing.example',
        'active_language' => 'cs',
        'fallback_used' => 'English fallback',
        'last_seen' => 'new-time',
    ], $updatedRows[$diagnosticKey] ?? null, 'Repeated missing translation updates the existing diagnostic row and timestamp');
    session_context_assert_same($priorRows['unrelated'], $updatedRows['unrelated'] ?? null, 'Diagnostic update preserves unrelated rows');
    session_context_assert_same($priorRows['legacy_malformed'], $updatedRows['legacy_malformed'] ?? null, 'Diagnostic update preserves legacy rows for the reader policy');
    session_context_set('cms_translation_missing', $updatedRows);
    session_context_assert_same(2, count(translation_missing_diagnostics()), 'Diagnostic reader drops malformed rows and returns valid rows');
    $diagnosticsBeforeCliWrite = session_context_get('cms_translation_missing');
    translation_record_missing_key('must.not.write', 'cs', 'English fallback');
    session_context_assert_same($diagnosticsBeforeCliWrite, session_context_get('cms_translation_missing'), 'CLI policy keeps the public diagnostic writer disabled');
    translation_clear_missing_diagnostics();
    session_context_assert_same(null, session_context_get('cms_translation_missing'), 'Diagnostic clear removes its owned session value');
    session_context_assert_same('kept', session_context_get('sentinel'), 'Diagnostic clear preserves unrelated session values');

    $authorizationUrl = navigation_data_navigraph_authorization_url();
    parse_str((string) parse_url($authorizationUrl, PHP_URL_QUERY), $authorizationQuery);
    $generatedOAuth = session_context_get('navigation_data_navigraph_oauth');
    session_context_assert_same((string) ($generatedOAuth['state'] ?? ''), (string) ($authorizationQuery['state'] ?? ''), 'Authorization URL state matches the stored CSRF state');
    session_context_assert_same(86, strlen((string) ($generatedOAuth['verifier'] ?? '')), 'Authorization stores the expected URL-safe PKCE verifier');
    session_context_assert_same('S256', $authorizationQuery['code_challenge_method'] ?? null, 'Authorization URL uses the S256 challenge method');

    $httpCalls = [];
    $postForm = static function (string $url, array $fields, int $timeoutSeconds) use (&$httpCalls): string {
        $httpCalls[] = ['url' => $url, 'fields' => $fields, 'timeout' => $timeoutSeconds];
        return (string) json_encode([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'id_token' => '',
            'expires_in' => 3600,
            'scope' => 'openid profile offline_access',
        ], JSON_THROW_ON_ERROR);
    };

    session_context_set('navigation_data_navigraph_oauth', [
        'state' => 'mismatch-state',
        'verifier' => 'mismatch-verifier',
        'created_at' => session_context_fixture_time(),
    ]);
    session_context_assert_throws(
        static function () use ($postForm): void {
            navigation_data_navigraph_exchange_code('auth-code', 'wrong-state', $postForm);
        },
        'did not match',
        'OAuth state mismatch'
    );
    session_context_assert_same([], $httpCalls, 'OAuth mismatch is rejected before the injected HTTP transport');

    session_context_set('navigation_data_navigraph_oauth', [
        'state' => 'expired-state',
        'verifier' => 'expired-verifier',
        'created_at' => session_context_fixture_time() - 901,
    ]);
    session_context_assert_throws(
        static function () use ($postForm): void {
            navigation_data_navigraph_exchange_code('auth-code', 'expired-state', $postForm);
        },
        'expired',
        'Expired OAuth state'
    );
    session_context_assert_same([], $httpCalls, 'Expired OAuth state is rejected before the injected HTTP transport');
    session_context_assert_same('expired-state', session_context_get('navigation_data_navigraph_oauth')['state'] ?? null, 'Expired-state refusal retains the pending state for existing retry behavior');

    $GLOBALS['session_context_account_schema_state'] = 'available';
    $GLOBALS['session_context_account_delete_schema_state'] = 'available';
    $GLOBALS['session_context_test_user'] = ['id' => 17, 'role' => 'admin'];
    $GLOBALS['session_context_test_config']['navigation_data']['navigraph']['token_encryption_key'] = 'session-context-fixture-key';
    $databaseRow = [
        'access_token_cipher' => navigation_data_encrypt_secret('database-access-token'),
        'refresh_token_cipher' => navigation_data_encrypt_secret('database-refresh-token'),
        'id_token_cipher' => navigation_data_encrypt_secret('database-id-token'),
        'token_expires_at' => session_context_fixture_time() + 3600,
        'scope_text' => 'openid profile',
        'claims_json' => '{"sub":"database-admin"}',
        'subscription_json' => '{"tier":"database"}',
        'package_cycle' => '2608',
        'package_status' => 'previous',
        'package_format' => 'xplane12',
        'package_checked_at' => '2026-10-04 12:00:00',
        'updated_at' => '2026-10-04 12:00:00',
    ];
    $GLOBALS['session_context_account_row'] = $databaseRow;
    session_context_remove('navigation_data_navigraph');
    $hydratedNavigation = navigation_data_navigraph_session();
    session_context_assert_same('database-access-token', $hydratedNavigation['access_token'] ?? null, 'Available account storage hydrates the access token into the session cache');
    session_context_assert_same('2608', $hydratedNavigation['package_cycle'] ?? null, 'Available account storage hydrates package cache metadata');
    session_context_assert_same([17], $GLOBALS['session_context_account_reads'], 'Hydration queries only the current administrator account');
    navigation_data_navigraph_session();
    session_context_assert_same([17], $GLOBALS['session_context_account_reads'], 'A populated session cache avoids another account read');

    session_context_set('navigation_data_navigraph', [
        'access_token' => 'old-access-token',
        'refresh_token' => 'old-refresh-token',
        'id_token' => 'old-id-token',
        'expires_at' => session_context_fixture_time() - 1,
        'scope' => 'old-scope',
        'subscription' => ['tier' => 'fixture'],
        'claims' => ['sub' => 'fixture-admin'],
        'package_cycle' => '2609',
        'package_status' => 'current',
        'package_format' => 'xplane11',
        'package_checked_at' => '2026-10-05 11:00:00',
    ]);
    session_context_set('navigation_data_navigraph_oauth', [
        'state' => 'valid-at-900',
        'verifier' => 'valid-verifier',
        'created_at' => session_context_fixture_time() - 900,
    ]);
    navigation_data_navigraph_exchange_code('auth-code', 'valid-at-900', $postForm);
    session_context_assert_same(1, count($httpCalls), 'Valid OAuth state reaches the injected transport exactly once');
    session_context_assert_same('https://identity.invalid/connect/token', $httpCalls[0]['url'] ?? null, 'OAuth transport receives the configured token endpoint');
    session_context_assert_same('authorization_code', $httpCalls[0]['fields']['grant_type'] ?? null, 'OAuth transport receives the authorization-code grant');
    session_context_assert_same('auth-code', $httpCalls[0]['fields']['code'] ?? null, 'OAuth transport receives the callback code');
    session_context_assert_same('valid-verifier', $httpCalls[0]['fields']['code_verifier'] ?? null, 'OAuth transport receives the stored PKCE verifier');
    session_context_assert_same(30, $httpCalls[0]['timeout'] ?? null, 'OAuth transport receives the established timeout');
    session_context_assert_same(null, session_context_get('navigation_data_navigraph_oauth'), 'Successful exchange consumes only its OAuth state');
    $storedNavigation = navigation_data_navigraph_session();
    session_context_assert_same('new-access-token', $storedNavigation['access_token'] ?? null, 'Successful exchange stores the new access token');
    session_context_assert_same('new-refresh-token', $storedNavigation['refresh_token'] ?? null, 'Successful exchange stores the new refresh token');
    session_context_assert_same('2609', $storedNavigation['package_cycle'] ?? null, 'Token replacement preserves the cached package cycle');
    session_context_assert_same('current', $storedNavigation['package_status'] ?? null, 'Token replacement preserves the cached package status');
    session_context_assert_same('xplane11', $storedNavigation['package_format'] ?? null, 'Token replacement preserves the cached package format');
    session_context_assert_same('2026-10-05 11:00:00', $storedNavigation['package_checked_at'] ?? null, 'Token replacement preserves the package check time');
    session_context_assert_same(['tier' => 'fixture'], $storedNavigation['subscription'] ?? null, 'Token replacement preserves existing subscription claims when new token has none');
    session_context_assert_same(1, count($GLOBALS['session_context_account_upserts']), 'Available schema persists one token update through the account model');
    $persistedAccount = $GLOBALS['session_context_account_upserts'][0] ?? [];
    session_context_assert_same(17, $persistedAccount['user_id'] ?? null, 'Persistent token update is scoped to the current administrator');
    session_context_assert_same('2026-10-05 12:00:00', $persistedAccount['now'] ?? null, 'Persistent token update uses the application timestamp');
    session_context_assert_same(false, str_contains((string) ($persistedAccount['values']['access_token_cipher'] ?? ''), 'new-access-token'), 'Persistent access token value is encrypted before model storage');
    session_context_assert_same('new-access-token', \Gallery\Services\navigation_data_decrypt_secret((string) ($persistedAccount['values']['access_token_cipher'] ?? '')), 'Persisted access token decrypts to the successful exchange value');
    session_context_assert_same('new-refresh-token', \Gallery\Services\navigation_data_decrypt_secret((string) ($persistedAccount['values']['refresh_token_cipher'] ?? '')), 'Persisted refresh token decrypts to the successful exchange value');
    session_context_assert_same('2609', $persistedAccount['values']['package_cycle'] ?? null, 'Persistent token update retains the current package cycle');
    session_context_assert_same('current', $persistedAccount['values']['package_status'] ?? null, 'Persistent token update retains the current package status');

    session_context_assert_throws(
        static function () use ($postForm): void {
            navigation_data_navigraph_exchange_code('auth-code', 'valid-at-900', $postForm);
        },
        'did not match',
        'OAuth state replay'
    );
    session_context_assert_same(1, count($httpCalls), 'Consumed OAuth state rejects replay before a second HTTP call');

    session_context_set('unrelated_session_value', ['kept' => true]);
    navigation_data_navigraph_disconnect();
    session_context_assert_same(null, session_context_get('navigation_data_navigraph'), 'Disconnect clears the Navigraph token and package namespace');
    session_context_assert_same(null, session_context_get('navigation_data_navigraph_oauth'), 'Disconnect clears any pending Navigraph OAuth namespace');
    session_context_assert_same(['kept' => true], session_context_get('unrelated_session_value'), 'Disconnect leaves unrelated session state intact');
    session_context_assert_same([17], $GLOBALS['session_context_account_deletes'], 'Persistent disconnect deletes only the current administrator account row');

    $protectedSession = [
        'navigation_data_navigraph' => ['access_token' => 'preserve-on-unknown', 'package_cycle' => '2610'],
        'navigation_data_navigraph_oauth' => ['state' => 'preserve-state'],
        'unrelated_session_value' => ['kept' => true],
    ];
    $_SESSION = $protectedSession;
    $GLOBALS['session_context_account_schema_state'] = 'unknown';
    session_context_assert_throws(
        static function (): void {
            \Gallery\Services\navigation_data_navigraph_store_tokens(['access_token' => 'must-not-replace']);
        },
        'could not be verified',
        'Unknown account schema write refusal'
    );
    session_context_assert_same($protectedSession, $_SESSION, 'Unknown account schema refusal preserves all existing session values');
    session_context_assert_same(1, count($GLOBALS['session_context_account_upserts']), 'Unknown account schema refusal does not call the model writer');
    $GLOBALS['session_context_account_delete_schema_state'] = 'unknown';
    session_context_assert_throws(
        static function (): void {
            navigation_data_navigraph_disconnect();
        },
        'could not verify',
        'Unknown account-delete schema refusal'
    );
    session_context_assert_same($protectedSession, $_SESSION, 'Unknown account-delete refusal preserves local credentials and OAuth state');
    session_context_assert_same([17], $GLOBALS['session_context_account_deletes'], 'Unknown account-delete refusal must not call the model deleter');
    $GLOBALS['session_context_account_schema_state'] = 'missing';
    $GLOBALS['session_context_account_delete_schema_state'] = 'missing';

    $_SESSION = ['unrelated' => 'preserved'];
    $galleryId = 42;
    $galleryKey = gallery_access_session_key($galleryId);
    $clock = session_context_fixture_time();
    session_context_set($galleryKey, $clock - 600);
    session_context_assert_same(true, gallery_public_access_session_is_valid($galleryId), 'Gallery unlock remains valid at exactly 600 seconds');
    session_context_assert_same($clock - 600, session_context_get($galleryKey), 'Exactly-600-second unlock remains stored');
    session_context_set($galleryKey, $clock - 601);
    session_context_assert_same(false, gallery_public_access_session_is_valid($galleryId), 'Gallery unlock expires after 601 seconds');
    session_context_assert_same(null, session_context_get($galleryKey), 'Expired gallery unlock is removed from its owned session slot');
    session_context_assert_same('preserved', session_context_get('unrelated'), 'Gallery expiry preserves unrelated session state');
    session_context_set($galleryKey, $clock + 60);
    session_context_assert_same(true, gallery_public_access_session_is_valid($galleryId), 'A future timestamp preserves the historical gallery-unlock policy');
    grant_gallery_public_access($galleryId);
    session_context_assert_same($clock, session_context_get($galleryKey), 'Gallery grant stores the current timestamp');

    $nsfwKey = nsfw_guard_session_key();
    session_context_set($nsfwKey, 1);
    session_context_assert_same(true, nsfw_guard_session_is_valid(), 'Any positive historical NSFW acknowledgment remains valid without a TTL');
    session_context_set($nsfwKey, 0);
    session_context_assert_same(false, nsfw_guard_session_is_valid(), 'Zero NSFW acknowledgment is invalid');
    grant_nsfw_guard_access();
    session_context_assert_same($clock, session_context_get($nsfwKey), 'NSFW confirmation stores the current timestamp');
    session_context_assert_same(true, nsfw_guard_session_is_valid(), 'Fresh NSFW confirmation remains valid');

    fwrite(STDOUT, "PASS session context and consuming session semantics\n");
}
