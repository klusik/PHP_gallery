<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_authentication.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides viewer password authentication, forced first-login password replacement, and password-reset orchestration.
 *
 * Responsibilities:
 *   - Throttle viewer password authentication before native password verification
 *   - Keep authentication results enumeration-resistant while retaining internal diagnostic reasons
 *   - Establish only the independent viewer session principal after successful password authentication
 *   - Hold administrator-created accounts in limited pre-authentication state until their temporary password is replaced
 *   - Keep password-reset token inspection scanner-safe and non-consuming
 *   - Bind final password reset to short-lived server-side pre-authentication state
 *   - Rotate security_version and revoke viewer authentication authority atomically after reset
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *   - No function in this file is registered as an HTTP route in Phase 0.6.
 *   - No viewer email transport is implemented here.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

const VIEWER_PASSWORD_RESET_NAMESPACE = 'viewer_password_reset';
const VIEWER_FIRST_LOGIN_PASSWORD_NAMESPACE = 'viewer_first_login_password';
const VIEWER_FIRST_LOGIN_PASSWORD_LIFETIME_SECONDS = 900;

/**
 * Return the generic future-public failure result for viewer password authentication.
 *
 * @return string Enumeration-resistant external result code.
 */
function viewer_authentication_public_failure_code(): string
{
    return 'authentication_failed';
}

/**
 * Return the fixed dummy password hash used for unknown viewer identifiers.
 *
 * The hash is intentionally precomputed so an attacker cannot force password_hash() work for
 * every unknown account request. Native password_verify() still performs a real slow-hash check.
 *
 * @return string Encoded native password hash.
 */
function viewer_authentication_dummy_password_hash(): string
{
    if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)) {
        return '$argon2id$v=19$m=65536,t=4,p=1$UUouUEg3Y2dDa09CeVFhRA$CuogUm2oI6AP5xRNgVDGb4flQhYz3vLGqUO/tsxv+MM';
    }
    return '$2y$10$LfGo3R.NEOiqJKb9ne7VvOga/pjHejPp1BJzJr/Bt0X52ohA.OET.';
}

/**
 * Consume all low-cost viewer login abuse budgets before password verification.
 *
 * @param string $normalizedEmail Normalized viewer identifier.
 * @param string $clientIp Trusted exact client IP.
 * @return array{allowed:bool,reason:string,retry_after_seconds:int,bucket:string} Admission result.
 */
function viewer_login_rate_limits_consume(string $normalizedEmail, string $clientIp): array
{
    if ($clientIp === '') {
        return ['allowed' => false, 'reason' => 'client_ip_unavailable', 'retry_after_seconds' => 0, 'bucket' => ''];
    }

    $plan = [
        ['bucket' => 'viewer_login_ip', 'kind' => 'ip', 'subject' => $clientIp],
        ['bucket' => 'viewer_login_subnet', 'kind' => 'subnet', 'subject' => $clientIp],
        ['bucket' => 'viewer_login_identifier', 'kind' => 'identifier', 'subject' => $normalizedEmail],
        ['bucket' => 'viewer_login_global', 'kind' => 'global', 'subject' => 'global'],
    ];
    try {
        foreach ($plan as $dimension) {
            $decision = viewer_rate_limit_consume($dimension['bucket'], $dimension['kind'], $dimension['subject']);
            if (!$decision['allowed']) {
                return [
                    'allowed' => false,
                    'reason' => 'rate_limited',
                    'retry_after_seconds' => (int) $decision['retry_after_seconds'],
                    'bucket' => $dimension['bucket'],
                ];
            }
        }
    } catch (Throwable) {
        return ['allowed' => false, 'reason' => 'limiter_unavailable', 'retry_after_seconds' => 0, 'bucket' => ''];
    }
    return ['allowed' => true, 'reason' => 'ok', 'retry_after_seconds' => 0, 'bucket' => ''];
}

/**
 * Record a non-authorizing viewer security event without changing a denial/result if logging fails.
 *
 * Successful authority-changing transitions record events inside their database transaction and
 * allow an insert failure to roll the transition back. Failure-only diagnostics are best-effort.
 *
 * @param string $eventKey Stable viewer event key.
 * @param ?int $viewerAccountId Known viewer account id or null.
 * @param string $outcome Short outcome.
 * @param array $context Allowlisted event context.
 */
function viewer_security_event_record_best_effort(
    string $eventKey,
    ?int $viewerAccountId = null,
    string $outcome = '',
    array $context = []
): void {
    try {
        viewer_security_event_record($eventKey, $viewerAccountId, $outcome, $context);
    } catch (Throwable) {
        // A diagnostic failure must never grant authority or change a generic denial result.
    }
}

/**
 * Return the isolated PHP-session key used for forced first-login password replacement.
 *
 * This namespace is deliberately not viewer_auth. Possession proves only that the temporary
 * password was verified recently and never grants favourites, collections, or gallery access.
 *
 * @return string First-login pre-authentication namespace.
 */
function viewer_first_login_password_namespace_key(): string
{
    return VIEWER_FIRST_LOGIN_PASSWORD_NAMESPACE;
}

/**
 * Clear only forced first-login password replacement authority.
 */
function viewer_first_login_password_state_clear(): void
{
    unset($_SESSION[viewer_first_login_password_namespace_key()]);
}

/**
 * Derive an integrity context for forced first-login password replacement state.
 *
 * Binding the state to the current password hash and security version invalidates it if any
 * concurrent credential/security transition changes the account before replacement completes.
 *
 * @param int $viewerAccountId Viewer account id.
 * @param int $securityVersion Current account security version.
 * @param string $passwordHash Current administrator-issued temporary password hash.
 * @return string HMAC integrity context.
 */
function viewer_first_login_password_context(int $viewerAccountId, int $securityVersion, string $passwordHash): string
{
    if ($viewerAccountId <= 0 || $securityVersion <= 0 || $passwordHash === '') {
        return '';
    }
    return viewer_security_fingerprint(
        'viewer-first-login-password-state',
        $viewerAccountId . "\0" . $securityVersion . "\0" . $passwordHash
    );
}

/**
 * Establish limited first-login authority after the temporary password has been verified.
 *
 * Normal viewer session state is explicitly cleared first. Administrator session keys are not
 * touched. The resulting state is short-lived and is revalidated against the account row on every
 * request before the forced password-change form is allowed to operate.
 *
 * @param array $account Locked viewer account row.
 */
function viewer_first_login_password_establish(array $account): void
{
    if (!viewer_accounts_enabled() || !viewer_auth_storage_available() || !viewer_security_transport_allowed()) {
        throw new RuntimeException('Viewer first-login password replacement is unavailable.');
    }
    if (!viewer_account_can_authenticate($account) || !viewer_account_requires_password_change($account)) {
        throw new RuntimeException('Viewer first-login password replacement is unavailable.');
    }

    $accountId = (int) ($account['id'] ?? 0);
    $securityVersion = (int) ($account['security_version'] ?? 0);
    $passwordHash = (string) ($account['password_hash'] ?? '');
    $context = viewer_first_login_password_context($accountId, $securityVersion, $passwordHash);
    if ($context === '') {
        throw new RuntimeException('Viewer first-login password replacement state is invalid.');
    }

    viewer_session_clear();
    viewer_password_reset_state_clear();
    if (session_status() === PHP_SESSION_ACTIVE && !session_regenerate_id(true)) {
        throw new RuntimeException('Viewer first-login session rotation failed.');
    }
    $_SESSION[viewer_first_login_password_namespace_key()] = [
        'account_id' => $accountId,
        'security_version' => $securityVersion,
        'expires_at' => time() + VIEWER_FIRST_LOGIN_PASSWORD_LIFETIME_SECONDS,
        'context' => $context,
    ];
}

/**
 * Return currently valid forced first-login password replacement authority.
 *
 * @return ?array{account_id:int,security_version:int,expires_at:int,context:string,email:string} Valid state or null.
 */
function viewer_first_login_password_state(): ?array
{
    $state = $_SESSION[viewer_first_login_password_namespace_key()] ?? null;
    if (!is_array($state)
        || !viewer_accounts_enabled()
        || !viewer_auth_storage_available()
        || !viewer_security_transport_allowed()) {
        viewer_first_login_password_state_clear();
        return null;
    }

    $accountId = (int) ($state['account_id'] ?? 0);
    $securityVersion = (int) ($state['security_version'] ?? 0);
    $expiresAt = (int) ($state['expires_at'] ?? 0);
    $context = (string) ($state['context'] ?? '');
    if ($accountId <= 0
        || $securityVersion <= 0
        || $expiresAt < time()
        || preg_match('/^[a-f0-9]{64}$/D', $context) !== 1) {
        viewer_first_login_password_state_clear();
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, email, password_hash, must_change_password, status, security_version, email_verified_at '
            . 'FROM viewer_accounts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $account = $stmt->fetch();
        $expected = $account ? viewer_first_login_password_context(
            (int) $account['id'],
            (int) $account['security_version'],
            (string) $account['password_hash']
        ) : '';
        if (!$account
            || !viewer_account_can_authenticate($account)
            || !viewer_account_requires_password_change($account)
            || (int) $account['security_version'] !== $securityVersion
            || $expected === ''
            || !hash_equals($expected, $context)) {
            viewer_first_login_password_state_clear();
            return null;
        }
        return [
            'account_id' => $accountId,
            'security_version' => $securityVersion,
            'expires_at' => $expiresAt,
            'context' => $context,
            'email' => (string) $account['email'],
        ];
    } catch (Throwable) {
        viewer_first_login_password_state_clear();
        return null;
    }
}

/**
 * Replace an administrator-issued temporary password and establish the normal viewer principal.
 *
 * The new password must differ from the temporary credential. The account security version is
 * incremented and every older viewer session/remember/reset/email-change authority is revoked
 * before a fresh normal viewer session is inserted. The must-change flag is cleared atomically.
 *
 * @param string $newPassword Replacement viewer password.
 * @return array{changed:bool,reason:string} Internal transition result.
 */
function viewer_first_login_password_complete(string $newPassword): array
{
    if (!viewer_accounts_enabled()) {
        return ['changed' => false, 'reason' => 'viewer_disabled'];
    }
    if (!viewer_auth_storage_available()) {
        return ['changed' => false, 'reason' => 'storage_unavailable'];
    }
    if (!viewer_security_transport_allowed()) {
        return ['changed' => false, 'reason' => 'secure_transport_required'];
    }
    $state = viewer_first_login_password_state();
    if ($state === null) {
        return ['changed' => false, 'reason' => 'first_login_state_invalid'];
    }
    if (!viewer_password_input_is_acceptable($newPassword)) {
        return ['changed' => false, 'reason' => 'password_policy'];
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $accountStmt = $pdo->prepare('SELECT * FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
        $accountStmt->execute([$state['account_id']]);
        $account = $accountStmt->fetch();
        $expected = $account ? viewer_first_login_password_context(
            (int) $account['id'],
            (int) $account['security_version'],
            (string) $account['password_hash']
        ) : '';
        if (!$account
            || !viewer_account_can_authenticate($account)
            || !viewer_account_requires_password_change($account)
            || (int) $account['security_version'] !== $state['security_version']
            || $expected === ''
            || !hash_equals($expected, $state['context'])) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            viewer_first_login_password_state_clear();
            return ['changed' => false, 'reason' => 'first_login_state_invalid'];
        }
        if (viewer_password_verify($newPassword, (string) $account['password_hash'])) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['changed' => false, 'reason' => 'password_reuse'];
        }

        $now = now_sql();
        $newSecurityVersion = (int) $account['security_version'] + 1;
        $newPasswordHash = viewer_password_hash($newPassword);
        $update = $pdo->prepare(
            'UPDATE viewer_accounts SET password_hash = ?, must_change_password = 0, password_changed_at = ?, '
            . 'security_version = ?, updated_at = ? WHERE id = ? AND security_version = ? AND must_change_password = 1'
        );
        $update->execute([
            $newPasswordHash,
            $now,
            $newSecurityVersion,
            $now,
            (int) $account['id'],
            (int) $account['security_version'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Viewer first-login password replacement lost the account state race.');
        }

        $pdo->prepare('UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')
            ->execute([$now, (int) $account['id']]);
        $pdo->prepare('UPDATE viewer_email_verification_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')
            ->execute([$now, (int) $account['id']]);
        $pdo->prepare('UPDATE viewer_email_change_requests SET cancelled_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND cancelled_at IS NULL')
            ->execute([$now, (int) $account['id']]);
        $pdo->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')
            ->execute([$now, (int) $account['id']]);
        $pdo->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')
            ->execute([$now, (int) $account['id']]);

        viewer_security_event_record('viewer.first_login_password_changed', (int) $account['id'], 'success', [
            'security_version' => $newSecurityVersion,
        ]);

        $account['password_hash'] = $newPasswordHash;
        $account['must_change_password'] = 0;
        $account['password_changed_at'] = $now;
        $account['security_version'] = $newSecurityVersion;
        $account['updated_at'] = $now;
        viewer_first_login_password_state_clear();
        viewer_session_establish($account);
        if (function_exists(__NAMESPACE__ . '\\viewer_reauthentication_establish')) {
            $viewer = current_viewer();
            if ($viewer !== null) {
                viewer_reauthentication_establish($viewer);
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return ['changed' => true, 'reason' => 'password_changed'];
    } catch (Throwable $exception) {
        viewer_first_login_password_state_clear();
        viewer_session_clear();
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Authenticate one viewer password without exposing any HTTP behavior.
 *
 * Rate-limit admission occurs before account lookup/password verification. Unknown accounts,
 * wrong passwords, inactive states, and otherwise ineligible accounts share the same future
 * public failure code. Successful authentication establishes only viewer_auth.
 *
 * @param string $email Submitted viewer email identifier.
 * @param string $password Submitted plaintext password.
 * @param ?string $clientIp Explicit test/internal client IP, otherwise trusted resolver result.
 * @return array{authenticated:bool,reason:string,public_result:string,account_id:?int,retry_after_seconds:int,password_change_required:bool}
 */
function viewer_authenticate_password(string $email, string $password, ?string $clientIp = null): array
{
    $publicFailure = viewer_authentication_public_failure_code();
    $failure = static fn (string $reason, int $retry = 0): array => [
        'authenticated' => false,
        'reason' => $reason,
        'public_result' => $publicFailure,
        'account_id' => null,
        'retry_after_seconds' => $retry,
        'password_change_required' => false,
    ];

    if (!viewer_accounts_enabled()) {
        return $failure('viewer_disabled');
    }
    if (!viewer_auth_storage_available()) {
        return $failure('storage_unavailable');
    }
    if (!viewer_security_transport_allowed()) {
        return $failure('secure_transport_required');
    }

    $normalized = viewer_email_normalize($email);
    if ($normalized === null || !viewer_password_input_is_safe($password)) {
        return $failure('invalid_input');
    }
    $resolvedIp = $clientIp === null ? request_client_ip() : request_client_ip_normalize($clientIp);

    // Intentionally before account lookup and password_verify(): keep slow hashing behind cheap budgets.
    $rateDecision = viewer_login_rate_limits_consume($normalized, $resolvedIp);
    if (!$rateDecision['allowed']) {
        return $failure($rateDecision['reason'], (int) $rateDecision['retry_after_seconds']);
    }

    $accountStmt = db()->prepare('SELECT * FROM viewer_accounts WHERE normalized_email = ? LIMIT 1');
    $accountStmt->execute([$normalized]);
    $account = $accountStmt->fetch();
    $passwordHash = $account ? (string) ($account['password_hash'] ?? '') : viewer_authentication_dummy_password_hash();
    if ($passwordHash === '') {
        $passwordHash = viewer_authentication_dummy_password_hash();
    }

    $passwordValid = viewer_password_verify($password, $passwordHash);
    $eligible = $account
        && $passwordValid
        && viewer_password_input_is_acceptable($password)
        && viewer_account_can_authenticate($account);
    if (!$eligible) {
        viewer_security_event_record_best_effort(
            'viewer.login_failure',
            $account ? (int) $account['id'] : null,
            'denied',
            ['reason' => 'authentication_failed']
        );
        return $failure('authentication_failed');
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lockStmt = $pdo->prepare('SELECT * FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
        $lockStmt->execute([(int) $account['id']]);
        $lockedAccount = $lockStmt->fetch();
        if (!$lockedAccount
            || !viewer_account_can_authenticate($lockedAccount)
            || (int) $lockedAccount['security_version'] !== (int) $account['security_version']
            || !hash_equals((string) $lockedAccount['password_hash'], (string) $account['password_hash'])) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $failure('authentication_state_changed');
        }

        $now = now_sql();
        $newPasswordHash = null;
        if (viewer_password_needs_rehash((string) $lockedAccount['password_hash'])) {
            $newPasswordHash = viewer_password_hash($password);
        }
        if ($newPasswordHash !== null) {
            $pdo->prepare('UPDATE viewer_accounts SET password_hash = ?, last_login_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$newPasswordHash, $now, $now, (int) $lockedAccount['id']]);
            $lockedAccount['password_hash'] = $newPasswordHash;
        } else {
            $pdo->prepare('UPDATE viewer_accounts SET last_login_at = ?, updated_at = ? WHERE id = ?')
                ->execute([$now, $now, (int) $lockedAccount['id']]);
        }

        if (viewer_account_requires_password_change($lockedAccount)) {
            viewer_first_login_password_establish($lockedAccount);
            viewer_security_event_record('viewer.login_password_change_required', (int) $lockedAccount['id'], 'success', [
                'security_version' => (int) $lockedAccount['security_version'],
            ]);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'authenticated' => true,
                'reason' => 'password_change_required',
                'public_result' => 'authenticated',
                'account_id' => (int) $lockedAccount['id'],
                'retry_after_seconds' => 0,
                'password_change_required' => true,
            ];
        }

        viewer_first_login_password_state_clear();
        viewer_session_establish($lockedAccount);
        if (function_exists(__NAMESPACE__ . '\viewer_reauthentication_establish')) {
            $authenticatedViewer = current_viewer();
            if ($authenticatedViewer !== null) {
                viewer_reauthentication_establish($authenticatedViewer);
            }
        }
        viewer_security_event_record('viewer.login_success', (int) $lockedAccount['id'], 'success', [
            'security_version' => (int) $lockedAccount['security_version'],
        ]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return [
            'authenticated' => true,
            'reason' => 'authenticated',
            'public_result' => 'authenticated',
            'account_id' => (int) $lockedAccount['id'],
            'retry_after_seconds' => 0,
            'password_change_required' => false,
        ];
    } catch (Throwable $exception) {
        viewer_first_login_password_state_clear();
        viewer_session_clear();
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return the viewer password-reset pre-authentication PHP-session namespace key.
 *
 * @return string Viewer reset pre-auth namespace.
 */
function viewer_password_reset_namespace_key(): string
{
    return VIEWER_PASSWORD_RESET_NAMESPACE;
}

/**
 * Clear only viewer password-reset pre-authentication state.
 */
function viewer_password_reset_state_clear(): void
{
    unset($_SESSION[viewer_password_reset_namespace_key()]);
}

/**
 * Return the lifetime of server-side viewer password-reset authorization state.
 *
 * @return int Lifetime in seconds.
 */
function viewer_password_reset_authorization_lifetime_seconds(): int
{
    return (int) viewer_accounts_config()['password_reset_authorization_lifetime_minutes'] * 60;
}

/**
 * Derive an integrity context for one reset authorization without storing its plaintext token.
 *
 * @param int $tokenId Password-reset token row id.
 * @param int $viewerAccountId Viewer account id.
 * @param int $securityVersion Security version captured by the token.
 * @param string $expiresAt Token expiry timestamp.
 * @return string HMAC integrity context.
 */
function viewer_password_reset_context(
    int $tokenId,
    int $viewerAccountId,
    int $securityVersion,
    string $expiresAt
): string {
    if ($tokenId <= 0 || $viewerAccountId <= 0 || $securityVersion <= 0 || $expiresAt === '') {
        return '';
    }
    return viewer_security_fingerprint(
        'viewer-password-reset-state',
        $tokenId . "\0" . $viewerAccountId . "\0" . $securityVersion . "\0" . $expiresAt
    );
}

/**
 * Consume low-cost password-reset request budgets before account lookup or mail authorization.
 *
 * @param string $normalizedEmail Normalized viewer identifier.
 * @param string $clientIp Trusted exact client IP.
 * @return array{allowed:bool,reason:string,retry_after_seconds:int} Admission result.
 */
function viewer_password_reset_rate_limits_consume(string $normalizedEmail, string $clientIp): array
{
    if ($clientIp === '') {
        return ['allowed' => false, 'reason' => 'client_ip_unavailable', 'retry_after_seconds' => 0];
    }
    $plan = [
        ['bucket' => 'viewer_password_reset_ip', 'kind' => 'ip', 'subject' => $clientIp],
        ['bucket' => 'viewer_password_reset_subnet', 'kind' => 'subnet', 'subject' => $clientIp],
        ['bucket' => 'viewer_password_reset_identifier', 'kind' => 'identifier', 'subject' => $normalizedEmail],
        ['bucket' => 'viewer_password_reset_global', 'kind' => 'global', 'subject' => 'global'],
    ];
    try {
        foreach ($plan as $dimension) {
            $decision = viewer_rate_limit_consume($dimension['bucket'], $dimension['kind'], $dimension['subject']);
            if (!$decision['allowed']) {
                return [
                    'allowed' => false,
                    'reason' => 'rate_limited',
                    'retry_after_seconds' => (int) $decision['retry_after_seconds'],
                ];
            }
        }
    } catch (Throwable) {
        return ['allowed' => false, 'reason' => 'limiter_unavailable', 'retry_after_seconds' => 0];
    }
    return ['allowed' => true, 'reason' => 'ok', 'retry_after_seconds' => 0];
}

/**
 * Admit one future viewer password-reset request without sending email.
 *
 * @param string $email Submitted viewer email identifier.
 * @param ?string $clientIp Explicit test/internal client IP, otherwise trusted resolver result.
 * @return array{accepted:bool,mail_eligible:bool,reason:string,public_result:string,reset_token:?string,expires_at:?string}
 */
function viewer_password_reset_request(string $email, ?string $clientIp = null): array
{
    $publicResult = viewer_mail_public_result_code();
    $result = static fn (bool $accepted, bool $mailEligible, string $reason, ?string $token = null, ?string $expiresAt = null): array => [
        'accepted' => $accepted,
        'mail_eligible' => $mailEligible,
        'reason' => $reason,
        'public_result' => $publicResult,
        'reset_token' => $token,
        'expires_at' => $expiresAt,
    ];

    if (!viewer_accounts_enabled()) {
        return $result(false, false, 'viewer_disabled');
    }
    if (!viewer_auth_storage_available()) {
        return $result(false, false, 'storage_unavailable');
    }
    if (!viewer_security_transport_allowed()) {
        return $result(false, false, 'secure_transport_required');
    }

    $normalized = viewer_email_normalize($email);
    if ($normalized === null) {
        return $result(false, false, 'invalid_input');
    }
    $resolvedIp = $clientIp === null ? request_client_ip() : request_client_ip_normalize($clientIp);

    // Intentionally before account lookup and before the separate mail-abuse budget reservation.
    $rateDecision = viewer_password_reset_rate_limits_consume($normalized, $resolvedIp);
    if (!$rateDecision['allowed']) {
        return $result(false, false, $rateDecision['reason']);
    }

    $accountStmt = db()->prepare('SELECT * FROM viewer_accounts WHERE normalized_email = ? LIMIT 1');
    $accountStmt->execute([$normalized]);
    $account = $accountStmt->fetch();

    $mailDecision = viewer_mail_authorize_send(VIEWER_MAIL_ACTION_PASSWORD_RESET, $normalized, $resolvedIp);
    if (!$mailDecision['allowed']) {
        return $result(false, false, $mailDecision['reason']);
    }
    if (!$account || !viewer_account_can_authenticate($account)) {
        return $result(true, false, 'request_received');
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lockStmt = $pdo->prepare('SELECT * FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
        $lockStmt->execute([(int) $account['id']]);
        $lockedAccount = $lockStmt->fetch();
        if (!$lockedAccount || !viewer_account_can_authenticate($lockedAccount)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $result(true, false, 'request_received');
        }

        $tokenLifetimeSeconds = (int) viewer_accounts_config()['password_reset_token_lifetime_minutes'] * 60;
        $token = viewer_password_reset_token_issue(
            (int) $lockedAccount['id'],
            (int) $lockedAccount['security_version'],
            $tokenLifetimeSeconds
        );
        $expiresAt = date('Y-m-d H:i:s', time() + $tokenLifetimeSeconds);
        viewer_security_event_record('viewer.password_reset_requested', (int) $lockedAccount['id'], 'accepted');

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $result(true, true, 'reset_token_issued', $token, $expiresAt);
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Inspect one password-reset token without consuming it or creating pre-authentication state.
 *
 * This function is safe for a future GET-style link inspection. A mail scanner calling it can
 * neither reset the password nor consume the single-use final transition.
 *
 * @param string $token Plaintext password-reset token.
 * @return ?array{token_id:int,account_id:int,security_version:int,expires_at:string} Usable token metadata.
 */
function viewer_password_reset_inspect(string $token): ?array
{
    if (!viewer_accounts_enabled() || !viewer_auth_storage_available() || !viewer_security_transport_allowed()) {
        return null;
    }
    if ($token === '' || strlen($token) > 512) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT vprt.id AS token_id, vprt.viewer_account_id, vprt.security_version AS token_security_version, '
        . 'vprt.expires_at, vprt.consumed_at, vprt.invalidated_at, '
        . 'va.password_hash, va.status, va.email_verified_at, va.security_version AS account_security_version '
        . 'FROM viewer_password_reset_tokens vprt INNER JOIN viewer_accounts va ON va.id = vprt.viewer_account_id '
        . 'WHERE vprt.token_hash = ? LIMIT 1'
    );
    $stmt->execute([security_authority_token_hash($token)]);
    $row = $stmt->fetch();
    $account = $row ? [
        'status' => $row['status'] ?? '',
        'password_hash' => $row['password_hash'] ?? '',
        'email_verified_at' => $row['email_verified_at'] ?? null,
    ] : [];
    if (!$row
        || !viewer_one_time_token_row_is_usable($row)
        || !viewer_account_can_authenticate($account)
        || (int) $row['token_security_version'] !== (int) $row['account_security_version']) {
        return null;
    }

    return [
        'token_id' => (int) $row['token_id'],
        'account_id' => (int) $row['viewer_account_id'],
        'security_version' => (int) $row['token_security_version'],
        'expires_at' => (string) $row['expires_at'],
    ];
}

/**
 * Explicitly convert a valid reset token into short-lived server-side reset authority.
 *
 * The token remains unconsumed here. Only viewer_password_reset_complete() consumes it.
 *
 * @param string $token Plaintext reset token from a future explicit confirmation request.
 * @return bool True when reset pre-authentication state was established.
 */
function viewer_password_reset_authorize(string $token): bool
{
    $inspected = viewer_password_reset_inspect($token);
    if ($inspected === null) {
        viewer_password_reset_state_clear();
        return false;
    }
    $context = viewer_password_reset_context(
        $inspected['token_id'],
        $inspected['account_id'],
        $inspected['security_version'],
        $inspected['expires_at']
    );
    $tokenExpiry = strtotime($inspected['expires_at']);
    if ($context === '' || $tokenExpiry === false || $tokenExpiry < time()) {
        viewer_password_reset_state_clear();
        return false;
    }

    if (session_status() === PHP_SESSION_ACTIVE && !session_regenerate_id(true)) {
        throw new RuntimeException('Viewer password-reset session rotation failed.');
    }
    $_SESSION[viewer_password_reset_namespace_key()] = [
        'token_id' => $inspected['token_id'],
        'account_id' => $inspected['account_id'],
        'security_version' => $inspected['security_version'],
        'token_expires_at' => $inspected['expires_at'],
        'expires_at' => min($tokenExpiry, time() + viewer_password_reset_authorization_lifetime_seconds()),
        'context' => $context,
    ];
    return true;
}

/**
 * Parse current viewer password-reset pre-authentication state.
 *
 * @return ?array{token_id:int,account_id:int,security_version:int,token_expires_at:string,expires_at:int,context:string}
 */
function viewer_password_reset_state(): ?array
{
    $state = $_SESSION[viewer_password_reset_namespace_key()] ?? null;
    if (!is_array($state)) {
        return null;
    }
    $tokenId = (int) ($state['token_id'] ?? 0);
    $accountId = (int) ($state['account_id'] ?? 0);
    $securityVersion = (int) ($state['security_version'] ?? 0);
    $tokenExpiresAt = (string) ($state['token_expires_at'] ?? '');
    $expiresAt = (int) ($state['expires_at'] ?? 0);
    $context = (string) ($state['context'] ?? '');
    if ($tokenId <= 0
        || $accountId <= 0
        || $securityVersion <= 0
        || $tokenExpiresAt === ''
        || $expiresAt < time()
        || preg_match('/^[a-f0-9]{64}$/D', $context) !== 1) {
        viewer_password_reset_state_clear();
        return null;
    }
    return [
        'token_id' => $tokenId,
        'account_id' => $accountId,
        'security_version' => $securityVersion,
        'token_expires_at' => $tokenExpiresAt,
        'expires_at' => $expiresAt,
        'context' => $context,
    ];
}

/**
 * Complete one password reset atomically from server-side pre-authentication authority.
 *
 * @param string $newPassword New viewer password.
 * @return array{reset:bool,reason:string} Internal reset result.
 */
function viewer_password_reset_complete(string $newPassword): array
{
    if (!viewer_accounts_enabled()) {
        return ['reset' => false, 'reason' => 'viewer_disabled'];
    }
    if (!viewer_auth_storage_available()) {
        return ['reset' => false, 'reason' => 'storage_unavailable'];
    }
    if (!viewer_security_transport_allowed()) {
        return ['reset' => false, 'reason' => 'secure_transport_required'];
    }

    $state = viewer_password_reset_state();
    if ($state === null) {
        return ['reset' => false, 'reason' => 'reset_state_invalid'];
    }
    if (!viewer_password_input_is_acceptable($newPassword)) {
        return ['reset' => false, 'reason' => 'password_policy'];
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $accountStmt = $pdo->prepare('SELECT * FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
        $accountStmt->execute([$state['account_id']]);
        $account = $accountStmt->fetch();
        if (!$account
            || !viewer_account_can_authenticate($account)
            || (int) $account['security_version'] !== $state['security_version']) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            viewer_password_reset_state_clear();
            return ['reset' => false, 'reason' => 'reset_state_invalid'];
        }

        $tokenStmt = $pdo->prepare('SELECT * FROM viewer_password_reset_tokens WHERE id = ? LIMIT 1 FOR UPDATE');
        $tokenStmt->execute([$state['token_id']]);
        $token = $tokenStmt->fetch();
        $context = $token ? viewer_password_reset_context(
            (int) $token['id'],
            (int) $token['viewer_account_id'],
            (int) $token['security_version'],
            (string) $token['expires_at']
        ) : '';
        if (!$token
            || !viewer_one_time_token_row_is_usable($token)
            || (int) $token['viewer_account_id'] !== $state['account_id']
            || (int) $token['security_version'] !== $state['security_version']
            || $context === ''
            || !hash_equals($context, $state['context'])) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            viewer_password_reset_state_clear();
            return ['reset' => false, 'reason' => 'reset_state_invalid'];
        }

        $passwordHash = viewer_password_hash($newPassword);
        $now = now_sql();
        $newSecurityVersion = $state['security_version'] + 1;
        $updateAccount = $pdo->prepare(
            'UPDATE viewer_accounts SET password_hash = ?, must_change_password = 0, password_changed_at = ?, security_version = ?, updated_at = ? '
            . 'WHERE id = ? AND security_version = ?'
        );
        $updateAccount->execute([
            $passwordHash,
            $now,
            $newSecurityVersion,
            $now,
            $state['account_id'],
            $state['security_version'],
        ]);
        if ($updateAccount->rowCount() !== 1) {
            throw new RuntimeException('Viewer password reset lost the account security-version race.');
        }

        $consume = $pdo->prepare(
            'UPDATE viewer_password_reset_tokens SET consumed_at = ? '
            . 'WHERE id = ? AND consumed_at IS NULL AND invalidated_at IS NULL'
        );
        $consume->execute([$now, $state['token_id']]);
        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('Viewer password reset token consumption lost a concurrent race.');
        }

        $pdo->prepare(
            'UPDATE viewer_password_reset_tokens SET invalidated_at = ? '
            . 'WHERE viewer_account_id = ? AND id <> ? AND consumed_at IS NULL AND invalidated_at IS NULL'
        )->execute([$now, $state['account_id'], $state['token_id']]);
        $pdo->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')
            ->execute([$now, $state['account_id']]);
        $pdo->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')
            ->execute([$now, $state['account_id']]);

        viewer_security_event_record('viewer.password_reset_completed', $state['account_id'], 'success', [
            'security_version' => $newSecurityVersion,
        ]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        viewer_password_reset_state_clear();
        viewer_session_clear();
        return ['reset' => true, 'reason' => 'reset_completed'];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
