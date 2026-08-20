<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/security.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides core bootstrap, configuration, helper, security, database, or routing functionality.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Core;

use Throwable;
use function Gallery\Services\app_setting;
use function Gallery\Services\auth_restore_persistent_login;
use function Gallery\Services\auth_log_schema_unavailable;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\auth_user_email_schema_status;
use function Gallery\Services\t;

/**
 * Get or create the per-session CSRF token used by admin POST forms.
 *
 * @return string Text result for the caller.
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
 *
 * @return string Text result for the caller.
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
    // Variable $token stores this steps working value.
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(400);
        exit('Invalid CSRF token.');
    }
}

/**
 * Return the logged-in admin user, or null for anonymous visitors.
 *
 * @return ?array Structured result data for the caller.
 */
function current_user(): ?array
{
    static $cache = false;
    static $cachedUser = null;
    if ($cache !== false) {
        return $cachedUser;
    }
    if (empty($_SESSION['user_id'])) {
        // $restoredUser stores a durable login restored from a hashed database token when PHP session storage expired.
        $restoredUser = function_exists('Gallery\Services\auth_restore_persistent_login') ? auth_restore_persistent_login() : null;
        if (!$restoredUser) {
            // $cache stores an intermediate value used by the surrounding gallery workflow.
            $cache = true;
            return $cachedUser = null;
        }
    }

    // Email is optional for an already authenticated session. Confirmed missing or
    // operationally unknown email metadata must not be converted by catching an
    // arbitrary PDO failure from the full user query. Use the verified capability
    // when available and the authentication-minimal row shape otherwise.
    $emailSchemaStatus = function_exists('Gallery\Services\auth_user_email_schema_status')
        ? auth_user_email_schema_status()
        : ['state' => 'missing'];
    if (function_exists('Gallery\Services\schema_inspection_is_unknown') && schema_inspection_is_unknown($emailSchemaStatus)) {
        auth_log_schema_unavailable('auth_user_email', 'current_user_optional_email');
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare(
        function_exists('Gallery\Services\schema_inspection_is_available') && schema_inspection_is_available($emailSchemaStatus)
            ? 'SELECT id, username, email, role FROM users WHERE id = ?'
            : 'SELECT id, username, role FROM users WHERE id = ?'
    );
    $stmt->execute([(int) $_SESSION['user_id']]);
    // Variable $user stores this steps working value.
    $user = $stmt->fetch();
    if ($user && !array_key_exists('email', $user)) {
        $user['email'] = null;
    }

    // $cache stores an intermediate value used by the surrounding gallery workflow.
    $cache = true;
    return $cachedUser = ($user ?: null);
}

/**
 * Redirect anonymous/non-admin users to the login page.
 */
function require_admin(): void
{
    // Variable $user stores this steps working value.
    $user = current_user();
    if (!$user || $user['role'] !== 'admin') {
        // Preserve the protected URL so successful login resumes the intended admin action.
        redirect_to(url_for('admin_login', ['return' => current_login_return_target()]));
    }
}

/**
 * Build a stable anonymous identity for voting without storing raw IP addresses.
 *
 * @return string Text result for the caller.
 */
function visitor_hash(): string
{
    // Variable $secret stores this steps working value.
    $secret = (string) cms_config()['visitor_vote_secret'];
    // Variable $ip stores this steps working value.
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    // Variable $agent stores this steps working value.
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash('sha256', $ip . '|' . $agent . '|' . $secret);
}


/**
 * Send conservative browser security headers for normal HTML and asset routes.
 */
function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // $page stores an intermediate value used by the surrounding gallery workflow.
    $page = (string) ($_GET['page'] ?? 'home');
    // Viewer and viewer pre-auth session state must never be eligible for anonymous/public response caching.
    // This is intentionally a PHP-session presence check only; cache classification must not query viewer tables.
    $hasViewerSensitiveState = isset($_SESSION['viewer_auth'])
        || isset($_SESSION['viewer_registration_activation'])
        || isset($_SESSION['viewer_password_reset'])
        || isset($_SESSION['viewer_csrf_token'])
        || isset($_COOKIE['php_gallery_viewer_remember']);
    // $isAnonymousGet stores an intermediate value used by the surrounding gallery workflow.
    $isAnonymousGet = request_method() === 'GET' && current_user() === null && !$hasViewerSensitiveState;
    // $isPublicHtmlCacheCandidate stores public pages whose rendered HTML depends on DB-backed theme and gallery settings.
    $isPublicHtmlCacheCandidate = in_array($page, ['home', 'gallery', 'share', 'tag', 'picture_game'], true);
    // $isStaticPublicCacheCandidate stores public routes that do not render gallery-card HTML.
    $isStaticPublicCacheCandidate = in_array($page, ['robots', 'sitemap', 'theme_css'], true);

    if ($isAnonymousGet && $isPublicHtmlCacheCandidate) {
        // Public gallery pages include theme-controlled HTML classes, for example the horizontal/vertical
        // gallery description layout. Require revalidation so a Theme save is visible on a normal refresh.
        header('Cache-Control: no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Theme-Content-Revision: ' . (string) app_setting('theme_public_content_revision', '0'));
        return;
    }

    if ($isAnonymousGet && $isStaticPublicCacheCandidate) {
        header('Cache-Control: public, max-age=120, stale-while-revalidate=600');
        return;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

/**
 * Reject public POST abuse by limiting fast repeated anonymous votes per image.
 *
 * @param int $imageId Image identifier.
 */
function verify_vote_rate_limit(int $imageId): void
{
    // $now stores an intermediate value used by the surrounding gallery workflow.
    $now = time();
    // $key stores an intermediate value used by the surrounding gallery workflow.
    $key = 'vote_rate_' . $imageId;
    // $lastVote stores an intermediate value used by the surrounding gallery workflow.
    $lastVote = (int) ($_SESSION[$key] ?? 0);
    if ($lastVote > 0 && ($now - $lastVote) < 2) {
        http_response_code(429);
        header('Content-Type: application/json');
        exit(json_encode(['error' => function_exists('Gallery\\Services\\t') ? t('vote.error.too_many_votes', 'Too many votes. Try again in a moment.') : 'Too many votes. Try again in a moment.']));
    }
    $_SESSION[$key] = $now;
}

/**
 * Return true when installer/setup functionality has been locked after install.
 *
 * @return bool True when the condition matches.
 */
function cms_setup_is_locked(): bool
{
    return is_file(dirname(__DIR__) . '/config.php') && is_file(dirname(__DIR__) . '/cache/installed.lock');
}

/**
 * Return true when setup has already created at least one administrator.
 *
 * @return bool True when the condition matches.
 */
function cms_admin_user_exists(): bool
{
    try {
        return (bool) db()->query("SELECT 1 FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Create the one-way installation lock file after the first successful setup.
 */
function cms_write_setup_lock(): void
{
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = dirname(__DIR__) . '/cache/installed.lock';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, 'installed=' . gmdate('c') . PHP_EOL, LOCK_EX);
}
