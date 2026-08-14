<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/auth_schema_policy_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies Phase 9 three-state schema policy for administrator authentication storage.
 *
 * Responsibilities:
 *   - Preserve username/session compatibility for confirmed pre-migration states
 *   - Distinguish persistent-login, email, password-reset, and external-identity capabilities
 *   - Refuse token/link operations when metadata inspection is unknown
 *   - Verify disabled persistent login does not query its optional table
 *   - Verify request-local schema caching avoids repeated metadata queries
 *   - Verify operational logs contain bounded capability context and no simulated secrets
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Core helpers are isolated fixture shims; no database connection is permitted.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** @return array<string,mixed> Deterministic authentication fixture configuration. */
    function cms_config(): array
    {
        return $GLOBALS['auth_schema_fixture_config'] ?? [];
    }

    /** @return never Database access is forbidden in this schema-policy unit test. */
    function db(): never
    {
        $GLOBALS['auth_schema_fixture_db_touches'] = ($GLOBALS['auth_schema_fixture_db_touches'] ?? 0) + 1;
        throw new \RuntimeException('Fixture database access was not expected.');
    }

    /** @return string Deterministic SQL timestamp. */
    function now_sql(): string
    {
        return '2026-08-12 18:00:00';
    }

    /** @return bool Fixture HTTPS state. */
    function request_is_https(): bool
    {
        return true;
    }

    /** @param string $url Relative URL. @return string Absolute fixture URL. */
    function absolute_public_url(string $url): string
    {
        return 'https://gallery.example.test' . $url;
    }

    /** @param string $page Route identifier. @param array $params Route parameters. @return string Fixture route. */
    function url_for(string $page, array $params = []): string
    {
        return '/index.php?page=' . rawurlencode($page);
    }

    /** @return ?array No authenticated user is needed by these tests. */
    function current_user(): ?array
    {
        return null;
    }

    /** @param string $target Candidate return target. @param string $fallback Safe fallback. @return string Safe fixture target. */
    function sanitize_login_return_target(string $target, string $fallback): string
    {
        return $target !== '' ? $target : $fallback;
    }
}

namespace Gallery\Controllers {
    /** @param string $email Account email. @return string Normalized fixture email. */
    function cms_normalize_account_email(string $email): string
    {
        return strtolower(trim($email));
    }
}

namespace Gallery\Services {
    /**
     * Capture safe schema-policy logs without persistent storage.
     *
     * @param string $level Event level.
     * @param string $eventKey Event key.
     * @param string $message Safe message.
     * @param array $context Bounded context.
     */
    function admin_log_event(string $level, string $eventKey, string $message, array $context = []): void
    {
        $GLOBALS['auth_schema_fixture_logs'][] = compact('level', 'eventKey', 'message', 'context');
    }
}

namespace {
    require_once __DIR__ . '/../app/services/schema_inspection.php';
    require_once __DIR__ . '/../app/services/auth_persistence.php';
    require_once __DIR__ . '/../app/services/google_auth.php';

    use Gallery\Services\AuthenticationSchemaUnavailableException;
    use function Gallery\Services\auth_password_reset_schema_status;
    use function Gallery\Services\auth_persistent_login_operation_available;
    use function Gallery\Services\auth_persistent_login_ready;
    use function Gallery\Services\auth_persistent_login_schema_status;
    use function Gallery\Services\auth_user_email_schema_status;
    use function Gallery\Services\google_auth_configuration_ready;
    use function Gallery\Services\google_auth_schema_operation_available;
    use function Gallery\Services\google_auth_schema_status;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

    /**
     * Assert strict equality for one authentication schema-policy expectation.
     *
     * @param mixed $expected Expected value.
     * @param mixed $actual Actual value.
     * @param string $label Assertion label.
     */
    function auth_schema_policy_assert_same(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    /**
     * Assert one callback throws the expected exception class.
     *
     * @param callable $callback Callback expected to throw.
     * @param string $exceptionClass Expected exception class.
     * @param string $label Assertion label.
     */
    function auth_schema_policy_assert_throws(callable $callback, string $exceptionClass, string $label): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            if ($exception instanceof $exceptionClass) {
                return;
            }
            throw new \RuntimeException($label . ' threw ' . $exception::class . ' instead of ' . $exceptionClass . '.');
        }
        throw new \RuntimeException($label . ' did not throw.');
    }

    $GLOBALS['auth_schema_fixture_config'] = [
        'admin_session_name' => 'gallery_admin_fixture',
        'auth' => [
            'persistent_login_enabled' => true,
            'persistent_login_default_checked' => true,
            'remember_lifetime_days' => 30,
            'session_lifetime_days' => 14,
        ],
        'google_login' => [
            'enabled' => true,
            'client_id' => 'fixture-client-id',
            'client_secret' => 'fixture-client-secret',
            'redirect_uri' => 'https://gallery.example.test/index.php?page=admin_google_callback',
        ],
    ];
    $GLOBALS['auth_schema_fixture_db_touches'] = 0;
    $GLOBALS['auth_schema_fixture_logs'] = [];

    // Verified schema keeps all DB-backed authentication capabilities available.
    $queryCounts = [];
    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object) use (&$queryCounts): bool {
        $key = $objectType . ':' . $table . ':' . $object;
        $queryCounts[$key] = ($queryCounts[$key] ?? 0) + 1;
        return true;
    });
    auth_schema_policy_assert_same('available', auth_persistent_login_schema_status()['state'], 'persistent login available');
    auth_schema_policy_assert_same('available', auth_user_email_schema_status()['state'], 'user email available');
    auth_schema_policy_assert_same('available', auth_password_reset_schema_status()['state'], 'password reset available');
    auth_schema_policy_assert_same('available', google_auth_schema_status()['state'], 'external identity available');
    auth_schema_policy_assert_same(true, auth_persistent_login_ready(), 'persistent login ready');
    auth_schema_policy_assert_same(true, google_auth_configuration_ready(), 'Google config ready');
    auth_schema_policy_assert_same(1, $queryCounts['column:users:email'] ?? 0, 'shared users.email metadata query count');
    auth_schema_policy_assert_same(1, $queryCounts['table:admin_remember_tokens:admin_remember_tokens'] ?? 0, 'remember-token metadata query count');
    auth_schema_policy_assert_same(1, $queryCounts['table:password_reset_tokens:password_reset_tokens'] ?? 0, 'password-reset metadata query count');
    auth_schema_policy_assert_same(1, $queryCounts['table:user_google_accounts:user_google_accounts'] ?? 0, 'Google-link metadata query count');

    // Confirmed missing optional authentication storage preserves only explicit compatibility behavior.
    schema_inspection_set_query_executor_for_tests(static fn (): bool => false);
    auth_schema_policy_assert_same('missing', auth_persistent_login_schema_status()['state'], 'persistent login missing');
    auth_schema_policy_assert_same('missing', auth_user_email_schema_status()['state'], 'user email missing');
    auth_schema_policy_assert_same('missing', auth_password_reset_schema_status()['state'], 'password reset missing');
    auth_schema_policy_assert_same('missing', google_auth_schema_status()['state'], 'external identity missing');
    auth_schema_policy_assert_same(false, auth_persistent_login_operation_available('issue'), 'missing persistent login degrades to session-only');
    auth_schema_policy_assert_same(false, google_auth_schema_operation_available('lookup', true), 'missing Google link read compatibility');
    auth_schema_policy_assert_throws(static fn () => google_auth_schema_operation_available('link', false), \RuntimeException::class, 'missing Google link mutation migration requirement');

    // Password reset is incomplete when either its table or users.email is confirmed missing.
    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object): bool {
        if ($objectType === 'column' && $table === 'users' && $object === 'email') {
            return false;
        }
        return true;
    });
    auth_schema_policy_assert_same('missing', auth_password_reset_schema_status()['state'], 'partial password reset state');

    // Unknown persistent-login metadata is not the same as feature absence and must refuse token issuance.
    $GLOBALS['auth_schema_fixture_logs'] = [];
    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object): bool {
        if ($objectType === 'table' && $table === 'admin_remember_tokens') {
            throw new \RuntimeException('fixture-secret cookie=remember-token SELECT * FROM admin_remember_tokens');
        }
        return true;
    });
    auth_schema_policy_assert_same('unknown', auth_persistent_login_schema_status()['state'], 'persistent login unknown');
    auth_schema_policy_assert_throws(static fn () => auth_persistent_login_operation_available('issue'), AuthenticationSchemaUnavailableException::class, 'persistent login unknown issue');
    $logJson = (string) json_encode($GLOBALS['auth_schema_fixture_logs']);
    auth_schema_policy_assert_same(true, str_contains($logJson, 'auth_persistent_login'), 'persistent login bounded log feature');
    foreach (['fixture-secret', 'remember-token', 'SELECT *'] as $forbidden) {
        auth_schema_policy_assert_same(false, str_contains($logJson, $forbidden), 'persistent login log redaction ' . $forbidden);
    }

    // Unknown users.email metadata remains unknown and cannot be reclassified as legacy username-only schema.
    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object): bool {
        if ($objectType === 'column' && $table === 'users' && $object === 'email') {
            throw new \RuntimeException('fixture-secret users.email inspection failed');
        }
        return true;
    });
    auth_schema_policy_assert_same('unknown', auth_user_email_schema_status()['state'], 'user email unknown');
    auth_schema_policy_assert_same('unknown', auth_password_reset_schema_status()['state'], 'password reset unknown through email dependency');

    // Unknown external identity metadata refuses both read and write policy helpers.
    $GLOBALS['auth_schema_fixture_logs'] = [];
    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table): bool {
        if ($objectType === 'table' && $table === 'user_google_accounts') {
            throw new \RuntimeException('fixture-secret client_secret=do-not-log');
        }
        return true;
    });
    auth_schema_policy_assert_same('unknown', google_auth_schema_status()['state'], 'Google identity unknown');
    auth_schema_policy_assert_throws(static fn () => google_auth_schema_operation_available('lookup', true), AuthenticationSchemaUnavailableException::class, 'Google unknown read');
    auth_schema_policy_assert_throws(static fn () => google_auth_schema_operation_available('link', false), AuthenticationSchemaUnavailableException::class, 'Google unknown mutation');
    $googleLogJson = (string) json_encode($GLOBALS['auth_schema_fixture_logs']);
    auth_schema_policy_assert_same(true, str_contains($googleLogJson, 'auth_external_identity'), 'Google bounded log feature');
    auth_schema_policy_assert_same(false, str_contains($googleLogJson, 'client_secret=do-not-log'), 'Google log secret redaction');

    // Configuration-disabled persistent login short-circuits before schema inspection.
    $GLOBALS['auth_schema_fixture_config']['auth']['persistent_login_enabled'] = false;
    $disabledQueries = 0;
    schema_inspection_set_query_executor_for_tests(static function () use (&$disabledQueries): bool {
        $disabledQueries++;
        throw new \RuntimeException('disabled persistent login should not inspect schema');
    });
    auth_schema_policy_assert_same(false, auth_persistent_login_operation_available('issue'), 'disabled persistent login operation');
    auth_schema_policy_assert_same(0, $disabledQueries, 'disabled persistent login query count');

    auth_schema_policy_assert_same(0, $GLOBALS['auth_schema_fixture_db_touches'], 'no database operations during schema policy tests');
    schema_inspection_set_query_executor_for_tests(null);
    echo "Authentication schema policy checks passed.\n";
}
