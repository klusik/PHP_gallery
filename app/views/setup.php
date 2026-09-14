<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/setup.php
 * Module Type: View
 *
 * Purpose:
 *   Renders setup wizard pages from controller-prepared presentation data.
 *
 * Responsibilities:
 *   - Render locked setup states
 *   - Render the initial administrator account form
 *   - Avoid request, persistence, and domain-service lookups
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
 *   - The controller owns setup authorization, migration execution, and account persistence.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Render one locked setup state.
 *
 * @param array<string, mixed> $viewModel Controller-prepared title and message.
 */
function view_render_setup_locked(array $viewModel): void
{
    $title = (string) ($viewModel['title'] ?? '');
    $message = (string) ($viewModel['message'] ?? '');

    render_header($title);
    echo '<section class="panel"><h1>' . e($title) . '</h1><p>' . e($message) . '</p></section>';
    render_footer();
}

/**
 * Render the first-install administrator setup form.
 *
 * @param array<string, mixed> $viewModel Controller-prepared error, migration list, and CSRF field.
 */
function view_render_setup_form(array $viewModel): void
{
    $error = (string) ($viewModel['error'] ?? '');
    $migrations = (array) ($viewModel['migrations'] ?? []);
    $csrfHtml = (string) ($viewModel['csrf_html'] ?? '');
    $title = t('setup.title');

    render_header($title);
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e($title) . '</h1><p>' . e(t('setup.applied_migrations', 'Applied migrations: {migrations}', ['migrations' => $migrations !== [] ? implode(', ', $migrations) : t('admin.common.none')])) . '</p>';
    echo '<form method="post" class="form-grid">' . $csrfHtml;
    echo '<label>' . e(t('setup.admin_username')) . '<input name="username" required autocomplete="username"></label>';
    echo '<label>' . e(t('setup.admin_recovery_email')) . '<input name="email" type="email" autocomplete="email"></label>';
    echo '<p class="muted">' . e(t('setup.recovery_email_help')) . '</p>';
    echo '<label>' . e(t('setup.admin_password')) . '<input name="password" type="password" required autocomplete="new-password"></label>';
    echo '<button type="submit">' . e(t('setup.create_or_update_admin')) . '</button></form></section>';
    render_footer();
}
