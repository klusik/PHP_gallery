<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_security_foundations_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the pure security primitives prepared for dormant viewer accounts.
 *
 * Responsibilities:
 *   - Prove viewer configuration fails closed and remains separate from current_user()
 *   - Verify token generation, hashing, password handling, and one-time token expiry policy
 *   - Verify identifier normalization and trusted-proxy client IP resolution
 *   - Verify viewer security event context excludes secret/PII-shaped fields
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return mutable fixture configuration for isolated viewer security tests.
     *
     * @return array<string,mixed> Fixture configuration.
     */
    function cms_config(): array
    {
        return $GLOBALS['viewer_fixture_config'];
    }

    /**
     * Return a deterministic SQL timestamp for service functions not exercised against a database.
     *
     * @return string Fixed SQL timestamp.
     */
    function now_sql(): string
    {
        return '2026-08-18 12:00:00';
    }

    /**
     * Refuse accidental database access from this pure isolated test.
     *
     * @return \PDO Never returns.
     */
    function db(): \PDO
    {
        throw new \RuntimeException('Database access is not expected in viewer_security_foundations_test.php.');
    }
}

namespace {
    use function Gallery\Core\current_user;
    use function Gallery\Services\current_viewer;
    use function Gallery\Services\request_client_ip;
    use function Gallery\Services\request_ip_matches_trusted_proxy;
    use function Gallery\Services\security_authority_token_hash;
    use function Gallery\Services\security_authority_token_verify;
    use function Gallery\Services\security_opaque_token_generate;
    use function Gallery\Services\viewer_accounts_enabled;
    use function Gallery\Services\viewer_email_fingerprint;
    use function Gallery\Services\viewer_email_verification_token_consume;
    use function Gallery\Services\viewer_email_verification_token_issue;
    use function Gallery\Services\viewer_email_normalize;
    use function Gallery\Services\viewer_one_time_token_row_is_usable;
    use function Gallery\Services\viewer_password_hash;
    use function Gallery\Services\viewer_remember_token_verify;
    use function Gallery\Services\viewer_password_input_is_acceptable;
    use function Gallery\Services\viewer_password_max_bytes;
    use function Gallery\Services\viewer_password_needs_rehash;
    use function Gallery\Services\viewer_password_verify;
    use function Gallery\Services\viewer_rate_limit_normalize_subject;
    use function Gallery\Services\viewer_rate_limit_policy;
    use function Gallery\Services\viewer_rate_limit_subject_cap;
    use function Gallery\Services\viewer_registration_mode;
    use function Gallery\Services\viewer_security_event_sanitize_context;
    use function Gallery\Services\viewer_session_clear;
    use function Gallery\Services\viewer_session_namespace_key;
    use function Gallery\Services\viewer_session_state;

    $GLOBALS['viewer_fixture_config'] = [
        'visitor_vote_secret' => 'viewer-foundation-test-secret',
        'admin_session_name' => 'gallery_admin_session',
        'viewer_accounts' => [
            'enabled' => false,
            'registration_mode' => 'open',
            'rate_limit_max_subjects_per_bucket' => 250,
        ],
        'security' => [
            'trusted_proxies' => [],
            'trusted_proxy_headers' => [],
        ],
    ];

    require_once __DIR__ . '/../app/services/security_tokens.php';
    require_once __DIR__ . '/../app/services/client_ip.php';
    require_once __DIR__ . '/../app/services/viewer_accounts.php';
    require_once __DIR__ . '/../app/services/viewer_tokens.php';
    require_once __DIR__ . '/../app/services/viewer_rate_limits.php';
    require_once __DIR__ . '/../app/services/viewer_security_events.php';
    require_once __DIR__ . '/../app/services/viewer_maintenance.php';
    require_once __DIR__ . '/../app/security.php';

    /**
     * Throw when one viewer security foundation expectation fails.
     *
     * @param bool $condition Condition value.
     * @param string $label Assertion label.
     */
    function viewer_security_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    viewer_security_assert(!viewer_accounts_enabled(), 'Viewer accounts must be disabled unless explicitly enabled.');
    viewer_security_assert(viewer_registration_mode() === 'disabled', 'Registration mode must fail closed while the viewer feature is disabled.');
    viewer_security_assert(viewer_session_namespace_key() === 'viewer_auth', 'Viewer session state must use an explicit viewer-only namespace.');

    $_SESSION = [
        'viewer_auth' => [
            'account_id' => 42,
            'security_version' => 7,
            'token' => 'viewer-session-token',
        ],
    ];
    viewer_security_assert(current_user() === null, 'Viewer session state must never satisfy current_user().');
    viewer_security_assert(current_viewer() === null, 'Disabled viewer functionality must not resolve a viewer principal.');
    viewer_security_assert(viewer_session_state() === null, 'Disabled viewer resolution must clear invalid local viewer authority fail-closed.');
    viewer_security_assert(viewer_email_verification_token_consume('disabled-token') === null, 'Disabled viewer one-time-token consumption must fail closed before database access.');
    viewer_security_assert(viewer_remember_token_verify(str_repeat('a', 36), 'disabled-verifier') === null, 'Disabled viewer persistent-token verification must fail closed before database access.');
    $disabledIssueRefused = false;
    try {
        viewer_email_verification_token_issue(42, 'viewer@example.com');
    } catch (RuntimeException $exception) {
        $disabledIssueRefused = true;
    }
    viewer_security_assert($disabledIssueRefused, 'Disabled viewer token issuance must refuse before database access.');

    $_SESSION['user_id'] = 9;
    viewer_session_clear();
    viewer_security_assert(isset($_SESSION['user_id']) && !isset($_SESSION['viewer_auth']), 'Viewer logout/clear must preserve existing admin session state.');

    $tokenA = security_opaque_token_generate();
    $tokenB = security_opaque_token_generate();
    viewer_security_assert($tokenA !== $tokenB, 'Opaque token generation must produce independent random values.');
    viewer_security_assert(strlen($tokenA) >= 43 && preg_match('/^[A-Za-z0-9_-]+$/', $tokenA) === 1, 'Opaque tokens must retain at least 256 bits in URL-safe encoding.');
    $tokenHash = security_authority_token_hash($tokenA);
    viewer_security_assert(strlen($tokenHash) === 64, 'Authority token storage hash must be SHA-256 shaped.');
    viewer_security_assert(security_authority_token_verify($tokenHash, $tokenA), 'Authority token verification must accept the matching token.');
    viewer_security_assert(!security_authority_token_verify($tokenHash, $tokenB), 'Authority token verification must reject a different token.');

    viewer_security_assert(viewer_email_normalize('  User.Name@EXAMPLE.COM ') === 'User.Name@example.com', 'Email normalization must trim and lowercase only the domain.');
    viewer_security_assert(viewer_email_normalize('User.Name+tag@example.com') === 'User.Name+tag@example.com', 'Email normalization must preserve plus addressing.');
    viewer_security_assert(viewer_email_normalize('not-an-email') === null, 'Invalid email must be rejected deterministically.');
    viewer_security_assert(viewer_email_fingerprint('User.Name@EXAMPLE.COM') === viewer_email_fingerprint('User.Name@example.com'), 'Email fingerprint must be stable across domain case.');
    viewer_security_assert(viewer_email_fingerprint('User.Name@example.com') !== viewer_email_fingerprint('user.name@example.com'), 'Email identity normalization must not silently fold provider-specific local-part case.');

    $password = 'correct horse battery staple 2026';
    viewer_security_assert(viewer_password_input_is_acceptable($password), 'Normal long passphrases must be accepted.');
    $passwordHash = viewer_password_hash($password);
    viewer_security_assert(viewer_password_verify($password, $passwordHash), 'Native viewer password verification must accept the correct password.');
    viewer_security_assert(!viewer_password_verify('wrong password', $passwordHash), 'Native viewer password verification must reject the wrong password.');
    viewer_security_assert(!viewer_password_needs_rehash($passwordHash), 'Fresh viewer password hashes should not immediately require rehashing.');
    $tooLong = str_repeat('x', viewer_password_max_bytes() + 1);
    viewer_security_assert(!viewer_password_input_is_acceptable($tooLong), 'Password helper must reject input that the active native algorithm could truncate or overconsume.');

    $fixedNow = strtotime('2026-08-18 12:00:00');
    viewer_security_assert(viewer_one_time_token_row_is_usable([
        'expires_at' => '2026-08-18 12:05:00',
        'consumed_at' => null,
        'invalidated_at' => null,
    ], $fixedNow), 'Unexpired unused one-time token must be usable.');
    viewer_security_assert(!viewer_one_time_token_row_is_usable([
        'expires_at' => '2026-08-18 11:59:59',
        'consumed_at' => null,
        'invalidated_at' => null,
    ], $fixedNow), 'Expired one-time token must be rejected.');
    viewer_security_assert(!viewer_one_time_token_row_is_usable([
        'expires_at' => '2026-08-18 12:05:00',
        'consumed_at' => '2026-08-18 11:58:00',
        'invalidated_at' => null,
    ], $fixedNow), 'Consumed one-time token must be rejected.');

    viewer_security_assert(viewer_rate_limit_policy('viewer_login_ip') !== null, 'Viewer login IP rate-limit policy must exist.');
    viewer_security_assert(viewer_rate_limit_policy('attacker_bucket') === null, 'Rate-limit buckets must be application allowlisted.');
    viewer_security_assert(viewer_rate_limit_normalize_subject('identifier', '  User@Example.COM ') === 'user@example.com', 'Rate-limit identifiers must resist case/whitespace bypass.');
    viewer_security_assert(viewer_rate_limit_normalize_subject('subnet', '192.0.2.117') === '192.0.2.0/24', 'IPv4 subnet abuse subject must normalize to /24.');
    viewer_security_assert(str_ends_with(viewer_rate_limit_normalize_subject('subnet', '2001:db8:abcd:1234:9999::1'), '/64'), 'IPv6 subnet abuse subject must normalize to /64.');
    viewer_security_assert(viewer_rate_limit_subject_cap() === 250, 'Viewer rate-limit storage cap must be configurable and bounded.');

    $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';
    viewer_security_assert(request_client_ip() === '198.51.100.10', 'Spoofed forwarded headers must be ignored when the direct peer is not trusted.');
    viewer_security_assert(request_ip_matches_trusted_proxy('192.0.2.55', '192.0.2.0/24'), 'Trusted proxy CIDR matching must support IPv4.');
    viewer_security_assert(request_ip_matches_trusted_proxy('2001:db8::5', '2001:db8::/32'), 'Trusted proxy CIDR matching must support IPv6.');

    $GLOBALS['viewer_fixture_config']['security'] = [
        'trusted_proxies' => ['198.51.100.10', '192.0.2.0/24'],
        'trusted_proxy_headers' => ['x-forwarded-for'],
    ];
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77, 192.0.2.55';
    viewer_security_assert(request_client_ip() === '203.0.113.77', 'Forwarded chain must resolve through explicitly trusted proxies only.');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77, 198.18.0.5';
    viewer_security_assert(request_client_ip() === '198.18.0.5', 'Rightmost untrusted forwarding hop must stop the trust walk.');

    $safeContext = viewer_security_event_sanitize_context([
        'action' => 'viewer_login',
        'reason' => 'invalid_credentials',
        'token' => 'must-not-log',
        'password' => 'must-not-log',
        'email' => 'person@example.com',
        'url' => 'https://example.invalid/reset?token=secret',
        'attempts' => 3,
    ]);
    viewer_security_assert(isset($safeContext['action'], $safeContext['reason'], $safeContext['attempts']), 'Security event sanitizer must retain low-risk diagnostics.');
    viewer_security_assert(!isset($safeContext['token'], $safeContext['password'], $safeContext['email'], $safeContext['url']), 'Security event sanitizer must discard secret/PII-shaped context.');

    $GLOBALS['viewer_fixture_config']['viewer_accounts']['enabled'] = true;
    viewer_security_assert(viewer_accounts_enabled(), 'Explicit boolean true must enable the dormant viewer domain.');
    viewer_security_assert(viewer_registration_mode() === 'open', 'Valid configured registration mode may be read only after viewer enablement.');

    echo "Viewer security foundation primitive tests passed.\n";
}
