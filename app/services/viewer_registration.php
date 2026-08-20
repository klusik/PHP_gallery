<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_registration.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides dormant pending-registration and invitation foundations for future viewer accounts.
 *
 * Responsibilities:
 *   - Keep anonymous registration requests outside the durable viewer_accounts identity table
 *   - Deduplicate pending requests by the canonical normalized email identity
 *   - Bound pending-registration storage with an explicitly locked capacity counter
 *   - Issue, validate, claim, expire, and revoke hashed administrator-created invitation capabilities
 *   - Validate verification links without consuming them and consume confirmation exactly once
 *   - Keep account activation and every HTTP route outside Phase 0.5
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
 *   - A pending registration request is not a viewer identity.
 *   - This service intentionally contains no INSERT into viewer_accounts.
 *   - Link-scanner-safe verification requires validation and explicit confirmation to remain separate operations.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

const VIEWER_REGISTRATION_STATUS_PENDING = 'pending_verification';
const VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED = 'email_verified';
const VIEWER_REGISTRATION_STATUS_CANCELLED = 'cancelled';
const VIEWER_REGISTRATION_STATE_KEY = 'pending_requests';
const VIEWER_REGISTRATION_ACTIVATION_NAMESPACE = 'viewer_registration_activation';
const VIEWER_REGISTRATION_RESEND_TOKEN_CAP = 12;

/**
 * Return the aggregate schema capability for the dormant Phase 0.5 registration foundation.
 *
 * @return array Aggregate three-state schema inspection result.
 */
function viewer_registration_schema_status(): array
{
    return schema_inspection_feature('viewer.registration_foundation', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_table('viewer_invitations'),
        schema_inspection_column('viewer_invitations', 'target_email'),
        schema_inspection_table('viewer_registration_requests'),
        schema_inspection_table('viewer_registration_verification_tokens'),
        schema_inspection_table('viewer_registration_state'),
        schema_inspection_table('viewer_rate_limit_buckets'),
        schema_inspection_table('viewer_rate_limits'),
    ]);
}

/**
 * Return true only when every Phase 0.5 registration table is verifiably available.
 *
 * Unknown and confirmed-missing schema states both fail closed.
 *
 * @return bool True only for verified available storage.
 */
function viewer_registration_storage_available(): bool
{
    return schema_inspection_is_available(viewer_registration_schema_status());
}

/**
 * Return a stable generic result intended for future anonymous registration responses.
 *
 * Internal service callers may use detailed reason codes for diagnostics, but a future
 * public controller must not expose those reasons because they can reveal account,
 * invitation, or throttling state.
 *
 * @return string Generic external result code.
 */
function viewer_registration_public_result_code(): string
{
    return 'request_received';
}

/**
 * Return true when the configured viewer registration policy can accept a request.
 *
 * @return bool True for explicitly enabled invite-only or open registration modes.
 */
function viewer_registration_requests_enabled(): bool
{
    return viewer_accounts_enabled() && in_array(viewer_registration_mode(), ['invite_only', 'open'], true);
}

/**
 * Return the configured maximum number of staged registration rows.
 *
 * @return int Hard row cap.
 */
function viewer_registration_request_cap(): int
{
    return (int) viewer_accounts_config()['max_pending_registration_requests'];
}

/**
 * Return the lifetime of an unverified pending registration request.
 *
 * @return int Lifetime in seconds.
 */
function viewer_registration_request_lifetime_seconds(): int
{
    return (int) viewer_accounts_config()['registration_request_lifetime_minutes'] * 60;
}

/**
 * Return the lifetime of one pending-registration verification token.
 *
 * @return int Lifetime in seconds.
 */
function viewer_registration_verification_lifetime_seconds(): int
{
    return (int) viewer_accounts_config()['verification_token_lifetime_minutes'] * 60;
}

/**
 * Return the hard per-request cap for simultaneously stored resend verification authorities.
 *
 * This is a defense-in-depth storage bound in addition to the existing resend and mail budgets.
 * A new resend is suppressed rather than deleting an existing still-valid sibling authority.
 *
 * @return int Maximum child verification-token rows for one staged request.
 */
function viewer_registration_resend_token_cap(): int
{
    return VIEWER_REGISTRATION_RESEND_TOKEN_CAP;
}

/**
 * Return how long an email-verified staging row remains available for later activation.
 *
 * @return int Lifetime in seconds.
 */
function viewer_registration_verified_lifetime_seconds(): int
{
    return (int) viewer_accounts_config()['verified_registration_lifetime_minutes'] * 60;
}


/**
 * Return the viewer-registration activation PHP-session namespace key.
 *
 * @return string Viewer pre-auth activation namespace.
 */
function viewer_registration_activation_namespace_key(): string
{
    return VIEWER_REGISTRATION_ACTIVATION_NAMESPACE;
}

/**
 * Return the maximum lifetime of one server-side registration activation grant.
 *
 * @return int Lifetime in seconds.
 */
function viewer_registration_activation_lifetime_seconds(): int
{
    return (int) viewer_accounts_config()['registration_activation_lifetime_minutes'] * 60;
}

/**
 * Derive an integrity context for a verified staging row without storing its email in PHP session state.
 *
 * @param int $requestId Registration request identifier.
 * @param string $verifiedAt Authoritative verification timestamp.
 * @param string $normalizedEmail Authoritative normalized email.
 * @return string HMAC integrity context.
 */
function viewer_registration_activation_context(int $requestId, string $verifiedAt, string $normalizedEmail): string
{
    if ($requestId <= 0 || $verifiedAt === '' || $normalizedEmail === '') {
        return '';
    }
    return viewer_security_fingerprint(
        'viewer-registration-activation',
        $requestId . "\0" . $verifiedAt . "\0" . $normalizedEmail
    );
}

/**
 * Clear only the pre-authentication registration activation grant.
 */
function viewer_registration_activation_clear(): void
{
    unset($_SESSION[viewer_registration_activation_namespace_key()]);
}

/**
 * Establish a short-lived server-side activation grant after explicit verification confirmation.
 *
 * @param array $row Newly verified authoritative staging row.
 */
function viewer_registration_activation_establish(array $row): void
{
    $requestId = (int) ($row['id'] ?? 0);
    $verifiedAt = (string) ($row['verified_at'] ?? '');
    $normalizedEmail = (string) ($row['normalized_email'] ?? '');
    $rowExpiry = strtotime((string) ($row['expires_at'] ?? ''));
    $context = viewer_registration_activation_context($requestId, $verifiedAt, $normalizedEmail);
    if ($requestId <= 0 || $rowExpiry === false || $rowExpiry < time() || $context === '') {
        throw new RuntimeException('Verified viewer registration cannot establish activation authority.');
    }

    $expiresAt = min($rowExpiry, time() + viewer_registration_activation_lifetime_seconds());
    if (session_status() === PHP_SESSION_ACTIVE && !session_regenerate_id(true)) {
        throw new RuntimeException('Viewer registration activation session rotation failed.');
    }
    $_SESSION[viewer_registration_activation_namespace_key()] = [
        'request_id' => $requestId,
        'expires_at' => $expiresAt,
        'context' => $context,
    ];
}

/**
 * Parse the current short-lived registration activation grant.
 *
 * @return ?array{request_id:int,expires_at:int,context:string} Valid state or null.
 */
function viewer_registration_activation_state(): ?array
{
    $state = $_SESSION[viewer_registration_activation_namespace_key()] ?? null;
    if (!is_array($state)) {
        return null;
    }
    $requestId = (int) ($state['request_id'] ?? 0);
    $expiresAt = (int) ($state['expires_at'] ?? 0);
    $context = (string) ($state['context'] ?? '');
    if ($requestId <= 0 || $expiresAt < time() || preg_match('/^[a-f0-9]{64}$/D', $context) !== 1) {
        viewer_registration_activation_clear();
        return null;
    }
    return ['request_id' => $requestId, 'expires_at' => $expiresAt, 'context' => $context];
}

/**
 * Return whether a server-side activation grant matches the locked authoritative staging row.
 *
 * @param array $state Parsed activation state.
 * @param array $row Locked registration-request row.
 * @return bool True only for the same verified staging authority.
 */
function viewer_registration_activation_matches_row(array $state, array $row): bool
{
    $expected = viewer_registration_activation_context(
        (int) ($row['id'] ?? 0),
        (string) ($row['verified_at'] ?? ''),
        (string) ($row['normalized_email'] ?? '')
    );
    return $expected !== ''
        && (int) ($state['request_id'] ?? 0) === (int) ($row['id'] ?? 0)
        && hash_equals($expected, (string) ($state['context'] ?? ''));
}

/**
 * Return the default lifetime of one viewer invitation.
 *
 * @return int Lifetime in seconds.
 */
function viewer_invitation_lifetime_seconds(): int
{
    return (int) viewer_accounts_config()['invitation_lifetime_days'] * 86400;
}

/**
 * Return whether one invitation row can be used for the supplied email at the supplied time.
 *
 * This pure helper deliberately treats claimed, revoked, and expired invitations as
 * unavailable. Repeated submissions for an already-claimed invitation are handled by
 * viewer_registration_request_begin(), which can prove the claim belongs to the same
 * pending request under one transaction.
 *
 * @param array $row Invitation database row.
 * @param string $email Candidate email address.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return bool True only when the invitation is usable and any email binding matches.
 */
function viewer_invitation_row_is_usable(array $row, string $email, ?int $nowTimestamp = null): bool
{
    $normalized = viewer_email_normalize($email);
    if ($normalized === null || !empty($row['claimed_at']) || !empty($row['revoked_at'])) {
        return false;
    }

    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    $nowTimestamp ??= time();
    if ($expiresAt === false || $expiresAt < $nowTimestamp) {
        return false;
    }

    $expectedFingerprint = trim((string) ($row['target_email_fingerprint'] ?? ''));
    return $expectedFingerprint === '' || hash_equals($expectedFingerprint, viewer_email_fingerprint($normalized));
}

/**
 * Return whether one staged registration request is still pending and inside its own lifetime.
 *
 * This request-level predicate intentionally does not inspect one particular verification
 * capability. Phase 4.2 child resend tokens can remain usable even after the historical
 * primary token has expired, while the owning registration request itself is still live.
 *
 * @param array $row Registration-request row.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return bool True only for a live, uncancelled pending request.
 */
function viewer_registration_request_row_is_pending_active(array $row, ?int $nowTimestamp = null): bool
{
    if ((string) ($row['status'] ?? '') !== VIEWER_REGISTRATION_STATUS_PENDING
        || !empty($row['verification_token_consumed_at'])
        || !empty($row['cancelled_at'])) {
        return false;
    }

    $requestExpiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    $nowTimestamp ??= time();
    return $requestExpiresAt !== false && $requestExpiresAt >= $nowTimestamp;
}

/**
 * Return whether the historical primary pending-registration verification token is usable.
 *
 * @param array $row Registration-request row.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return bool True only for an unconsumed, unexpired primary verification token.
 */
function viewer_registration_request_row_is_verifiable(array $row, ?int $nowTimestamp = null): bool
{
    if (!viewer_registration_request_row_is_pending_active($row, $nowTimestamp)) {
        return false;
    }

    $tokenExpiresAt = strtotime((string) ($row['verification_token_expires_at'] ?? ''));
    $nowTimestamp ??= time();
    return $tokenExpiresAt !== false && $tokenExpiresAt >= $nowTimestamp;
}

/**
 * Return whether a pending request already has one successfully mailed usable verifier.
 *
 * Repeated registration submissions must not rotate an authority that was already sent
 * and remains usable. A row with no successful send, an expired request/token, a consumed
 * token, or missing token hash may proceed through the normal retry path.
 *
 * @param array $row Registration-request row.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return bool True only when the existing emailed verification authority must be preserved.
 */
function viewer_registration_pending_has_sent_valid_verification_authority(array $row, ?int $nowTimestamp = null): bool
{
    return (int) ($row['verification_send_count'] ?? 0) > 0
        && trim((string) ($row['verification_token_hash'] ?? '')) !== ''
        && viewer_registration_request_row_is_verifiable($row, $nowTimestamp);
}

/**
 * Return whether one staged registration originated from an administrator invitation.
 *
 * The existing nullable viewer_invitation_id column is the authoritative origin marker;
 * no parallel registration-origin column is required.
 *
 * @param array $row Registration-request row.
 * @return bool True only when the row is backed by a positive invitation id.
 */
function viewer_registration_request_is_invitation_backed(array $row): bool
{
    return (int) ($row['viewer_invitation_id'] ?? 0) > 0;
}

/**
 * Return whether one staged registration originated from open registration.
 *
 * @param array $row Registration-request row.
 * @return bool True only when no invitation capability backs the row.
 */
function viewer_registration_request_is_open_origin(array $row): bool
{
    return !viewer_registration_request_is_invitation_backed($row);
}

/**
 * Authorize one staged request against the current effective registration policy.
 *
 * This decision is intentionally evaluated at the service boundary each time the
 * staged authority progresses. The policy that existed when the row was created is
 * never treated as durable authority.
 *
 * @param array $row Registration-request row.
 * @return bool True when the current policy permits this request origin to continue.
 */
function viewer_registration_request_allowed_by_current_mode(array $row): bool
{
    return match (viewer_registration_mode()) {
        'open' => true,
        'invite_only' => viewer_registration_request_is_invitation_backed($row),
        default => false,
    };
}

/**
 * Inspect one invitation bearer without consuming it or requiring the intended email.
 *
 * This is the scanner-safe GET boundary used by Phase 1.0. It returns only the
 * minimum state required to decide whether an acceptance form may be displayed.
 * A claimed invitation remains inspectable only while its single attached staged
 * registration is still live, allowing safe retries after mail delivery failure.
 *
 * @param string $token Plaintext invitation token.
 * @return ?array{id:int,expires_at:string,claimed:bool,email_bound:bool} Safe invitation metadata.
 */
function viewer_invitation_inspect(string $token): ?array
{
    if (!viewer_registration_requests_enabled()
        || $token === ''
        || strlen($token) > 512
        || !viewer_registration_storage_available()
        || !viewer_security_transport_allowed()) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT vi.id, vi.target_email_fingerprint, vi.expires_at, vi.claimed_at, vi.revoked_at, '
        . 'vrr.status AS registration_status, vrr.expires_at AS registration_expires_at '
        . 'FROM viewer_invitations vi '
        . 'LEFT JOIN viewer_registration_requests vrr ON vrr.viewer_invitation_id = vi.id '
        . 'WHERE vi.token_hash = ? LIMIT 1'
    );
    $stmt->execute([security_authority_token_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || !empty($row['revoked_at'])) {
        return null;
    }
    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    if ($expiresAt === false || $expiresAt < time()) {
        return null;
    }

    $claimed = !empty($row['claimed_at']);
    if ($claimed) {
        $registrationExpiry = strtotime((string) ($row['registration_expires_at'] ?? ''));
        if (!in_array((string) ($row['registration_status'] ?? ''), [VIEWER_REGISTRATION_STATUS_PENDING, VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED], true)
            || $registrationExpiry === false
            || $registrationExpiry < time()) {
            return null;
        }
    }

    return [
        'id' => (int) $row['id'],
        'expires_at' => (string) $row['expires_at'],
        'claimed' => $claimed,
        'email_bound' => trim((string) ($row['target_email_fingerprint'] ?? '')) !== '',
    ];
}

/**
 * Return a bounded administrator-facing invitation list without exposing token hashes.
 *
 * @param int $limit Maximum invitation rows to return.
 * @return array<int,array<string,mixed>> Invitation operational rows with derived state.
 */
function viewer_invitation_list_for_admin(int $limit = 100): array
{
    if ($limit < 1 || $limit > 500 || !viewer_registration_storage_available()) {
        return [];
    }

    $stmt = db()->query(
        'SELECT vi.id, vi.target_email, vi.created_by_admin_user_id, vi.created_at, vi.expires_at, vi.claimed_at, vi.revoked_at, '
        . 'CASE WHEN vi.target_email_fingerprint IS NULL OR vi.target_email_fingerprint = \'\' THEN 0 ELSE 1 END AS email_bound, '
        . 'vrr.status AS registration_status, vrr.email AS registration_email '
        . 'FROM viewer_invitations vi '
        . 'LEFT JOIN viewer_registration_requests vrr ON vrr.viewer_invitation_id = vi.id '
        . 'ORDER BY vi.created_at DESC, vi.id DESC LIMIT ' . (int) $limit
    );
    $rows = $stmt ? $stmt->fetchAll() : [];
    $now = time();
    foreach ($rows as &$row) {
        if (!empty($row['revoked_at'])) {
            $row['invitation_status'] = 'revoked';
        } else {
            $expiry = strtotime((string) ($row['expires_at'] ?? ''));
            if ($expiry === false || $expiry < $now) {
                $row['invitation_status'] = 'expired';
            } elseif (!empty($row['claimed_at'])) {
                $row['invitation_status'] = 'consumed';
            } else {
                $row['invitation_status'] = 'unused';
            }
        }
        unset($row['target_email_fingerprint']);
    }
    unset($row);
    return $rows;
}

/**
 * Issue one administrator-created invitation capability without exposing a route or UI.
 *
 * Only a verified administrator row may be recorded as creator. The optional target email
 * is stored for administrator display while its HMAC fingerprint remains the authority binding.
 *
 * @param int $adminUserId Existing administrator user id.
 * @param ?string $targetEmail Optional email binding.
 * @param ?int $lifetimeSeconds Optional invitation lifetime override.
 * @return array{id:int,token:string,expires_at:string} Newly issued capability.
 */
function viewer_invitation_issue(int $adminUserId, ?string $targetEmail = null, ?int $lifetimeSeconds = null): array
{
    if (!viewer_registration_requests_enabled()) {
        throw new RuntimeException('Viewer invitation issuance is unavailable.');
    }
    if (!viewer_registration_storage_available()) {
        throw new RuntimeException('Viewer registration storage is unavailable.');
    }
    if (viewer_account_capacity_reconcile() >= viewer_account_cap()) {
        throw new RuntimeException('Viewer account capacity is full.');
    }
    if ($adminUserId <= 0) {
        throw new InvalidArgumentException('Viewer invitation administrator id is invalid.');
    }

    $targetAddress = null;
    $targetFingerprint = null;
    if ($targetEmail !== null && trim($targetEmail) !== '') {
        $normalized = viewer_email_normalize($targetEmail);
        if ($normalized === null) {
            throw new InvalidArgumentException('Viewer invitation target email is invalid.');
        }
        $targetAddress = $normalized;
        $targetFingerprint = viewer_email_fingerprint($normalized);
    }

    $lifetimeSeconds ??= viewer_invitation_lifetime_seconds();
    if ($lifetimeSeconds < 300 || $lifetimeSeconds > 31536000) {
        throw new InvalidArgumentException('Viewer invitation lifetime is outside safe bounds.');
    }

    $adminStmt = db()->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    $adminStmt->execute([$adminUserId]);
    if ((int) $adminStmt->fetchColumn() !== $adminUserId) {
        throw new InvalidArgumentException('Viewer invitation creator must be an existing administrator.');
    }

    $token = security_opaque_token_generate(32);
    $createdAt = now_sql();
    $expiresAt = date('Y-m-d H:i:s', time() + $lifetimeSeconds);
    $stmt = db()->prepare(
        'INSERT INTO viewer_invitations '
        . '(token_hash, target_email, target_email_fingerprint, created_by_admin_user_id, created_at, expires_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        security_authority_token_hash($token),
        $targetAddress,
        $targetFingerprint,
        $adminUserId,
        $createdAt,
        $expiresAt,
    ]);

    return ['id' => (int) db()->lastInsertId(), 'token' => $token, 'expires_at' => $expiresAt];
}

/**
 * Validate one invitation without claiming or consuming it.
 *
 * GET-like link inspection can call this operation safely because it performs no irreversible
 * transition. Claiming belongs to the explicit registration request mutation.
 *
 * @param string $token Plaintext invitation token.
 * @param string $email Candidate email address.
 * @return ?array Matching usable invitation row, otherwise null.
 */
function viewer_invitation_validate(string $token, string $email): ?array
{
    if (!viewer_registration_requests_enabled() || $token === '' || !viewer_registration_storage_available()) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM viewer_invitations WHERE token_hash = ? LIMIT 1');
    $stmt->execute([security_authority_token_hash($token)]);
    $row = $stmt->fetch();
    return $row && viewer_invitation_row_is_usable($row, $email) ? $row : null;
}

/**
 * Preflight an invitation for registration admission without consuming it.
 *
 * A fresh unclaimed invitation is accepted normally. An already-claimed invitation is
 * accepted only when the database proves it belongs to the same still-live normalized
 * pending request. This preserves idempotent retries without making a claimed invitation
 * reusable for a second identity.
 *
 * @param string $token Plaintext invitation token.
 * @param string $email Candidate email address.
 * @return ?array Matching invitation row, otherwise null.
 */
function viewer_invitation_registration_preflight(string $token, string $email): ?array
{
    if (!viewer_registration_requests_enabled() || $token === '' || !viewer_registration_storage_available()) {
        return null;
    }

    $normalized = viewer_email_normalize($email);
    if ($normalized === null) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT vi.*, vrr.normalized_email AS registration_normalized_email, '
        . 'vrr.status AS registration_status, vrr.expires_at AS registration_expires_at '
        . 'FROM viewer_invitations vi '
        . 'LEFT JOIN viewer_registration_requests vrr ON vrr.viewer_invitation_id = vi.id '
        . 'WHERE vi.token_hash = ? LIMIT 1'
    );
    $stmt->execute([security_authority_token_hash($token)]);
    $row = $stmt->fetch();
    if (!$row || !empty($row['revoked_at'])) {
        return null;
    }

    $expiresAt = strtotime((string) ($row['expires_at'] ?? ''));
    if ($expiresAt === false || $expiresAt < time()) {
        return null;
    }

    $expectedFingerprint = trim((string) ($row['target_email_fingerprint'] ?? ''));
    if ($expectedFingerprint !== '' && !hash_equals($expectedFingerprint, viewer_email_fingerprint($normalized))) {
        return null;
    }

    if (empty($row['claimed_at'])) {
        return $row;
    }

    $requestExpiresAt = strtotime((string) ($row['registration_expires_at'] ?? ''));
    if ((string) ($row['registration_normalized_email'] ?? '') !== $normalized
        || !in_array((string) ($row['registration_status'] ?? ''), [VIEWER_REGISTRATION_STATUS_PENDING, VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED], true)
        || $requestExpiresAt === false
        || $requestExpiresAt < time()) {
        return null;
    }

    return $row;
}

/**
 * Consume the network-scoped anonymous registration-request budget.
 *
 * Invalid invitation guesses use only these network budgets. They deliberately do not
 * consume the installation-wide registration budget, avoiding a trivial global denial of
 * service where anyone without an invitation could exhaust legitimate invite registrations.
 *
 * @param string $clientIp Canonical client IP address.
 * @return array{allowed:bool,reason:string,retry_after_seconds:int} Aggregate decision.
 */
function viewer_registration_request_authorize_network(string $clientIp): array
{
    $clientIp = request_client_ip_normalize($clientIp);
    if ($clientIp === '') {
        return ['allowed' => false, 'reason' => 'client_ip_unavailable', 'retry_after_seconds' => 0];
    }

    $checks = [
        ['viewer_register_ip', 'ip', $clientIp],
        ['viewer_register_subnet', 'subnet', $clientIp],
    ];

    try {
        foreach ($checks as [$bucket, $kind, $subject]) {
            $decision = viewer_rate_limit_consume($bucket, $kind, $subject);
            if (!$decision['allowed']) {
                return [
                    'allowed' => false,
                    'reason' => (string) $decision['reason'],
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
 * Consume identifier and installation-wide registration budgets after policy eligibility is known.
 *
 * In invite-only mode this is called only after a non-consuming invitation validation succeeds.
 * Open registration calls it after network admission. This separation preserves the global
 * circuit breaker without letting invalid invitation guesses exhaust it.
 *
 * @param string $email Valid canonical email address.
 * @return array{allowed:bool,reason:string,retry_after_seconds:int} Aggregate decision.
 */
function viewer_registration_request_authorize_identity(string $email): array
{
    $normalized = viewer_email_normalize($email);
    if ($normalized === null) {
        return ['allowed' => false, 'reason' => 'invalid_subject', 'retry_after_seconds' => 0];
    }

    $checks = [
        ['viewer_register_identifier', 'identifier', $normalized],
        ['viewer_register_global_day', 'global', 'global'],
    ];

    try {
        foreach ($checks as [$bucket, $kind, $subject]) {
            $decision = viewer_rate_limit_consume($bucket, $kind, $subject);
            if (!$decision['allowed']) {
                return [
                    'allowed' => false,
                    'reason' => (string) $decision['reason'],
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
 * Ensure and lock the singleton pending-registration capacity row.
 *
 * @return int Current staged-row count while the state row lock is held.
 */
function viewer_registration_capacity_lock(): int
{
    $now = now_sql();
    $pdo = db();
    $pdo->prepare(
        'INSERT INTO viewer_registration_state (state_key, active_request_count, updated_at) '
        . 'VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at'
    )->execute([VIEWER_REGISTRATION_STATE_KEY, $now]);

    $stmt = $pdo->prepare(
        'SELECT active_request_count FROM viewer_registration_state '
        . 'WHERE state_key = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([VIEWER_REGISTRATION_STATE_KEY]);
    $count = $stmt->fetchColumn();
    if ($count === false) {
        throw new RuntimeException('Viewer registration capacity state could not be locked.');
    }
    return (int) $count;
}

/**
 * Recount staged registration rows while the capacity state row is locked.
 *
 * @return int Repaired staged-row count.
 */
function viewer_registration_capacity_recount_locked(): int
{
    $count = (int) db()->query('SELECT COUNT(*) FROM viewer_registration_requests')->fetchColumn();
    db()->prepare(
        'UPDATE viewer_registration_state SET active_request_count = ?, updated_at = ? WHERE state_key = ?'
    )->execute([$count, now_sql(), VIEWER_REGISTRATION_STATE_KEY]);
    return $count;
}

/**
 * Delete an expired bounded batch and repair the staged-row capacity counter.
 *
 * The caller must hold the singleton registration-state row lock.
 *
 * @param int $limit Maximum rows reclaimed.
 * @return int Remaining staged-row count.
 */
function viewer_registration_cleanup_requests_locked(int $limit = 1000): int
{
    $limit = max(1, min(1000, $limit));
    $stmt = db()->prepare(
        'DELETE FROM viewer_registration_requests WHERE expires_at < ? LIMIT ' . $limit
    );
    $stmt->execute([now_sql()]);
    return viewer_registration_capacity_recount_locked();
}

/**
 * Permanently retire all currently staged open-origin registration authority.
 *
 * Mode transitions use this while holding the same singleton capacity lock as request
 * creation. Only rows without viewer_invitation_id are cancelled, preserving every
 * invitation-backed pending or email-verified registration. Cancellation is durable;
 * re-enabling open registration does not make an old verification token usable again.
 *
 * @return int Number of open-origin staged rows newly cancelled.
 */
function viewer_registration_cancel_open_origin_staging(): int
{
    if (!viewer_registration_storage_available()) {
        throw new RuntimeException('Viewer registration storage is unavailable.');
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        viewer_registration_capacity_lock();
        $now = now_sql();
        $stmt = $pdo->prepare(
            'UPDATE viewer_registration_requests SET status = ?, cancelled_at = ?, updated_at = ? '
            . 'WHERE viewer_invitation_id IS NULL AND status IN (?, ?) AND cancelled_at IS NULL'
        );
        $stmt->execute([
            VIEWER_REGISTRATION_STATUS_CANCELLED,
            $now,
            $now,
            VIEWER_REGISTRATION_STATUS_PENDING,
            VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED,
        ]);
        $cancelled = $stmt->rowCount();

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $cancelled;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Begin or refresh one staged registration request without creating a viewer account.
 *
 * This is an internal Phase 0.5 primitive only. It performs no mail delivery and exposes no
 * HTTP behavior. The returned verification token is authority-bearing secret material for a
 * future mail flow and must never be logged or returned to an anonymous client as API data.
 *
 * @param string $email Candidate viewer email.
 * @param ?string $invitationToken Optional invitation capability.
 * @param ?string $clientIp Explicit client IP for tests/internal callers, otherwise trusted resolver result.
 * @return array{accepted:bool,mail_eligible:bool,reason:string,public_result:string,request_id:?int,verification_token:?string,expires_at:?string}
 */
function viewer_registration_request_begin(string $email, ?string $invitationToken = null, ?string $clientIp = null): array
{
    $publicResult = viewer_registration_public_result_code();
    if (!viewer_registration_requests_enabled()) {
        return [
            'accepted' => false,
            'mail_eligible' => false,
            'reason' => 'registration_disabled',
            'public_result' => $publicResult,
            'request_id' => null,
            'verification_token' => null,
            'expires_at' => null,
        ];
    }
    if (!viewer_security_transport_allowed()) {
        return [
            'accepted' => false,
            'mail_eligible' => false,
            'reason' => 'secure_transport_required',
            'public_result' => $publicResult,
            'request_id' => null,
            'verification_token' => null,
            'expires_at' => null,
        ];
    }

    $normalized = viewer_email_normalize($email);
    if ($normalized === null) {
        return [
            'accepted' => false,
            'mail_eligible' => false,
            'reason' => 'invalid_email',
            'public_result' => $publicResult,
            'request_id' => null,
            'verification_token' => null,
            'expires_at' => null,
        ];
    }

    if (!viewer_registration_storage_available()) {
        return [
            'accepted' => false,
            'mail_eligible' => false,
            'reason' => 'storage_unavailable',
            'public_result' => $publicResult,
            'request_id' => null,
            'verification_token' => null,
            'expires_at' => null,
        ];
    }

    $resolvedIp = $clientIp === null ? request_client_ip() : request_client_ip_normalize($clientIp);
    $networkDecision = viewer_registration_request_authorize_network($resolvedIp);
    if (!$networkDecision['allowed']) {
        $networkReason = $networkDecision['reason'] === 'limiter_unavailable'
            ? 'limiter_unavailable'
            : ($networkDecision['reason'] === 'client_ip_unavailable' ? 'client_ip_unavailable' : 'rate_limited');
        return [
            'accepted' => false,
            'mail_eligible' => false,
            'reason' => $networkReason,
            'public_result' => $publicResult,
            'request_id' => null,
            'verification_token' => null,
            'expires_at' => null,
        ];
    }

    $mode = viewer_registration_mode();
    if ($mode === 'invite_only' && trim((string) $invitationToken) === '') {
        return [
            'accepted' => false,
            'mail_eligible' => false,
            'reason' => 'invitation_required',
            'public_result' => $publicResult,
            'request_id' => null,
            'verification_token' => null,
            'expires_at' => null,
        ];
    }

    if ($mode === 'invite_only' && viewer_invitation_registration_preflight((string) $invitationToken, $normalized) === null) {
        return [
            'accepted' => false,
            'mail_eligible' => false,
            'reason' => 'invalid_invitation',
            'public_result' => $publicResult,
            'request_id' => null,
            'verification_token' => null,
            'expires_at' => null,
        ];
    }

    $identityDecision = viewer_registration_request_authorize_identity($normalized);
    if (!$identityDecision['allowed']) {
        return [
            'accepted' => false,
            'mail_eligible' => false,
            'reason' => $identityDecision['reason'] === 'limiter_unavailable' ? 'limiter_unavailable' : 'rate_limited',
            'public_result' => $publicResult,
            'request_id' => null,
            'verification_token' => null,
            'expires_at' => null,
        ];
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $rowCount = viewer_registration_capacity_lock();

        // Re-read policy after taking the staging admission lock. This closes the race
        // where an open-origin request started just before an administrator restricted
        // registration and would otherwise create fresh staging after cleanup completed.
        $mode = viewer_registration_mode();
        if ($mode === 'disabled' || ($mode === 'invite_only' && trim((string) $invitationToken) === '')) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return [
                'accepted' => false,
                'mail_eligible' => false,
                'reason' => $mode === 'disabled' ? 'registration_disabled' : 'invitation_required',
                'public_result' => $publicResult,
                'request_id' => null,
                'verification_token' => null,
                'expires_at' => null,
            ];
        }

        $accountStmt = $pdo->prepare('SELECT id FROM viewer_accounts WHERE normalized_email = ? LIMIT 1');
        $accountStmt->execute([$normalized]);
        if ($accountStmt->fetchColumn() !== false) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'accepted' => false,
                'mail_eligible' => false,
                'reason' => 'existing_account',
                'public_result' => $publicResult,
                'request_id' => null,
                'verification_token' => null,
                'expires_at' => null,
            ];
        }

        $existingStmt = $pdo->prepare(
            'SELECT * FROM viewer_registration_requests WHERE normalized_email = ? LIMIT 1 FOR UPDATE'
        );
        $existingStmt->execute([$normalized]);
        $existing = $existingStmt->fetch();

        $invitation = null;
        if (trim((string) $invitationToken) !== '') {
            $inviteStmt = $pdo->prepare(
                'SELECT * FROM viewer_invitations WHERE token_hash = ? LIMIT 1 FOR UPDATE'
            );
            $inviteStmt->execute([security_authority_token_hash((string) $invitationToken)]);
            $invitation = $inviteStmt->fetch();
            if (!$invitation) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return [
                    'accepted' => false,
                    'mail_eligible' => false,
                    'reason' => 'invalid_invitation',
                    'public_result' => $publicResult,
                    'request_id' => null,
                    'verification_token' => null,
                    'expires_at' => null,
                ];
            }

            $sameClaim = $existing
                && (int) ($existing['viewer_invitation_id'] ?? 0) === (int) $invitation['id']
                && (string) ($existing['normalized_email'] ?? '') === $normalized;
            $invitationExpiresAt = strtotime((string) ($invitation['expires_at'] ?? ''));
            $expectedFingerprint = trim((string) ($invitation['target_email_fingerprint'] ?? ''));
            $invitationStateValid = empty($invitation['revoked_at'])
                && $invitationExpiresAt !== false
                && $invitationExpiresAt >= time()
                && ($expectedFingerprint === '' || hash_equals($expectedFingerprint, viewer_email_fingerprint($normalized)));

            // Re-check authority while holding the invitation row lock. The earlier preflight is
            // only an abuse-budget optimization and must never become the authorization decision.
            if (!$invitationStateValid || (!$sameClaim && !empty($invitation['claimed_at']))) {
                if ($ownsTransaction) {
                    $pdo->rollBack();
                }
                return [
                    'accepted' => false,
                    'mail_eligible' => false,
                    'reason' => 'invalid_invitation',
                    'public_result' => $publicResult,
                    'request_id' => null,
                    'verification_token' => null,
                    'expires_at' => null,
                ];
            }
        } elseif ($mode === 'invite_only') {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return [
                'accepted' => false,
                'mail_eligible' => false,
                'reason' => 'invitation_required',
                'public_result' => $publicResult,
                'request_id' => null,
                'verification_token' => null,
                'expires_at' => null,
            ];
        }

        if ($existing && viewer_registration_pending_has_sent_valid_verification_authority($existing)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'accepted' => true,
                'mail_eligible' => false,
                'reason' => 'pending_verification',
                'public_result' => $publicResult,
                'request_id' => (int) $existing['id'],
                'verification_token' => null,
                'expires_at' => (string) $existing['expires_at'],
            ];
        }

        if ($existing
            && (string) ($existing['status'] ?? '') === VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED
            && strtotime((string) ($existing['expires_at'] ?? '')) >= time()) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return [
                'accepted' => true,
                'mail_eligible' => false,
                'reason' => 'already_verified',
                'public_result' => $publicResult,
                'request_id' => (int) $existing['id'],
                'verification_token' => null,
                'expires_at' => (string) $existing['expires_at'],
            ];
        }

        if (!$existing && $rowCount >= viewer_registration_request_cap()) {
            $rowCount = viewer_registration_cleanup_requests_locked();
            if ($rowCount >= viewer_registration_request_cap()) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                return [
                    'accepted' => false,
                    'mail_eligible' => false,
                    'reason' => 'storage_cap',
                    'public_result' => $publicResult,
                    'request_id' => null,
                    'verification_token' => null,
                    'expires_at' => null,
                ];
            }
        }

        $token = security_opaque_token_generate(32);
        $now = now_sql();
        $tokenExpiresAt = date('Y-m-d H:i:s', time() + viewer_registration_verification_lifetime_seconds());
        $requestExpiresAt = date('Y-m-d H:i:s', time() + viewer_registration_request_lifetime_seconds());
        $ipHash = $resolvedIp === '' ? null : viewer_security_fingerprint('viewer-registration-ip', $resolvedIp);
        $invitationId = $invitation ? (int) $invitation['id'] : null;

        if ($existing) {
            $requestId = (int) $existing['id'];
            $update = $pdo->prepare(
                'UPDATE viewer_registration_requests SET '
                . 'email = ?, normalized_email = ?, email_fingerprint = ?, viewer_invitation_id = ?, '
                . 'status = ?, request_ip_hash = ?, verification_token_hash = ?, '
                . 'verification_token_expires_at = ?, verification_token_consumed_at = NULL, '
                . 'expires_at = ?, verified_at = NULL, cancelled_at = NULL, updated_at = ? '
                . 'WHERE id = ?'
            );
            $update->execute([
                trim($email),
                $normalized,
                viewer_email_fingerprint($normalized),
                $invitationId,
                VIEWER_REGISTRATION_STATUS_PENDING,
                $ipHash,
                security_authority_token_hash($token),
                $tokenExpiresAt,
                $requestExpiresAt,
                $now,
                $requestId,
            ]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO viewer_registration_requests '
                . '(email, normalized_email, email_fingerprint, viewer_invitation_id, status, request_ip_hash, '
                . 'verification_token_hash, verification_token_expires_at, expires_at, created_at, updated_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                trim($email),
                $normalized,
                viewer_email_fingerprint($normalized),
                $invitationId,
                VIEWER_REGISTRATION_STATUS_PENDING,
                $ipHash,
                security_authority_token_hash($token),
                $tokenExpiresAt,
                $requestExpiresAt,
                $now,
                $now,
            ]);
            $requestId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE viewer_registration_state SET active_request_count = active_request_count + 1, updated_at = ? '
                . 'WHERE state_key = ?'
            )->execute([$now, VIEWER_REGISTRATION_STATE_KEY]);
        }

        if ($invitation && empty($invitation['claimed_at'])) {
            $claim = $pdo->prepare(
                'UPDATE viewer_invitations SET claimed_at = ? '
                . 'WHERE id = ? AND claimed_at IS NULL AND revoked_at IS NULL AND expires_at >= ?'
            );
            $claim->execute([$now, (int) $invitation['id'], $now]);
            if ($claim->rowCount() !== 1) {
                throw new RuntimeException('Viewer invitation claim lost a concurrent race.');
            }
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'accepted' => true,
            'mail_eligible' => true,
            'reason' => 'pending_verification',
            'public_result' => $publicResult,
            'request_id' => $requestId,
            'verification_token' => $token,
            'expires_at' => $requestExpiresAt,
        ];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Consume the dedicated per-identifier verification-resend budget.
 *
 * This first-party limiter is intentionally separate from verification-mail authorization.
 * Both layers must allow a resend before transport can run. Limiter storage failure fails closed.
 *
 * @param string $email Candidate email address.
 * @return array{allowed:bool,reason:string,retry_after_seconds:int} Resend authorization decision.
 */
function viewer_registration_verification_resend_authorize(string $email): array
{
    $normalized = viewer_email_normalize($email);
    if ($normalized === null) {
        return ['allowed' => false, 'reason' => 'invalid_email', 'retry_after_seconds' => 0];
    }

    try {
        $decision = viewer_rate_limit_consume(
            'viewer_resend_verification_identifier',
            'identifier',
            $normalized
        );
    } catch (Throwable) {
        return ['allowed' => false, 'reason' => 'limiter_unavailable', 'retry_after_seconds' => 0];
    }

    if (empty($decision['allowed'])) {
        return [
            'allowed' => false,
            'reason' => 'rate_limited',
            'retry_after_seconds' => (int) ($decision['retry_after_seconds'] ?? 0),
        ];
    }

    return ['allowed' => true, 'reason' => 'ok', 'retry_after_seconds' => 0];
}

/**
 * Return whether an invitation-backed request still has live server-side invitation authority.
 *
 * Open-origin requests do not need an invitation row. Invitation-backed requests must still
 * reference the claimed, unrevoked, unexpired invitation that staged the same normalized email.
 * The caller must already hold the registration-state/request locks for mutation workflows.
 *
 * @param array $row Locked registration-request row.
 * @return bool True when invitation authority remains eligible for resend.
 */
function viewer_registration_request_invitation_resend_allowed_locked(array $row): bool
{
    $invitationId = (int) ($row['viewer_invitation_id'] ?? 0);
    if ($invitationId <= 0) {
        return true;
    }

    $stmt = db()->prepare('SELECT * FROM viewer_invitations WHERE id = ? LIMIT 1 FOR UPDATE');
    $stmt->execute([$invitationId]);
    $invitation = $stmt->fetch();
    if (!$invitation || !empty($invitation['revoked_at']) || empty($invitation['claimed_at'])) {
        return false;
    }

    $expiresAt = strtotime((string) ($invitation['expires_at'] ?? ''));
    if ($expiresAt === false || $expiresAt < time()) {
        return false;
    }

    $expectedFingerprint = trim((string) ($invitation['target_email_fingerprint'] ?? ''));
    if ($expectedFingerprint === '') {
        return true;
    }

    $requestFingerprint = viewer_email_fingerprint((string) ($row['normalized_email'] ?? ''));
    return $requestFingerprint !== '' && hash_equals($expectedFingerprint, $requestFingerprint);
}

/**
 * Prepare one additional verification capability for an eligible staged registration.
 *
 * The submitted email is only a lookup candidate. Recipient identity and invitation/open origin
 * come from the locked registration row. The historical primary token is never rotated or edited.
 * Plaintext child authority is returned only for immediate mail orchestration and is never persisted.
 *
 * @param string $email Candidate viewer email.
 * @return array{accepted:bool,mail_eligible:bool,reason:string,public_result:string,request_id:?int,verification_authority_id:?int,verification_token:?string,recipient_email:?string,invitation_backed:bool,expires_at:?string}
 */
function viewer_registration_verification_resend_prepare(string $email): array
{
    $publicResult = viewer_registration_public_result_code();
    $empty = static fn (string $reason): array => [
        'accepted' => false,
        'mail_eligible' => false,
        'reason' => $reason,
        'public_result' => $publicResult,
        'request_id' => null,
        'verification_authority_id' => null,
        'verification_token' => null,
        'recipient_email' => null,
        'invitation_backed' => false,
        'expires_at' => null,
    ];

    if (!viewer_registration_requests_enabled()) {
        return $empty('registration_disabled');
    }
    if (!viewer_security_transport_allowed()) {
        return $empty('secure_transport_required');
    }
    if (!viewer_auth_storage_available() || !viewer_registration_storage_available()) {
        return $empty('storage_unavailable');
    }

    $normalized = viewer_email_normalize($email);
    if ($normalized === null) {
        return $empty('invalid_email');
    }

    $resendDecision = viewer_registration_verification_resend_authorize($normalized);
    if (empty($resendDecision['allowed'])) {
        return $empty((string) ($resendDecision['reason'] ?? 'rate_limited'));
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        // Use the same singleton lock as registration-mode transitions so policy is
        // re-read from authoritative state before new resend authority is minted.
        viewer_registration_capacity_lock();

        $requestStmt = $pdo->prepare(
            'SELECT * FROM viewer_registration_requests WHERE normalized_email = ? LIMIT 1 FOR UPDATE'
        );
        $requestStmt->execute([$normalized]);
        $request = $requestStmt->fetch();
        if (!$request || !viewer_registration_request_row_is_pending_active($request)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $empty($request ? 'request_ineligible' : 'not_found');
        }

        if (!viewer_registration_request_allowed_by_current_mode($request)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $empty('registration_mode_restricted');
        }
        if (!viewer_registration_request_invitation_resend_allowed_locked($request)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $empty('invitation_invalid');
        }

        $accountStmt = $pdo->prepare('SELECT id FROM viewer_accounts WHERE normalized_email = ? LIMIT 1');
        $accountStmt->execute([(string) $request['normalized_email']]);
        if ($accountStmt->fetchColumn() !== false) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $empty('existing_account');
        }

        $now = now_sql();
        $pdo->prepare(
            'DELETE FROM viewer_registration_verification_tokens '
            . 'WHERE viewer_registration_request_id = ? AND expires_at < ?'
        )->execute([(int) $request['id'], $now]);

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM viewer_registration_verification_tokens '
            . 'WHERE viewer_registration_request_id = ?'
        );
        $countStmt->execute([(int) $request['id']]);
        if ((int) $countStmt->fetchColumn() >= viewer_registration_resend_token_cap()) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $empty('authority_cap');
        }

        $requestExpiry = strtotime((string) ($request['expires_at'] ?? ''));
        if ($requestExpiry === false || $requestExpiry < time()) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $empty('request_expired');
        }

        $token = security_opaque_token_generate(32);
        $tokenExpiry = min($requestExpiry, time() + viewer_registration_verification_lifetime_seconds());
        if ($tokenExpiry < time()) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $empty('request_expired');
        }
        $tokenExpiresAt = date('Y-m-d H:i:s', $tokenExpiry);

        $insert = $pdo->prepare(
            'INSERT INTO viewer_registration_verification_tokens '
            . '(viewer_registration_request_id, token_hash, expires_at, created_at, sent_at) '
            . 'VALUES (?, ?, ?, ?, NULL)'
        );
        $insert->execute([
            (int) $request['id'],
            security_authority_token_hash($token),
            $tokenExpiresAt,
            $now,
        ]);
        $authorityId = (int) $pdo->lastInsertId();
        if ($authorityId <= 0) {
            throw new RuntimeException('Viewer verification resend authority was not created.');
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return [
            'accepted' => true,
            'mail_eligible' => true,
            'reason' => 'pending_verification',
            'public_result' => $publicResult,
            'request_id' => (int) $request['id'],
            'verification_authority_id' => $authorityId,
            'verification_token' => $token,
            'recipient_email' => (string) $request['normalized_email'],
            'invitation_backed' => viewer_registration_request_is_invitation_backed($request),
            'expires_at' => $tokenExpiresAt,
        ];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Delete one prepared child verification authority that was never marked sent.
 *
 * This cleanup is intentionally available even after registration is disabled. It can retire
 * an unreachable prepared token without touching the historical primary token or a sent sibling.
 *
 * @param int $requestId Owning registration request id.
 * @param int $authorityId Prepared child authority id.
 * @return bool True when one unsent authority was deleted.
 */
function viewer_registration_verification_resend_discard(int $requestId, int $authorityId): bool
{
    if ($requestId <= 0 || $authorityId <= 0 || !viewer_registration_storage_available()) {
        return false;
    }

    $stmt = db()->prepare(
        'DELETE FROM viewer_registration_verification_tokens '
        . 'WHERE id = ? AND viewer_registration_request_id = ? AND sent_at IS NULL'
    );
    $stmt->execute([$authorityId, $requestId]);
    return $stmt->rowCount() === 1;
}

/**
 * Revalidate one prepared resend authority under the registration-mode lock and run transport.
 *
 * The callback is invoked only after current policy, request lifetime, invitation authority,
 * durable-account absence, and child-token state are revalidated. Holding the same singleton
 * registration lock used by Admin mode transitions makes the send decision serialize with
 * open-to-restrictive policy changes. Successful transport handoff is recorded atomically;
 * failed transport deletes only child B and never touches historical token A.
 *
 * @param int $requestId Owning registration request id.
 * @param int $authorityId Prepared child verification authority id.
 * @param callable $deliver Zero-argument mail transport callback returning an array with sent status.
 * @return array{sent:bool,reason:string,delivery:array<string,mixed>}
 */
function viewer_registration_verification_resend_deliver_locked(int $requestId, int $authorityId, callable $deliver): array
{
    if ($requestId <= 0 || $authorityId <= 0 || !viewer_registration_storage_available()) {
        return ['sent' => false, 'reason' => 'storage_unavailable', 'delivery' => []];
    }

    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Verification resend delivery requires an independent transaction boundary.');
    }

    $pdo->beginTransaction();
    try {
        viewer_registration_capacity_lock();
        $stmt = $pdo->prepare(
            'SELECT vrr.*, vrvt.id AS resend_token_id, vrvt.expires_at AS resend_token_expires_at, '
            . 'vrvt.sent_at AS resend_token_sent_at '
            . 'FROM viewer_registration_verification_tokens vrvt '
            . 'INNER JOIN viewer_registration_requests vrr ON vrr.id = vrvt.viewer_registration_request_id '
            . 'WHERE vrr.id = ? AND vrvt.id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$requestId, $authorityId]);
        $row = $stmt->fetch();
        $authorityExpiry = $row ? strtotime((string) ($row['resend_token_expires_at'] ?? '')) : false;
        $eligible = $row
            && empty($row['resend_token_sent_at'])
            && viewer_registration_request_row_is_pending_active($row)
            && $authorityExpiry !== false
            && $authorityExpiry >= time()
            && viewer_registration_request_allowed_by_current_mode($row)
            && viewer_registration_request_invitation_resend_allowed_locked($row)
            && viewer_security_transport_allowed()
            && viewer_auth_storage_available();

        if ($eligible) {
            $accountStmt = $pdo->prepare('SELECT id FROM viewer_accounts WHERE normalized_email = ? LIMIT 1');
            $accountStmt->execute([(string) $row['normalized_email']]);
            $eligible = $accountStmt->fetchColumn() === false;
        }

        if (!$eligible) {
            $pdo->prepare(
                'DELETE FROM viewer_registration_verification_tokens '
                . 'WHERE id = ? AND viewer_registration_request_id = ? AND sent_at IS NULL'
            )->execute([$authorityId, $requestId]);
            $pdo->commit();
            return ['sent' => false, 'reason' => 'request_ineligible', 'delivery' => []];
        }

        $delivery = $deliver();
        if (!is_array($delivery)) {
            $delivery = [];
        }
        if (empty($delivery['sent'])) {
            $pdo->prepare(
                'DELETE FROM viewer_registration_verification_tokens '
                . 'WHERE id = ? AND viewer_registration_request_id = ? AND sent_at IS NULL'
            )->execute([$authorityId, $requestId]);
            $pdo->commit();
            return ['sent' => false, 'reason' => 'mail_delivery_failed', 'delivery' => $delivery];
        }

        $now = now_sql();
        $mark = $pdo->prepare(
            'UPDATE viewer_registration_verification_tokens SET sent_at = ? '
            . 'WHERE id = ? AND viewer_registration_request_id = ? AND sent_at IS NULL AND expires_at >= ?'
        );
        $mark->execute([$now, $authorityId, $requestId, $now]);
        if ($mark->rowCount() !== 1) {
            throw new RuntimeException('Verification resend handoff state could not be recorded.');
        }

        $requestUpdate = $pdo->prepare(
            'UPDATE viewer_registration_requests '
            . 'SET verification_send_count = verification_send_count + 1, verification_last_sent_at = ?, updated_at = ? '
            . 'WHERE id = ? AND status = ? AND cancelled_at IS NULL AND expires_at >= ?'
        );
        $requestUpdate->execute([
            $now,
            $now,
            $requestId,
            VIEWER_REGISTRATION_STATUS_PENDING,
            $now,
        ]);
        if ($requestUpdate->rowCount() !== 1) {
            throw new RuntimeException('Verification resend request handoff state could not be recorded.');
        }

        $pdo->commit();
        return ['sent' => true, 'reason' => 'sent', 'delivery' => $delivery];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Mark one verification message as successfully handed to the configured mail transport.
 *
 * Rate-limit reservation must happen before delivery. This counter is informational lifecycle
 * state and is updated only after the transport reports success.
 *
 * @param int $requestId Registration request id.
 * @return bool True when one pending request was updated.
 */
function viewer_registration_mark_verification_sent(int $requestId): bool
{
    if (!viewer_registration_requests_enabled() || $requestId <= 0 || !viewer_registration_storage_available()) {
        return false;
    }

    $stmt = db()->prepare(
        'UPDATE viewer_registration_requests '
        . 'SET verification_send_count = verification_send_count + 1, verification_last_sent_at = ?, updated_at = ? '
        . 'WHERE id = ? AND status = ? AND expires_at >= ?'
    );
    $now = now_sql();
    $stmt->execute([$now, $now, $requestId, VIEWER_REGISTRATION_STATUS_PENDING, $now]);
    return $stmt->rowCount() === 1;
}

/**
 * Look up one primary or resent verification authority without exposing token material.
 *
 * Historical Phase 4.1 primary hashes remain on viewer_registration_requests. Phase 4.2
 * resent hashes live in the normalized child table and are usable only after successful
 * transport handoff recorded sent_at. The returned row is always the owning request row.
 *
 * @param string $token Plaintext verification capability supplied by the browser.
 * @param bool $forUpdate Whether authoritative rows should be locked for confirmation.
 * @return ?array Matching request plus internal authority metadata, otherwise null.
 */
function viewer_registration_verification_lookup(string $token, bool $forUpdate = false): ?array
{
    if ($token === '' || strlen($token) > 512) {
        return null;
    }

    $hash = security_authority_token_hash($token);
    $lock = $forUpdate ? ' FOR UPDATE' : '';

    $primaryStmt = db()->prepare(
        'SELECT * FROM viewer_registration_requests WHERE verification_token_hash = ? LIMIT 1' . $lock
    );
    $primaryStmt->execute([$hash]);
    $primary = $primaryStmt->fetch();
    if ($primary && viewer_registration_request_row_is_verifiable($primary)) {
        $primary['_verification_authority_kind'] = 'primary';
        $primary['_verification_authority_id'] = null;
        return $primary;
    }

    $childStmt = db()->prepare(
        'SELECT vrr.*, vrvt.id AS resend_token_id, vrvt.expires_at AS resend_token_expires_at, '
        . 'vrvt.sent_at AS resend_token_sent_at '
        . 'FROM viewer_registration_verification_tokens vrvt '
        . 'INNER JOIN viewer_registration_requests vrr ON vrr.id = vrvt.viewer_registration_request_id '
        . 'WHERE vrvt.token_hash = ? LIMIT 1' . $lock
    );
    $childStmt->execute([$hash]);
    $child = $childStmt->fetch();
    if (!$child || !viewer_registration_request_row_is_pending_active($child) || empty($child['resend_token_sent_at'])) {
        return null;
    }

    $childExpiry = strtotime((string) ($child['resend_token_expires_at'] ?? ''));
    if ($childExpiry === false || $childExpiry < time()) {
        return null;
    }

    $child['_verification_authority_kind'] = 'resend';
    $child['_verification_authority_id'] = (int) $child['resend_token_id'];
    return $child;
}

/**
 * Validate a registration verification token without consuming it.
 *
 * This scanner-safe GET boundary recognizes both historical Phase 4.1 primary authority
 * and successfully mailed Phase 4.2 sibling authority. Request policy is revalidated now.
 *
 * @param string $token Plaintext verification token.
 * @return ?array Matching pending registration row, otherwise null.
 */
function viewer_registration_verification_validate(string $token): ?array
{
    if (!viewer_registration_requests_enabled() || $token === '' || !viewer_registration_storage_available()
        || !viewer_security_transport_allowed()) {
        return null;
    }

    $row = viewer_registration_verification_lookup($token, false);
    return $row && viewer_registration_request_allowed_by_current_mode($row) ? $row : null;
}

/**
 * Consume one primary or resent verification authority and mark only the staged request email-verified.
 *
 * No durable viewer account is created here. Successful explicit confirmation establishes only
 * a short-lived server-side activation grant. Request-level status plus deletion of child rows
 * closes every sibling authority regardless of which valid token won the confirmation race.
 *
 * @param string $token Plaintext verification token.
 * @return ?array Newly email-verified registration row, otherwise null.
 */
function viewer_registration_verification_confirm(string $token): ?array
{
    if (!viewer_registration_requests_enabled() || $token === '' || !viewer_registration_storage_available()
        || !viewer_security_transport_allowed()) {
        return null;
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $row = viewer_registration_verification_lookup($token, true);
        if (!$row || !viewer_registration_request_allowed_by_current_mode($row)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return null;
        }

        $now = now_sql();
        $verifiedExpiry = date('Y-m-d H:i:s', time() + viewer_registration_verified_lifetime_seconds());
        $update = $pdo->prepare(
            'UPDATE viewer_registration_requests SET status = ?, verification_token_consumed_at = ?, '
            . 'verified_at = ?, expires_at = ?, updated_at = ? '
            . 'WHERE id = ? AND status = ? AND verification_token_consumed_at IS NULL '
            . 'AND cancelled_at IS NULL AND expires_at >= ?'
        );
        $update->execute([
            VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED,
            $now,
            $now,
            $verifiedExpiry,
            $now,
            (int) $row['id'],
            VIEWER_REGISTRATION_STATUS_PENDING,
            $now,
        ]);
        if ($update->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->rollBack();
            }
            return null;
        }

        // The request transition is authoritative; deleting children additionally removes
        // sibling lookup rows immediately instead of waiting for scheduled cleanup.
        $pdo->prepare(
            'DELETE FROM viewer_registration_verification_tokens WHERE viewer_registration_request_id = ?'
        )->execute([(int) $row['id']]);

        $row['status'] = VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED;
        $row['verification_token_consumed_at'] = $now;
        $row['verified_at'] = $now;
        $row['expires_at'] = $verifiedExpiry;
        viewer_registration_activation_establish($row);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $row;
    } catch (Throwable $exception) {
        viewer_registration_activation_clear();
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Revoke one invitation capability and cancel any attached staged registration.
 *
 * Revocation is intentionally available while registration itself is disabled so an
 * operator can invalidate dormant capabilities before re-enabling the feature later.
 *
 * @param int $invitationId Invitation row id.
 * @return bool True when one invitation was newly revoked.
 */
function viewer_invitation_revoke(int $invitationId): bool
{
    if ($invitationId <= 0 || !viewer_registration_storage_available()) {
        return false;
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $now = now_sql();
        $stmt = $pdo->prepare(
            'UPDATE viewer_invitations SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$now, $invitationId]);
        if ($stmt->rowCount() !== 1) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return false;
        }

        $pdo->prepare(
            'UPDATE viewer_registration_requests SET status = ?, cancelled_at = ?, updated_at = ? '
            . 'WHERE viewer_invitation_id = ? AND status IN (?, ?)'
        )->execute([
            VIEWER_REGISTRATION_STATUS_CANCELLED,
            $now,
            $now,
            $invitationId,
            VIEWER_REGISTRATION_STATUS_PENDING,
            VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED,
        ]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Permanently remove one administrator invitation after invalidating any staged authority.
 *
 * Revocation happens first and is committed independently. If the subsequent delete fails,
 * the bearer capability still remains invalid and any staged registration remains cancelled.
 * Deleting an already consumed invitation never deletes the activated viewer account.
 *
 * @param int $invitationId Invitation row id.
 * @return bool True when the invitation row was deleted.
 */
function viewer_invitation_delete(int $invitationId): bool
{
    if ($invitationId <= 0 || !viewer_registration_storage_available()) {
        return false;
    }

    // Invalidate first. viewer_invitation_revoke() also cancels a pending or email-verified
    // registration request. A previously revoked invitation may still be deleted below.
    viewer_invitation_revoke($invitationId);

    $stmt = db()->prepare('DELETE FROM viewer_invitations WHERE id = ?');
    $stmt->execute([$invitationId]);
    return $stmt->rowCount() === 1;
}
/**
 * Run bounded cleanup for pending registrations and invitation capabilities.
 *
 * Cleanup is intended to be called from scheduled site maintenance, not from every page request.
 *
 * @return array<string,int|string> Cleanup result.
 */
function viewer_registration_maintenance_cleanup(): array
{
    if (!viewer_registration_storage_available()) {
        return ['storage' => 'unavailable'];
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        viewer_registration_capacity_lock();
        $now = now_sql();
        $verificationDelete = $pdo->prepare(
            'DELETE FROM viewer_registration_verification_tokens WHERE expires_at < ? LIMIT 1000'
        );
        $verificationDelete->execute([$now]);
        $verificationTokensDeleted = $verificationDelete->rowCount();

        $before = (int) $pdo->query('SELECT COUNT(*) FROM viewer_registration_requests')->fetchColumn();
        $remaining = viewer_registration_cleanup_requests_locked();
        $requestsDeleted = max(0, $before - $remaining);

        $oldInvitationCutoff = date('Y-m-d H:i:s', time() - 604800);
        $inviteDelete = $pdo->prepare(
            'DELETE FROM viewer_invitations WHERE expires_at < ? '
            . 'OR revoked_at < ? OR claimed_at < ? LIMIT 1000'
        );
        $inviteDelete->execute([now_sql(), $oldInvitationCutoff, $oldInvitationCutoff]);
        $invitationsDeleted = $inviteDelete->rowCount();

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return [
            'registration_requests' => $requestsDeleted,
            'verification_tokens' => $verificationTokensDeleted,
            'invitations' => $invitationsDeleted,
        ];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Convert the current verified staging registration into exactly one durable viewer account.
 *
 * Authority comes only from the short-lived server-side activation grant established by
 * explicit verification confirmation. No client-supplied request id participates in the
 * authorization decision, and successful activation does not create a viewer login session.
 *
 * @param string $password New password for the durable viewer account.
 * @return array{activated:bool,reason:string,account_id:?int} Internal activation result.
 */
function viewer_registration_activate_verified(string $password): array
{
    if (!viewer_accounts_enabled()) {
        return ['activated' => false, 'reason' => 'viewer_disabled', 'account_id' => null];
    }
    if (!viewer_auth_storage_available()) {
        return ['activated' => false, 'reason' => 'storage_unavailable', 'account_id' => null];
    }
    if (!viewer_security_transport_allowed()) {
        return ['activated' => false, 'reason' => 'secure_transport_required', 'account_id' => null];
    }

    $activation = viewer_registration_activation_state();
    if ($activation === null) {
        return ['activated' => false, 'reason' => 'activation_state_invalid', 'account_id' => null];
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $registrationCount = viewer_registration_capacity_lock();
        $registrationCount = viewer_registration_capacity_recount_locked();

        $requestStmt = $pdo->prepare(
            'SELECT * FROM viewer_registration_requests WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $requestStmt->execute([$activation['request_id']]);
        $request = $requestStmt->fetch();
        $requestExpiry = $request ? strtotime((string) ($request['expires_at'] ?? '')) : false;
        $verifiedAt = $request ? strtotime((string) ($request['verified_at'] ?? '')) : false;
        if (!$request
            || (string) ($request['status'] ?? '') !== VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED
            || !empty($request['cancelled_at'])
            || $requestExpiry === false
            || $requestExpiry < time()
            || $verifiedAt === false
            || !viewer_registration_activation_matches_row($activation, $request)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            viewer_registration_activation_clear();
            return ['activated' => false, 'reason' => 'activation_state_invalid', 'account_id' => null];
        }

        if (!viewer_registration_request_allowed_by_current_mode($request)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            viewer_registration_activation_clear();
            return ['activated' => false, 'reason' => 'registration_mode_restricted', 'account_id' => null];
        }

        $invitationId = (int) ($request['viewer_invitation_id'] ?? 0);
        if ($invitationId > 0) {
            $inviteStmt = $pdo->prepare('SELECT * FROM viewer_invitations WHERE id = ? LIMIT 1 FOR UPDATE');
            $inviteStmt->execute([$invitationId]);
            $invitation = $inviteStmt->fetch();
            $inviteExpiry = $invitation ? strtotime((string) ($invitation['expires_at'] ?? '')) : false;
            $expectedFingerprint = trim((string) ($invitation['target_email_fingerprint'] ?? ''));
            $requestFingerprint = viewer_email_fingerprint((string) ($request['normalized_email'] ?? ''));
            if (!$invitation
                || !empty($invitation['revoked_at'])
                || empty($invitation['claimed_at'])
                || $inviteExpiry === false
                || $inviteExpiry < time()
                || ($expectedFingerprint !== '' && ($requestFingerprint === '' || !hash_equals($expectedFingerprint, $requestFingerprint)))) {
                if ($ownsTransaction) {
                    $pdo->commit();
                }
                viewer_registration_activation_clear();
                return ['activated' => false, 'reason' => 'invitation_invalid', 'account_id' => null];
            }
        }

        $existingStmt = $pdo->prepare('SELECT id FROM viewer_accounts WHERE normalized_email = ? LIMIT 1');
        $existingStmt->execute([(string) $request['normalized_email']]);
        if ($existingStmt->fetchColumn() !== false) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            viewer_registration_activation_clear();
            return ['activated' => false, 'reason' => 'account_exists', 'account_id' => null];
        }

        viewer_account_capacity_lock();
        $accountCount = viewer_account_capacity_recount_locked();
        if ($accountCount >= viewer_account_cap()) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['activated' => false, 'reason' => 'account_capacity', 'account_id' => null];
        }

        if (!viewer_password_input_is_acceptable($password)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return ['activated' => false, 'reason' => 'password_policy', 'account_id' => null];
        }

        $passwordHash = viewer_password_hash($password);
        $now = now_sql();
        $insert = $pdo->prepare(
            'INSERT INTO viewer_accounts '
            . '(email, normalized_email, password_hash, status, security_version, email_verified_at, password_changed_at, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?)'
        );
        $insert->execute([
            (string) $request['email'],
            (string) $request['normalized_email'],
            $passwordHash,
            VIEWER_ACCOUNT_STATUS_ACTIVE,
            (string) $request['verified_at'],
            $now,
            $now,
            $now,
        ]);
        $accountId = (int) $pdo->lastInsertId();
        if ($accountId <= 0) {
            throw new RuntimeException('Viewer account activation did not create a durable account.');
        }

        $pdo->prepare(
            'UPDATE viewer_account_state SET account_count = ?, updated_at = ? WHERE state_key = ?'
        )->execute([$accountCount + 1, $now, VIEWER_ACCOUNT_CAPACITY_STATE_KEY]);

        $delete = $pdo->prepare('DELETE FROM viewer_registration_requests WHERE id = ?');
        $delete->execute([(int) $request['id']]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Viewer activation could not retire the staging registration.');
        }
        $pdo->prepare(
            'UPDATE viewer_registration_state SET active_request_count = ?, updated_at = ? WHERE state_key = ?'
        )->execute([max(0, $registrationCount - 1), $now, VIEWER_REGISTRATION_STATE_KEY]);

        if (function_exists(__NAMESPACE__ . '\\viewer_security_event_record')) {
            viewer_security_event_record('viewer.account_activated', $accountId, 'success', [
                'account_state' => VIEWER_ACCOUNT_STATUS_ACTIVE,
                'security_version' => 1,
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        viewer_registration_activation_clear();
        return ['activated' => true, 'reason' => 'activated', 'account_id' => $accountId];
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
