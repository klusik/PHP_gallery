<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/viewer_accounts.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Defines the dormant viewer identity boundary and future authentication primitives.
 *
 * Responsibilities:
 *   - Keep viewer principals separate from the existing administrator users/current_user() domain
 *   - Fail closed unless the global feature wrapper and viewer mode both permit viewer functionality
 *   - Normalize viewer identifiers without provider-specific email rewriting
 *   - Provide native PHP password hashing/verification helpers without silent bcrypt truncation
 *   - Maintain viewer-only server-side session state with security-version invalidation
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
 *   - Viewer authentication must never satisfy administrator authorization.
 *   - No public route calls these helpers in Phase 0.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

const VIEWER_ACCOUNT_STATUS_PENDING_VERIFICATION = 'pending_verification';
const VIEWER_ACCOUNT_STATUS_ACTIVE = 'active';
const VIEWER_ACCOUNT_STATUS_SUSPENDED = 'suspended';
const VIEWER_ACCOUNT_STATUS_DISABLED = 'disabled';
const VIEWER_SESSION_NAMESPACE = 'viewer_auth';
const VIEWER_CSRF_NAMESPACE = 'viewer_csrf_token';
const VIEWER_REAUTHENTICATION_NAMESPACE = 'viewer_reauthentication';
const VIEWER_EMAIL_CHANGE_CONFIRMATION_NAMESPACE = 'viewer_email_change_confirmation';
const VIEWER_ACCOUNT_CAPACITY_STATE_KEY = 'accounts';
const VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY = 'viewer_accounts_admin_mode';

/**
 * Parse one bounded integer configuration value without permissive numeric coercion.
 *
 * Non-integer values fall back to the supplied default. Integer values outside the
 * security boundary are clamped rather than creating an unlimited sentinel.
 *
 * @param mixed $value Raw configuration value.
 * @param int $default Safe default value.
 * @param int $minimum Hard lower bound.
 * @param int $maximum Hard upper bound.
 * @return int Strictly parsed bounded value.
 */
function viewer_config_bounded_int($value, int $default, int $minimum, int $maximum): int
{
    if ($minimum > $maximum || $default < $minimum || $default > $maximum) {
        throw new InvalidArgumentException('Viewer bounded configuration contract is invalid.');
    }

    if (is_int($value)) {
        $parsed = $value;
    } elseif (is_string($value) && preg_match('/^-?[0-9]+$/D', $value) === 1) {
        $digits = ltrim($value, '-');
        if (strlen($digits) > 18) {
            return str_starts_with($value, '-') ? $minimum : $maximum;
        }
        $parsed = (int) $value;
    } else {
        return $default;
    }

    return max($minimum, min($maximum, $parsed));
}

/**
 * Normalize one viewer registration policy value to the bounded supported set.
 *
 * Invalid or unknown values fail closed to disabled. This helper is the single
 * normalization boundary for config and database-backed administrator overrides.
 *
 * @param mixed $value Raw registration policy value.
 * @return string One of disabled, invite_only, or open.
 */
function viewer_registration_mode_normalize($value): string
{
    $mode = strtolower(trim((string) $value));
    return in_array($mode, ['disabled', 'invite_only', 'open'], true) ? $mode : 'disabled';
}

/**
 * Return viewer feature configuration merged with conservative inactive defaults.
 *
 * @return array<string,mixed> Normalized viewer configuration.
 */
function viewer_accounts_config(): array
{
    $config = cms_config();
    $viewer = is_array($config['viewer_accounts'] ?? null) ? $config['viewer_accounts'] : [];
    $registrationMode = viewer_registration_mode_normalize($viewer['registration_mode'] ?? 'disabled');

    return [
        'enabled' => array_key_exists('enabled', $viewer) && $viewer['enabled'] === true,
        'registration_mode' => $registrationMode,
        'require_https' => !array_key_exists('require_https', $viewer) || $viewer['require_https'] === true,
        'session_lifetime_seconds' => max(900, min(2592000, (int) ($viewer['session_lifetime_seconds'] ?? 86400))),
        'remember_lifetime_days' => max(1, min(365, (int) ($viewer['remember_lifetime_days'] ?? 30))),
        'security_event_retention_days' => max(7, min(730, (int) ($viewer['security_event_retention_days'] ?? 180))),
        'rate_limit_max_subjects_per_bucket' => max(100, min(50000, (int) ($viewer['rate_limit_max_subjects_per_bucket'] ?? 5000))),
        'anti_automation_enabled' => !array_key_exists('anti_automation_enabled', $viewer) || $viewer['anti_automation_enabled'] === true,
        'anti_automation_min_form_age_seconds' => viewer_config_bounded_int($viewer['anti_automation_min_form_age_seconds'] ?? 2, 2, 1, 10),
        'anti_automation_form_lifetime_seconds' => viewer_config_bounded_int($viewer['anti_automation_form_lifetime_seconds'] ?? 600, 600, 120, 1800),
        'anti_automation_pow_min_bits' => viewer_config_bounded_int($viewer['anti_automation_pow_min_bits'] ?? 12, 12, 10, 16),
        'anti_automation_pow_max_bits' => max(
            viewer_config_bounded_int($viewer['anti_automation_pow_min_bits'] ?? 12, 12, 10, 16),
            viewer_config_bounded_int($viewer['anti_automation_pow_max_bits'] ?? 15, 15, 10, 16)
        ),
        'max_viewer_accounts' => max(1, min(100000, (int) ($viewer['max_viewer_accounts'] ?? 250))),
        'max_active_viewer_sessions_per_account' => max(1, min(100, (int) ($viewer['max_active_viewer_sessions_per_account'] ?? 10))),
        'max_active_viewer_remember_tokens_per_account' => max(1, min(100, (int) ($viewer['max_active_viewer_remember_tokens_per_account'] ?? 10))),
        'max_pending_registration_requests' => max(10, min(10000, (int) ($viewer['max_pending_registration_requests'] ?? 250))),
        'registration_request_lifetime_minutes' => max(30, min(10080, (int) ($viewer['registration_request_lifetime_minutes'] ?? 1440))),
        'verified_registration_lifetime_minutes' => max(15, min(1440, (int) ($viewer['verified_registration_lifetime_minutes'] ?? 60))),
        'registration_activation_lifetime_minutes' => max(5, min(60, (int) ($viewer['registration_activation_lifetime_minutes'] ?? 20))),
        'verification_token_lifetime_minutes' => max(15, min(1440, (int) ($viewer['verification_token_lifetime_minutes'] ?? 60))),
        'password_reset_authorization_lifetime_minutes' => max(5, min(60, (int) ($viewer['password_reset_authorization_lifetime_minutes'] ?? 15))),
        'password_reset_token_lifetime_minutes' => max(15, min(1440, (int) ($viewer['password_reset_token_lifetime_minutes'] ?? 60))),
        'viewer_reauthentication_lifetime_minutes' => viewer_config_bounded_int($viewer['viewer_reauthentication_lifetime_minutes'] ?? 15, 15, 5, 30),
        'email_change_request_lifetime_minutes' => viewer_config_bounded_int($viewer['email_change_request_lifetime_minutes'] ?? 60, 60, 15, 1440),
        'email_change_confirmation_lifetime_minutes' => viewer_config_bounded_int($viewer['email_change_confirmation_lifetime_minutes'] ?? 15, 15, 5, 30),
        'invitation_lifetime_days' => max(1, min(90, (int) ($viewer['invitation_lifetime_days'] ?? 7))),
        'registration_global_daily_limit' => max(1, min(10000, (int) ($viewer['registration_global_daily_limit'] ?? 50))),
        'verification_mail_email_cooldown_seconds' => max(60, min(86400, (int) ($viewer['verification_mail_email_cooldown_seconds'] ?? 600))),
        'verification_mail_email_hourly_limit' => max(1, min(100, (int) ($viewer['verification_mail_email_hourly_limit'] ?? 3))),
        'verification_mail_email_daily_limit' => max(1, min(500, (int) ($viewer['verification_mail_email_daily_limit'] ?? 5))),
        'verification_mail_ip_hourly_limit' => max(1, min(500, (int) ($viewer['verification_mail_ip_hourly_limit'] ?? 10))),
        'verification_mail_ip_daily_limit' => max(1, min(5000, (int) ($viewer['verification_mail_ip_daily_limit'] ?? 25))),
        'verification_mail_subnet_hourly_limit' => max(1, min(5000, (int) ($viewer['verification_mail_subnet_hourly_limit'] ?? 25))),
        'verification_mail_subnet_daily_limit' => max(1, min(10000, (int) ($viewer['verification_mail_subnet_daily_limit'] ?? 60))),
        'verification_mail_global_daily_limit' => max(1, min(10000, (int) ($viewer['verification_mail_global_daily_limit'] ?? 50))),
        'password_reset_mail_email_cooldown_seconds' => max(60, min(86400, (int) ($viewer['password_reset_mail_email_cooldown_seconds'] ?? 600))),
        'password_reset_mail_email_hourly_limit' => max(1, min(100, (int) ($viewer['password_reset_mail_email_hourly_limit'] ?? 3))),
        'password_reset_mail_email_daily_limit' => max(1, min(500, (int) ($viewer['password_reset_mail_email_daily_limit'] ?? 5))),
        'password_reset_mail_ip_hourly_limit' => max(1, min(500, (int) ($viewer['password_reset_mail_ip_hourly_limit'] ?? 5))),
        'password_reset_mail_ip_daily_limit' => max(1, min(5000, (int) ($viewer['password_reset_mail_ip_daily_limit'] ?? 20))),
        'password_reset_mail_subnet_hourly_limit' => max(1, min(5000, (int) ($viewer['password_reset_mail_subnet_hourly_limit'] ?? 15))),
        'password_reset_mail_subnet_daily_limit' => max(1, min(10000, (int) ($viewer['password_reset_mail_subnet_daily_limit'] ?? 40))),
        'password_reset_mail_global_daily_limit' => max(1, min(10000, (int) ($viewer['password_reset_mail_global_daily_limit'] ?? 50))),
        'invitation_mail_email_daily_limit' => max(1, min(100, (int) ($viewer['invitation_mail_email_daily_limit'] ?? 3))),
        'invitation_mail_global_daily_limit' => max(1, min(10000, (int) ($viewer['invitation_mail_global_daily_limit'] ?? 50))),
        'max_viewer_favourites_per_account' => viewer_config_bounded_int($viewer['max_viewer_favourites_per_account'] ?? $viewer['max_favourites_per_account'] ?? 5000, 5000, 1, 100000),
        'max_viewer_collections_per_account' => viewer_config_bounded_int($viewer['max_viewer_collections_per_account'] ?? $viewer['max_collections_per_account'] ?? 25, 25, 1, 1000),
        'max_viewer_items_per_collection' => viewer_config_bounded_int($viewer['max_viewer_items_per_collection'] ?? $viewer['max_items_per_collection'] ?? 500, 500, 1, 10000),
        'max_active_viewer_collection_shares_per_collection' => viewer_config_bounded_int($viewer['max_active_viewer_collection_shares_per_collection'] ?? 1, 1, 1, 100),
    ];
}

/**
 * Return whether the global Admin feature wrapper permits the viewer subsystem.
 *
 * The normal application bootstrap always loads feature_flags.php before this service.
 * The compatibility fallback keeps isolated service tests and legacy direct includes
 * functional without creating a second persistence path for the master feature state.
 *
 * @return bool True when the master viewer feature is available.
 */
function viewer_accounts_master_feature_enabled(): bool
{
    if (!function_exists(__NAMESPACE__ . '\\feature_flag_enabled')) {
        return true;
    }
    return feature_flag_enabled('viewer_accounts');
}

/**
 * Return the administrator-selected viewer mode override when one has been saved.
 *
 * The database-backed override is registration policy only. Phase 4.1 exposes the same
 * bounded disabled/invite_only/open values through one Admin selector; the global Viewer
 * Accounts feature flag remains the outer master switch. Missing or unavailable app_settings
 * data falls back to the historical local configuration.
 *
 * @return ?string Disabled, invite_only, or open when an explicit Admin override exists.
 */
function viewer_accounts_admin_mode_override(): ?string
{
    if (!function_exists(__NAMESPACE__ . '\\app_setting')) {
        return null;
    }
    $rawMode = trim((string) app_setting(VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY, ''));
    if ($rawMode === '') {
        return null;
    }
    return viewer_registration_mode_normalize($rawMode);
}

/**
 * Persist one bounded administrator registration mode with lifecycle-safe transitions.
 *
 * Transitions involving open serialize against the pending-registration capacity lock.
 * Moving away from open commits the restrictive policy before cleanup, so cleanup failure
 * cannot keep account creation enabled. Moving into open durably cancels old open-origin
 * staging before persisting open, so previously stranded authority cannot resurrect.
 * Invitation-backed staging is never targeted by this cleanup.
 *
 * @param string $mode Requested administrator registration mode.
 * @return int Number of open-origin staged rows cancelled by this transition.
 */
function viewer_accounts_set_admin_registration_mode(string $mode): int
{
    if (!function_exists(__NAMESPACE__ . '\\set_app_setting')) {
        throw new RuntimeException('Application settings storage is unavailable.');
    }

    $normalized = viewer_registration_mode_normalize($mode);
    $oldMode = viewer_registration_mode();
    if ($oldMode === $normalized) {
        set_app_setting(VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY, $normalized);
        return 0;
    }

    $transitionInvolvesOpen = $oldMode === 'open' || $normalized === 'open';
    if (!$transitionInvolvesOpen) {
        set_app_setting(VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY, $normalized);
        return 0;
    }
    if (!function_exists(__NAMESPACE__ . '\\viewer_registration_capacity_lock')
        || !function_exists(__NAMESPACE__ . '\\viewer_registration_cancel_open_origin_staging')) {
        throw new RuntimeException('Viewer registration lifecycle service is unavailable.');
    }

    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Viewer registration mode transitions require an independent transaction boundary.');
    }

    if ($normalized === 'open') {
        // Retire stale open-origin authority while the restrictive mode is still effective.
        $pdo->beginTransaction();
        try {
            viewer_registration_capacity_lock();
            $cancelled = viewer_registration_cancel_open_origin_staging();
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        // Open becomes effective only after durable stale-authority cleanup succeeded.
        set_app_setting(VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY, $normalized);
        return $cancelled;
    }

    // Serialize the restrictive policy write with request creation/final activation.
    // Once this transaction commits, every later holder of the registration lock sees
    // the restrictive mode before it can create staging or a durable viewer account.
    $pdo->beginTransaction();
    try {
        viewer_registration_capacity_lock();
        set_app_setting(VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY, $normalized);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (isset($GLOBALS['cms_app_settings_cache']) && is_array($GLOBALS['cms_app_settings_cache'])) {
            unset($GLOBALS['cms_app_settings_cache'][VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY]);
        }
        throw $exception;
    }

    // Cleanup is defense in depth. The restrictive policy is already durable, so a
    // cleanup failure cannot keep open-origin activation enabled. A future transition
    // back to open must successfully run the same cleanup before open is persisted.
    return viewer_registration_cancel_open_origin_staging();
}

/**
 * Persist the historical boolean viewer-account compatibility control.
 *
 * Phase 4.1 uses viewer_accounts_set_admin_registration_mode() directly for the visible
 * three-state selector. This compatibility helper still maps true to invite_only and false
 * to disabled for older callers without creating another setting.
 */
function viewer_accounts_set_admin_enabled(bool $enabled): void
{
    viewer_accounts_set_admin_registration_mode($enabled ? 'invite_only' : 'disabled');
}

/**
 * Return true when the viewer domain is enabled by Admin override or local configuration.
 *
 * @return bool True only when the effective viewer mode is enabled.
 */
function viewer_accounts_enabled(): bool
{
    if (!viewer_accounts_master_feature_enabled()) {
        return false;
    }

    $override = viewer_accounts_admin_mode_override();
    if ($override !== null) {
        return in_array($override, ['invite_only', 'open'], true);
    }

    return (bool) viewer_accounts_config()['enabled'];
}

/**
 * Return the effective registration mode, forced disabled while the viewer feature is off.
 *
 * @return string One of disabled, invite_only, or open.
 */
function viewer_registration_mode(): string
{
    if (!viewer_accounts_master_feature_enabled()) {
        return 'disabled';
    }

    $override = viewer_accounts_admin_mode_override();
    if ($override !== null) {
        return $override;
    }

    if (!viewer_accounts_enabled()) {
        return 'disabled';
    }
    return (string) viewer_accounts_config()['registration_mode'];
}

/**
 * Return the aggregate three-state schema capability required by viewer authentication.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_auth_schema_status(): array
{
    return schema_inspection_feature('viewer.authentication_foundation', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_column('viewer_accounts', 'must_change_password'),
        schema_inspection_table('viewer_account_state'),
        schema_inspection_table('viewer_sessions'),
        schema_inspection_table('viewer_remember_tokens'),
        schema_inspection_table('viewer_password_reset_tokens'),
        schema_inspection_table('viewer_security_events'),
        schema_inspection_table('viewer_rate_limit_buckets'),
        schema_inspection_table('viewer_rate_limits'),
        schema_inspection_table('viewer_invitations'),
        schema_inspection_table('viewer_registration_state'),
        schema_inspection_table('viewer_registration_requests'),
    ]);
}

/**
 * Return true only when every authentication foundation table is verifiably available.
 *
 * Missing and unknown schema states both fail closed.
 *
 * @return bool True only for confirmed available viewer-authentication storage.
 */
function viewer_auth_storage_available(): bool
{
    return schema_inspection_is_available(viewer_auth_schema_status());
}

/**
 * Return the aggregate schema capability needed for account security-state transitions.
 *
 * @return array Aggregate schema inspection result.
 */
function viewer_account_security_transition_schema_status(): array
{
    return schema_inspection_feature('viewer.account_security_transition', [
        schema_inspection_table('viewer_accounts'),
        schema_inspection_column('viewer_accounts', 'must_change_password'),
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
 * Return the configured hard installation cap for durable viewer accounts.
 *
 * @return int Maximum durable viewer accounts.
 */
function viewer_account_cap(): int
{
    return (int) viewer_accounts_config()['max_viewer_accounts'];
}

/**
 * Ensure and lock the singleton durable-account capacity row.
 *
 * @return int Stored account count while the capacity row lock is held.
 */
function viewer_account_capacity_lock(): int
{
    $pdo = db();
    $now = now_sql();
    $pdo->prepare(
        'INSERT INTO viewer_account_state (state_key, account_count, updated_at) '
        . 'VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE updated_at = updated_at'
    )->execute([VIEWER_ACCOUNT_CAPACITY_STATE_KEY, $now]);

    $stmt = $pdo->prepare(
        'SELECT account_count FROM viewer_account_state WHERE state_key = ? LIMIT 1 FOR UPDATE'
    );
    $stmt->execute([VIEWER_ACCOUNT_CAPACITY_STATE_KEY]);
    $count = $stmt->fetchColumn();
    if ($count === false) {
        throw new RuntimeException('Viewer account capacity state could not be locked.');
    }
    return (int) $count;
}

/**
 * Recount durable viewer accounts while the singleton capacity row is locked.
 *
 * @return int Reconciled durable viewer-account count.
 */
function viewer_account_capacity_recount_locked(): int
{
    $count = (int) db()->query('SELECT COUNT(*) FROM viewer_accounts')->fetchColumn();
    db()->prepare(
        'UPDATE viewer_account_state SET account_count = ?, updated_at = ? WHERE state_key = ?'
    )->execute([$count, now_sql(), VIEWER_ACCOUNT_CAPACITY_STATE_KEY]);
    return $count;
}

/**
 * Reconcile the durable viewer-account counter under its serialization lock.
 *
 * @return int Reconciled durable viewer-account count.
 */
function viewer_account_capacity_reconcile(): int
{
    if (!viewer_auth_storage_available()) {
        throw new RuntimeException('Viewer authentication storage is unavailable.');
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        viewer_account_capacity_lock();
        $count = viewer_account_capacity_recount_locked();
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $count;
    } catch (\Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return the installation secret used only for HMAC-based viewer security fingerprints.
 *
 * @return string Secret key material from existing installation configuration.
 */
function viewer_security_secret(): string
{
    $config = cms_config();
    $secret = (string) ($config['visitor_vote_secret'] ?? $config['setup_key'] ?? '');
    if ($secret === '') {
        throw new RuntimeException('Viewer security primitives require an installation secret.');
    }
    return $secret;
}

/**
 * Create a privacy-safer keyed fingerprint for viewer security metadata.
 *
 * @param string $scope Fixed context separating different fingerprint uses.
 * @param string $value Sensitive value that must not be stored raw.
 * @return string Lowercase SHA-256 HMAC digest.
 */
function viewer_security_fingerprint(string $scope, string $value): string
{
    if ($scope === '' || $value === '') {
        return '';
    }
    return hash_hmac('sha256', $scope . "\0" . $value, viewer_security_secret());
}

/**
 * Normalize an email address for deterministic viewer identity comparison.
 *
 * Whitespace around the full address is removed and only the domain is folded
 * to lowercase. The local part is preserved exactly because SMTP local-part
 * case semantics are provider-specific. No Gmail dot/plus or other provider
 * transformations are performed.
 *
 * @param string $email Submitted email address.
 * @return ?string Canonical address or null when invalid/too long.
 */
function viewer_email_normalize(string $email): ?string
{
    $email = trim($email);
    if ($email === '' || strlen($email) > 190 || str_contains($email, "\0") || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }

    $separator = strrpos($email, '@');
    if ($separator === false || $separator === 0 || $separator === strlen($email) - 1) {
        return null;
    }

    $local = substr($email, 0, $separator);
    $domain = strtolower(substr($email, $separator + 1));
    $normalized = $local . '@' . $domain;
    return strlen($normalized) <= 190 ? $normalized : null;
}

/**
 * Return a keyed email fingerprint for token binding or abuse controls.
 *
 * @param string $email Submitted or stored email address.
 * @return string HMAC fingerprint or an empty string for invalid input.
 */
function viewer_email_fingerprint(string $email): string
{
    $normalized = viewer_email_normalize($email);
    return $normalized === null ? '' : viewer_security_fingerprint('viewer-email', $normalized);
}

/**
 * Return the strongest native password algorithm available on this PHP build.
 *
 * Argon2id is preferred when the PHP runtime exposes it. Shared hosts without
 * Argon2 support fall back to PASSWORD_DEFAULT, whose current bcrypt input cap
 * is handled explicitly by viewer_password_max_bytes().
 *
 * @return string|int Native password_hash() algorithm identifier.
 */
function viewer_password_algorithm()
{
    if (defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true)) {
        return constant('PASSWORD_ARGON2ID');
    }
    return PASSWORD_DEFAULT;
}

/**
 * Return the maximum accepted password byte length without silent algorithm truncation.
 *
 * @return int Maximum password length in bytes.
 */
function viewer_password_max_bytes(): int
{
    return viewer_password_algorithm() === PASSWORD_BCRYPT ? 72 : 4096;
}

/**
 * Return whether password bytes are safe to pass to native password verification.
 *
 * This does not apply the registration/reset minimum length so failed login attempts
 * do not bypass the expensive verification path merely by submitting a short value.
 *
 * @param string $password Plaintext password candidate.
 * @return bool True for valid UTF-8 input within the native algorithm byte limit.
 */
function viewer_password_input_is_safe(string $password): bool
{
    $length = strlen($password);
    return $length > 0
        && $length <= viewer_password_max_bytes()
        && !str_contains($password, "\0")
        && preg_match('//u', $password) === 1;
}

/**
 * Count Unicode code points in a validated viewer password.
 *
 * @param string $password Valid UTF-8 password candidate.
 * @return int Unicode code-point count, or zero when the input is invalid.
 */
function viewer_password_character_length(string $password): int
{
    if (!viewer_password_input_is_safe($password)) {
        return 0;
    }
    $count = preg_match_all('/./us', $password, $matches);
    return is_int($count) ? $count : 0;
}

/**
 * Return whether a password input can be passed safely to the selected native hash algorithm.
 *
 * @param string $password Plaintext password supplied by a future viewer flow.
 * @return bool True when non-empty and within the no-truncation byte limit.
 */
function viewer_password_input_is_acceptable(string $password): bool
{
    return viewer_password_input_is_safe($password) && viewer_password_character_length($password) >= 15;
}

/**
 * Hash a viewer password using PHP's native password API.
 *
 * @param string $password Plaintext password.
 * @return string Encoded password hash suitable for viewer_accounts.password_hash.
 */
function viewer_password_hash(string $password): string
{
    if (!viewer_password_input_is_acceptable($password)) {
        throw new InvalidArgumentException('Viewer password length is invalid for the active password hashing algorithm.');
    }

    $hash = password_hash($password, viewer_password_algorithm());
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Viewer password hashing failed.');
    }
    return $hash;
}

/**
 * Verify a viewer password against a stored native password hash.
 *
 * @param string $password Plaintext password.
 * @param string $passwordHash Stored password_hash() value.
 * @return bool True only when the password matches.
 */
function viewer_password_verify(string $password, string $passwordHash): bool
{
    if (!viewer_password_input_is_safe($password) || $passwordHash === '') {
        return false;
    }
    return password_verify($password, $passwordHash);
}

/**
 * Return whether a successful viewer password verification should refresh its hash.
 *
 * @param string $passwordHash Stored password_hash() value.
 * @return bool True when PHP recommends rehashing with the current viewer algorithm.
 */
function viewer_password_needs_rehash(string $passwordHash): bool
{
    return $passwordHash !== '' && password_needs_rehash($passwordHash, viewer_password_algorithm());
}

/**
 * Return the supported viewer account lifecycle values.
 *
 * @return array<int,string> Supported status values.
 */
function viewer_account_statuses(): array
{
    return [
        VIEWER_ACCOUNT_STATUS_PENDING_VERIFICATION,
        VIEWER_ACCOUNT_STATUS_ACTIVE,
        VIEWER_ACCOUNT_STATUS_SUSPENDED,
        VIEWER_ACCOUNT_STATUS_DISABLED,
    ];
}

/**
 * Return true when an account state may establish or retain a normal viewer session.
 *
 * @param array $account Viewer account row or equivalent data.
 * @return bool True only for active accounts.
 */
function viewer_account_can_authenticate(array $account): bool
{
    return (string) ($account['status'] ?? '') === VIEWER_ACCOUNT_STATUS_ACTIVE
        && !empty($account['email_verified_at'])
        && (string) ($account['password_hash'] ?? '') !== '';
}

/**
 * Return true when an otherwise-authenticatable viewer must replace an administrator-issued temporary password.
 *
 * This flag deliberately does not make the account inactive because the temporary password still needs to be
 * verified once. Normal viewer session establishment separately refuses flagged accounts.
 *
 * @param array $account Viewer account row or equivalent data.
 * @return bool True only while first-login password replacement is required.
 */
function viewer_account_requires_password_change(array $account): bool
{
    return (int) ($account['must_change_password'] ?? 0) === 1;
}

/**
 * Return true when a viewer account may mutate favourites/collections in later phases.
 *
 * @param array $account Viewer account row or equivalent data.
 * @return bool True only for active accounts.
 */
function viewer_account_can_mutate_content(array $account): bool
{
    return viewer_account_can_authenticate($account)
        && !viewer_account_requires_password_change($account);
}

/**
 * Return the isolated PHP-session key reserved for viewer authentication state.
 *
 * @return string Viewer-only session namespace key.
 */
function viewer_session_namespace_key(): string
{
    return VIEWER_SESSION_NAMESPACE;
}

/**
 * Return the isolated PHP-session key reserved for viewer CSRF authority.
 *
 * @return string Viewer-only CSRF namespace key.
 */
function viewer_csrf_namespace_key(): string
{
    return VIEWER_CSRF_NAMESPACE;
}

/**
 * Return or create a cryptographically random viewer-only CSRF token.
 *
 * @return string Viewer CSRF token.
 */
function viewer_csrf_token(): string
{
    $key = viewer_csrf_namespace_key();
    $token = $_SESSION[$key] ?? null;
    if (!is_string($token) || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        $token = bin2hex(random_bytes(32));
        $_SESSION[$key] = $token;
    }
    return $token;
}

/**
 * Verify a submitted viewer CSRF token without consulting administrator CSRF state.
 *
 * @param string $token Submitted viewer token.
 * @return bool True only for the current viewer CSRF authority.
 */
function viewer_csrf_verify(string $token): bool
{
    $stored = $_SESSION[viewer_csrf_namespace_key()] ?? null;
    return is_string($stored) && $stored !== '' && $token !== '' && hash_equals($stored, $token);
}

/**
 * Parse viewer authentication state without consulting administrator session keys.
 *
 * @return ?array{account_id:int,security_version:int,token:string} Validated state or null.
 */
function viewer_session_state(): ?array
{
    $state = $_SESSION[viewer_session_namespace_key()] ?? null;
    if (!is_array($state)) {
        return null;
    }

    $accountId = (int) ($state['account_id'] ?? 0);
    $securityVersion = (int) ($state['security_version'] ?? 0);
    $token = (string) ($state['token'] ?? '');
    if ($accountId <= 0 || $securityVersion <= 0 || $token === '') {
        return null;
    }

    return [
        'account_id' => $accountId,
        'security_version' => $securityVersion,
        'token' => $token,
    ];
}

/**
 * Clear only viewer authentication state, preserving existing administrator/session data.
 */
function viewer_session_clear(): void
{
    unset(
        $_SESSION[viewer_session_namespace_key()],
        $_SESSION[VIEWER_REAUTHENTICATION_NAMESPACE],
        $_SESSION[VIEWER_EMAIL_CHANGE_CONFIRMATION_NAMESPACE]
    );
}

/**
 * Remove a bounded set of inactive viewer sessions for one locked account.
 *
 * @param int $viewerAccountId Viewer account identifier whose row lock is held.
 * @param string $now Current SQL timestamp.
 * @param int $limit Maximum inactive rows removed.
 * @return int Number of deleted rows.
 */
function viewer_session_cleanup_account_locked(int $viewerAccountId, string $now, int $limit = 100): int
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare(
        'DELETE FROM viewer_sessions WHERE viewer_account_id = ? '
        . 'AND (revoked_at IS NOT NULL OR expires_at < ?) ORDER BY id ASC LIMIT ' . $limit
    );
    $stmt->execute([$viewerAccountId, $now]);
    return $stmt->rowCount();
}

/**
 * Enforce the active viewer-session cap while the account row lock is held.
 *
 * The oldest active rows are revoked deterministically before a new session is inserted.
 *
 * @param int $viewerAccountId Locked viewer account identifier.
 * @param string $now Current SQL timestamp.
 */
function viewer_session_enforce_limit_locked(int $viewerAccountId, string $now): void
{
    $cap = (int) viewer_accounts_config()['max_active_viewer_sessions_per_account'];
    $countStmt = db()->prepare(
        'SELECT COUNT(*) FROM viewer_sessions WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= ?'
    );
    $countStmt->execute([$viewerAccountId, $now]);
    $activeCount = (int) $countStmt->fetchColumn();
    $revokeCount = max(0, $activeCount - $cap + 1);
    if ($revokeCount === 0) {
        return;
    }

    $idsStmt = db()->prepare(
        'SELECT id FROM viewer_sessions WHERE viewer_account_id = ? AND revoked_at IS NULL AND expires_at >= ? '
        . 'ORDER BY created_at ASC, id ASC LIMIT ' . $revokeCount
    );
    $idsStmt->execute([$viewerAccountId, $now]);
    $ids = array_map('intval', $idsStmt->fetchAll(\PDO::FETCH_COLUMN));
    if ($ids === []) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$now], $ids);
    db()->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE id IN (' . $placeholders . ') AND revoked_at IS NULL')
        ->execute($params);
}

/**
 * Create one revocable viewer session without altering current_user() semantics.
 *
 * This helper is intentionally not routed in Phase 0. A future login flow must
 * authenticate credentials first, then call this boundary. The PHP session id is
 * rotated to prevent fixation, while existing admin session variables are kept.
 *
 * @param array $account Verified viewer account row.
 * @return string Plaintext viewer session token retained only in PHP session storage.
 */
function viewer_session_establish(array $account): string
{
    unset($_SESSION[VIEWER_REAUTHENTICATION_NAMESPACE], $_SESSION[VIEWER_EMAIL_CHANGE_CONFIRMATION_NAMESPACE]);
    if (!viewer_accounts_enabled() || !viewer_auth_storage_available()) {
        throw new RuntimeException('Viewer session establishment is unavailable.');
    }
    if (function_exists(__NAMESPACE__ . '\\viewer_security_transport_allowed') && !viewer_security_transport_allowed()) {
        throw new RuntimeException('Viewer authentication requires a trusted secure transport.');
    }

    $accountId = (int) ($account['id'] ?? 0);
    $expectedSecurityVersion = (int) ($account['security_version'] ?? 0);
    if ($accountId <= 0 || $expectedSecurityVersion <= 0) {
        throw new InvalidArgumentException('Viewer account identity/security version is invalid.');
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $accountStmt = $pdo->prepare(
            'SELECT id, email, normalized_email, password_hash, must_change_password, status, security_version, email_verified_at '
            . 'FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE'
        );
        $accountStmt->execute([$accountId]);
        $lockedAccount = $accountStmt->fetch();
        if (!$lockedAccount
            || !viewer_account_can_authenticate($lockedAccount)
            || viewer_account_requires_password_change($lockedAccount)
            || (int) ($lockedAccount['security_version'] ?? 0) !== $expectedSecurityVersion) {
            throw new RuntimeException('Viewer session establishment is unavailable.');
        }

        $token = security_opaque_token_generate(32);
        $sessionHash = security_authority_token_hash($token);
        $config = viewer_accounts_config();
        $now = now_sql();
        $expiresAt = date('Y-m-d H:i:s', time() + (int) $config['session_lifetime_seconds']);
        $clientIp = request_client_ip();
        $ipHash = $clientIp === '' ? null : viewer_security_fingerprint('viewer-session-ip', $clientIp);
        $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $userAgentHash = $userAgent === '' ? null : viewer_security_fingerprint('viewer-session-ua', $userAgent);

        viewer_session_cleanup_account_locked($accountId, $now);
        viewer_session_enforce_limit_locked($accountId, $now);

        $stmt = $pdo->prepare(
            'INSERT INTO viewer_sessions (viewer_account_id, session_hash, security_version, ip_hash, user_agent_hash, created_at, expires_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$accountId, $sessionHash, $expectedSecurityVersion, $ipHash, $userAgentHash, $now, $expiresAt]);

        if (function_exists(__NAMESPACE__ . '\\viewer_security_event_record')) {
            viewer_security_event_record('viewer.session_created', $accountId, 'success', [
                'security_version' => $expectedSecurityVersion,
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE && !session_regenerate_id(true)) {
            throw new RuntimeException('Viewer session id rotation failed.');
        }
        $_SESSION[viewer_session_namespace_key()] = [
            'account_id' => $accountId,
            'security_version' => $expectedSecurityVersion,
            'token' => $token,
        ];

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $token;
    } catch (\Throwable $exception) {
        viewer_session_clear();
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return the authenticated viewer principal, completely separate from current_user().
 *
 * Viewer functionality is fail-closed. Disabled configuration, missing state,
 * revoked/expired server-side sessions, non-active accounts, or security-version
 * mismatches all produce no viewer principal. No admin user/session key is read.
 *
 * @return ?array Structured viewer principal or null when viewer auth is unavailable.
 */
function current_viewer(): ?array
{
    if (!viewer_accounts_enabled()) {
        viewer_session_clear();
        return null;
    }

    $state = viewer_session_state();
    if ($state === null) {
        return null;
    }
    if (!viewer_auth_storage_available()) {
        viewer_session_clear();
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT va.id, va.email, va.normalized_email, va.password_hash, va.must_change_password, va.status, va.security_version, va.email_verified_at, '
            . 'vs.id AS viewer_session_id, vs.security_version AS session_security_version, vs.expires_at, vs.revoked_at '
            . 'FROM viewer_sessions vs INNER JOIN viewer_accounts va ON va.id = vs.viewer_account_id '
            . 'WHERE vs.viewer_account_id = ? AND vs.session_hash = ? LIMIT 1'
        );
        $stmt->execute([$state['account_id'], security_authority_token_hash($state['token'])]);
        $row = $stmt->fetch();
        if (!$row
            || !viewer_account_can_authenticate($row)
            || viewer_account_requires_password_change($row)
            || !empty($row['revoked_at'])
            || strtotime((string) ($row['expires_at'] ?? '')) < time()
            || (int) ($row['security_version'] ?? 0) !== $state['security_version']
            || (int) ($row['session_security_version'] ?? 0) !== $state['security_version']) {
            viewer_session_clear();
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'normalized_email' => (string) $row['normalized_email'],
            'status' => (string) $row['status'],
            'security_version' => (int) $row['security_version'],
            'email_verified_at' => $row['email_verified_at'] ?? null,
            'must_change_password' => false,
            'viewer_session_id' => (int) $row['viewer_session_id'],
        ];
    } catch (\Throwable) {
        viewer_session_clear();
        return null;
    }
}

/**
 * Return true when the independent viewer principal is currently authenticated.
 *
 * @return bool True only when current_viewer() resolves a valid viewer session.
 */
function viewer_is_authenticated(): bool
{
    return current_viewer() !== null;
}

/**
 * Revoke the current viewer session without logging out an administrator in the same PHP session.
 */
function viewer_session_revoke_current(): void
{
    $state = viewer_session_state();
    if ($state === null) {
        viewer_session_clear();
        return;
    }

    if (!viewer_auth_storage_available()) {
        viewer_session_clear();
        return;
    }

    try {
        $stmt = db()->prepare(
            'UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND session_hash = ? AND revoked_at IS NULL'
        );
        $stmt->execute([now_sql(), $state['account_id'], security_authority_token_hash($state['token'])]);
        if (function_exists(__NAMESPACE__ . '\\viewer_security_event_record')) {
            viewer_security_event_record('viewer.session_revoked', $state['account_id'], 'success');
        }
    } catch (\Throwable) {
        // Local authority is always removed even if persistent revocation storage is unavailable.
    }
    viewer_session_clear();
}

/**
 * Revoke all viewer authentication authority for one account.
 *
 * This route-free logout-all foundation increments security_version and revokes
 * sessions, remember credentials, and outstanding reset credentials. If the
 * current PHP session belongs to that viewer, only its viewer namespace is cleared.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @return int New security version.
 */
function viewer_session_revoke_all(int $viewerAccountId): int
{
    $newVersion = viewer_account_invalidate_authentication($viewerAccountId);
    $state = viewer_session_state();
    if ($state !== null && (int) $state['account_id'] === $viewerAccountId) {
        viewer_session_clear();
    }
    return $newVersion;
}

/**
 * Invalidate every viewer session and persistent token after an account security event.
 *
 * The account security version is incremented atomically first. Existing session
 * and remember-token rows are then explicitly revoked for diagnostic clarity.
 * Future password reset/change, recovery, logout-all, and suspension flows can
 * call this single boundary.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @return int New security version.
 */
function viewer_account_invalidate_authentication(int $viewerAccountId): int
{
    if ($viewerAccountId <= 0) {
        throw new InvalidArgumentException('Viewer account id must be positive.');
    }
    if (!viewer_auth_storage_available()) {
        throw new RuntimeException('Viewer authentication storage is unavailable.');
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $lock = $pdo->prepare('SELECT security_version FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
        $lock->execute([$viewerAccountId]);
        if ($lock->fetchColumn() === false) {
            throw new RuntimeException('Viewer account was not found.');
        }

        $now = now_sql();
        $stmt = $pdo->prepare('UPDATE viewer_accounts SET security_version = security_version + 1, updated_at = ? WHERE id = ?');
        $stmt->execute([$now, $viewerAccountId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Viewer authentication invalidation did not update the account.');
        }

        $versionStmt = $pdo->prepare('SELECT security_version FROM viewer_accounts WHERE id = ? LIMIT 1');
        $versionStmt->execute([$viewerAccountId]);
        $newVersion = (int) $versionStmt->fetchColumn();

        $pdo->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
        $pdo->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
        $pdo->prepare('UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $newVersion;
    } catch (\Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Apply one explicit viewer account security-state transition.
 *
 * Suspending, disabling, and restoring all rotate security_version and revoke every
 * previously issued viewer authentication/reset/share capability. Restoring an account
 * therefore never resurrects old credentials.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @param string $targetStatus One supported durable account status.
 * @return bool True when the requested state exists after the transaction.
 */
function viewer_account_transition_status(int $viewerAccountId, string $targetStatus): bool
{
    if ($viewerAccountId <= 0 || !in_array($targetStatus, [
        VIEWER_ACCOUNT_STATUS_ACTIVE,
        VIEWER_ACCOUNT_STATUS_SUSPENDED,
        VIEWER_ACCOUNT_STATUS_DISABLED,
    ], true)) {
        throw new InvalidArgumentException('Viewer account transition parameters are invalid.');
    }
    if (!schema_inspection_is_available(viewer_account_security_transition_schema_status())) {
        throw new RuntimeException('Viewer account security transition storage is unavailable.');
    }

    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare('SELECT * FROM viewer_accounts WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$viewerAccountId]);
        $account = $stmt->fetch();
        if (!$account) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return false;
        }

        $currentStatus = (string) ($account['status'] ?? '');
        if ($currentStatus === $targetStatus) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return true;
        }
        if ($targetStatus === VIEWER_ACCOUNT_STATUS_ACTIVE
            && (empty($account['email_verified_at']) || (string) ($account['password_hash'] ?? '') === '')) {
            throw new RuntimeException('Viewer account cannot be activated without verified email and password authority.');
        }

        $now = now_sql();
        $suspendedAt = $targetStatus === VIEWER_ACCOUNT_STATUS_SUSPENDED ? $now : null;
        $disabledAt = $targetStatus === VIEWER_ACCOUNT_STATUS_DISABLED ? $now : null;
        $update = $pdo->prepare(
            'UPDATE viewer_accounts SET status = ?, security_version = security_version + 1, '
            . 'suspended_at = ?, disabled_at = ?, updated_at = ? WHERE id = ?'
        );
        $update->execute([$targetStatus, $suspendedAt, $disabledAt, $now, $viewerAccountId]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Viewer account state transition failed.');
        }

        $pdo->prepare('UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
        $pdo->prepare('UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);
        $pdo->prepare('UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);
        $pdo->prepare('UPDATE viewer_email_verification_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL')->execute([$now, $viewerAccountId]);
        $pdo->prepare('UPDATE viewer_email_change_requests SET cancelled_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND cancelled_at IS NULL')->execute([$now, $viewerAccountId]);
        $pdo->prepare('UPDATE viewer_collection_share_tokens SET revoked_at = ? WHERE created_by_viewer_account_id = ? AND revoked_at IS NULL')->execute([$now, $viewerAccountId]);

        if ($targetStatus === VIEWER_ACCOUNT_STATUS_SUSPENDED) {
            $eventKey = 'viewer.account_suspended';
        } elseif ($targetStatus === VIEWER_ACCOUNT_STATUS_DISABLED) {
            $eventKey = 'viewer.account_disabled';
        } elseif ($currentStatus === VIEWER_ACCOUNT_STATUS_PENDING_VERIFICATION) {
            $eventKey = 'viewer.account_activated';
        } else {
            $eventKey = 'viewer.account_restored';
        }
        if (function_exists(__NAMESPACE__ . '\\viewer_security_event_record')) {
            viewer_security_event_record($eventKey, $viewerAccountId, 'success', [
                'account_state' => $targetStatus,
            ]);
        }

        if ($ownsTransaction) {
            $pdo->commit();
        }
        $localState = viewer_session_state();
        if ($localState !== null && (int) $localState['account_id'] === $viewerAccountId) {
            viewer_session_clear();
        }
        return true;
    } catch (\Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Suspend one viewer account and revoke all prior viewer authority.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @return bool True when suspended.
 */
function viewer_account_suspend(int $viewerAccountId): bool
{
    return viewer_account_transition_status($viewerAccountId, VIEWER_ACCOUNT_STATUS_SUSPENDED);
}

/**
 * Disable one viewer account and revoke all prior viewer authority.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @return bool True when disabled.
 */
function viewer_account_disable(int $viewerAccountId): bool
{
    return viewer_account_transition_status($viewerAccountId, VIEWER_ACCOUNT_STATUS_DISABLED);
}

/**
 * Restore one previously suspended/disabled viewer account without restoring old authority.
 *
 * @param int $viewerAccountId Viewer account identifier.
 * @return bool True when active.
 */
function viewer_account_restore(int $viewerAccountId): bool
{
    return viewer_account_transition_status($viewerAccountId, VIEWER_ACCOUNT_STATUS_ACTIVE);
}
