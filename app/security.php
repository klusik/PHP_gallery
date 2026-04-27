<?php

declare(strict_types=1);

/**
 * Get or create the per-session CSRF token used by admin POST forms.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

/**
 * Render the hidden CSRF field for admin forms.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Abort the request when a POST form does not contain the expected token.
 */
function verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
}

/**
 * Return the logged-in admin user, or null for anonymous visitors.
 */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $stmt = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    return $user ?: null;
}

/**
 * Redirect anonymous/non-admin users to the login page.
 */
function require_admin(): void
{
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        redirect_to(url_for('admin_login'));
    }
}

/**
 * Build a stable anonymous identity for voting without storing raw IP addresses.
 */
function visitor_hash(): string
{
    $secret = (string) cms_config()['visitor_vote_secret'];
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash('sha256', $ip . '|' . $agent . '|' . $secret);
}

