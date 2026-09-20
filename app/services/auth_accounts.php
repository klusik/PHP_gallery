<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/auth_accounts.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides Admin account and password-reset use-cases above the auth model layer.
 *
 * Responsibilities:
 *   - Keep password hashing and verification outside controllers and models
 *   - Orchestrate password-reset persistence through semantic model operations
 *   - Preserve optional email-column behavior without exposing SQL to callers
 *   - Own the completed-setup filesystem marker used by the compatibility security facade
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
 *   - Session, cookies, redirects, and request parsing remain controller concerns.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;
require_once dirname(__DIR__) . '/policy_constants.php';
use const Gallery\Core\SETUP_LOCK_DIRECTORY_PERMISSIONS;

use function Gallery\Core\now_sql;
use function Gallery\Models\auth_model_account_row;
use function Gallery\Models\auth_model_cleanup_password_reset_tokens;
use function Gallery\Models\auth_model_email_taken;
use function Gallery\Models\auth_model_find_user_by_email;
use function Gallery\Models\auth_model_find_user_by_username;
use function Gallery\Models\auth_model_find_valid_password_reset;
use function Gallery\Models\auth_model_mark_password_reset_used;
use function Gallery\Models\auth_model_replace_password_reset_token;
use function Gallery\Models\auth_model_update_account;
use function Gallery\Models\auth_model_update_user_password;
use function Gallery\Models\auth_model_upsert_setup_admin;
use function Gallery\Models\auth_model_user_password_hash;
use function Gallery\Models\auth_model_username_taken;

/**
 * Observe the historical in-application setup lock condition without reading configuration contents.
 *
 * The standalone installer deliberately has its own earlier OR guard. This
 * compatibility condition remains config-file AND completion-marker existence.
 *
 * @return bool Whether both the fixed configuration file and setup marker exist.
 */
function auth_setup_is_locked(): bool
{
    $root = dirname(__DIR__, 2);
    return is_file($root . '/config.php') && is_file($root . '/cache/installed.lock');
}

/**
 * Persist the setup-completion marker after the controller has verified successful setup.
 *
 * No submitted path, credential or configuration contents enter the marker.
 * A storage failure refuses with a bounded error instead of implying completion.
 *
 * @return void Creates the cache directory if needed and writes the existing UTC marker format.
 * @throws \RuntimeException When the fixed marker location cannot be prepared or written.
 */
function auth_setup_write_lock(): void
{
    $directory = dirname(__DIR__, 2) . '/cache';
    if (!is_dir($directory) && !@mkdir($directory, SETUP_LOCK_DIRECTORY_PERMISSIONS, true) && !is_dir($directory)) {
        throw new \RuntimeException('The setup completion marker directory could not be prepared.');
    }
    if (@file_put_contents($directory . '/installed.lock', 'installed=' . gmdate('c') . PHP_EOL, LOCK_EX) === false) {
        throw new \RuntimeException('The setup completion marker could not be written.');
    }
}

/**
 * Validate an existing session identity using the safe optional-email schema policy.
 *
 * Confirmed missing or unknown email metadata uses the authentication-minimal
 * projection. Unknown metadata remains a bounded logged capability observation;
 * it is never inferred from catching an arbitrary full-row query failure.
 *
 * @param int $userId Identifier supplied by the authenticated request/session adapter.
 * @return array{id:mixed,username:string,role:string,email:?string}|null Non-credential user projection.
 */
function auth_account_session_user(int $userId): ?array
{
    $emailSchemaStatus = function_exists('Gallery\Services\auth_user_email_schema_status')
        ? auth_user_email_schema_status() : ['state' => 'missing'];
    if (function_exists('Gallery\Services\schema_inspection_is_unknown') && schema_inspection_is_unknown($emailSchemaStatus)) {
        auth_log_schema_unavailable('auth_user_email', 'current_user_optional_email');
    }
    $emailAvailable = function_exists('Gallery\Services\schema_inspection_is_available')
        && schema_inspection_is_available($emailSchemaStatus);
    $user = \Gallery\Models\auth_model_session_user($userId, $emailAvailable);
    if ($user && !array_key_exists('email', $user)) {
        $user['email'] = null;
    }
    return $user;
}

/**
 * Preserve setup's historical best-effort administrator-existence observation.
 *
 * @return bool False on unavailable setup storage; creation still owns its schema preflight.
 */
function auth_account_admin_exists(): bool
{
    try {
        return \Gallery\Models\auth_model_admin_exists();
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Normalize an optional Admin account email value before validation or storage.
 *
 * @param string $email Email value.
 * @return string Normalized account email.
 */
function auth_account_normalize_email(string $email): string
{
    return trim(strtolower($email));
}

/** @return array<string,mixed>|null */
function auth_account_find_by_email(string $email): ?array
{
    return auth_model_find_user_by_email($email);
}

/** @return array<string,mixed>|null */
function auth_account_find_by_username(string $username): ?array
{
    return auth_model_find_user_by_username($username);
}

/**
 * Remove expired and consumed password-reset tokens.
 */
function auth_account_cleanup_password_reset_tokens(): void
{
    auth_model_cleanup_password_reset_tokens(now_sql());
}

/**
 * Replace a user's outstanding password-reset token with a newly issued token.
 *
 * @param int $userId User identifier.
 * @param string $selector Public token selector.
 * @param string $tokenHash SHA-256 hash of the secret token.
 * @param string $expiresAt Token expiration timestamp.
 * @param string $requestHash Privacy-safe request fingerprint.
 */
function auth_account_replace_password_reset_token(int $userId, string $selector, string $tokenHash, string $expiresAt, string $requestHash): void
{
    auth_model_replace_password_reset_token($userId, $selector, $tokenHash, now_sql(), $expiresAt, $requestHash);
}

/** @return array<string,mixed>|null */
function auth_account_find_valid_password_reset(string $selector): ?array
{
    return auth_model_find_valid_password_reset($selector, now_sql());
}

/**
 * Persist a replacement password and consume the reset token used for it.
 *
 * @param int $userId User identifier.
 * @param int $resetTokenId Password-reset token identifier.
 * @param string $newPassword New plaintext password supplied by the authenticated reset flow.
 */
function auth_account_complete_password_reset(int $userId, int $resetTokenId, string $newPassword): void
{
    auth_model_update_user_password($userId, password_hash($newPassword, PASSWORD_DEFAULT), now_sql());
    auth_model_mark_password_reset_used($resetTokenId, now_sql());
}

/**
 * Verify a plaintext password against one user's stored password hash.
 *
 * @param int $userId User identifier.
 * @param string $password Plaintext password to verify.
 * @return bool True when the supplied password matches.
 */
function auth_account_password_matches(int $userId, string $password): bool
{
    $hash = auth_model_user_password_hash($userId);
    return $hash !== null && password_verify($password, $hash);
}

/** @return array<string,mixed>|null */
function auth_account_profile_row(int $userId, bool $emailSchemaAvailable): ?array
{
    return auth_model_account_row($userId, $emailSchemaAvailable);
}

/**
 * Return whether an account username is already owned by another user.
 *
 * @param string $username Candidate username.
 * @param int $excludeUserId User identifier excluded from the uniqueness check.
 * @return bool True when the username is unavailable.
 */
function auth_account_username_taken(string $username, int $excludeUserId): bool
{
    return auth_model_username_taken($username, $excludeUserId);
}

/**
 * Return whether a recovery email is already owned by another user.
 *
 * @param string $email Candidate recovery email.
 * @param int $excludeUserId User identifier excluded from the uniqueness check.
 * @return bool True when the email is unavailable.
 */
function auth_account_email_taken(string $email, int $excludeUserId): bool
{
    return auth_model_email_taken($email, $excludeUserId);
}

/**
 * Persist validated editable Admin account fields.
 *
 * @param int $userId User identifier.
 * @param string $username Validated username.
 * @param string $email Normalized recovery email, possibly empty.
 * @param bool $emailSchemaAvailable Whether the installed schema supports email.
 * @param string $newPassword Optional plaintext replacement password, possibly empty.
 */
function auth_account_update_profile(int $userId, string $username, string $email, bool $emailSchemaAvailable, string $newPassword): void
{
    $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : null;
    auth_model_update_account($userId, $username, $emailSchemaAvailable && $email !== '' ? $email : null, $emailSchemaAvailable, $passwordHash, now_sql());
}

/**
 * Create or replace the setup Admin credentials through the auth model.
 *
 * @param string $username Admin username.
 * @param string $email Optional recovery email, possibly empty.
 * @param string $password Plaintext password from the setup form.
 */
function auth_setup_upsert_admin(string $username, string $email, string $password): void
{
    auth_model_upsert_setup_admin($username, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), now_sql());
}
