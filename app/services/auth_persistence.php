<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/auth_persistence.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides durable administrator login persistence for shared hosting.
 *
 * Responsibilities:
 *   - Keep admin sessions alive across normal browser restarts
 *   - Restore an admin session from a hashed database token when PHP session files expire
 *   - Revoke persistent tokens on logout and account security changes
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
 *
 * Last Updated:
 *   2026-05-31
 */

declare(strict_types=1);

/**
 * Return authentication configuration merged with safe defaults.
 */
function auth_persistence_config(): array
{
    // $config stores the installed configuration array.
    $config = cms_config();
    // $auth stores optional authentication settings from config.php.
    $auth = is_array($config['auth'] ?? null) ? $config['auth'] : [];

    return [
        'session_lifetime_days' => max(1, min(90, (int) ($auth['session_lifetime_days'] ?? 14))),
        'remember_lifetime_days' => max(1, min(365, (int) ($auth['remember_lifetime_days'] ?? 30))),
        'persistent_login_enabled' => array_key_exists('persistent_login_enabled', $auth) ? (bool) $auth['persistent_login_enabled'] : true,
        'persistent_login_default_checked' => array_key_exists('persistent_login_default_checked', $auth) ? (bool) $auth['persistent_login_default_checked'] : true,
    ];
}

/**
 * Return the long-lived PHP session lifetime in seconds.
 */
function auth_admin_session_lifetime_seconds(): int
{
    // $settings stores normalized authentication settings.
    $settings = auth_persistence_config();
    return (int) $settings['session_lifetime_days'] * 86400;
}

/**
 * Return the persistent login cookie lifetime in seconds.
 */
function auth_remember_lifetime_seconds(): int
{
    // $settings stores normalized authentication settings.
    $settings = auth_persistence_config();
    return (int) $settings['remember_lifetime_days'] * 86400;
}

/**
 * Return true when DB-backed persistent login is enabled and migrated.
 */
function auth_persistent_login_ready(): bool
{
    // $settings stores normalized authentication settings.
    $settings = auth_persistence_config();
    return (bool) $settings['persistent_login_enabled']
        && function_exists('db_table_exists')
        && db_table_exists('admin_remember_tokens');
}

/**
 * Return the persistent login cookie name for this installation.
 */
function auth_remember_cookie_name(): string
{
    // $sessionName stores the configured admin session name.
    $sessionName = preg_replace('/[^a-zA-Z0-9_]/', '_', (string) cms_config()['admin_session_name']);
    return $sessionName . '_remember';
}

/**
 * Send or clear the persistent login cookie using admin-safe attributes.
 */
function auth_set_remember_cookie(string $value, int $expiresAt): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(auth_remember_cookie_name(), $value, [
        'expires' => $expiresAt,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    if ($expiresAt <= time()) {
        unset($_COOKIE[auth_remember_cookie_name()]);
    } else {
        $_COOKIE[auth_remember_cookie_name()] = $value;
    }
}

/**
 * Remove expired and revoked persistent login tokens.
 */
function auth_prune_persistent_tokens(?int $userId = null): void
{
    if (!auth_persistent_login_ready()) {
        return;
    }

    if ($userId !== null) {
        // $stmt stores the scoped cleanup query for one account.
        $stmt = db()->prepare('DELETE FROM admin_remember_tokens WHERE user_id = ? AND (expires_at < ? OR revoked_at IS NOT NULL)');
        $stmt->execute([$userId, now_sql()]);
        return;
    }

    db()->prepare('DELETE FROM admin_remember_tokens WHERE expires_at < ? OR revoked_at IS NOT NULL')->execute([now_sql()]);
}

/**
 * Issue a new persistent login token and store only its hash in the database.
 */
function auth_issue_persistent_login(int $userId): void
{
    if (!auth_persistent_login_ready()) {
        return;
    }

    auth_prune_persistent_tokens($userId);
    // $selector stores the public database lookup key saved in the browser cookie.
    $selector = bin2hex(random_bytes(18));
    // $validator stores the private credential saved only in the browser cookie.
    $validator = bin2hex(random_bytes(32));
    // $validatorHash stores the one-way credential hash saved in the database.
    $validatorHash = hash('sha256', $validator);
    // $expiresAt stores the database expiration timestamp.
    $expiresAt = date('Y-m-d H:i:s', time() + auth_remember_lifetime_seconds());
    // $userAgentHash stores a privacy-safe diagnostic for future troubleshooting.
    $userAgentHash = hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));

    // Limit old active tokens per admin account so forgotten browsers do not accumulate forever.
    // $oldTokenLimit stores the number of newest active tokens to keep before issuing this one.
    $oldTokenLimit = 10;
    // $stmt stores active token ids older than the newest retained set.
    $stmt = db()->prepare('SELECT id FROM admin_remember_tokens WHERE user_id = ? AND revoked_at IS NULL AND expires_at >= ? ORDER BY created_at DESC, id DESC LIMIT 100 OFFSET ' . $oldTokenLimit);
    $stmt->execute([$userId, now_sql()]);
    // $oldIds stores token ids that should be revoked before adding a new token.
    $oldIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($oldIds !== []) {
        // $placeholders stores a safe placeholder list for the old token ids.
        $placeholders = implode(',', array_fill(0, count($oldIds), '?'));
        db()->prepare('UPDATE admin_remember_tokens SET revoked_at = ? WHERE id IN (' . $placeholders . ')')->execute(array_merge([now_sql()], $oldIds));
    }

    // $stmt stores the persistent token insert statement.
    $stmt = db()->prepare('INSERT INTO admin_remember_tokens (user_id, selector, token_hash, user_agent_hash, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $selector, $validatorHash, $userAgentHash, now_sql(), $expiresAt]);

    auth_set_remember_cookie($selector . ':' . $validator, time() + auth_remember_lifetime_seconds());
}

/**
 * Parse the browser persistent login cookie into selector and validator parts.
 */
function auth_parse_remember_cookie(): ?array
{
    // $cookie stores the raw browser cookie value.
    $cookie = (string) ($_COOKIE[auth_remember_cookie_name()] ?? '');
    if ($cookie === '' || !str_contains($cookie, ':')) {
        return null;
    }

    [$selector, $validator] = explode(':', $cookie, 2);
    if (!preg_match('/^[a-f0-9]{36}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) {
        return null;
    }

    return ['selector' => $selector, 'validator' => $validator];
}

/**
 * Restore an admin session from a valid persistent login cookie.
 */
function auth_restore_persistent_login(): ?array
{
    if (!auth_persistent_login_ready() || session_status() !== PHP_SESSION_ACTIVE) {
        return null;
    }

    // $cookie stores validated persistent cookie parts.
    $cookie = auth_parse_remember_cookie();
    if ($cookie === null) {
        return null;
    }

    // $stmt stores the token lookup joined with the target admin user.
    $stmt = db()->prepare('SELECT art.*, u.username, u.email, u.role FROM admin_remember_tokens art INNER JOIN users u ON u.id = art.user_id WHERE art.selector = ? AND art.revoked_at IS NULL AND art.expires_at >= ? LIMIT 1');
    $stmt->execute([$cookie['selector'], now_sql()]);
    // $row stores the matched remember token and user data.
    $row = $stmt->fetch();
    if (!$row) {
        auth_set_remember_cookie('', time() - 3600);
        return null;
    }

    if (!hash_equals((string) $row['token_hash'], hash('sha256', (string) $cookie['validator']))) {
        // A selector with a bad validator is treated as a stolen or corrupted cookie.
        // $stmt stores the targeted revocation query.
        $stmt = db()->prepare('UPDATE admin_remember_tokens SET revoked_at = ? WHERE id = ?');
        $stmt->execute([now_sql(), (int) $row['id']]);
        auth_set_remember_cookie('', time() - 3600);
        return null;
    }

    if ((string) $row['role'] !== 'admin') {
        auth_set_remember_cookie('', time() - 3600);
        return null;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $row['user_id'];
    // $stmt stores the successful-use timestamp update.
    $stmt = db()->prepare('UPDATE admin_remember_tokens SET last_used_at = ? WHERE id = ?');
    $stmt->execute([now_sql(), (int) $row['id']]);

    return [
        'id' => (int) $row['user_id'],
        'username' => (string) $row['username'],
        'email' => $row['email'] ?? null,
        'role' => (string) $row['role'],
    ];
}

/**
 * Revoke the current persistent login token if the browser has one.
 */
function auth_revoke_current_persistent_login(): void
{
    if (!auth_persistent_login_ready()) {
        auth_set_remember_cookie('', time() - 3600);
        return;
    }

    // $cookie stores validated persistent cookie parts.
    $cookie = auth_parse_remember_cookie();
    if ($cookie !== null) {
        // $stmt stores the targeted revocation query for the current selector.
        $stmt = db()->prepare('UPDATE admin_remember_tokens SET revoked_at = ? WHERE selector = ?');
        $stmt->execute([now_sql(), $cookie['selector']]);
    }

    auth_set_remember_cookie('', time() - 3600);
}

/**
 * Revoke every persistent login token for one user.
 */
function auth_revoke_user_persistent_logins(int $userId): void
{
    if (!auth_persistent_login_ready()) {
        auth_set_remember_cookie('', time() - 3600);
        return;
    }

    // $stmt stores the account-wide revocation query.
    $stmt = db()->prepare('UPDATE admin_remember_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL');
    $stmt->execute([now_sql(), $userId]);
    auth_set_remember_cookie('', time() - 3600);
}
