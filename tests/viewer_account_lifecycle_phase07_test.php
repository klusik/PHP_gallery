<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_account_lifecycle_phase07_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies dormant Phase 0.7 viewer account lifecycle/content-authorization foundations.
 *
 * Responsibilities:
 *   - Exercise pure recent-auth namespace, plain-text, quota, and schema policies
 *   - Protect security-version-aware password/email/deletion transaction structure
 *   - Protect the no-admin-bypass source-image authorization boundary
 *   - Prove Phase 0.7 services remain route-free while later HTTP wiring stays in a separate thin controller
 *   - Prove the foundational mail/content services remain transport-free and collection-CRUD-free
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return mutable fixture configuration for isolated Phase 0.7 tests.
     *
     * @return array<string,mixed> Fixture configuration.
     */
    function cms_config(): array
    {
        return $GLOBALS['viewer_phase07_config'];
    }

    /**
     * Return a deterministic SQL timestamp for pure helpers.
     *
     * @return string Fixed SQL timestamp.
     */
    function now_sql(): string
    {
        return '2026-08-18 15:00:00';
    }

    /**
     * Refuse accidental database access from the driverless Phase 0.7 model test.
     *
     * @return \PDO Never returns.
     */
    function db(): \PDO
    {
        throw new \RuntimeException('Database access is not expected in viewer_account_lifecycle_phase07_test.php.');
    }
}

namespace {
    use function Gallery\Services\schema_inspection_is_available;
    use function Gallery\Services\schema_inspection_is_missing;
    use function Gallery\Services\schema_inspection_is_unknown;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
    use function Gallery\Services\viewer_account_deletion_schema_status;
    use function Gallery\Services\viewer_collection_title_policy;
    use function Gallery\Services\viewer_collection_title_validate;
    use function Gallery\Services\viewer_content_quota_config;
    use function Gallery\Services\viewer_email_change_confirmation_namespace_key;
    use function Gallery\Services\viewer_lifecycle_schema_status;
    use function Gallery\Services\viewer_plain_text_validate;
    use function Gallery\Services\viewer_reauthentication_namespace_key;
    use function Gallery\Services\viewer_reauthentication_status;
    use function Gallery\Services\viewer_session_clear;

    $GLOBALS['viewer_phase07_config'] = [
        'visitor_vote_secret' => 'phase-07-fixture-secret',
        'base_url' => 'https://gallery.example.test',
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
    require_once __DIR__ . '/../app/services/viewer_lifecycle.php';
    require_once __DIR__ . '/../app/services/viewer_content_foundations.php';

    /**
     * Throw when one Phase 0.7 expectation fails.
     *
     * @param bool $condition Condition value.
     * @param string $label Assertion label.
     */
    function viewer_phase07_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    schema_inspection_set_query_executor_for_tests(
        static fn (string $objectType, string $table, string $object, ?string $definition = null): bool => true
    );
    viewer_phase07_assert(schema_inspection_is_available(viewer_lifecycle_schema_status()), 'Complete viewer lifecycle schema must report available.');
    viewer_phase07_assert(schema_inspection_is_available(viewer_account_deletion_schema_status()), 'Complete viewer deletion schema must report available.');

    schema_inspection_set_query_executor_for_tests(
        static fn (string $objectType, string $table, string $object, ?string $definition = null): bool => $table !== 'viewer_email_change_requests'
    );
    viewer_phase07_assert(schema_inspection_is_missing(viewer_lifecycle_schema_status()), 'Missing email-change storage must fail lifecycle capability closed.');

    schema_inspection_set_query_executor_for_tests(
        static fn (string $objectType, string $table, string $object, ?string $definition = null): bool => $table !== 'viewer_passkeys'
    );
    viewer_phase07_assert(schema_inspection_is_missing(viewer_account_deletion_schema_status()), 'Missing dormant passkey storage must fail destructive account deletion closed.');

    schema_inspection_set_query_executor_for_tests(
        static function (string $objectType, string $table, string $object, ?string $definition = null): bool {
            if ($table === 'viewer_email_change_requests') {
                throw new \RuntimeException('synthetic email-change schema inspection failure');
            }
            return true;
        }
    );
    viewer_phase07_assert(schema_inspection_is_unknown(viewer_lifecycle_schema_status()), 'Email-change inspection error must remain unknown rather than missing.');
    schema_inspection_set_query_executor_for_tests(null);

    viewer_phase07_assert(viewer_reauthentication_namespace_key() === 'viewer_reauthentication', 'Recent viewer credential proof must use a distinct session namespace.');
    viewer_phase07_assert(viewer_email_change_confirmation_namespace_key() === 'viewer_email_change_confirmation', 'Email-change confirmation must use a distinct session namespace.');
    $_SESSION = [
        'user_id' => 91,
        'viewer_auth' => ['account_id' => 7, 'security_version' => 2, 'token' => 'viewer-token'],
        'viewer_reauthentication' => ['account_id' => 7, 'security_version' => 2, 'viewer_session_id' => 4, 'expires_at' => time() + 600],
        'viewer_email_change_confirmation' => ['request_id' => 3],
    ];
    viewer_session_clear();
    viewer_phase07_assert(isset($_SESSION['user_id']), 'Clearing viewer authority must preserve administrator session state.');
    viewer_phase07_assert(!isset($_SESSION['viewer_auth'], $_SESSION['viewer_reauthentication'], $_SESSION['viewer_email_change_confirmation']), 'Viewer auth clear must also clear recent-auth and email-change authority.');

    $_SESSION = [
        'viewer_reauthentication' => [
            'account_id' => 7,
            'security_version' => 2,
            'viewer_session_id' => 4,
            'expires_at' => time() - 1,
        ],
    ];
    $expired = viewer_reauthentication_status();
    viewer_phase07_assert(!$expired['valid'] && $expired['reason'] === 'expired', 'Expired recent reauthentication must fail closed without database access.');
    viewer_phase07_assert(!isset($_SESSION['viewer_reauthentication']), 'Expired recent reauthentication must be removed from server-side session state.');

    $policy = viewer_collection_title_policy();
    viewer_phase07_assert($policy['max_characters'] === 120 && $policy['max_bytes'] === 480, 'Collection title policy must be 120 Unicode code points with a bounded UTF-8 byte budget.');
    viewer_phase07_assert(viewer_collection_title_validate('Ordinary collection title')['valid'], 'Ordinary collection title must be accepted.');
    viewer_phase07_assert(viewer_collection_title_validate('Fotky ze Švédska 日本語')['valid'], 'Ordinary Unicode collection title must be accepted.');
    viewer_phase07_assert(viewer_collection_title_validate('A title with spaces')['valid'], 'Spaces must be accepted in viewer plain text.');
    viewer_phase07_assert(viewer_collection_title_validate('<script>alert(1)</script>')['valid'], 'Plain-text validation must not interpret HTML syntax.');
    viewer_phase07_assert(!viewer_collection_title_validate(str_repeat('a', 121))['valid'], 'Over-limit collection title must be rejected rather than truncated.');
    viewer_phase07_assert(viewer_collection_title_validate(str_repeat('ž', 120))['valid'], 'Exactly 120 multibyte Unicode code points must be accepted within byte budget.');
    viewer_phase07_assert(!viewer_plain_text_validate(str_repeat('🙂', 121), 200, 480, true)['valid'], 'Plain-text byte limit must be enforced independently from character limit.');
    viewer_phase07_assert(!viewer_collection_title_validate("bad\0title")['valid'], 'NUL must be rejected.');
    viewer_phase07_assert(!viewer_collection_title_validate("bad\ntitle")['valid'], 'Unsafe ASCII controls must be rejected.');
    viewer_phase07_assert(!viewer_collection_title_validate("safe\u{202E}evil")['valid'], 'Unicode bidi controls must be rejected deterministically.');
    $invalidUtf8 = "bad\xC3\x28";
    viewer_phase07_assert(!viewer_collection_title_validate($invalidUtf8)['valid'], 'Invalid UTF-8 must be rejected.');

    $quotas = viewer_content_quota_config();
    viewer_phase07_assert($quotas === [
        'max_viewer_favourites_per_account' => 5000,
        'max_viewer_collections_per_account' => 25,
        'max_viewer_items_per_collection' => 500,
        'max_active_viewer_collection_shares_per_collection' => 1,
    ], 'Future viewer content quotas must have one conservative centralized default contract.');
    $GLOBALS['viewer_phase07_config']['viewer_accounts']['max_viewer_favourites_per_account'] = -10;
    $GLOBALS['viewer_phase07_config']['viewer_accounts']['max_viewer_collections_per_account'] = PHP_INT_MAX;
    $bounded = viewer_content_quota_config();
    viewer_phase07_assert($bounded['max_viewer_favourites_per_account'] === 1, 'Negative quota configuration must fail to a safe bounded value.');
    viewer_phase07_assert($bounded['max_viewer_collections_per_account'] === 1000, 'Huge quota configuration must be bounded by a hard ceiling.');
    $GLOBALS['viewer_phase07_config']['viewer_accounts']['max_viewer_items_per_collection'] = 'not-a-number';
    $GLOBALS['viewer_phase07_config']['viewer_accounts']['max_active_viewer_collection_shares_per_collection'] = '999999999999999999999999';
    $strictBounded = viewer_content_quota_config();
    viewer_phase07_assert($strictBounded['max_viewer_items_per_collection'] === 500, 'Malformed quota configuration must fall back to the conservative default instead of integer coercion.');
    viewer_phase07_assert($strictBounded['max_active_viewer_collection_shares_per_collection'] === 100, 'Oversized numeric quota configuration must remain hard-bounded without integer overflow.');

    $root = dirname(__DIR__);
    $accountService = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
    $authService = (string) file_get_contents($root . '/app/services/viewer_authentication.php');
    $tokenService = (string) file_get_contents($root . '/app/services/viewer_tokens.php');
    $lifecycleService = (string) file_get_contents($root . '/app/services/viewer_lifecycle.php');
    $contentService = (string) file_get_contents($root . '/app/services/viewer_content_foundations.php');
    $galleryAccessService = (string) file_get_contents($root . '/app/services/gallery_access.php');
    $mailService = (string) file_get_contents($root . '/app/services/viewer_mail.php');
    $migration = (string) file_get_contents($root . '/database/migrations/202608180004_viewer_account_lifecycle_foundations.php');

    viewer_phase07_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS viewer_email_change_requests') && str_contains($migration, 'ENGINE=InnoDB'), 'Email-change migration must be additive/replay-safe and InnoDB-compatible.');
    viewer_phase07_assert(str_contains($migration, 'verification_token_hash CHAR(64)') && !str_contains($migration, 'verification_token VARCHAR'), 'Email-change storage must persist only verification-token hashes.');
    viewer_phase07_assert(str_contains($migration, 'normalized_new_email') && str_contains($migration, 'security_version BIGINT UNSIGNED NOT NULL'), 'Email-change staging must bind normalized target identity to security version.');

    viewer_phase07_assert(str_contains($authService, 'viewer_reauthentication_establish($authenticatedViewer)'), 'Successful interactive password login must establish recent reauthentication when the lifecycle service is loaded.');
    viewer_phase07_assert(str_contains($lifecycleService, "viewer_login_rate_limits_consume((string) \$viewer['normalized_email']"), 'Explicit password reauthentication must reuse the established viewer password-attempt abuse-control budgets before password verification.');
    viewer_phase07_assert(!str_contains($tokenService, 'viewer_reauthentication_establish'), 'Remember-token restoration must never establish recent reauthentication.');
    viewer_phase07_assert(str_contains($accountService, "unset(\$_SESSION[VIEWER_REAUTHENTICATION_NAMESPACE]"), 'New viewer session establishment must discard inherited recent-auth authority.');

    viewer_phase07_assert(str_contains($lifecycleService, 'function viewer_change_password(string $newPassword, ?string $currentPassword = null)') && str_contains($lifecycleService, 'FOR UPDATE'), 'Password change must be route-free, account-locked, and able to require explicit current-password proof.');
    viewer_phase07_assert(str_contains($lifecycleService, 'password_changed_at = ?') && str_contains($lifecycleService, 'security_version = ?'), 'Password change must update password_changed_at and security_version.');
    viewer_phase07_assert(str_contains($lifecycleService, 'UPDATE viewer_sessions SET revoked_at = ?') && str_contains($lifecycleService, 'UPDATE viewer_remember_tokens SET revoked_at = ?'), 'Password/email lifecycle transitions must revoke viewer session and remember authority.');
    viewer_phase07_assert(str_contains($lifecycleService, 'UPDATE viewer_password_reset_tokens SET invalidated_at = ?'), 'Password/email lifecycle transitions must invalidate reset authority.');

    viewer_phase07_assert(str_contains($lifecycleService, 'function viewer_email_change_request_inspect(string $verificationToken)') && str_contains($lifecycleService, 'function viewer_email_change_authorize(string $verificationToken)'), 'Email-change inspection and explicit confirmation authorization must remain separate.');
    viewer_phase07_assert(str_contains($lifecycleService, 'SELECT * FROM viewer_email_change_requests WHERE id = ? LIMIT 1 FOR UPDATE'), 'Final email change must lock the staged request.');
    viewer_phase07_assert(str_contains($lifecycleService, 'normalized_email = ? AND id <> ?') && str_contains($migration, 'viewer_accounts_normalized_email_unique') === false, 'Email change must re-check target uniqueness while relying on the existing account unique constraint.');
    viewer_phase07_assert(str_contains($lifecycleService, 'viewer_mail_authorize_send(VIEWER_MAIL_ACTION_EMAIL_CHANGE'), 'Email-change request creation must pass the existing mail-abuse authorization boundary.');
    viewer_phase07_assert(str_contains($mailService, "const VIEWER_MAIL_ACTION_EMAIL_CHANGE = 'email_change';"), 'Email-change mail intent must be allowlisted without adding transport.');

    viewer_phase07_assert(str_contains($lifecycleService, 'function viewer_account_delete(): array') && str_contains($lifecycleService, 'DELETE FROM viewer_accounts WHERE id = ?'), 'Account deletion must be an internal route-free terminal transition.');
    viewer_phase07_assert(str_contains($lifecycleService, 'viewer_account_capacity_lock();') && substr_count($lifecycleService, 'viewer_account_capacity_recount_locked();') >= 2, 'Account deletion must serialize and reconcile durable account capacity in the same transaction.');
    viewer_phase07_assert(str_contains($lifecycleService, 'UPDATE viewer_collection_share_tokens SET revoked_at = ?') && str_contains($migration, 'ON DELETE CASCADE'), 'Deletion must revoke creator share authority and rely on FK cascades for owned rows.');

    viewer_phase07_assert(str_contains($contentService, 'visitor_can_access_gallery_without_admin_bypass($gallery)'), 'Viewer source-image references must use the canonical no-admin-bypass gallery decision.');
    viewer_phase07_assert(str_contains($contentService, "SELECT * FROM images WHERE id = ? LIMIT 1") && str_contains($contentService, 'find_gallery($galleryId, true)'), 'Source-image authorization must reload authoritative image/gallery state.');
    viewer_phase07_assert(!str_contains($contentService, 'current_viewer()'), 'Viewer authentication must remain independent from source-media authorization.');
    viewer_phase07_assert(str_contains($contentService, 'viewer_source_image_can_reference') && str_contains($contentService, 'viewer_source_image_can_render_reference'), 'Future create/render paths must share one source-image authorization primitive.');
    viewer_phase07_assert(str_contains($galleryAccessService, 'function visitor_can_access_gallery_without_admin_bypass') && !str_contains(substr($galleryAccessService, strpos($galleryAccessService, 'function visitor_can_access_gallery_without_admin_bypass'), 1800), 'current_user()'), 'No-admin-bypass gallery helper must not consult the administrator principal.');

    $viewerController = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
    $viewerLifecycleController = (string) file_get_contents($root . '/app/controllers/viewer_lifecycle.php');
    viewer_phase07_assert(stripos($viewerController, 'viewer_change_password(') === false && stripos($viewerController, 'viewer_email_change_confirm(') === false && stripos($viewerController, 'viewer_account_delete(') === false, 'The Phase 1.0 authentication controller must remain separate from later lifecycle service orchestration.');
    viewer_phase07_assert(str_contains($viewerLifecycleController, 'viewer_change_password(') && str_contains($viewerLifecycleController, 'viewer_email_change_confirm()') && str_contains($viewerLifecycleController, 'viewer_account_delete()'), 'Phase 1.2 may expose the Phase 0.7 lifecycle only through its dedicated thin controller.');
    viewer_phase07_assert(!preg_match('/UPDATE\s+viewer_accounts|DELETE\s+FROM\s+viewer_accounts|INSERT\s+INTO\s+viewer_email_change_requests/i', $viewerLifecycleController), 'Phase 1.2 lifecycle HTTP wiring must not duplicate Phase 0.7 lifecycle SQL.');
    viewer_phase07_assert(!str_contains($contentService, 'INSERT INTO viewer_favourites') && !str_contains($contentService, 'DELETE FROM viewer_favourites'), 'The Phase 0.7 content-foundation service itself must remain policy-only even after a later phase adds favourite CRUD.');
    viewer_phase07_assert(!str_contains($contentService, 'INSERT INTO viewer_collections') && !str_contains($contentService, 'DELETE FROM viewer_collections'), 'The Phase 0.7 content-foundation module must remain route-free/policy-only even when a later phase adds collection CRUD.');
    viewer_phase07_assert(strpos($mailService, "\n    mail(") === false && strpos($mailService, 'return mail(') === false && stripos($mailService, 'stream_socket_client') === false && stripos($mailService, 'curl_') === false, 'Viewer email transport must remain unimplemented.');

    echo "Viewer Phase 0.7 lifecycle/content foundation tests passed.\n";
}
