<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_anti_automation.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides a fully first-party adaptive anti-automation gate for anonymous viewer registration actions.
 *
 * Responsibilities:
 *   - Issue and validate short-lived server-signed form and challenge tickets
 *   - Bind anonymous anti-automation authority to the current PHP session without creating viewer identity
 *   - Enforce bounded one-time/replay state, server-measured form age, and randomized honeypots
 *   - Reuse the existing viewer rate-limit subsystem for local repeated-request escalation and hard suppression
 *   - Issue and verify bounded first-party SHA-256 proof-of-work challenges with an accessible no-JavaScript fallback
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
 *   - This service never owns registration, invitation, verification, account, or mail SQL.
 *   - Anti-automation success authorizes only one anonymous HTTP action to continue.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use Throwable;

const VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE = 'viewer_anti_automation';
const VIEWER_ANTI_AUTOMATION_ACTION_REGISTER = 'register';
const VIEWER_ANTI_AUTOMATION_ACTION_RESEND = 'resend';
const VIEWER_ANTI_AUTOMATION_RESULT_ALLOW = 'allow';
const VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED = 'challenge_required';
const VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS = 'suppress';
const VIEWER_ANTI_AUTOMATION_RESULT_INVALID = 'invalid';
const VIEWER_ANTI_AUTOMATION_KIND_FORM = 'form';
const VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE = 'challenge';
const VIEWER_ANTI_AUTOMATION_OUTSTANDING_CAP = 12;
const VIEWER_ANTI_AUTOMATION_CHALLENGE_LIFETIME_SECONDS = 180;
const VIEWER_ANTI_AUTOMATION_FALLBACK_MIN_AGE_SECONDS = 3;
const VIEWER_ANTI_AUTOMATION_MAX_COUNTER = 1048575;
const VIEWER_ANTI_AUTOMATION_TICKET_MAX_BYTES = 1024;
const VIEWER_ANTI_AUTOMATION_HONEYPOT_MAX_BYTES = 256;

/**
 * Return whether first-party viewer anti-automation protection is enabled.
 *
 * @return bool True when the configured local gate is enabled.
 */
function viewer_anti_automation_enabled(): bool
{
    return !empty(viewer_accounts_config()['anti_automation_enabled']);
}

/**
 * Return the isolated PHP-session namespace used only by pre-auth anti-automation state.
 *
 * @return string Dedicated session key.
 */
function viewer_anti_automation_session_namespace_key(): string
{
    return VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE;
}

/**
 * Return whether an action is part of the fixed Phase 4.3 protected action set.
 *
 * @param string $action Candidate action.
 * @return bool True only for an allowlisted anonymous action.
 */
function viewer_anti_automation_action_is_allowed(string $action): bool
{
    return in_array($action, [
        VIEWER_ANTI_AUTOMATION_ACTION_REGISTER,
        VIEWER_ANTI_AUTOMATION_ACTION_RESEND,
    ], true);
}

/**
 * Encode bytes as unpadded base64url text.
 *
 * @param string $value Raw bytes.
 * @return string URL-safe representation.
 */
function viewer_anti_automation_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

/**
 * Decode one bounded unpadded base64url value.
 *
 * @param string $value Encoded value.
 * @return ?string Decoded bytes or null for malformed input.
 */
function viewer_anti_automation_base64url_decode(string $value): ?string
{
    if ($value === '' || strlen($value) > 900 || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
        return null;
    }
    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return is_string($decoded) ? $decoded : null;
}

/**
 * Return the installation-specific signature for one encoded anti-automation payload.
 *
 * @param string $encodedPayload Canonical encoded payload.
 * @return string Lowercase HMAC digest.
 */
function viewer_anti_automation_payload_signature(string $encodedPayload): string
{
    return viewer_security_fingerprint('viewer-anti-automation-ticket-v1', $encodedPayload);
}

/**
 * Encode and sign one bounded anti-automation payload.
 *
 * @param array<string,int|string> $payload Authoritative ticket fields in canonical insertion order.
 * @return string Browser-carried signed ticket.
 */
function viewer_anti_automation_ticket_encode(array $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || $json === '' || strlen($json) > 640) {
        throw new InvalidArgumentException('Viewer anti-automation ticket payload is invalid.');
    }
    $encoded = viewer_anti_automation_base64url_encode($json);
    return $encoded . '.' . viewer_anti_automation_payload_signature($encoded);
}

/**
 * Decode and authenticate one anti-automation ticket without trusting any payload field first.
 *
 * @param string $ticket Browser-carried signed ticket.
 * @return ?array<string,mixed> Authenticated payload or null.
 */
function viewer_anti_automation_ticket_decode(string $ticket): ?array
{
    if ($ticket === '' || strlen($ticket) > VIEWER_ANTI_AUTOMATION_TICKET_MAX_BYTES) {
        return null;
    }
    $parts = explode('.', $ticket, 2);
    if (count($parts) !== 2 || preg_match('/^[a-f0-9]{64}$/D', $parts[1]) !== 1) {
        return null;
    }
    $expectedSignature = viewer_anti_automation_payload_signature($parts[0]);
    if ($expectedSignature === '' || !hash_equals($expectedSignature, $parts[1])) {
        return null;
    }
    $json = viewer_anti_automation_base64url_decode($parts[0]);
    if ($json === null) {
        return null;
    }
    $payload = json_decode($json, true);
    return is_array($payload) ? $payload : null;
}

/**
 * Return the HMAC fingerprint used as the session-side one-time nonce lookup key.
 *
 * @param string $nonce Public high-entropy ticket nonce.
 * @return string Scoped HMAC fingerprint.
 */
function viewer_anti_automation_nonce_fingerprint(string $nonce): string
{
    return viewer_security_fingerprint('viewer-anti-automation-nonce-v1', $nonce);
}

/**
 * Opportunistically normalize and prune expired anti-automation session entries.
 *
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 */
function viewer_anti_automation_session_cleanup(?int $nowTimestamp = null): void
{
    $now = $nowTimestamp ?? time();
    $key = viewer_anti_automation_session_namespace_key();
    $state = $_SESSION[$key] ?? [];
    $entries = is_array($state) && is_array($state['entries'] ?? null) ? $state['entries'] : [];
    $normalized = [];
    foreach ($entries as $fingerprint => $entry) {
        if (count($normalized) >= 64 || !is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1 || !is_array($entry)) {
            continue;
        }
        $kind = (string) ($entry['kind'] ?? '');
        $action = (string) ($entry['action'] ?? '');
        $issuedAt = (int) ($entry['issued_at'] ?? 0);
        $expiresAt = (int) ($entry['expires_at'] ?? 0);
        $difficulty = (int) ($entry['difficulty'] ?? 0);
        if (!in_array($kind, [VIEWER_ANTI_AUTOMATION_KIND_FORM, VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE], true)
            || !viewer_anti_automation_action_is_allowed($action)
            || $issuedAt <= 0
            || $expiresAt <= $now
            || $expiresAt < $issuedAt) {
            continue;
        }
        $normalized[$fingerprint] = [
            'kind' => $kind,
            'action' => $action,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'difficulty' => $difficulty,
        ];
    }
    uasort($normalized, static function (array $left, array $right): int {
        return ((int) $left['issued_at']) <=> ((int) $right['issued_at']);
    });
    while (count($normalized) > VIEWER_ANTI_AUTOMATION_OUTSTANDING_CAP) {
        array_shift($normalized);
    }
    $_SESSION[$key] = ['entries' => $normalized];
}

/**
 * Register one issued ticket nonce in bounded server-side session state.
 *
 * @param string $nonce Public ticket nonce.
 * @param string $kind Ticket kind.
 * @param string $action Protected action.
 * @param int $issuedAt Authoritative issue timestamp.
 * @param int $expiresAt Authoritative expiry timestamp.
 * @param int $difficulty Signed proof difficulty for challenges, otherwise zero.
 */
function viewer_anti_automation_session_register(
    string $nonce,
    string $kind,
    string $action,
    int $issuedAt,
    int $expiresAt,
    int $difficulty = 0
): void {
    viewer_anti_automation_session_cleanup($issuedAt);
    $fingerprint = viewer_anti_automation_nonce_fingerprint($nonce);
    if ($fingerprint === '') {
        throw new InvalidArgumentException('Viewer anti-automation nonce is invalid.');
    }
    $key = viewer_anti_automation_session_namespace_key();
    $state = $_SESSION[$key] ?? ['entries' => []];
    $entries = is_array($state['entries'] ?? null) ? $state['entries'] : [];
    $entries[$fingerprint] = [
        'kind' => $kind,
        'action' => $action,
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
        'difficulty' => $difficulty,
    ];
    $_SESSION[$key] = ['entries' => $entries];
    viewer_anti_automation_session_cleanup($issuedAt);
}

/**
 * Consume one issued ticket nonce exactly once from the current session.
 *
 * @param string $nonce Public ticket nonce.
 * @param string $kind Expected ticket kind.
 * @param string $action Expected action.
 * @param int $issuedAt Signed issue timestamp.
 * @param int $expiresAt Signed expiry timestamp.
 * @param int $difficulty Signed challenge difficulty or zero for forms.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return bool True only when matching current-session state existed and was consumed.
 */
function viewer_anti_automation_session_consume(
    string $nonce,
    string $kind,
    string $action,
    int $issuedAt,
    int $expiresAt,
    int $difficulty = 0,
    ?int $nowTimestamp = null
): bool {
    $now = $nowTimestamp ?? time();
    viewer_anti_automation_session_cleanup($now);
    $fingerprint = viewer_anti_automation_nonce_fingerprint($nonce);
    $key = viewer_anti_automation_session_namespace_key();
    $entries = is_array($_SESSION[$key]['entries'] ?? null) ? $_SESSION[$key]['entries'] : [];
    $entry = $entries[$fingerprint] ?? null;
    if (!is_array($entry)) {
        return false;
    }
    $matches = (string) ($entry['kind'] ?? '') === $kind
        && (string) ($entry['action'] ?? '') === $action
        && (int) ($entry['issued_at'] ?? 0) === $issuedAt
        && (int) ($entry['expires_at'] ?? 0) === $expiresAt
        && (int) ($entry['difficulty'] ?? 0) === $difficulty;
    if (!$matches) {
        return false;
    }
    unset($entries[$fingerprint]);
    $_SESSION[$key] = ['entries' => $entries];
    return true;
}

/**
 * Validate the strict field structure shared by signed form and challenge tickets.
 *
 * @param array<string,mixed> $payload Authenticated payload.
 * @param string $expectedKind Required ticket kind.
 * @param string $expectedAction Required protected action.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return ?array<string,mixed> Normalized payload or null.
 */
function viewer_anti_automation_ticket_payload_validate(
    array $payload,
    string $expectedKind,
    string $expectedAction,
    ?int $nowTimestamp = null
): ?array {
    if (!viewer_anti_automation_action_is_allowed($expectedAction)
        || !in_array($expectedKind, [VIEWER_ANTI_AUTOMATION_KIND_FORM, VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE], true)) {
        return null;
    }
    $requiredKeys = $expectedKind === VIEWER_ANTI_AUTOMATION_KIND_FORM
        ? ['v', 'k', 'a', 'n', 'i', 'e', 'h']
        : ['v', 'k', 'a', 'n', 'i', 'e', 'd'];
    if (array_keys($payload) !== $requiredKeys
        || (int) ($payload['v'] ?? 0) !== 1
        || (string) ($payload['k'] ?? '') !== $expectedKind
        || (string) ($payload['a'] ?? '') !== $expectedAction) {
        return null;
    }
    $nonce = (string) ($payload['n'] ?? '');
    $issuedAt = is_int($payload['i'] ?? null) ? $payload['i'] : 0;
    $expiresAt = is_int($payload['e'] ?? null) ? $payload['e'] : 0;
    $now = $nowTimestamp ?? time();
    if (preg_match('/^[A-Za-z0-9_-]{32,86}$/D', $nonce) !== 1
        || $issuedAt <= 0
        || $expiresAt <= $issuedAt
        || $expiresAt <= $now
        || $issuedAt > $now + 5) {
        return null;
    }
    if ($expectedKind === VIEWER_ANTI_AUTOMATION_KIND_FORM) {
        $honeypot = (string) ($payload['h'] ?? '');
        if (preg_match('/^vf_[a-f0-9]{16}$/D', $honeypot) !== 1) {
            return null;
        }
        $lifetime = (int) viewer_accounts_config()['anti_automation_form_lifetime_seconds'];
        if ($expiresAt - $issuedAt !== $lifetime) {
            return null;
        }
        return [
            'version' => 1,
            'kind' => $expectedKind,
            'action' => $expectedAction,
            'nonce' => $nonce,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
            'honeypot_field' => $honeypot,
            'difficulty' => 0,
        ];
    }

    $difficulty = is_int($payload['d'] ?? null) ? $payload['d'] : 0;
    $config = viewer_accounts_config();
    if ($difficulty < (int) $config['anti_automation_pow_min_bits']
        || $difficulty > (int) $config['anti_automation_pow_max_bits']
        || $expiresAt - $issuedAt !== min(
            VIEWER_ANTI_AUTOMATION_CHALLENGE_LIFETIME_SECONDS,
            (int) $config['anti_automation_form_lifetime_seconds']
        )) {
        return null;
    }
    return [
        'version' => 1,
        'kind' => $expectedKind,
        'action' => $expectedAction,
        'nonce' => $nonce,
        'issued_at' => $issuedAt,
        'expires_at' => $expiresAt,
        'honeypot_field' => '',
        'difficulty' => $difficulty,
    ];
}

/**
 * Decode, authenticate, validate, and optionally consume one signed ticket.
 *
 * @param string $ticket Browser-carried signed ticket.
 * @param string $expectedKind Required ticket kind.
 * @param string $expectedAction Required action.
 * @param bool $consume Whether to consume matching current-session nonce authority.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return ?array<string,mixed> Normalized authenticated state or null.
 */
function viewer_anti_automation_ticket_validate(
    string $ticket,
    string $expectedKind,
    string $expectedAction,
    bool $consume = false,
    ?int $nowTimestamp = null
): ?array {
    $payload = viewer_anti_automation_ticket_decode($ticket);
    if ($payload === null) {
        return null;
    }
    $normalized = viewer_anti_automation_ticket_payload_validate($payload, $expectedKind, $expectedAction, $nowTimestamp);
    if ($normalized === null) {
        return null;
    }
    if ($consume && !viewer_anti_automation_session_consume(
        (string) $normalized['nonce'],
        $expectedKind,
        $expectedAction,
        (int) $normalized['issued_at'],
        (int) $normalized['expires_at'],
        (int) $normalized['difficulty'],
        $nowTimestamp
    )) {
        return null;
    }
    return $normalized;
}

/**
 * Issue one short-lived action-bound form ticket and randomized honeypot field.
 *
 * @param string $action Protected action.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return array{ticket:string,honeypot_field:string,issued_at:int,expires_at:int} Form state.
 */
function viewer_anti_automation_form_issue(string $action, ?int $nowTimestamp = null): array
{
    if (!viewer_anti_automation_action_is_allowed($action)) {
        throw new InvalidArgumentException('Viewer anti-automation action is invalid.');
    }
    $now = $nowTimestamp ?? time();
    $lifetime = (int) viewer_accounts_config()['anti_automation_form_lifetime_seconds'];
    $nonce = security_opaque_token_generate(24);
    $honeypot = 'vf_' . bin2hex(random_bytes(8));
    $expiresAt = $now + $lifetime;
    $payload = [
        'v' => 1,
        'k' => VIEWER_ANTI_AUTOMATION_KIND_FORM,
        'a' => $action,
        'n' => $nonce,
        'i' => $now,
        'e' => $expiresAt,
        'h' => $honeypot,
    ];
    viewer_anti_automation_session_register($nonce, VIEWER_ANTI_AUTOMATION_KIND_FORM, $action, $now, $expiresAt);
    return [
        'ticket' => viewer_anti_automation_ticket_encode($payload),
        'honeypot_field' => $honeypot,
        'issued_at' => $now,
        'expires_at' => $expiresAt,
    ];
}

/**
 * Clamp a challenge difficulty to the configured and hard-bounded range.
 *
 * @param int $difficulty Candidate difficulty in leading zero bits.
 * @return int Bounded configured difficulty.
 */
function viewer_anti_automation_difficulty_normalize(int $difficulty): int
{
    $config = viewer_accounts_config();
    $minimum = (int) $config['anti_automation_pow_min_bits'];
    $maximum = (int) $config['anti_automation_pow_max_bits'];
    return max($minimum, min($maximum, $difficulty));
}

/**
 * Choose bounded proof difficulty from deterministic local repetition signals.
 *
 * @param int $ageSeconds Server-measured form age.
 * @param int $ipAttempts Current anti-automation exact-IP attempts.
 * @param int $subnetAttempts Current anti-automation subnet attempts.
 * @return int Configured bounded leading-zero-bit target.
 */
function viewer_anti_automation_difficulty_for_signals(int $ageSeconds, int $ipAttempts, int $subnetAttempts): int
{
    $config = viewer_accounts_config();
    $minimum = (int) $config['anti_automation_pow_min_bits'];
    $maximum = (int) $config['anti_automation_pow_max_bits'];
    $difficulty = $minimum;
    if ($ipAttempts >= 4 || $subnetAttempts >= 18) {
        $difficulty = min($maximum, $minimum + 1);
    }
    if ($ipAttempts >= 7 || $subnetAttempts >= 36) {
        $difficulty = $maximum;
    }
    return viewer_anti_automation_difficulty_normalize($difficulty);
}

/**
 * Issue one action-bound first-party proof challenge with bounded lifetime and difficulty.
 *
 * @param string $action Protected action.
 * @param int $difficulty Requested leading-zero-bit target.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return array{ticket:string,challenge:string,difficulty:int,issued_at:int,expires_at:int,max_counter:int} Challenge state.
 */
function viewer_anti_automation_challenge_issue(string $action, int $difficulty, ?int $nowTimestamp = null): array
{
    if (!viewer_anti_automation_action_is_allowed($action)) {
        throw new InvalidArgumentException('Viewer anti-automation action is invalid.');
    }
    $now = $nowTimestamp ?? time();
    $difficulty = viewer_anti_automation_difficulty_normalize($difficulty);
    $lifetime = min(
        VIEWER_ANTI_AUTOMATION_CHALLENGE_LIFETIME_SECONDS,
        (int) viewer_accounts_config()['anti_automation_form_lifetime_seconds']
    );
    $expiresAt = $now + $lifetime;
    $nonce = security_opaque_token_generate(24);
    $payload = [
        'v' => 1,
        'k' => VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE,
        'a' => $action,
        'n' => $nonce,
        'i' => $now,
        'e' => $expiresAt,
        'd' => $difficulty,
    ];
    viewer_anti_automation_session_register(
        $nonce,
        VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE,
        $action,
        $now,
        $expiresAt,
        $difficulty
    );
    return [
        'ticket' => viewer_anti_automation_ticket_encode($payload),
        'challenge' => $nonce,
        'difficulty' => $difficulty,
        'issued_at' => $now,
        'expires_at' => $expiresAt,
        'max_counter' => VIEWER_ANTI_AUTOMATION_MAX_COUNTER,
    ];
}

/**
 * Consume the existing viewer rate-limit subsystem for one protected anonymous request.
 *
 * @param string $clientIp Canonical or raw client IP.
 * @return array{allowed:bool,reason:string,ip_attempts:int,subnet_attempts:int,retry_after_seconds:int} Local signals.
 */
function viewer_anti_automation_rate_signals(string $clientIp): array
{
    $clientIp = request_client_ip_normalize($clientIp);
    if ($clientIp === '') {
        return [
            'allowed' => false,
            'reason' => 'client_ip_unavailable',
            'ip_attempts' => 0,
            'subnet_attempts' => 0,
            'retry_after_seconds' => 0,
        ];
    }
    try {
        $ipDecision = viewer_rate_limit_consume('viewer_automation_ip', 'ip', $clientIp);
        if (empty($ipDecision['allowed'])) {
            return [
                'allowed' => false,
                'reason' => (string) ($ipDecision['reason'] ?? 'rate_limited'),
                'ip_attempts' => (int) ($ipDecision['attempts'] ?? 0),
                'subnet_attempts' => 0,
                'retry_after_seconds' => (int) ($ipDecision['retry_after_seconds'] ?? 0),
            ];
        }
        $subnetDecision = viewer_rate_limit_consume('viewer_automation_subnet', 'subnet', $clientIp);
        return [
            'allowed' => !empty($subnetDecision['allowed']),
            'reason' => !empty($subnetDecision['allowed']) ? 'ok' : (string) ($subnetDecision['reason'] ?? 'rate_limited'),
            'ip_attempts' => (int) ($ipDecision['attempts'] ?? 0),
            'subnet_attempts' => (int) ($subnetDecision['attempts'] ?? 0),
            'retry_after_seconds' => (int) ($subnetDecision['retry_after_seconds'] ?? 0),
        ];
    } catch (Throwable) {
        return [
            'allowed' => false,
            'reason' => 'limiter_unavailable',
            'ip_attempts' => 0,
            'subnet_attempts' => 0,
            'retry_after_seconds' => 0,
        ];
    }
}

/**
 * Return the deterministic adaptive authorization decision for validated local signals.
 *
 * @param int $ageSeconds Server-measured form age.
 * @param bool $honeypotFilled Whether the randomized decoy field was populated.
 * @param array{allowed:bool,reason:string,ip_attempts:int,subnet_attempts:int,retry_after_seconds:int} $rateSignals Existing limiter result.
 * @return array{result:string,reason:string,difficulty:int} Small fixed authorization result.
 */
function viewer_anti_automation_policy_decision(int $ageSeconds, bool $honeypotFilled, array $rateSignals): array
{
    if ($honeypotFilled) {
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS, 'reason' => 'honeypot', 'difficulty' => 0];
    }
    if (empty($rateSignals['allowed'])) {
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS, 'reason' => 'hard_limit', 'difficulty' => 0];
    }
    $ipAttempts = max(0, (int) ($rateSignals['ip_attempts'] ?? 0));
    $subnetAttempts = max(0, (int) ($rateSignals['subnet_attempts'] ?? 0));
    $minimumAge = (int) viewer_accounts_config()['anti_automation_min_form_age_seconds'];
    if ($ageSeconds < $minimumAge) {
        return [
            'result' => VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED,
            'reason' => 'form_too_fast',
            'difficulty' => viewer_anti_automation_difficulty_for_signals($ageSeconds, $ipAttempts, $subnetAttempts),
        ];
    }
    if ($ipAttempts >= 3 || $subnetAttempts >= 12) {
        return [
            'result' => VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED,
            'reason' => 'repeated_request',
            'difficulty' => viewer_anti_automation_difficulty_for_signals($ageSeconds, $ipAttempts, $subnetAttempts),
        ];
    }
    return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_ALLOW, 'reason' => 'ok', 'difficulty' => 0];
}

/**
 * Return whether a SHA-256 digest has at least the required leading zero bits.
 *
 * @param string $digest Raw 32-byte SHA-256 digest.
 * @param int $requiredBits Required leading zero bits.
 * @return bool True only when the target is satisfied.
 */
function viewer_anti_automation_digest_has_leading_zero_bits(string $digest, int $requiredBits): bool
{
    if (strlen($digest) !== 32 || $requiredBits < 1 || $requiredBits > 256) {
        return false;
    }
    $fullBytes = intdiv($requiredBits, 8);
    $remainingBits = $requiredBits % 8;
    for ($index = 0; $index < $fullBytes; $index++) {
        if (ord($digest[$index]) !== 0) {
            return false;
        }
    }
    if ($remainingBits === 0) {
        return true;
    }
    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($digest[$fullBytes]) & $mask) === 0;
}

/**
 * Return the unambiguous versioned byte string hashed by browser and PHP for proof-of-work.
 *
 * @param string $action Protected action.
 * @param string $challenge Public challenge nonce.
 * @param int $counter Bounded proposed counter.
 * @return string Canonical proof input.
 */
function viewer_anti_automation_pow_input(string $action, string $challenge, int $counter): string
{
    return "viewer-aa-pow-v1\n" . $action . "\n" . $challenge . "\n" . $counter;
}

/**
 * Verify one proposed proof with exactly one server-side SHA-256 operation.
 *
 * @param string $action Protected action.
 * @param string $challenge Public challenge nonce from signed state.
 * @param int $difficulty Signed bounded leading-zero-bit target.
 * @param string $counterValue Browser-submitted decimal counter.
 * @return bool True only for a valid bounded proof.
 */
function viewer_anti_automation_pow_verify(
    string $action,
    string $challenge,
    int $difficulty,
    string $counterValue
): bool {
    if (!viewer_anti_automation_action_is_allowed($action)
        || preg_match('/^[A-Za-z0-9_-]{32,86}$/D', $challenge) !== 1
        || preg_match('/^(0|[1-9][0-9]{0,6})$/D', $counterValue) !== 1) {
        return false;
    }
    $counter = (int) $counterValue;
    if ($counter < 0 || $counter > VIEWER_ANTI_AUTOMATION_MAX_COUNTER) {
        return false;
    }
    $difficulty = viewer_anti_automation_difficulty_normalize($difficulty);
    $digest = hash('sha256', viewer_anti_automation_pow_input($action, $challenge, $counter), true);
    return viewer_anti_automation_digest_has_leading_zero_bits($digest, $difficulty);
}

/**
 * Record one bounded anti-automation diagnostic without changing authorization if logging fails.
 *
 * @param string $eventKey Stable viewer event key.
 * @param string $outcome Short outcome class.
 * @param string $action Protected action.
 * @param string $reason Bounded local reason class.
 * @param int $attempts Optional local attempt count.
 */
function viewer_anti_automation_event(
    string $eventKey,
    string $outcome,
    string $action,
    string $reason,
    int $attempts = 0
): void {
    viewer_security_event_record_best_effort($eventKey, null, $outcome, [
        'action' => substr($action, 0, 32),
        'reason' => substr($reason, 0, 64),
        'attempts' => min(1000, max(0, $attempts)),
    ]);
}

/**
 * Authorize one signed active challenge submission, including the no-JavaScript fallback.
 *
 * Challenge state is consumed before proof/fallback evaluation so a failed submitted proof cannot
 * be replayed as a cheap server-side brute-force oracle.
 *
 * @param string $action Protected action.
 * @param array<string,mixed> $post Submitted POST data.
 * @param string $clientIp Resolved client IP.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return array<string,mixed> Fixed result plus optional replacement challenge state.
 */
function viewer_anti_automation_authorize_challenge(
    string $action,
    array $post,
    string $clientIp,
    ?int $nowTimestamp = null
): array {
    $now = $nowTimestamp ?? time();
    $ticket = is_string($post['viewer_aa_challenge_ticket'] ?? null)
        ? (string) $post['viewer_aa_challenge_ticket']
        : '';
    $state = viewer_anti_automation_ticket_validate(
        $ticket,
        VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE,
        $action,
        true,
        $now
    );
    if ($state === null) {
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_INVALID, 'reason' => 'challenge_invalid'];
    }

    $rateSignals = viewer_anti_automation_rate_signals($clientIp);
    if (empty($rateSignals['allowed'])) {
        viewer_anti_automation_event(
            'viewer.automation_request_suppressed',
            'suppressed',
            $action,
            'challenge_hard_limit',
            (int) ($rateSignals['ip_attempts'] ?? 0)
        );
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS, 'reason' => 'challenge_hard_limit'];
    }

    $fallbackRequested = (string) ($post['viewer_aa_fallback'] ?? '') === '1';
    if ($fallbackRequested) {
        $ageSeconds = max(0, $now - (int) $state['issued_at']);
        if ($ageSeconds < VIEWER_ANTI_AUTOMATION_FALLBACK_MIN_AGE_SECONDS) {
            $challenge = viewer_anti_automation_challenge_issue($action, (int) $state['difficulty'], $now);
            viewer_anti_automation_event('viewer.automation_challenge_required', 'required', $action, 'fallback_too_fast');
            return [
                'result' => VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED,
                'reason' => 'fallback_too_fast',
                'challenge' => $challenge,
            ];
        }
        viewer_anti_automation_event('viewer.automation_challenge_passed', 'success', $action, 'fallback');
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_ALLOW, 'reason' => 'fallback'];
    }

    $counterValue = is_string($post['viewer_aa_pow_counter'] ?? null)
        ? (string) $post['viewer_aa_pow_counter']
        : '';
    if (viewer_anti_automation_pow_verify(
        $action,
        (string) $state['nonce'],
        (int) $state['difficulty'],
        $counterValue
    )) {
        viewer_anti_automation_event('viewer.automation_challenge_passed', 'success', $action, 'proof_of_work');
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_ALLOW, 'reason' => 'proof_of_work'];
    }

    $challenge = viewer_anti_automation_challenge_issue($action, (int) $state['difficulty'], $now);
    viewer_anti_automation_event('viewer.automation_challenge_failed', 'failed', $action, 'proof_invalid');
    return [
        'result' => VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED,
        'reason' => 'proof_invalid',
        'challenge' => $challenge,
    ];
}

/**
 * Authorize one anonymous protected submission before registration/resend or mail work begins.
 *
 * @param string $action Protected action.
 * @param array<string,mixed> $post Submitted POST data.
 * @param string $clientIp Resolved client IP.
 * @param ?int $nowTimestamp Optional deterministic timestamp for tests.
 * @return array<string,mixed> One of allow, challenge_required, suppress, or invalid.
 */
function viewer_anti_automation_authorize_submission(
    string $action,
    array $post,
    string $clientIp,
    ?int $nowTimestamp = null
): array {
    if (!viewer_anti_automation_action_is_allowed($action)) {
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_INVALID, 'reason' => 'action_invalid'];
    }
    if (!viewer_anti_automation_enabled()) {
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_ALLOW, 'reason' => 'disabled'];
    }
    if (isset($post['viewer_aa_challenge_ticket'])) {
        return viewer_anti_automation_authorize_challenge($action, $post, $clientIp, $nowTimestamp);
    }

    $now = $nowTimestamp ?? time();
    $ticket = is_string($post['viewer_aa_form_ticket'] ?? null) ? (string) $post['viewer_aa_form_ticket'] : '';
    $state = viewer_anti_automation_ticket_validate(
        $ticket,
        VIEWER_ANTI_AUTOMATION_KIND_FORM,
        $action,
        true,
        $now
    );
    if ($state === null) {
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_INVALID, 'reason' => 'form_invalid'];
    }

    $honeypotField = (string) $state['honeypot_field'];
    $honeypotRaw = $post[$honeypotField] ?? '';
    $honeypotFilled = !is_string($honeypotRaw)
        || strlen((string) $honeypotRaw) > VIEWER_ANTI_AUTOMATION_HONEYPOT_MAX_BYTES
        || trim((string) $honeypotRaw) !== '';
    if ($honeypotFilled) {
        viewer_anti_automation_event('viewer.automation_request_suppressed', 'suppressed', $action, 'honeypot');
        return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS, 'reason' => 'honeypot'];
    }

    $ageSeconds = max(0, $now - (int) $state['issued_at']);
    $rateSignals = viewer_anti_automation_rate_signals($clientIp);
    $decision = viewer_anti_automation_policy_decision($ageSeconds, false, $rateSignals);
    if ($decision['result'] === VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS) {
        viewer_anti_automation_event(
            'viewer.automation_request_suppressed',
            'suppressed',
            $action,
            (string) $decision['reason'],
            (int) ($rateSignals['ip_attempts'] ?? 0)
        );
        return $decision;
    }
    if ($decision['result'] === VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED) {
        $challenge = viewer_anti_automation_challenge_issue($action, (int) $decision['difficulty'], $now);
        viewer_anti_automation_event(
            'viewer.automation_challenge_required',
            'required',
            $action,
            (string) $decision['reason'],
            (int) ($rateSignals['ip_attempts'] ?? 0)
        );
        return [
            'result' => VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED,
            'reason' => (string) $decision['reason'],
            'challenge' => $challenge,
        ];
    }
    return ['result' => VIEWER_ANTI_AUTOMATION_RESULT_ALLOW, 'reason' => 'ok'];
}
