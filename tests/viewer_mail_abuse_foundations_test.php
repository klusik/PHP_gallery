<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_mail_abuse_foundations_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the dormant Phase 0.5 email-abuse authorization boundary.
 *
 * Responsibilities:
 *   - Prove email delivery remains disabled and transport-free in Phase 0.5
 *   - Verify verification/reset/invitation mail plans use independent multidimensional budgets
 *   - Verify conservative default limits and one generic external response code
 *   - Protect corrected max-attempt semantics used by the future mail budgets
 *   - Protect trusted-client handling by refusing valid mail authorization without a usable client IP
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return mutable fixture configuration for isolated viewer mail tests.
     *
     * @return array<string,mixed> Fixture configuration.
     */
    function cms_config(): array
    {
        return $GLOBALS['viewer_mail_fixture_config'];
    }

    /**
     * Return a fixed timestamp for service helpers not expected to reach persistence.
     *
     * @return string Fixed SQL timestamp.
     */
    function now_sql(): string
    {
        return '2026-08-18 13:30:00';
    }

    /**
     * Refuse accidental database access from this pure mail-boundary test.
     *
     * @return \PDO Never returns.
     */
    function db(): \PDO
    {
        throw new \RuntimeException('Database access is not expected in viewer_mail_abuse_foundations_test.php.');
    }
}

namespace {
    use function Gallery\Services\viewer_accounts_enabled;
    use function Gallery\Services\viewer_mail_authorize_send;
    use function Gallery\Services\viewer_mail_public_result_code;
    use function Gallery\Services\viewer_mail_rate_limit_plan;
    use function Gallery\Services\viewer_rate_limit_policy;

    $GLOBALS['viewer_mail_fixture_config'] = [
        'visitor_vote_secret' => 'phase-05-mail-fixture-secret',
        'viewer_accounts' => [
            'enabled' => false,
            'registration_mode' => 'invite_only',
            'verification_mail_email_cooldown_seconds' => 600,
            'verification_mail_email_hourly_limit' => 3,
            'verification_mail_email_daily_limit' => 5,
            'verification_mail_ip_hourly_limit' => 10,
            'verification_mail_ip_daily_limit' => 25,
            'verification_mail_subnet_hourly_limit' => 25,
            'verification_mail_subnet_daily_limit' => 60,
            'verification_mail_global_daily_limit' => 50,
            'password_reset_mail_email_cooldown_seconds' => 600,
            'password_reset_mail_email_hourly_limit' => 3,
            'password_reset_mail_email_daily_limit' => 5,
            'password_reset_mail_ip_hourly_limit' => 5,
            'password_reset_mail_ip_daily_limit' => 20,
            'password_reset_mail_subnet_hourly_limit' => 15,
            'password_reset_mail_subnet_daily_limit' => 40,
            'password_reset_mail_global_daily_limit' => 50,
            'invitation_mail_email_daily_limit' => 3,
            'invitation_mail_global_daily_limit' => 50,
        ],
        'security' => [
            'trusted_proxies' => [],
            'trusted_proxy_headers' => [],
        ],
    ];

    require_once __DIR__ . '/../app/services/schema_inspection.php';
    require_once __DIR__ . '/../app/services/security_tokens.php';
    require_once __DIR__ . '/../app/services/client_ip.php';
    require_once __DIR__ . '/../app/services/viewer_accounts.php';
    require_once __DIR__ . '/../app/services/viewer_rate_limits.php';
    require_once __DIR__ . '/../app/services/viewer_mail.php';

    Gallery\Services\schema_inspection_set_query_executor_for_tests(
        static fn (string $objectType, string $table, string $object, ?string $definition = null): bool => true
    );

    /**
     * Throw when one Phase 0.5 mail-boundary expectation fails.
     *
     * @param bool $condition Condition value.
     * @param string $label Assertion label.
     */
    function viewer_mail_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    viewer_mail_assert(!viewer_accounts_enabled(), 'Viewer mail authority must remain unavailable while the viewer feature is disabled.');
    $disabled = viewer_mail_authorize_send('verification', 'person@example.com', '192.0.2.10');
    viewer_mail_assert(!$disabled['allowed'] && $disabled['reason'] === 'viewer_disabled', 'Disabled viewer mail must fail closed before rate-limit database access.');
    viewer_mail_assert($disabled['public_result'] === viewer_mail_public_result_code(), 'Disabled viewer mail must retain the generic external result.');

    $verificationPlan = viewer_mail_rate_limit_plan('verification');
    $verificationBuckets = array_column($verificationPlan, 'bucket');
    viewer_mail_assert($verificationBuckets[0] === 'viewer_verify_mail_email_cooldown', 'Verification recipient cooldown must be checked before broader budgets.');
    viewer_mail_assert($verificationBuckets[count($verificationBuckets) - 1] === 'viewer_verify_mail_global_day', 'Verification global mail budget must be reserved last so suppressed recipient attempts cannot burn it.');
    foreach ([
        'viewer_verify_mail_global_day',
        'viewer_verify_mail_ip_hour',
        'viewer_verify_mail_ip_day',
        'viewer_verify_mail_subnet_hour',
        'viewer_verify_mail_subnet_day',
        'viewer_verify_mail_email_cooldown',
        'viewer_verify_mail_email_hour',
        'viewer_verify_mail_email_day',
    ] as $bucket) {
        viewer_mail_assert(in_array($bucket, $verificationBuckets, true), 'Verification mail must reserve budget: ' . $bucket);
    }

    $resetPlan = viewer_mail_rate_limit_plan('password_reset');
    $resetBuckets = array_column($resetPlan, 'bucket');
    viewer_mail_assert($resetBuckets[0] === 'viewer_reset_mail_email_cooldown' && $resetBuckets[count($resetBuckets) - 1] === 'viewer_reset_mail_global_day', 'Reset mail must reserve narrow recipient budgets before the global circuit breaker.');
    viewer_mail_assert(in_array('viewer_reset_mail_global_day', $resetBuckets, true), 'Password-reset email must have a global installation budget.');
    viewer_mail_assert(in_array('viewer_reset_mail_email_day', $resetBuckets, true), 'Password-reset email must have a per-address daily budget.');
    viewer_mail_assert(in_array('viewer_reset_mail_ip_hour', $resetBuckets, true), 'Password-reset email must have a per-client budget.');
    viewer_mail_assert(in_array('viewer_reset_mail_subnet_hour', $resetBuckets, true), 'Password-reset email must have a secondary subnet budget.');

    $invitePlan = viewer_mail_rate_limit_plan('invitation');
    viewer_mail_assert(count($invitePlan) === 2, 'Future administrator invitation delivery needs only bounded recipient/global mail budgets in this phase.');
    viewer_mail_assert($invitePlan[0]['bucket'] === 'viewer_invite_mail_email_day' && $invitePlan[1]['bucket'] === 'viewer_invite_mail_global_day', 'Invitation recipient budget must precede the installation-global budget.');
    viewer_mail_assert(viewer_mail_public_result_code() === 'request_received', 'Future mail-triggering endpoints must share one generic external result.');

    $GLOBALS['viewer_mail_fixture_config']['viewer_accounts']['enabled'] = true;
    viewer_mail_assert(viewer_accounts_enabled(), 'Fixture must explicitly enable dormant viewer services for policy inspection.');

    viewer_mail_assert((viewer_rate_limit_policy('viewer_verify_mail_email_cooldown')['max_attempts'] ?? 0) === 1, 'Verification mail cooldown must permit exactly one reserved send per cooldown window.');
    viewer_mail_assert((viewer_rate_limit_policy('viewer_verify_mail_email_hour')['max_attempts'] ?? 0) === 3, 'Verification mail per-address hourly default must be three.');
    viewer_mail_assert((viewer_rate_limit_policy('viewer_verify_mail_email_day')['max_attempts'] ?? 0) === 5, 'Verification mail per-address daily default must be five.');
    viewer_mail_assert((viewer_rate_limit_policy('viewer_verify_mail_global_day')['max_attempts'] ?? 0) === 50, 'Verification mail global daily default must bound distributed abuse.');
    viewer_mail_assert((viewer_rate_limit_policy('viewer_reset_mail_email_hour')['max_attempts'] ?? 0) === 3, 'Reset mail per-address hourly default must be three.');

    $invalidEmail = viewer_mail_authorize_send('verification', 'not-an-email', '192.0.2.10');
    viewer_mail_assert(!$invalidEmail['allowed'] && $invalidEmail['reason'] === 'invalid_email', 'Invalid recipient must fail before limiter access.');
    viewer_mail_assert($invalidEmail['public_result'] === viewer_mail_public_result_code(), 'Invalid recipient must not change the future generic public result.');

    $missingIp = viewer_mail_authorize_send('verification', 'person@example.com', '');
    viewer_mail_assert(!$missingIp['allowed'] && $missingIp['reason'] === 'client_ip_unavailable', 'Anonymous verification mail must fail closed without a trustworthy client IP.');

    $root = dirname(__DIR__);
    $mailService = (string) file_get_contents($root . '/app/services/viewer_mail.php');
    viewer_mail_assert(strpos($mailService, "\n    mail(") === false && strpos($mailService, 'return mail(') === false, 'Phase 0.5 viewer mail boundary must not call PHP mail().');
    viewer_mail_assert(stripos($mailService, 'stream_socket_client') === false && stripos($mailService, 'smtp_') === false, 'Phase 0.5 viewer mail boundary must not implement SMTP transport.');
    viewer_mail_assert(stripos($mailService, 'curl_') === false, 'Phase 0.5 viewer mail boundary must not add a provider/API transport.');

    $rateService = (string) file_get_contents($root . '/app/services/viewer_rate_limits.php');
    viewer_mail_assert(str_contains($rateService, '$attempts > (int) $policy[\'max_attempts\']'), 'Rate limiter max_attempts must mean the number of attempts that may pass, with the following attempt locked.');

    $configExample = (string) file_get_contents($root . '/config.example.php');
    viewer_mail_assert(str_contains($configExample, "'verification_mail_global_daily_limit' => 50"), 'Example configuration must expose a conservative global verification-mail circuit breaker.');
    viewer_mail_assert(str_contains($configExample, "'max_pending_registration_requests' => 250"), 'Example configuration must expose a hard pending-registration cap.');

    echo "Viewer mail abuse foundation tests passed.\n";
}
