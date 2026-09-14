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
