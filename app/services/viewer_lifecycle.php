<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_lifecycle.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides dormant viewer recent-reauthentication and account-lifecycle transitions.
 *
 * Responsibilities:
 *   - Keep recent credential proof separate from ordinary viewer session possession
 *   - Change viewer passwords atomically with security-version invalidation
 *   - Stage and confirm verified viewer email changes without mail transport
 *   - Delete viewer accounts atomically while reconciling durable account capacity
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - No Phase 0.7 route exposes these functions.
 *   - Viewer lifecycle authority never satisfies administrator authorization.
 *   - Passwords and token material must never enter security-event context.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Gallery\Models\ViewerLifecycleEmailConflict;
use function Gallery\Core\viewer_identity_session_get;
use function Gallery\Core\viewer_identity_session_regenerate;
use function Gallery\Core\viewer_identity_session_set;
use function Gallery\Core\viewer_identity_session_unset;
use function Gallery\Models\viewer_lifecycle_model_account_delete;
use function Gallery\Models\viewer_lifecycle_model_account_get;
use function Gallery\Models\viewer_lifecycle_model_account_lock;
use function Gallery\Models\viewer_lifecycle_model_account_mark_deleting;
use function Gallery\Models\viewer_lifecycle_model_email_change_cancel_active;
use function Gallery\Models\viewer_lifecycle_model_email_change_consume;
use function Gallery\Models\viewer_lifecycle_model_email_change_insert;
use function Gallery\Models\viewer_lifecycle_model_email_change_inspect;
use function Gallery\Models\viewer_lifecycle_model_email_change_lock;
use function Gallery\Models\viewer_lifecycle_model_email_conflict_exists;
use function Gallery\Models\viewer_lifecycle_model_email_update;
use function Gallery\Models\viewer_lifecycle_model_password_update;
use function Gallery\Models\viewer_lifecycle_model_revoke_after_email_change;
use function Gallery\Models\viewer_lifecycle_model_revoke_after_password_change;
use function Gallery\Models\viewer_lifecycle_model_revoke_created_shares;
use function Gallery\Models\viewer_lifecycle_model_transaction;
use function Gallery\Core\now_sql;

/**
 * Return the aggregate three-state storage capability required by viewer lifecycle mutations.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_lifecycle_schema_status(): array
{
    return schema_inspection_feature('viewer.account_lifecycle', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_column('viewer_accounts', 'must_change_password'),
        schema_inspection_table('viewer_account_state'),
        schema_inspection_table('viewer_sessions'),
        schema_inspection_table('viewer_remember_tokens'),
        schema_inspection_table('viewer_password_reset_tokens'),
        schema_inspection_table('viewer_email_verification_tokens'),
        schema_inspection_table('viewer_email_change_requests'),
        schema_inspection_table('viewer_collection_share_tokens'),
        schema_inspection_table('viewer_security_events'),
    ]);
}

/**
 * Return the aggregate capability required for destructive viewer-account deletion.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_account_deletion_schema_status(): array
{
    $lifecycle = viewer_lifecycle_schema_status();
    return schema_inspection_feature('viewer.account_deletion', array_merge(
        $lifecycle['requirements'],
        [
            schema_inspection_table('viewer_favourites'),
            schema_inspection_table('viewer_collections'),
            schema_inspection_table('viewer_collection_items'),
            schema_inspection_table('viewer_passkeys'),
        ]
    ));
}

/**
 * Record one lifecycle security event without allowing diagnostics to grant or destroy authority.
 *
 * @param string $eventKey Viewer security event key.
 * @param ?int $viewerAccountId Viewer account id when known.
 * @param string $outcome Outcome category.
 * @param array $context Allowlisted low-risk event context.
 */
function viewer_lifecycle_security_event_record_best_effort(
    string $eventKey,
    ?int $viewerAccountId,
    string $outcome,
    array $context = []
): void {
    try {
        viewer_security_event_record($eventKey, $viewerAccountId, $outcome, $context);
    } catch (Throwable) {
        // Diagnostic storage failure must not change lifecycle authority decisions.
    }
}

/**
 * Return the viewer recent-reauthentication session namespace.
 *
 * @return string Viewer-only session key.
 */
function viewer_reauthentication_namespace_key(): string
{
    return VIEWER_REAUTHENTICATION_NAMESPACE;
}

/**
 * Return the viewer email-change confirmation session namespace.
 *
 * @return string Viewer-only session key.
 */
function viewer_email_change_confirmation_namespace_key(): string
{
    return VIEWER_EMAIL_CHANGE_CONFIRMATION_NAMESPACE;
}

/**
 * Return the configured short lifetime for recent viewer credential proof.
 *
 * @return int Lifetime in seconds.
 */
function viewer_reauthentication_lifetime_seconds(): int
{
    return (int) viewer_accounts_config()['viewer_reauthentication_lifetime_minutes'] * 60;
}

/**
 * Clear recent viewer credential proof without altering administrator session state.
 */
function viewer_clear_reauthentication(): void
{
    viewer_identity_session_unset(viewer_reauthentication_namespace_key());
}

/**
 * Establish short-lived recent credential proof for the current concrete viewer session.
 *
 * @param array $viewer Current viewer principal returned by current_viewer().
 */
function viewer_reauthentication_establish(array $viewer): void
{
    $accountId = (int) ($viewer['id'] ?? 0);
    $securityVersion = (int) ($viewer['security_version'] ?? 0);
    $viewerSessionId = (int) ($viewer['viewer_session_id'] ?? 0);
    if ($accountId <= 0 || $securityVersion <= 0 || $viewerSessionId <= 0) {
        throw new InvalidArgumentException('Viewer reauthentication principal is invalid.');
    }

    $now = time();
    viewer_identity_session_set(viewer_reauthentication_namespace_key(), [
        'account_id' => $accountId,
        'security_version' => $securityVersion,
        'viewer_session_id' => $viewerSessionId,
        'authenticated_at' => $now,
        'expires_at' => $now + viewer_reauthentication_lifetime_seconds(),
    ]);
}

/**
 * Return validated recent-reauthentication status bound to the current viewer session.
 *
 * @return array{valid:bool,reason:string,account_id:?int,security_version:?int,viewer_session_id:?int,expires_at:?int}
 */
function viewer_reauthentication_status(): array
{
    $failure = static fn (string $reason): array => [
        'valid' => false,
        'reason' => $reason,
        'account_id' => null,
        'security_version' => null,
        'viewer_session_id' => null,
        'expires_at' => null,
    ];

    $state = viewer_identity_session_get(viewer_reauthentication_namespace_key());
    if (!is_array($state)) {
        return $failure('missing');
    }

    $accountId = (int) ($state['account_id'] ?? 0);
    $securityVersion = (int) ($state['security_version'] ?? 0);
    $viewerSessionId = (int) ($state['viewer_session_id'] ?? 0);
    $expiresAt = (int) ($state['expires_at'] ?? 0);
    if ($accountId <= 0 || $securityVersion <= 0 || $viewerSessionId <= 0 || $expiresAt <= time()) {
        viewer_clear_reauthentication();
        return $failure($expiresAt > 0 && $expiresAt <= time() ? 'expired' : 'invalid');
    }

    $viewer = current_viewer();
    if ($viewer === null
        || (int) $viewer['id'] !== $accountId
        || (int) $viewer['security_version'] !== $securityVersion
        || (int) $viewer['viewer_session_id'] !== $viewerSessionId) {
        viewer_clear_reauthentication();
        return $failure('principal_mismatch');
    }

    return [
        'valid' => true,
        'reason' => 'recent',
        'account_id' => $accountId,
        'security_version' => $securityVersion,
        'viewer_session_id' => $viewerSessionId,
        'expires_at' => $expiresAt,
    ];
}

/**
 * Return true when a sensitive viewer operation still needs explicit credential proof.
 *
 * @return bool True when no valid recent proof exists.
 */
function viewer_recent_reauthentication_required(): bool
{
    return !viewer_reauthentication_status()['valid'];
}

/**
 * Explicitly verify the current viewer password and establish recent credential proof.
 *
 * @param string $password Current viewer password.
 * @param ?string $clientIp Explicit test/internal client IP, otherwise trusted resolver result.
 * @return array{reauthenticated:bool,reason:string}
 */
function viewer_reauthenticate_password(string $password, ?string $clientIp = null): array
{
    if (!viewer_accounts_enabled()) {
        return ['reauthenticated' => false, 'reason' => 'viewer_disabled'];
    }
    if (!schema_inspection_is_available(viewer_lifecycle_schema_status())) {
        return ['reauthenticated' => false, 'reason' => 'storage_unavailable'];
    }
    if (!viewer_security_transport_allowed()) {
        return ['reauthenticated' => false, 'reason' => 'secure_transport_required'];
    }

    $viewer = current_viewer();
    if ($viewer === null || !viewer_password_input_is_safe($password)) {
        viewer_clear_reauthentication();
        return ['reauthenticated' => false, 'reason' => 'authentication_failed'];
    }

    $resolvedIp = $clientIp === null ? request_client_ip() : request_client_ip_normalize($clientIp);
    if (!function_exists(__NAMESPACE__ . '\\viewer_login_rate_limits_consume')) {
        viewer_clear_reauthentication();
        return ['reauthenticated' => false, 'reason' => 'limiter_unavailable'];
    }
    $rateDecision = viewer_login_rate_limits_consume((string) $viewer['normalized_email'], $resolvedIp);
    if (!$rateDecision['allowed']) {
        viewer_clear_reauthentication();
        viewer_lifecycle_security_event_record_best_effort('viewer.reauthentication_failure', (int) $viewer['id'], 'denied', [
            'reason' => (string) $rateDecision['reason'],
        ]);
        return ['reauthenticated' => false, 'reason' => (string) $rateDecision['reason']];
    }

    $account = viewer_lifecycle_model_account_get((int) $viewer['id']);
    if (!$account
        || !viewer_account_can_authenticate($account)
        || (int) $account['security_version'] !== (int) $viewer['security_version']
        || !viewer_password_verify($password, (string) ($account['password_hash'] ?? ''))) {
        viewer_clear_reauthentication();
        viewer_lifecycle_security_event_record_best_effort('viewer.reauthentication_failure', (int) $viewer['id'], 'denied', [
            'reason' => 'authentication_failed',
        ]);
        return ['reauthenticated' => false, 'reason' => 'authentication_failed'];
    }

    viewer_reauthentication_establish($viewer);
    viewer_lifecycle_security_event_record_best_effort('viewer.reauthentication_success', (int) $viewer['id'], 'success', [
        'security_version' => (int) $viewer['security_version'],
    ]);
    return ['reauthenticated' => true, 'reason' => 'reauthenticated'];
}

/**
 * Change the current viewer password and invalidate every prior viewer authentication authority.
 *
 * When recent reauthentication is absent, a supplied current password may satisfy the explicit
 * credential check. A successful transition deliberately logs the viewer out.
 *
 * @param string $newPassword New viewer password.
 * @param ?string $currentPassword Optional current password for inline explicit reauthentication.
 * @return array{changed:bool,reason:string}
 */
function viewer_change_password(string $newPassword, ?string $currentPassword = null): array
{
    if (!viewer_accounts_enabled()) {
        return ['changed' => false, 'reason' => 'viewer_disabled'];
    }
    if (!schema_inspection_is_available(viewer_lifecycle_schema_status())) {
        return ['changed' => false, 'reason' => 'storage_unavailable'];
    }
    if (!viewer_security_transport_allowed()) {
        return ['changed' => false, 'reason' => 'secure_transport_required'];
    }

    $viewer = current_viewer();
    if ($viewer === null) {
        return ['changed' => false, 'reason' => 'authentication_required'];
    }
    if (viewer_recent_reauthentication_required() && $currentPassword !== null) {
        viewer_reauthenticate_password($currentPassword);
    }
    if (viewer_recent_reauthentication_required()) {
        return ['changed' => false, 'reason' => 'reauthentication_required'];
    }
    if (!viewer_password_input_is_acceptable($newPassword)) {
        return ['changed' => false, 'reason' => 'password_policy'];
    }

    $result = viewer_lifecycle_model_transaction(static function () use ($viewer, $newPassword): array {
        $account = viewer_lifecycle_model_account_lock((int) $viewer['id']);
        if (!$account
            || !viewer_account_can_authenticate($account)
            || (int) $account['security_version'] !== (int) $viewer['security_version']) {
            return ['changed' => false, 'reason' => 'authentication_state_changed'];
        }

        $now = now_sql();
        $newSecurityVersion = (int) $account['security_version'] + 1;
        viewer_lifecycle_model_password_update(
            (int) $account['id'],
            (int) $account['security_version'],
            $newSecurityVersion,
            viewer_password_hash($newPassword),
            $now
        );
        viewer_lifecycle_model_revoke_after_password_change((int) $account['id'], $now);
        viewer_security_event_record('viewer.password_changed', (int) $account['id'], 'success', [
            'security_version' => $newSecurityVersion,
        ]);
        return ['changed' => true, 'reason' => 'password_changed'];
    });

    if ($result['changed'] || $result['reason'] === 'authentication_state_changed') {
        viewer_session_clear();
    }
    return $result;
}

/**
 * Return the configured lifetime for one pending email-change verification request.
 *
 * @return int Lifetime in seconds.
 */
function viewer_email_change_request_lifetime_seconds(): int
{
    return (int) viewer_accounts_config()['email_change_request_lifetime_minutes'] * 60;
}

/**
 * Return the short server-side confirmation lifetime after token inspection.
 *
 * @return int Lifetime in seconds.
 */
function viewer_email_change_confirmation_lifetime_seconds(): int
{
    return (int) viewer_accounts_config()['email_change_confirmation_lifetime_minutes'] * 60;
}

/**
 * Build an integrity context for email-change confirmation authority.
 *
 * @param int $requestId Email-change request id.
 * @param int $accountId Viewer account id.
 * @param int $securityVersion Security version captured by the request.
 * @param string $expiresAt SQL expiration timestamp.
 * @param string $verificationTokenHash Stored verification-token hash.
 * @return string HMAC-shaped context or an empty string for invalid input.
 */
function viewer_email_change_confirmation_context(
    int $requestId,
    int $accountId,
    int $securityVersion,
    string $expiresAt,
    string $verificationTokenHash
): string {
    if ($requestId <= 0 || $accountId <= 0 || $securityVersion <= 0 || $expiresAt === '' || $verificationTokenHash === '') {
        return '';
    }
    return viewer_security_fingerprint(
        'viewer-email-change-confirmation',
        implode(':', [$requestId, $accountId, $securityVersion, $expiresAt, $verificationTokenHash])
    );
}

/**
 * Clear server-side email-change confirmation authority only.
 */
function viewer_email_change_confirmation_clear(): void
{
    viewer_identity_session_unset(viewer_email_change_confirmation_namespace_key());
}

/**
 * Start a staged verified email change without sending email.
 *
 * The returned plaintext token is intended only for a future mail-intent caller. Storage receives
 * only its hash. Existing pending requests for this account are superseded transactionally.
 *
 * @param string $newEmail Proposed new verified email address.
 * @param ?string $clientIp Explicit test/internal client IP, otherwise trusted resolver result.
 * @return array{requested:bool,reason:string,request_id:?int,selector:?string,verification_token:?string,expires_at:?string}
 */
function viewer_email_change_request_start(string $newEmail, ?string $clientIp = null): array
{
    $failure = static fn (string $reason): array => [
        'requested' => false,
        'reason' => $reason,
        'request_id' => null,
        'selector' => null,
        'verification_token' => null,
        'expires_at' => null,
    ];

    if (!viewer_accounts_enabled()) {
        return $failure('viewer_disabled');
    }
    if (!schema_inspection_is_available(viewer_lifecycle_schema_status())) {
        return $failure('storage_unavailable');
    }
    if (!viewer_security_transport_allowed()) {
        return $failure('secure_transport_required');
    }

    $viewer = current_viewer();
    if ($viewer === null) {
        return $failure('authentication_required');
    }
    if (viewer_recent_reauthentication_required()) {
        return $failure('reauthentication_required');
    }

    $normalizedEmail = viewer_email_normalize($newEmail);
    if ($normalizedEmail === null) {
        return $failure('invalid_email');
    }
    if (hash_equals((string) $viewer['normalized_email'], $normalizedEmail)) {
        return $failure('email_unchanged');
    }
    if (viewer_lifecycle_model_email_conflict_exists($normalizedEmail, (int) $viewer['id'])) {
        return $failure('email_unavailable');
    }

    $mailDecision = viewer_mail_authorize_send(VIEWER_MAIL_ACTION_EMAIL_CHANGE, $normalizedEmail, $clientIp);
    if (!$mailDecision['allowed']) {
        return $failure($mailDecision['reason']);
    }

    return viewer_lifecycle_model_transaction(static function () use ($viewer, $newEmail, $normalizedEmail, $failure): array {
        $account = viewer_lifecycle_model_account_lock((int) $viewer['id']);
        if (!$account
            || !viewer_account_can_authenticate($account)
            || (int) $account['security_version'] !== (int) $viewer['security_version']) {
            return $failure('authentication_state_changed');
        }
        if (viewer_lifecycle_model_email_conflict_exists($normalizedEmail, (int) $account['id'])) {
            return $failure('email_unavailable');
        }

        $now = now_sql();
        viewer_lifecycle_model_email_change_cancel_active((int) $account['id'], $now);
        $selector = security_token_selector_generate(18);
        $verificationToken = security_opaque_token_generate(32);
        $expiresAt = date('Y-m-d H:i:s', time() + viewer_email_change_request_lifetime_seconds());
        $requestId = viewer_lifecycle_model_email_change_insert(
            (int) $account['id'],
            trim($newEmail),
            $normalizedEmail,
            $selector,
            security_authority_token_hash($verificationToken),
            (int) $account['security_version'],
            $now,
            $expiresAt
        );

        viewer_email_change_confirmation_clear();
        viewer_security_event_record('viewer.email_change_requested', (int) $account['id'], 'success', [
            'security_version' => (int) $account['security_version'],
        ]);
        return [
            'requested' => true,
            'reason' => 'email_change_requested',
            'request_id' => $requestId,
            'selector' => $selector,
            'verification_token' => $verificationToken,
            'expires_at' => $expiresAt,
        ];
    });
}

/**
 * Inspect one email-change verification token without consuming it or changing the account.
 *
 * @param string $verificationToken Plaintext verification token from a future link.
 * @return ?array{request_id:int,account_id:int,security_version:int,new_email:string,expires_at:string,context:string}
 */
function viewer_email_change_request_inspect(string $verificationToken): ?array
{
    if (!viewer_accounts_enabled()
        || !schema_inspection_is_available(viewer_lifecycle_schema_status())
        || !viewer_security_transport_allowed()
        || $verificationToken === ''
        || strlen($verificationToken) > 512) {
        return null;
    }

    $row = viewer_lifecycle_model_email_change_inspect(security_authority_token_hash($verificationToken));
    $expiry = $row ? strtotime((string) ($row['expires_at'] ?? '')) : false;
    $account = $row ? [
        'status' => $row['account_status'] ?? '',
        'password_hash' => $row['password_hash'] ?? '',
        'email_verified_at' => $row['email_verified_at'] ?? null,
    ] : [];
    if (!$row
        || !viewer_account_can_authenticate($account)
        || !empty($row['consumed_at'])
        || !empty($row['cancelled_at'])
        || $expiry === false
        || $expiry <= time()
        || (int) $row['security_version'] !== (int) $row['account_security_version']) {
        return null;
    }

    $context = viewer_email_change_confirmation_context(
        (int) $row['id'],
        (int) $row['viewer_account_id'],
        (int) $row['security_version'],
        (string) $row['expires_at'],
        (string) $row['verification_token_hash']
    );
    if ($context === '') {
        return null;
    }

    return [
        'request_id' => (int) $row['id'],
        'account_id' => (int) $row['viewer_account_id'],
        'security_version' => (int) $row['security_version'],
        'new_email' => (string) $row['new_email'],
        'expires_at' => (string) $row['expires_at'],
        'context' => $context,
    ];
}

/**
 * Convert a valid email-change token into short-lived server-side confirmation authority.
 *
 * Token inspection remains non-consuming. Only viewer_email_change_confirm() mutates the account.
 *
 * @param string $verificationToken Plaintext verification token.
 * @return bool True when confirmation authority was established for the current viewer.
 */
function viewer_email_change_authorize(string $verificationToken): bool
{
    $viewer = current_viewer();
    $inspected = viewer_email_change_request_inspect($verificationToken);
    if ($viewer === null
        || $inspected === null
        || (int) $viewer['id'] !== $inspected['account_id']
        || (int) $viewer['security_version'] !== $inspected['security_version']) {
        viewer_email_change_confirmation_clear();
        return false;
    }

    $tokenExpiry = strtotime($inspected['expires_at']);
    if ($tokenExpiry === false || $tokenExpiry <= time()) {
        viewer_email_change_confirmation_clear();
        return false;
    }

    if (!viewer_identity_session_regenerate()) {
        throw new RuntimeException('Viewer email-change session rotation failed.');
    }
    viewer_identity_session_set(viewer_email_change_confirmation_namespace_key(), [
        'request_id' => $inspected['request_id'],
        'account_id' => $inspected['account_id'],
        'security_version' => $inspected['security_version'],
        'token_expires_at' => $inspected['expires_at'],
        'expires_at' => min($tokenExpiry, time() + viewer_email_change_confirmation_lifetime_seconds()),
        'context' => $inspected['context'],
    ]);
    return true;
}

/**
 * Parse current server-side viewer email-change confirmation authority.
 *
 * @return ?array{request_id:int,account_id:int,security_version:int,token_expires_at:string,expires_at:int,context:string}
 */
function viewer_email_change_confirmation_state(): ?array
{
    $state = viewer_identity_session_get(viewer_email_change_confirmation_namespace_key());
    if (!is_array($state)) {
        return null;
    }

    $requestId = (int) ($state['request_id'] ?? 0);
    $accountId = (int) ($state['account_id'] ?? 0);
    $securityVersion = (int) ($state['security_version'] ?? 0);
    $tokenExpiresAt = (string) ($state['token_expires_at'] ?? '');
    $expiresAt = (int) ($state['expires_at'] ?? 0);
    $context = (string) ($state['context'] ?? '');
    if ($requestId <= 0
        || $accountId <= 0
        || $securityVersion <= 0
        || $tokenExpiresAt === ''
        || $expiresAt <= time()
        || preg_match('/^[a-f0-9]{64}$/D', $context) !== 1) {
        viewer_email_change_confirmation_clear();
        return null;
    }

    return [
        'request_id' => $requestId,
        'account_id' => $accountId,
        'security_version' => $securityVersion,
        'token_expires_at' => $tokenExpiresAt,
        'expires_at' => $expiresAt,
        'context' => $context,
    ];
}

/**
 * Atomically switch the current viewer to a previously verified staged email address.
 *
 * @return array{changed:bool,reason:string}
 */
function viewer_email_change_confirm(): array
{
    if (!viewer_accounts_enabled()) {
        return ['changed' => false, 'reason' => 'viewer_disabled'];
    }
    if (!schema_inspection_is_available(viewer_lifecycle_schema_status())) {
        return ['changed' => false, 'reason' => 'storage_unavailable'];
    }
    if (!viewer_security_transport_allowed()) {
        return ['changed' => false, 'reason' => 'secure_transport_required'];
    }

    $viewer = current_viewer();
    if ($viewer === null) {
        return ['changed' => false, 'reason' => 'authentication_required'];
    }
    if (viewer_recent_reauthentication_required()) {
        return ['changed' => false, 'reason' => 'reauthentication_required'];
    }
    $state = viewer_email_change_confirmation_state();
    if ($state === null
        || $state['account_id'] !== (int) $viewer['id']
        || $state['security_version'] !== (int) $viewer['security_version']) {
        return ['changed' => false, 'reason' => 'confirmation_state_invalid'];
    }

    try {
        $result = viewer_lifecycle_model_transaction(static function () use ($state): array {
            $account = viewer_lifecycle_model_account_lock($state['account_id']);
            if (!$account
                || !viewer_account_can_authenticate($account)
                || (int) $account['security_version'] !== $state['security_version']) {
                return ['changed' => false, 'reason' => 'confirmation_state_invalid', 'clear_session' => true];
            }

            $request = viewer_lifecycle_model_email_change_lock($state['request_id']);
            $expiry = $request ? strtotime((string) ($request['expires_at'] ?? '')) : false;
            $context = $request ? viewer_email_change_confirmation_context(
                (int) $request['id'],
                (int) $request['viewer_account_id'],
                (int) $request['security_version'],
                (string) $request['expires_at'],
                (string) $request['verification_token_hash']
            ) : '';
            if (!$request
                || (int) $request['viewer_account_id'] !== $state['account_id']
                || (int) $request['security_version'] !== $state['security_version']
                || !empty($request['consumed_at'])
                || !empty($request['cancelled_at'])
                || $expiry === false
                || $expiry <= time()
                || $context === ''
                || !hash_equals($context, $state['context'])) {
                return ['changed' => false, 'reason' => 'confirmation_state_invalid', 'clear_confirmation' => true];
            }
            if (viewer_lifecycle_model_email_conflict_exists((string) $request['normalized_new_email'], (int) $account['id'])) {
                return ['changed' => false, 'reason' => 'email_unavailable', 'clear_confirmation' => true];
            }

            $now = now_sql();
            $newSecurityVersion = (int) $account['security_version'] + 1;
            viewer_lifecycle_model_email_update(
                (int) $account['id'],
                (int) $account['security_version'],
                $newSecurityVersion,
                (string) $request['new_email'],
                (string) $request['normalized_new_email'],
                $now
            );
            viewer_lifecycle_model_email_change_consume((int) $request['id'], $now);
            viewer_lifecycle_model_email_change_cancel_active((int) $account['id'], $now, (int) $request['id']);
            viewer_lifecycle_model_revoke_after_email_change((int) $account['id'], $now);
            viewer_security_event_record('viewer.email_change_confirmed', (int) $account['id'], 'success', [
                'security_version' => $newSecurityVersion,
            ]);
            return ['changed' => true, 'reason' => 'email_changed'];
        });
    } catch (ViewerLifecycleEmailConflict) {
        viewer_email_change_confirmation_clear();
        return ['changed' => false, 'reason' => 'email_unavailable'];
    }

    if (!empty($result['clear_confirmation'])) {
        viewer_email_change_confirmation_clear();
    }
    if (!empty($result['clear_session']) || !empty($result['changed'])) {
        viewer_session_clear();
    }
    unset($result['clear_confirmation'], $result['clear_session']);
    return $result;
}

/**
 * Permanently delete the currently authenticated viewer account and all viewer-owned authority.
 *
 * Foreign-key cascades remove sessions, remember/reset/verification/email-change credentials,
 * favourites, collections, collection items, and shares for owned collections. Shares created
 * by this account for any other collection are explicitly revoked before the creator FK is nulled.
 * Existing viewer security events are retained pseudonymously through ON DELETE SET NULL.
 *
 * @return array{deleted:bool,reason:string}
 */
function viewer_account_delete(): array
{
    if (!viewer_accounts_enabled()) {
        return ['deleted' => false, 'reason' => 'viewer_disabled'];
    }
    if (!schema_inspection_is_available(viewer_account_deletion_schema_status())) {
        return ['deleted' => false, 'reason' => 'storage_unavailable'];
    }
    if (!viewer_security_transport_allowed()) {
        return ['deleted' => false, 'reason' => 'secure_transport_required'];
    }

    $viewer = current_viewer();
    if ($viewer === null) {
        return ['deleted' => false, 'reason' => 'authentication_required'];
    }
    if (viewer_recent_reauthentication_required()) {
        return ['deleted' => false, 'reason' => 'reauthentication_required'];
    }

    $result = viewer_lifecycle_model_transaction(static function () use ($viewer): array {
        $account = viewer_lifecycle_model_account_lock((int) $viewer['id']);
        if (!$account
            || !viewer_account_can_authenticate($account)
            || (int) $account['security_version'] !== (int) $viewer['security_version']) {
            return ['deleted' => false, 'reason' => 'authentication_state_changed'];
        }

        viewer_account_capacity_lock();
        viewer_account_capacity_recount_locked();
        $now = now_sql();
        $invalidatedSecurityVersion = (int) $account['security_version'] + 1;
        viewer_lifecycle_model_account_mark_deleting(
            (int) $account['id'],
            (int) $account['security_version'],
            $invalidatedSecurityVersion,
            $now
        );
        viewer_lifecycle_model_revoke_created_shares((int) $account['id'], $now);
        viewer_security_event_record('viewer.account_deleted', (int) $account['id'], 'success', [
            'security_version' => $invalidatedSecurityVersion,
        ]);
        viewer_lifecycle_model_account_delete((int) $account['id']);
        viewer_account_capacity_recount_locked();
        return ['deleted' => true, 'reason' => 'account_deleted'];
    });

    if ($result['deleted'] || $result['reason'] === 'authentication_state_changed') {
        viewer_session_clear();
    }
    return $result;
}
