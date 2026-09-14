<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/viewer_lifecycle.php
 * Module Type: View
 *
 * Purpose:
 *   Renders viewer account lifecycle screens from controller-prepared state.
 *
 * Responsibilities:
 *   - Render recent reauthentication and password-change forms
 *   - Render staged email-change verification and confirmation screens
 *   - Render viewer account deletion confirmation and completion screens
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
 *   - Authentication, token validation, CSRF verification, lifecycle mutations,
 *     session cleanup, and security-event persistence remain outside the view.
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

/** @param array<string,mixed> $viewModel Controller-prepared recent-reauthentication state. */
function view_render_viewer_reauthentication(array $viewModel): void
{
    render_header(t('viewer.reauth.title', 'Confirm viewer password'));
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.reauth.title', 'Confirm viewer password')) . '</h1>';
    echo '<p>' . e(t('viewer.reauth.help', 'Confirm your current viewer password before continuing with this sensitive account action.')) . '</p>';
    echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="destination" value="' . e((string) ($viewModel['destination'] ?? '')) . '">';
    echo '<label>' . e(t('viewer.common.password', 'Password')) . '<input type="password" name="password" required autocomplete="current-password"></label>';
    echo '<button type="submit">' . e(t('viewer.reauth.button', 'Confirm password')) . '</button></form>';
    echo '<p class="muted"><a href="' . e((string) ($viewModel['account_url'] ?? '')) . '">' . e(t('viewer.lifecycle.back_to_account', 'Back to account')) . '</a></p></section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared password-change state. */
function view_render_viewer_password_change(array $viewModel): void
{
    render_header(t('viewer.password_change.title', 'Change viewer password'));
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.password_change.title', 'Change viewer password')) . '</h1>';
    echo '<p>' . e(t('viewer.password_change.help', 'Changing your password signs the viewer account out on all devices and revokes viewer remember-me credentials.')) . '</p>';
    echo '<form method="post" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<label>' . e(t('viewer.password_change.new_password', 'New password')) . '<input type="password" name="password" required autocomplete="new-password"></label>';
    echo '<label>' . e(t('viewer.common.confirm_password', 'Confirm password')) . '<input type="password" name="password_confirmation" required autocomplete="new-password"></label>';
    echo (string) ($viewModel['password_policy_html'] ?? '');
    echo '<button type="submit">' . e(t('viewer.password_change.button', 'Change password')) . '</button></form>';
    echo '<p class="muted"><a href="' . e((string) ($viewModel['account_url'] ?? '')) . '">' . e(t('viewer.lifecycle.back_to_account', 'Back to account')) . '</a></p></section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared staged email-change state. */
function view_render_viewer_email_change(array $viewModel): void
{
    render_header(t('viewer.email_change.title', 'Change viewer email'));
    foreach (['notice', 'error'] as $messageKey) {
        $message = (string) ($viewModel[$messageKey] ?? '');
        if ($message !== '') {
            echo '<div class="notice">' . e($message) . '</div>';
        }
    }
    echo '<section class="panel"><h1>' . e(t('viewer.email_change.title', 'Change viewer email')) . '</h1>';
    echo '<p>' . e(t('viewer.email_change.current', 'Current verified email: {email}', ['email' => (string) ($viewModel['current_email'] ?? '')])) . '</p>';
    echo '<p class="muted">' . e(t('viewer.email_change.help', 'The current email stays active until the new address is verified and explicitly confirmed.')) . '</p>';
    echo '<form method="post" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<label>' . e(t('viewer.email_change.new_email', 'New email')) . '<input type="email" name="email" required autocomplete="email"></label>';
    echo '<button type="submit">' . e(t('viewer.email_change.button', 'Send verification email')) . '</button></form>';
    echo '<p class="muted"><a href="' . e((string) ($viewModel['account_url'] ?? '')) . '">' . e(t('viewer.lifecycle.back_to_account', 'Back to account')) . '</a></p></section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared login-required verification state. */
function view_render_viewer_email_verify_login_required(array $viewModel): void
{
    render_header(t('viewer.email_change.verify_title', 'Verify new viewer email'));
    echo '<section class="panel"><h1>' . e(t('viewer.email_change.verify_title', 'Verify new viewer email')) . '</h1>';
    echo '<p>' . e(t('viewer.email_change.login_first', 'Sign in to the viewer account, then reopen this verification link to continue. The email has not changed.')) . '</p>';
    echo '<p><a class="button" href="' . e((string) ($viewModel['login_url'] ?? '')) . '">' . e(t('viewer.login.button', 'Sign in')) . '</a></p></section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared verified email-change state. */
function view_render_viewer_email_verify(array $viewModel): void
{
    render_header(t('viewer.email_change.verify_title', 'Verify new viewer email'));
    echo '<section class="panel"><h1>' . e(t('viewer.email_change.verify_title', 'Verify new viewer email')) . '</h1>';
    echo '<p>' . e(t('viewer.email_change.verify_help', 'The verification link is valid. Confirm the final email change below. This page has not changed the account email.')) . '</p>';
    echo '<p><strong>' . e((string) ($viewModel['new_email'] ?? '')) . '</strong></p>';
    echo '<form method="post" action="' . e((string) ($viewModel['confirm_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<button type="submit">' . e(t('viewer.email_change.confirm_button', 'Confirm email change')) . '</button></form>';
    echo '<p class="muted"><a href="' . e((string) ($viewModel['account_url'] ?? '')) . '">' . e(t('viewer.lifecycle.back_to_account', 'Back to account')) . '</a></p></section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared final email-confirmation form state. */
function view_render_viewer_email_confirm(array $viewModel): void
{
    render_header(t('viewer.email_change.verify_title', 'Verify new viewer email'));
    echo '<section class="panel"><h1>' . e(t('viewer.email_change.verify_title', 'Verify new viewer email')) . '</h1>';
    echo '<p>' . e(t('viewer.email_change.confirm_help', 'Confirm the final email change. The account email remains unchanged until this POST request succeeds.')) . '</p>';
    echo '<form method="post" action="' . e((string) ($viewModel['confirm_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<button type="submit">' . e(t('viewer.email_change.confirm_button', 'Confirm email change')) . '</button></form></section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared email-confirmation failure state. */
function view_render_viewer_email_confirm_failed(array $viewModel): void
{
    render_header(t('viewer.email_change.verify_title', 'Verify new viewer email'));
    echo '<section class="panel"><h1>' . e(t('viewer.email_change.verify_title', 'Verify new viewer email')) . '</h1>';
    echo '<div class="notice">' . e(t('viewer.email_change.confirm_failed', 'The email change could not be confirmed. Request a new email change and try again.')) . '</div>';
    echo '<p><a class="button secondary" href="' . e((string) ($viewModel['restart_url'] ?? '')) . '">' . e(t('viewer.email_change.start_again', 'Start email change again')) . '</a></p></section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared account-deletion completion state. */
function view_render_viewer_account_deleted(array $viewModel): void
{
    render_header(t('viewer.delete.completed_title', 'Viewer account deleted'));
    echo '<section class="panel"><h1>' . e(t('viewer.delete.completed_title', 'Viewer account deleted')) . '</h1>';
    echo '<p>' . e(t('viewer.delete.completed', 'The viewer account and its viewer-owned data have been deleted. Gallery photographs were not deleted.')) . '</p>';
    echo '<p><a class="button" href="' . e((string) ($viewModel['home_url'] ?? '')) . '">' . e(t('viewer.delete.back_home', 'Back to gallery')) . '</a></p></section>';
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared account-deletion form state. */
function view_render_viewer_account_delete(array $viewModel): void
{
    render_header(t('viewer.delete.title', 'Delete viewer account'));
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.delete.title', 'Delete viewer account')) . '</h1>';
    echo '<p>' . e(t('viewer.delete.help', 'This permanently deletes your viewer account and viewer-owned data such as favourites. Gallery photographs are not deleted. This action cannot be undone.')) . '</p>';
    echo '<form method="post" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<label class="account-settings-toggle"><input type="checkbox" name="confirm_delete" value="1" required> <span><strong>' . e(t('viewer.delete.confirm_label', 'I understand that this permanently deletes my viewer account.')) . '</strong></span></label>';
    echo '<button type="submit" class="button secondary">' . e(t('viewer.delete.button', 'Delete viewer account')) . '</button></form>';
    echo '<p class="muted"><a href="' . e((string) ($viewModel['account_url'] ?? '')) . '">' . e(t('viewer.lifecycle.back_to_account', 'Back to account')) . '</a></p></section>';
    render_footer();
}
