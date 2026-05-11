<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/setup.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *   2026-05-04
 */

declare(strict_types=1);

/**
 * Setup controller model.
 * 
 * This module owns the setup wizard controller. It remains independent from theme customization and runtime gallery rendering.
 */

function cms_setup(): void
{
    if (cms_setup_is_locked()) {
        http_response_code(403);
        render_header(t('setup.locked_title'));
        echo '<section class="panel"><h1>' . e(t('setup.locked_title')) . '</h1><p>' . e(t('setup.locked_completed')) . '</p></section>';
        render_footer();
        return;
    }
    // Variable $key stores this steps working value.
    $key = (string) ($_GET['key'] ?? '');
    if ($key === '' || !hash_equals((string) cms_config()['setup_key'], $key)) {
        cms_not_found();
        return;
    }
    // Variable $ran stores this steps working value.
    $ran = run_migrations();
    if (cms_admin_user_exists()) {
        cms_write_setup_lock();
        http_response_code(403);
        render_header(t('setup.locked_title'));
        echo '<section class="panel"><h1>' . e(t('setup.locked_title')) . '</h1><p>' . e(t('setup.locked_admin_exists')) . '</p></section>';
        render_footer();
        return;
    }
    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $username stores this steps working value.
        $username = trim((string) $_POST['username']);
        // Variable $email stores this steps working value.
        $email = cms_normalize_account_email((string) ($_POST['email'] ?? ''));
        // Variable $password stores this steps working value.
        $password = (string) $_POST['password'];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Variable $error stores this steps working value.
            $error = t('setup.error_recovery_email_invalid');
        } elseif ($username !== '' && $password !== '') {
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('INSERT INTO users (username, email, password_hash, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE email = VALUES(email), password_hash = VALUES(password_hash), updated_at = VALUES(updated_at)');
            $stmt->execute([$username, $email === '' ? null : $email, password_hash($password, PASSWORD_DEFAULT), 'admin', now_sql(), now_sql()]);
            cms_write_setup_lock();
            redirect_to(url_for('admin_login'));
        }
    }
    render_header(t('setup.title'));
    if (isset($error)) {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('setup.title')) . '</h1><p>' . e(t('setup.applied_migrations', 'Applied migrations: {migrations}', ['migrations' => $ran ? implode(', ', $ran) : t('admin.common.none')])) . '</p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<label>' . e(t('setup.admin_username')) . '<input name="username" required autocomplete="username"></label>';
    echo '<label>' . e(t('setup.admin_recovery_email')) . '<input name="email" type="email" autocomplete="email"></label>';
    echo '<p class="muted">' . e(t('setup.recovery_email_help')) . '</p>';
    echo '<label>' . e(t('setup.admin_password')) . '<input name="password" type="password" required autocomplete="new-password"></label>';
    echo '<button type="submit">' . e(t('setup.create_or_update_admin')) . '</button></form></section>';
    render_footer();
}

