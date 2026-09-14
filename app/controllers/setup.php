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

namespace Gallery\Controllers;

use function Gallery\Services\auth_account_normalize_email;

use function Gallery\Core\cms_admin_user_exists;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_setup_is_locked;
use function Gallery\Core\cms_write_setup_lock;
use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\run_migrations;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\t;
use function Gallery\Services\auth_setup_upsert_admin;
use function Gallery\Services\feature_capability_seed_fresh_install_defaults;
use function Gallery\Views\view_render_setup_form;
use function Gallery\Views\view_render_setup_locked;

/**
 * Setup controller model.
 *
 * This module owns the setup wizard controller. It remains independent from theme customization and runtime gallery rendering.
 */
function cms_setup(): void
{
    if (cms_setup_is_locked()) {
        http_response_code(403);
        view_render_setup_locked([
            'title' => t('setup.locked_title'),
            'message' => t('setup.locked_completed'),
        ]);
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
        view_render_setup_locked([
            'title' => t('setup.locked_title'),
            'message' => t('setup.locked_admin_exists'),
        ]);
        return;
    }

    // Seed conservative capability defaults only inside the confirmed first-install lifecycle.
    feature_capability_seed_fresh_install_defaults();

    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $username stores this steps working value.
        $username = trim((string) $_POST['username']);
        // Variable $email stores this steps working value.
        $email = auth_account_normalize_email((string) ($_POST['email'] ?? ''));
        // Variable $password stores this steps working value.
        $password = (string) $_POST['password'];
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            // Variable $error stores this steps working value.
            $error = t('setup.error_recovery_email_invalid');
        } elseif ($username !== '' && $password !== '') {
            auth_setup_upsert_admin($username, $email, $password);
            cms_write_setup_lock();
            redirect_to(url_for('admin_login'));
        }
    }
    view_render_setup_form([
        'error' => isset($error) ? (string) $error : '',
        'migrations' => $ran,
        'csrf_html' => csrf_field(),
    ]);
}

