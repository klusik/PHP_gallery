<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_registration_foundations_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the dormant Phase 0.5 pending-registration and invitation security boundary.
 *
 * Responsibilities:
 *   - Prove pending registration remains unavailable while the viewer feature is disabled
 *   - Verify invitation binding and scanner-safe verification state predicates
 *   - Verify three-state schema inspection fails closed
 *   - Protect the no-account-creation boundary and later HTTP reuse of the same service
 *   - Protect schema uniqueness, expiry, and bounded-capacity constraints
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return mutable fixture configuration for isolated registration foundation tests.
     *
     * @return array<string,mixed> Fixture configuration.
     */
    function cms_config(): array
    {
        return $GLOBALS['viewer_registration_fixture_config'];
    }

    /**
     * Return a deterministic SQL timestamp for isolated registration tests.
     *
     * @return string Fixed SQL timestamp.
     */
    function now_sql(): string
    {
        return '2026-08-18 13:30:00';
    }

    /**
     * Refuse accidental database access from this pure isolated test.
     *
     * @return \PDO Never returns.
     */
    function db(): \PDO
    {
        throw new \RuntimeException('Database access is not expected in viewer_registration_foundations_test.php.');
    }
}

namespace {
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
    use function Gallery\Services\viewer_accounts_enabled;
    use function Gallery\Services\viewer_email_fingerprint;
    use function Gallery\Services\viewer_invitation_row_is_usable;
    use function Gallery\Services\viewer_registration_public_result_code;
    use function Gallery\Services\viewer_registration_request_begin;
    use function Gallery\Services\viewer_registration_request_cap;
    use function Gallery\Services\viewer_registration_request_row_is_verifiable;
    use function Gallery\Services\viewer_registration_requests_enabled;
    use function Gallery\Services\viewer_registration_storage_available;

    $GLOBALS['viewer_registration_fixture_config'] = [
        'visitor_vote_secret' => 'phase-05-registration-fixture-secret',
        'viewer_accounts' => [
            'enabled' => false,
            'registration_mode' => 'invite_only',
            'max_pending_registration_requests' => 250,
            'registration_request_lifetime_minutes' => 1440,
            'verified_registration_lifetime_minutes' => 60,
            'verification_token_lifetime_minutes' => 60,
            'invitation_lifetime_days' => 7,
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
    require_once __DIR__ . '/../app/services/viewer_registration.php';

    /**
     * Throw when one Phase 0.5 registration expectation fails.
     *
     * @param bool $condition Condition value.
     * @param string $label Assertion label.
     */
    function viewer_registration_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    viewer_registration_assert(!viewer_accounts_enabled(), 'Viewer feature must remain disabled by default.');
    viewer_registration_assert(!viewer_registration_requests_enabled(), 'Pending registration must remain unavailable while the viewer feature is disabled.');
    viewer_registration_assert(viewer_registration_request_cap() === 250, 'Pending registration row cap must have a conservative configurable default.');
    viewer_registration_assert(viewer_registration_public_result_code() === 'request_received', 'Anonymous registration should have one generic external result code.');

    $disabled = viewer_registration_request_begin('person@example.com', 'unused', '192.0.2.10');
    viewer_registration_assert(!$disabled['accepted'] && $disabled['reason'] === 'registration_disabled', 'Disabled registration must fail closed before database access.');
    viewer_registration_assert($disabled['public_result'] === viewer_registration_public_result_code(), 'Disabled registration must retain the same generic external result.');

    $boundEmail = 'Person@example.com';
    $boundFingerprint = viewer_email_fingerprint($boundEmail);
    $usableInvitation = [
        'target_email_fingerprint' => $boundFingerprint,
        'expires_at' => '2026-08-18 14:00:00',
        'claimed_at' => null,
        'revoked_at' => null,
    ];
    $fixedNow = strtotime('2026-08-18 13:30:00');
    viewer_registration_assert(viewer_invitation_row_is_usable($usableInvitation, $boundEmail, $fixedNow), 'Fresh invitation must accept its bound normalized email.');
    viewer_registration_assert(!viewer_invitation_row_is_usable($usableInvitation, 'Other@example.com', $fixedNow), 'Email-bound invitation must reject another identity.');
    $claimedInvitation = $usableInvitation;
    $claimedInvitation['claimed_at'] = '2026-08-18 13:20:00';
    viewer_registration_assert(!viewer_invitation_row_is_usable($claimedInvitation, $boundEmail, $fixedNow), 'Claimed invitation must not be reusable as a fresh capability.');
    $expiredInvitation = $usableInvitation;
    $expiredInvitation['expires_at'] = '2026-08-18 13:29:59';
    viewer_registration_assert(!viewer_invitation_row_is_usable($expiredInvitation, $boundEmail, $fixedNow), 'Expired invitation must be rejected.');

    $pendingRow = [
        'status' => 'pending_verification',
        'verification_token_consumed_at' => null,
        'cancelled_at' => null,
        'expires_at' => '2026-08-18 14:30:00',
        'verification_token_expires_at' => '2026-08-18 14:00:00',
    ];
    viewer_registration_assert(viewer_registration_request_row_is_verifiable($pendingRow, $fixedNow), 'Unexpired pending verification row must be valid for scanner-safe inspection.');
    $consumedRow = $pendingRow;
    $consumedRow['verification_token_consumed_at'] = '2026-08-18 13:25:00';
    viewer_registration_assert(!viewer_registration_request_row_is_verifiable($consumedRow, $fixedNow), 'Consumed verification row must reject replay.');
    $verifiedRow = $pendingRow;
    $verifiedRow['status'] = 'email_verified';
    viewer_registration_assert(!viewer_registration_request_row_is_verifiable($verifiedRow, $fixedNow), 'Already verified staging row must not accept another verification transition.');

    schema_inspection_set_query_executor_for_tests(static fn (...$args): bool => true);
    viewer_registration_assert(viewer_registration_storage_available(), 'Registration storage must be available only when every required table is confirmed.');

    schema_inspection_set_query_executor_for_tests(static fn (...$args): bool => false);
    viewer_registration_assert(!viewer_registration_storage_available(), 'Confirmed missing registration schema must fail closed.');

    schema_inspection_set_query_executor_for_tests(static function (...$args): bool {
        throw new RuntimeException('fixture inspection failure');
    });
    viewer_registration_assert(!viewer_registration_storage_available(), 'Unknown registration schema state must fail closed.');
    schema_inspection_set_query_executor_for_tests(null);

    $root = dirname(__DIR__);
    $migration = (string) file_get_contents($root . '/database/migrations/202608180002_viewer_registration_foundations.php');
    viewer_registration_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS viewer_invitations'), 'Phase 0.5 migration must create invitation storage.');
    viewer_registration_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS viewer_registration_requests'), 'Phase 0.5 migration must create ephemeral pending-registration storage.');
    viewer_registration_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS viewer_registration_state'), 'Phase 0.5 migration must create a locked capacity counter.');
    viewer_registration_assert(str_contains($migration, 'UNIQUE KEY viewer_registration_normalized_email_unique (normalized_email)'), 'Pending requests must deduplicate at database level by canonical email.');
    viewer_registration_assert(str_contains($migration, 'UNIQUE KEY viewer_registration_verification_hash_unique (verification_token_hash)'), 'Verification token hashes must be unique at database level.');
    viewer_registration_assert(str_contains($migration, 'UNIQUE KEY viewer_registration_invitation_unique (viewer_invitation_id)'), 'One invitation must not bind multiple pending requests.');
    viewer_registration_assert(str_contains($migration, 'KEY viewer_registration_expiry_index (expires_at)'), 'Pending request cleanup must be indexed by expiry.');
    viewer_registration_assert(str_contains($migration, 'token_hash CHAR(64) NOT NULL'), 'Invitation authority must be stored as a one-way hash.');
    viewer_registration_assert(stripos($migration, 'password') === false, 'Pending-registration schema must not store password material.');

    $registrationService = (string) file_get_contents($root . '/app/services/viewer_registration.php');
    viewer_registration_assert(stripos($registrationService, "prepare('INSERT INTO viewer_accounts") === false && stripos($registrationService, 'prepare("INSERT INTO viewer_accounts') === false, 'Phase 0.5 registration service must not create durable viewer accounts.');
    viewer_registration_assert(str_contains($registrationService, 'viewer_registration_verification_validate') && str_contains($registrationService, 'viewer_registration_verification_confirm'), 'Verification inspection and irreversible confirmation must remain separate operations.');
    viewer_registration_assert(str_contains($registrationService, 'FOR UPDATE'), 'Single-use invitation/verification transitions must use transactional row locking.');
    viewer_registration_assert(str_contains($registrationService, 'viewer_registration_capacity_lock'), 'Pending registration admission must serialize against a hard row cap.');
    viewer_registration_assert(str_contains($registrationService, 'viewer_invitation_registration_preflight'), 'Invite-only registration must have a non-consuming preflight that supports idempotent retries.');
    viewer_registration_assert(str_contains($registrationService, 'The earlier preflight is') && str_contains($registrationService, '$invitationStateValid'), 'Invitation authority must be re-validated under the transactional row lock rather than trusting preflight state.');
    $preflightPosition = strpos($registrationService, 'viewer_invitation_registration_preflight((string) $invitationToken');
    $identityBudgetPosition = strpos($registrationService, 'viewer_registration_request_authorize_identity($normalized)');
    viewer_registration_assert(is_int($preflightPosition) && is_int($identityBudgetPosition) && $preflightPosition < $identityBudgetPosition, 'Invalid invite guesses must not consume the installation-wide registration budget.');
    viewer_registration_assert(str_contains($registrationService, "SET status = ?, cancelled_at = ?, updated_at = ?") && str_contains($registrationService, 'viewer_invitation_revoke'), 'Revoking a claimed invitation must also cancel its staged registration state.');
    viewer_registration_assert(!str_contains($registrationService, 'if (!viewer_registration_requests_enabled() || $invitationId <= 0'), 'Invitation revocation must remain available while registration admission is disabled.');

    $services = (string) file_get_contents($root . '/app/services.php');
    viewer_registration_assert(str_contains($services, 'viewer_registration.php') && str_contains($services, 'viewer_mail.php'), 'Phase 0.5 services must be loaded only through the shared service bootstrap.');

    $migrationFiles = glob($root . '/database/migrations/*.php') ?: [];
    sort($migrationFiles, SORT_STRING);
    $phase0Migration = array_search($root . '/database/migrations/202608180001_viewer_security_foundations.php', $migrationFiles, true);
    $phase05Migration = array_search($root . '/database/migrations/202608180002_viewer_registration_foundations.php', $migrationFiles, true);
    viewer_registration_assert(is_int($phase0Migration) && is_int($phase05Migration) && $phase05Migration > $phase0Migration, 'Phase 0.5 migration must follow the Phase 0 schema in deterministic fresh-install/upgrade order.');

    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    $viewerController = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
    viewer_registration_assert(str_contains($dispatch, "'viewer_register' =>") && !str_contains($dispatch, "'viewer_signup' =>"), 'Phase 4.1 must expose only the intended generic viewer_register route, not a parallel signup subsystem.');
    viewer_registration_assert(str_contains($viewerController, 'viewer_registration_request_begin($email, null, request_client_ip())') && str_contains($viewerController, 'viewer_registration_activate_verified('), 'Phase 4.1 must reuse the established staged-registration and activation foundation instead of replacing it.');

    $adminAuth = (string) file_get_contents($root . '/app/controllers/admin_auth.php');
    viewer_registration_assert(stripos($adminAuth, 'viewer_registration') === false && stripos($adminAuth, 'viewer_mail') === false, 'Existing administrator authentication/mail behavior must remain unaware of Phase 0.5.');

    echo "Viewer registration foundation tests passed.\n";
}
