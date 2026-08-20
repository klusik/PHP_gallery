<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_authentication_phase06_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the dormant Phase 0.6 viewer authentication/request-security foundations.
 *
 * Responsibilities:
 *   - Exercise pure password, CSRF, schema, transport, origin, and pre-auth state policies
 *   - Protect fail-closed schema and trusted-proxy protocol boundaries
 *   - Protect transaction/locking/resource-limit structure when live MySQL is unavailable
 *   - Prove the Phase 0.6 service layer remains cookie-transport free after later HTTP wiring
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return mutable fixture configuration for isolated Phase 0.6 tests.
     *
     * @return array<string,mixed> Fixture configuration.
     */
    function cms_config(): array
    {
        return $GLOBALS['viewer_phase06_config'];
    }

    /**
     * Return a deterministic SQL timestamp for pure helpers.
     *
     * @return string Fixed SQL timestamp.
     */
    function now_sql(): string
    {
        return '2026-08-18 14:00:00';
    }

    /**
     * Refuse accidental database access from the driverless Phase 0.6 model test.
     *
     * @return \PDO Never returns.
     */
    function db(): \PDO
    {
        throw new \RuntimeException('Database access is not expected in viewer_authentication_phase06_test.php.');
    }
}

namespace {
    use function Gallery\Core\csrf_token;
    use function Gallery\Core\current_user;
    use function Gallery\Services\current_viewer;
    use function Gallery\Services\request_ip_matches_trusted_proxy;
    use function Gallery\Services\schema_inspection_is_available;
    use function Gallery\Services\schema_inspection_is_missing;
    use function Gallery\Services\schema_inspection_is_unknown;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
    use function Gallery\Services\viewer_auth_schema_status;
    use function Gallery\Services\viewer_auth_storage_available;
    use function Gallery\Services\viewer_csrf_namespace_key;
    use function Gallery\Services\viewer_csrf_token;
    use function Gallery\Services\viewer_csrf_verify;
    use function Gallery\Services\viewer_password_character_length;
    use function Gallery\Services\viewer_password_hash;
    use function Gallery\Services\viewer_password_input_is_acceptable;
    use function Gallery\Services\viewer_password_max_bytes;
    use function Gallery\Services\viewer_password_needs_rehash;
    use function Gallery\Services\viewer_password_reset_namespace_key;
    use function Gallery\Services\viewer_password_reset_state;
    use function Gallery\Services\viewer_password_verify;
    use function Gallery\Services\viewer_registration_activation_establish;
    use function Gallery\Services\viewer_registration_activation_matches_row;
    use function Gallery\Services\viewer_registration_activation_namespace_key;
    use function Gallery\Services\viewer_registration_activation_state;
    use function Gallery\Services\viewer_request_is_https;
    use function Gallery\Services\viewer_security_base_url;
    use function Gallery\Services\viewer_security_transport_allowed;
    use function Gallery\Services\viewer_security_url;

    $GLOBALS['viewer_phase06_config'] = [
        'visitor_vote_secret' => 'phase-06-fixture-secret',
        'base_url' => 'https://gallery.example.test/galerie',
        'viewer_accounts' => [
            'enabled' => false,
            'registration_mode' => 'invite_only',
            'require_https' => true,
        ],
        'security' => [
            'trusted_proxies' => [],
            'trusted_proxy_headers' => [],
            'trusted_proxy_protocol_headers' => [],
        ],
    ];

    require_once __DIR__ . '/../app/services/schema_inspection.php';
    require_once __DIR__ . '/../app/services/security_tokens.php';
    require_once __DIR__ . '/../app/services/client_ip.php';
    require_once __DIR__ . '/../app/services/viewer_accounts.php';
    require_once __DIR__ . '/../app/services/viewer_tokens.php';
    require_once __DIR__ . '/../app/services/viewer_rate_limits.php';
    require_once __DIR__ . '/../app/services/viewer_security_events.php';
    require_once __DIR__ . '/../app/services/viewer_registration.php';
    require_once __DIR__ . '/../app/services/viewer_mail.php';
    require_once __DIR__ . '/../app/services/viewer_authentication.php';
    require_once __DIR__ . '/../app/security.php';

    /**
     * Throw when one Phase 0.6 expectation fails.
     *
     * @param bool $condition Condition value.
     * @param string $label Assertion label.
     */
    function viewer_phase06_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    // Aggregate schema capability: available, confirmed missing, and inspection error are distinct.
    schema_inspection_set_query_executor_for_tests(
        static fn (string $objectType, string $table, string $object, ?string $definition = null): bool => true
    );
    viewer_phase06_assert(schema_inspection_is_available(viewer_auth_schema_status()), 'Complete viewer auth schema must report available.');
    viewer_phase06_assert(viewer_auth_storage_available(), 'Complete viewer auth schema must admit dormant auth storage.');

    schema_inspection_set_query_executor_for_tests(
        static fn (string $objectType, string $table, string $object, ?string $definition = null): bool => $table !== 'viewer_sessions'
    );
    viewer_phase06_assert(schema_inspection_is_missing(viewer_auth_schema_status()), 'Confirmed missing viewer auth table must fail closed as missing.');
    viewer_phase06_assert(!viewer_auth_storage_available(), 'Missing viewer auth table must refuse auth storage.');

    schema_inspection_set_query_executor_for_tests(
        static function (string $objectType, string $table, string $object, ?string $definition = null): bool {
            if ($table === 'viewer_sessions') {
                throw new \RuntimeException('synthetic inspection failure');
            }
            return true;
        }
    );
    viewer_phase06_assert(schema_inspection_is_unknown(viewer_auth_schema_status()), 'Schema inspection error must remain unknown rather than missing.');
    viewer_phase06_assert(!viewer_auth_storage_available(), 'Unknown viewer auth schema must fail closed.');
    schema_inspection_set_query_executor_for_tests(null);

    // Password-only viewer policy: 15 Unicode code points, no composition rules, bounded bytes, native hashing.
    viewer_phase06_assert(!viewer_password_input_is_acceptable('12345678901234'), 'Fourteen-character viewer password must be rejected.');
    viewer_phase06_assert(viewer_password_input_is_acceptable('123456789012345'), 'Fifteen characters must satisfy length without composition rules.');
    viewer_phase06_assert(viewer_password_input_is_acceptable('correct horse battery staple'), 'Long passphrase with spaces must be accepted.');
    $unicodePassword = str_repeat('ž', 15);
    viewer_phase06_assert(viewer_password_character_length($unicodePassword) === 15, 'Unicode password policy must count code points deterministically.');
    viewer_phase06_assert(viewer_password_input_is_acceptable($unicodePassword), 'Valid Unicode password at the minimum must be accepted.');
    viewer_phase06_assert(!viewer_password_input_is_acceptable("123456789012345\0"), 'NUL-containing viewer password must be rejected.');
    viewer_phase06_assert(viewer_password_max_bytes() >= 72, 'Viewer password byte maximum must preserve explicit bcrypt no-truncation handling.');
    $nativeHash = viewer_password_hash('correct horse battery staple');
    viewer_phase06_assert(viewer_password_verify('correct horse battery staple', $nativeHash), 'Native viewer password hash/verify must round-trip.');
    viewer_phase06_assert(!viewer_password_verify('wrong password value', $nativeHash), 'Native viewer password verification must reject a wrong password.');
    $legacyHash = password_hash('correct horse battery staple', PASSWORD_BCRYPT, ['cost' => 4]);
    viewer_phase06_assert(is_string($legacyHash) && viewer_password_needs_rehash($legacyHash), 'Lower-cost/legacy native hash must enter the needs_rehash path.');

    // Viewer CSRF authority is isolated from historical/admin CSRF authority.
    $_SESSION = [];
    $adminCsrf = csrf_token();
    $viewerCsrf = viewer_csrf_token();
    viewer_phase06_assert(viewer_csrf_namespace_key() === 'viewer_csrf_token', 'Viewer CSRF must use its own session namespace.');
    viewer_phase06_assert($adminCsrf !== $viewerCsrf, 'Viewer and admin CSRF tokens must be independent random authorities.');
    viewer_phase06_assert(viewer_csrf_verify($viewerCsrf), 'Viewer CSRF token must validate in its own domain.');
    viewer_phase06_assert(!viewer_csrf_verify($adminCsrf), 'Admin CSRF token must not validate as viewer CSRF.');
    viewer_phase06_assert(!hash_equals((string) $_SESSION['csrf_token'], $viewerCsrf), 'Viewer CSRF token must not satisfy admin token equality.');

    // Activation grant is short-lived, server-side, integrity-bound, and not an authenticated principal.
    $_SESSION = ['user_id' => 77];
    $activationRow = [
        'id' => 42,
        'verified_at' => '2026-08-18 13:59:00',
        'normalized_email' => 'viewer@example.test',
        'expires_at' => date('Y-m-d H:i:s', time() + 600),
    ];
    viewer_registration_activation_establish($activationRow);
    $activation = viewer_registration_activation_state();
    viewer_phase06_assert($activation !== null && $activation['request_id'] === 42, 'Explicit verification boundary must be able to establish activation state.');
    viewer_phase06_assert(viewer_registration_activation_namespace_key() === 'viewer_registration_activation', 'Activation state must have a dedicated pre-auth namespace.');
    viewer_phase06_assert(!isset($_SESSION['viewer_auth']), 'Registration activation state must not authenticate a viewer.');
    viewer_phase06_assert(isset($_SESSION['user_id']) && $_SESSION['user_id'] === 77, 'Viewer pre-auth session rotation/state must preserve admin session data.');
    viewer_phase06_assert(!str_contains(json_encode($_SESSION[viewer_registration_activation_namespace_key()]) ?: '', 'viewer@example.test'), 'Activation session must not retain plaintext email.');
    viewer_phase06_assert(viewer_registration_activation_matches_row($activation, $activationRow), 'Activation HMAC context must match its authoritative verified row.');
    $forgedRow = $activationRow;
    $forgedRow['normalized_email'] = 'attacker@example.test';
    viewer_phase06_assert(!viewer_registration_activation_matches_row($activation, $forgedRow), 'Modified authoritative staging data must invalidate activation context.');
    $_SESSION[viewer_registration_activation_namespace_key()]['expires_at'] = time() - 1;
    viewer_phase06_assert(viewer_registration_activation_state() === null, 'Expired activation state must auto-clear and fail closed.');

    // Reset pre-auth namespace is separate from viewer authentication and activation.
    viewer_phase06_assert(viewer_password_reset_namespace_key() === 'viewer_password_reset', 'Password reset must use a separate pre-auth namespace.');
    $_SESSION[viewer_password_reset_namespace_key()] = [
        'token_id' => 1,
        'account_id' => 2,
        'security_version' => 3,
        'token_expires_at' => date('Y-m-d H:i:s', time() + 600),
        'expires_at' => time() - 1,
        'context' => str_repeat('a', 64),
    ];
    viewer_phase06_assert(viewer_password_reset_state() === null, 'Expired reset pre-auth state must auto-clear.');

    // Strict viewer HTTPS resolver ignores attacker-controlled forwarded protocol unless proxy and header are both trusted.
    $_SERVER = ['REMOTE_ADDR' => '198.51.100.25', 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https'];
    viewer_phase06_assert(!viewer_request_is_https(), 'Forged X-Forwarded-Proto from an untrusted direct peer must be ignored.');
    $_SERVER['HTTP_X_FORWARDED_SSL'] = 'on';
    viewer_phase06_assert(!viewer_request_is_https(), 'Forged X-Forwarded-SSL from an untrusted direct peer must be ignored.');
    $_SERVER = ['REMOTE_ADDR' => '198.51.100.25', 'HTTPS' => 'on', 'SERVER_PORT' => '80'];
    viewer_phase06_assert(viewer_request_is_https(), 'Direct HTTPS must be accepted.');
    $_SERVER = ['REMOTE_ADDR' => '198.51.100.25', 'SERVER_PORT' => '443'];
    viewer_phase06_assert(viewer_request_is_https(), 'Direct server port 443 must be accepted.');

    $GLOBALS['viewer_phase06_config']['security']['trusted_proxies'] = ['203.0.113.0/24'];
    $GLOBALS['viewer_phase06_config']['security']['trusted_proxy_protocol_headers'] = [];
    $_SERVER = ['REMOTE_ADDR' => '203.0.113.9', 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https'];
    viewer_phase06_assert(!viewer_request_is_https(), 'Trusted proxy without explicitly enabled protocol header must fail closed.');
    $GLOBALS['viewer_phase06_config']['security']['trusted_proxy_protocol_headers'] = ['x-forwarded-proto'];
    viewer_phase06_assert(viewer_request_is_https(), 'Trusted proxy plus explicitly enabled HTTPS protocol header must be accepted.');
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https,http';
    viewer_phase06_assert(!viewer_request_is_https(), 'Ambiguous forwarded protocol data must fail closed.');
    $GLOBALS['viewer_phase06_config']['security']['trusted_proxies'] = ['not-a-cidr'];
    $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
    viewer_phase06_assert(!viewer_request_is_https(), 'Invalid trusted-proxy configuration must never widen trust.');
    viewer_phase06_assert(request_ip_matches_trusted_proxy('2001:db8::42', '2001:db8::/64'), 'Trusted proxy matcher must preserve IPv6 CIDR support.');
    $GLOBALS['viewer_phase06_config']['security']['trusted_proxies'] = ['2001:db8::/64'];
    $_SERVER = ['REMOTE_ADDR' => '2001:db8::42', 'SERVER_PORT' => '80', 'HTTP_X_FORWARDED_PROTO' => 'https'];
    viewer_phase06_assert(viewer_request_is_https(), 'Trusted IPv6 proxy must be able to assert explicitly enabled HTTPS.');
    viewer_phase06_assert(viewer_security_transport_allowed(), 'Default viewer HTTPS policy must accept proven trusted HTTPS.');

    // Security-link origin comes only from configured base_url, never HTTP_HOST.
    $GLOBALS['viewer_phase06_config']['security']['trusted_proxies'] = [];
    $_SERVER['HTTP_HOST'] = 'attacker.invalid';
    viewer_phase06_assert(viewer_security_base_url() === 'https://gallery.example.test/galerie', 'Configured absolute base_url must be the authoritative viewer security origin.');
    $securityUrl = viewer_security_url('/future/reset', ['token' => 'opaque']);
    viewer_phase06_assert($securityUrl === 'https://gallery.example.test/galerie/future/reset?token=opaque', 'Viewer security URL must retain configured authority.');
    viewer_phase06_assert(!str_contains((string) $securityUrl, 'attacker.invalid'), 'HTTP_HOST must not poison future viewer security links.');
    foreach ([
        'https://user:pass@example.test',
        'https://example.test/path?x=1',
        'https://example.test/path#fragment',
        'not-a-url',
        'http://example.test',
    ] as $invalidBase) {
        $GLOBALS['viewer_phase06_config']['base_url'] = $invalidBase;
        viewer_phase06_assert(viewer_security_base_url() === null, 'Malformed/insecure viewer security base URL must fail closed: ' . $invalidBase);
    }
    $GLOBALS['viewer_phase06_config']['base_url'] = '';
    viewer_phase06_assert(viewer_security_url('/future/reset') === null, 'Empty base_url must not fall back to request Host authority.');
    $GLOBALS['viewer_phase06_config']['base_url'] = 'https://gallery.example.test/galerie';

    // Disabled viewer state remains unable to become either principal.
    $GLOBALS['viewer_phase06_config']['viewer_accounts']['enabled'] = false;
    unset($_SESSION['user_id']);
    $_SESSION['viewer_auth'] = ['account_id' => 9, 'security_version' => 1, 'token' => 'x'];
    viewer_phase06_assert(current_user() === null, 'Viewer session state must never satisfy current_user().');
    viewer_phase06_assert(current_viewer() === null && !isset($_SESSION['viewer_auth']), 'Disabled viewer state must fail closed and clear local viewer authority.');

    // Static transactional/route-free contracts supplement live DB races when PDO MySQL is unavailable.
    $root = dirname(__DIR__);
    $accountService = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
    $authService = (string) file_get_contents($root . '/app/services/viewer_authentication.php');
    $tokenService = (string) file_get_contents($root . '/app/services/viewer_tokens.php');
    $registrationService = (string) file_get_contents($root . '/app/services/viewer_registration.php');
    $mailService = (string) file_get_contents($root . '/app/services/viewer_mail.php');
    $securityService = (string) file_get_contents($root . '/app/security.php');
    $maintenanceService = (string) file_get_contents($root . '/app/services/site_maintenance.php');
    $migration = (string) file_get_contents($root . '/database/migrations/202608180003_viewer_authentication_foundations.php');

    viewer_phase06_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS viewer_account_state') && str_contains($migration, 'ENGINE=InnoDB'), 'Account-cap migration must be additive/replay-safe and transactional-engine compatible.');
    viewer_phase06_assert(str_contains($accountService, 'SELECT account_count FROM viewer_account_state') && str_contains($accountService, 'FOR UPDATE'), 'Durable viewer-account cap must serialize on a locked singleton row.');
    viewer_phase06_assert(str_contains($registrationService, 'function viewer_registration_activate_verified(string $password)') && !str_contains($registrationService, 'viewer_registration_activate_verified(string $password, string'), 'Activation authority must not accept a client request id parameter.');
    viewer_phase06_assert(str_contains($registrationService, "SELECT * FROM viewer_invitations WHERE id = ? LIMIT 1 FOR UPDATE"), 'Activation must re-lock and re-check invitation authority.');
    viewer_phase06_assert(str_contains($registrationService, 'DELETE FROM viewer_registration_requests WHERE id = ?'), 'Successful activation must retire plaintext staging registration data.');
    viewer_phase06_assert(strpos($authService, 'viewer_login_rate_limits_consume(') < strpos($authService, "SELECT * FROM viewer_accounts WHERE normalized_email = ?"), 'Viewer login rate limits must precede account lookup.');
    viewer_phase06_assert(strpos($authService, 'viewer_login_rate_limits_consume(') < strpos($authService, 'viewer_password_verify('), 'Viewer login rate limits must precede expensive password verification.');
    viewer_phase06_assert(str_contains($accountService, 'ORDER BY created_at ASC, id ASC LIMIT') && str_contains($accountService, 'max_active_viewer_sessions_per_account'), 'Viewer active-session cap must revoke deterministically under account locking.');
    viewer_phase06_assert(str_contains($tokenService, 'max_active_viewer_remember_tokens_per_account') && str_contains($tokenService, 'ORDER BY created_at ASC, id ASC LIMIT'), 'Remember-token cap must be deterministic and bounded.');
    viewer_phase06_assert(str_contains($tokenService, 'SET selector = ?, verifier_hash = ?, last_used_at = ?, expires_at = ?') && str_contains($tokenService, 'viewer_session_establish($account)'), 'Remember restoration must rotate verifier authority before establishing a normal viewer session.');
    viewer_phase06_assert(stripos($tokenService, 'setcookie(') === false, 'Phase 0.6 remember orchestration must not emit a browser cookie.');
    viewer_phase06_assert(str_contains($authService, 'function viewer_password_reset_inspect(string $token)') && str_contains($authService, 'function viewer_password_reset_authorize(string $token)'), 'Password-reset inspection and explicit pre-auth authorization must remain separate.');
    viewer_phase06_assert(str_contains($authService, "SELECT * FROM viewer_password_reset_tokens WHERE id = ? LIMIT 1 FOR UPDATE") && str_contains($authService, 'security_version = ?'), 'Final reset must lock token/account state and be security-version aware.');
    viewer_phase06_assert(str_contains($authService, 'UPDATE viewer_sessions SET revoked_at = ?') && str_contains($authService, 'UPDATE viewer_remember_tokens SET revoked_at = ?'), 'Successful password reset must revoke viewer sessions and remember credentials.');
    viewer_phase06_assert(str_contains($accountService, 'UPDATE viewer_collection_share_tokens SET revoked_at = ?'), 'Account suspension/disable foundation must revoke viewer-created collection share authority.');
    viewer_phase06_assert(str_contains($securityService, "isset(\$_SESSION['viewer_auth'])") && str_contains($securityService, "isset(\$_SESSION['viewer_registration_activation'])") && str_contains($securityService, "isset(\$_SESSION['viewer_password_reset'])"), 'Viewer and pre-auth session state must force the safer cache path without viewer DB lookup.');
    viewer_phase06_assert(!str_contains($securityService, 'current_viewer()'), 'Cache classification must not query viewer persistence just to choose no-store.');
    viewer_phase06_assert(!str_contains($maintenanceService, 'viewer_accounts_enabled()') && str_contains($maintenanceService, 'viewer_security_maintenance_cleanup()'), 'Scheduled viewer cleanup must continue while feature capability is disabled.');
    viewer_phase06_assert(stripos($mailService, 'HTTP_HOST') !== false && str_contains($mailService, 'deliberately ignored'), 'Security-origin code must document that request Host authority is ignored.');
    viewer_phase06_assert(strpos($mailService, "\n    mail(") === false && strpos($mailService, 'return mail(') === false && stripos($mailService, 'stream_socket_client') === false && stripos($mailService, 'curl_') === false, 'Viewer mail transport must remain unimplemented.');

    $viewerHttpService = (string) file_get_contents($root . '/app/services/viewer_http.php');
    viewer_phase06_assert(stripos($tokenService, 'setcookie(') === false, 'Phase 0.6 token orchestration must remain browser-cookie free even after Phase 1.0.');
    viewer_phase06_assert(str_contains($viewerHttpService, 'viewer_remember_restore_and_rotate('), 'Later HTTP wiring must consume the established Phase 0.6 remember rotation service rather than reimplement it.');

    echo "Viewer Phase 0.6 authentication foundation tests passed.\n";
}
