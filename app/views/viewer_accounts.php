<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/viewer_accounts.php
 * Module Type: View
 *
 * Purpose:
 *   Renders viewer authentication, registration, and account presentation from controller-prepared data.
 *
 * Responsibilities:
 *   - Render viewer CSRF and anti-automation form fragments from prepared values
 *   - Render public viewer registration, invitation, login, password, and account pages
 *   - Keep viewer authentication policy, token validation, request handling, mail delivery, and persistence outside the view
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
 *   - Do not read request globals from this view.
 *   - Do not call models or viewer-domain services from this view.
 *   - Translation, escaping, routing, and shared layout renderers are presentation helpers.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Render the viewer-specific CSRF hidden field from a controller-issued token.
 */
function view_viewer_csrf_field(string $token): string
{
    return '<input type="hidden" name="viewer_csrf_token" value="' . e($token) . '">';
}

/**
 * Render first-party anti-automation hidden fields from controller-prepared state.
 *
 * @param array<string,mixed> $state Prepared ticket and honeypot state.
 */
function view_viewer_anti_automation_form_fields(array $state): string
{
    $ticket = (string) ($state['ticket'] ?? '');
    $honeypotField = (string) ($state['honeypot_field'] ?? '');
    if ($ticket === '' || $honeypotField === '') {
        return '';
    }

    return '<input type="hidden" name="viewer_aa_form_ticket" value="' . e($ticket) . '">'
        . '<div hidden aria-hidden="true">'
        . '<input type="text" name="' . e($honeypotField) . '" value="" tabindex="-1" autocomplete="off">'
        . '</div>';
}

/**
 * Render the bounded invalid viewer-request response.
 */
function view_render_viewer_invalid_request(): void
{
    render_header(t('viewer.common.invalid_request_title', 'Invalid request'));
    echo '<section class="panel"><h1>' . e(t('viewer.common.invalid_request_title', 'Invalid request')) . '</h1>';
    echo '<p>' . e(t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.')) . '</p></section>';
    render_footer();
}

/**
 * Render the local adaptive anti-automation challenge.
 *
 * @param array<string,mixed> $viewModel Controller-prepared challenge state and URLs.
 */
function view_render_viewer_anti_automation_challenge(array $viewModel): void
{
    render_header((string) ($viewModel['page_title'] ?? ''));
    echo '<section class="panel"><h1>' . e(t('viewer.automation.title', 'Additional verification')) . '</h1>';
    echo '<p>' . e(t('viewer.automation.message', 'This site is performing a local anti-automation check. No external service is used.')) . '</p>';
    echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" class="form-grid" data-viewer-anti-automation'
        . ' data-viewer-aa-action="' . e((string) ($viewModel['action'] ?? '')) . '"'
        . ' data-viewer-aa-challenge="' . e((string) ($viewModel['challenge'] ?? '')) . '"'
        . ' data-viewer-aa-difficulty="' . (int) ($viewModel['difficulty'] ?? 0) . '"'
        . ' data-viewer-aa-max-counter="' . (int) ($viewModel['max_counter'] ?? 0) . '"'
        . ' data-viewer-aa-ready="' . e(t('viewer.automation.ready', 'Local verification is ready. Continue to submit your request.')) . '"'
        . ' data-viewer-aa-failed="' . e(t('viewer.automation.failed', 'Automatic local verification is unavailable. Use the first-party fallback below.')) . '">';
    echo (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="email" value="' . e((string) ($viewModel['email'] ?? '')) . '">';
    echo '<input type="hidden" name="viewer_aa_challenge_ticket" value="' . e((string) ($viewModel['ticket'] ?? '')) . '">';
    echo '<input type="hidden" name="viewer_aa_pow_counter" value="" data-viewer-aa-counter>';
    echo '<p class="muted" id="viewer-aa-status" role="status" aria-live="polite" data-viewer-aa-status>' . e(t('viewer.automation.progress', 'Performing local verification...')) . '</p>';
    echo '<progress data-viewer-aa-progress max="100" value="0" aria-describedby="viewer-aa-status"></progress>';
    echo '<button type="submit" data-viewer-aa-continue disabled>' . e(t('viewer.automation.continue', 'Continue')) . '</button>';
    echo '<div hidden data-viewer-aa-fallback><p class="muted">' . e(t('viewer.automation.fallback_help', 'If the browser calculation is unavailable, use the first-party fallback.')) . '</p>';
    echo '<button type="submit" class="button secondary" name="viewer_aa_fallback" value="1">' . e(t('viewer.automation.fallback', 'Continue without browser calculation')) . '</button></div>';
    echo '<noscript><p class="muted">' . e(t('viewer.automation.fallback_help', 'If the browser calculation is unavailable, use the first-party fallback.')) . '</p>';
    echo '<button type="submit" class="button secondary" name="viewer_aa_fallback" value="1">' . e(t('viewer.automation.fallback', 'Continue without browser calculation')) . '</button></noscript>';
    echo '</form></section>';
    echo '<script src="' . e((string) ($viewModel['script_url'] ?? '')) . '"></script>';
    render_footer();
}

/**
 * Render a generic unavailable viewer response.
 */
function view_render_viewer_unavailable(): void
{
    render_header(t('viewer.common.unavailable_title', 'Viewer account unavailable'));
    echo '<section class="panel"><h1>' . e(t('viewer.common.unavailable_title', 'Viewer account unavailable')) . '</h1>';
    echo '<p>' . e(t('viewer.common.unavailable_message', 'This viewer account operation is unavailable.')) . '</p></section>';
    render_footer();
}

/**
 * Render the neutral verification-resend recovery link.
 */
function view_render_verification_resend_link(bool $available, string $url): void
{
    if (!$available) {
        return;
    }

    echo '<p class="muted">' . e(t('viewer.resend.prompt', "Didn't receive the verification email?")) . ' ';
    echo '<a href="' . e($url) . '">' . e(t('viewer.resend.link', 'Request another verification message')) . '</a></p>';
}

/**
 * Render an invalid/expired verification result.
 */
function view_render_verification_unavailable(array $viewModel): void
{
    render_header(t('viewer.verify.invalid_title', 'Verification link unavailable'));
    echo '<section class="panel"><h1>' . e(t('viewer.verify.invalid_title', 'Verification link unavailable')) . '</h1>';
    echo '<p>' . e(t('viewer.verify.invalid_message', 'This verification link is invalid, expired, or no longer usable. You can request another message if the staged registration is still eligible.')) . '</p>';
    view_render_verification_resend_link(!empty($viewModel['resend_available']), (string) ($viewModel['resend_url'] ?? ''));
    echo '<p><a class="button secondary" href="' . e((string) ($viewModel['login_url'] ?? '')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
    render_footer();
}

/**
 * Render the shared viewer password policy hint.
 */
function view_render_viewer_password_policy_hint(): void
{
    echo '<p class="muted">' . e(t('viewer.password.policy_hint', 'Use at least 15 characters.')) . '</p>';
}

/**
 * Render the open-registration completion or entry page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared registration presentation state.
 */
function view_render_viewer_register(array $viewModel): void
{
    render_header(t('viewer.register.title', 'Create viewer account'));
    echo '<section class="panel"><h1>' . e(t('viewer.register.title', 'Create viewer account')) . '</h1>';
    if (!empty($viewModel['complete'])) {
        echo '<div class="notice">' . e(t('viewer.register.request_received', 'If the registration request can be accepted, a verification message will be sent to the submitted email address.')) . '</div>';
        view_render_verification_resend_link(!empty($viewModel['resend_available']), (string) ($viewModel['resend_url'] ?? ''));
        echo '<p><a class="button secondary" href="' . e((string) ($viewModel['login_url'] ?? '')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
        render_footer();
        return;
    }

    $error = (string) ($viewModel['anti_automation_error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<p>' . e(t('viewer.register.help', 'Enter your email address. If registration can proceed, a verification message will be sent. No viewer account is created until you confirm the email and choose a password.')) . '</p>';
    echo '<form method="post" action="' . e((string) ($viewModel['register_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo (string) ($viewModel['anti_automation_html'] ?? '');
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="email"></label>';
    echo '<button type="submit">' . e(t('viewer.register.button', 'Send verification email')) . '</button></form>';
    view_render_verification_resend_link(!empty($viewModel['resend_available']), (string) ($viewModel['resend_url'] ?? ''));
    echo '<p class="muted"><a href="' . e((string) ($viewModel['login_url'] ?? '')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
    render_footer();
}

/**
 * Render the viewer invitation completion or email-entry page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared invitation presentation state.
 */
function view_render_viewer_invite(array $viewModel): void
{
    render_header(t('viewer.invite.title', 'Viewer invitation'));
    echo '<section class="panel"><h1>' . e(t('viewer.invite.title', 'Viewer invitation')) . '</h1>';
    if (!empty($viewModel['complete'])) {
        echo '<div class="notice">' . e((string) ($viewModel['notice'] ?? '')) . '</div>';
        view_render_verification_resend_link(!empty($viewModel['resend_available']), (string) ($viewModel['resend_url'] ?? ''));
        echo '<p><a class="button secondary" href="' . e((string) ($viewModel['login_url'] ?? '')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
        render_footer();
        return;
    }

    echo '<p>' . e(!empty($viewModel['email_bound'])
        ? t('viewer.invite.bound_help', 'Enter the email address this invitation was issued for. A verification email is required before any account can be created.')
        : t('viewer.invite.help', 'Enter the email address you want to verify for this viewer account. A verification email is required before any account can be created.')) . '</p>';
    echo '<form method="post" action="' . e((string) ($viewModel['invite_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="token" value="' . e((string) ($viewModel['token'] ?? '')) . '">';
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="email"></label>';
    echo '<button type="submit">' . e(t('viewer.invite.continue_button', 'Send verification email')) . '</button></form></section>';
    render_footer();
}

/**
 * Render the viewer login page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared login presentation state.
 */
function view_render_viewer_login(array $viewModel): void
{
    render_header(t('viewer.login.title', 'Viewer login'));
    foreach ((array) ($viewModel['notices'] ?? []) as $notice) {
        if (is_string($notice) && $notice !== '') {
            echo '<div class="notice">' . e($notice) . '</div>';
        }
    }
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.login.title', 'Viewer login')) . '</h1>';
    echo '<form method="post" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="username"></label>';
    echo '<label>' . e(t('viewer.common.password', 'Password')) . '<input type="password" name="password" required autocomplete="current-password"></label>';
    echo '<label class="account-settings-toggle account-settings-compact-toggle"><input type="checkbox" name="remember_me" value="1"> <span><strong>' . e(t('viewer.login.remember_me', 'Remember me')) . '</strong><small>' . e(t('viewer.login.remember_help', 'Uses a dedicated rotating viewer token. It does not create administrator authentication or recent reauthentication.')) . '</small></span></label>';
    echo '<button type="submit">' . e(t('viewer.login.button', 'Sign in')) . '</button></form>';
    echo '<p class="muted"><a href="' . e((string) ($viewModel['forgot_url'] ?? '')) . '">' . e(t('viewer.login.forgot_password', 'Forgot password?')) . '</a></p>';
    view_render_verification_resend_link(!empty($viewModel['resend_available']), (string) ($viewModel['resend_url'] ?? ''));
    if (!empty($viewModel['registration_available'])) {
        echo '<p class="muted"><a href="' . e((string) ($viewModel['register_url'] ?? '')) . '">' . e(t('viewer.login.register_link', 'Create viewer account')) . '</a></p>';
    }
    echo '</section>';
    render_footer();
}

/**
 * Render the forced first-login password replacement page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared first-login presentation state.
 */
function view_render_viewer_first_login_password(array $viewModel): void
{
    render_header(t('viewer.first_login.title', 'Choose a new viewer password'));
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.first_login.title', 'Choose a new viewer password')) . '</h1>';
    echo '<p>' . e(t('viewer.first_login.help', 'Your administrator-created account is using a temporary password. Choose a new password before the normal viewer session can start.')) . '</p>';
    echo '<p class="muted">' . e(t('viewer.first_login.signed_in_as', 'Account: {email}', ['email' => (string) ($viewModel['email'] ?? '')])) . '</p>';
    echo '<form method="post" action="' . e((string) ($viewModel['password_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<label>' . e(t('viewer.first_login.new_password', 'New password')) . '<input type="password" name="password" required minlength="15" autocomplete="new-password"></label>';
    echo '<label>' . e(t('viewer.first_login.confirm_password', 'Confirm new password')) . '<input type="password" name="password_confirmation" required minlength="15" autocomplete="new-password"></label>';
    view_render_viewer_password_policy_hint();
    echo '<button type="submit">' . e(t('viewer.first_login.save_button', 'Save password and continue')) . '</button></form>';
    echo '<form method="post" action="' . e((string) ($viewModel['logout_url'] ?? '')) . '">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<button type="submit" class="button secondary">' . e(t('viewer.first_login.cancel_button', 'Cancel sign-in')) . '</button></form>';
    echo '</section>';
    render_footer();
}

/**
 * Render the viewer forgot-password page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared forgot-password presentation state.
 */
function view_render_viewer_forgot_password(array $viewModel): void
{
    render_header(t('viewer.forgot.title', 'Reset viewer password'));
    $notice = (string) ($viewModel['notice'] ?? '');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.forgot.title', 'Reset viewer password')) . '</h1>';
    echo '<p class="muted">' . e(t('viewer.forgot.help', 'Enter the verified viewer email address. The response is the same whether or not an eligible account exists.')) . '</p>';
    echo '<form method="post" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="email"></label>';
    echo '<button type="submit">' . e(t('viewer.forgot.button', 'Request reset link')) . '</button></form>';
    echo '<p class="muted"><a href="' . e((string) ($viewModel['login_url'] ?? '')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
    render_footer();
}

/**
 * Render the private viewer account landing page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared viewer identity and navigation URLs.
 */
function view_render_viewer_account(array $viewModel): void
{
    render_header(t('viewer.account.title', 'Viewer account'));
    if (!empty($viewModel['password_initialized'])) {
        echo '<div class="notice">' . e(t('viewer.first_login.completed', 'Temporary password replaced. Your normal viewer session is now active.')) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.account.title', 'Viewer account')) . '</h1>';
    echo '<p>' . e(t('viewer.account.signed_in_as', 'Signed in as {email}', ['email' => (string) ($viewModel['email'] ?? '')])) . '</p>';
    echo '<div class="button-row">';
    echo '<a class="button" href="' . e((string) ($viewModel['favourites_url'] ?? '')) . '">' . e(t('viewer.favourites.open', 'Open favourites')) . '</a>';
    echo '<a class="button" href="' . e((string) ($viewModel['collections_url'] ?? '')) . '">' . e(t('viewer.collections.open', 'Open collections')) . '</a>';
    echo '<a class="button secondary" href="' . e((string) ($viewModel['password_url'] ?? '')) . '">' . e(t('viewer.account.change_password', 'Change password')) . '</a>';
    echo '<a class="button secondary" href="' . e((string) ($viewModel['email_url'] ?? '')) . '">' . e(t('viewer.account.change_email', 'Change email')) . '</a>';
    echo '<a class="button secondary" href="' . e((string) ($viewModel['delete_url'] ?? '')) . '">' . e(t('viewer.account.delete', 'Delete account')) . '</a>';
    echo '</div>';
    echo '<form method="post" action="' . e((string) ($viewModel['logout_url'] ?? '')) . '">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<button type="submit" class="button secondary">' . e(t('viewer.account.logout_button', 'Log out')) . '</button></form>';
    echo '<p class="muted">' . e(t('viewer.account.phase_note', 'Collection sharing and public viewer profiles are not available.')) . '</p></section>';
    render_footer();
}

/**
 * Render the verification-resend page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared resend presentation state.
 */
function view_render_viewer_resend_verification(array $viewModel): void
{
    render_header(t('viewer.resend.title', 'Request another verification message'));
    echo '<section class="panel"><h1>' . e(t('viewer.resend.title', 'Request another verification message')) . '</h1>';
    if (!empty($viewModel['submitted'])) {
        echo '<div class="notice">' . e(t('viewer.resend.request_received', 'If a verification message can be sent for this address, it will be sent.')) . '</div>';
        echo '<p><a class="button secondary" href="' . e((string) ($viewModel['login_url'] ?? '')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
        render_footer();
        return;
    }

    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<p>' . e(t('viewer.resend.help', 'Enter the email address used for the staged viewer registration. The response does not reveal whether a matching registration exists.')) . '</p>';
    echo '<form method="post" action="' . e((string) ($viewModel['resend_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo (string) ($viewModel['anti_automation_html'] ?? '');
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="email"></label>';
    echo '<button type="submit">' . e(t('viewer.resend.button', 'Request verification message')) . '</button></form>';
    echo '<p class="muted"><a href="' . e((string) ($viewModel['login_url'] ?? '')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
    render_footer();
}

/**
 * Render the viewer verification password-selection form.
 *
 * @param array<string,mixed> $viewModel Controller-prepared password presentation state.
 */
function view_render_viewer_verification_password(array $viewModel): void
{
    render_header(t('viewer.verify.choose_password_title', 'Choose viewer password'));
    echo '<section class="panel"><h1>' . e(t('viewer.verify.choose_password_title', 'Choose viewer password')) . '</h1>';
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    } elseif (!empty($viewModel['show_help'])) {
        echo '<p>' . e(t('viewer.verify.password_help', 'Email ownership is verified. Choose the password that will protect this viewer account.')) . '</p>';
    }
    echo '<form method="post" action="' . e((string) ($viewModel['verify_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="viewer_verify_action" value="activate">';
    echo '<label>' . e(t('viewer.common.password', 'Password')) . '<input type="password" name="password" required minlength="15" autocomplete="new-password"></label>';
    echo '<label>' . e(t('viewer.common.confirm_password', 'Confirm password')) . '<input type="password" name="password_confirmation" required minlength="15" autocomplete="new-password"></label>';
    view_render_viewer_password_policy_hint();
    echo '<button type="submit">' . e(t('viewer.verify.activate_button', 'Activate viewer account')) . '</button></form></section>';
    render_footer();
}

/**
 * Render the scanner-safe verification confirmation page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared confirmation state.
 */
function view_render_viewer_verification_confirm(array $viewModel): void
{
    render_header(t('viewer.verify.title', 'Verify viewer account'));
    echo '<section class="panel"><h1>' . e(t('viewer.verify.title', 'Verify viewer account')) . '</h1>';
    echo '<p>' . e(t('viewer.verify.confirm_help', 'Confirm this email address for the viewer account. This GET request has not consumed the verification token or activated an account.')) . '</p>';
    echo '<form method="post" action="' . e((string) ($viewModel['verify_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="viewer_verify_action" value="authorize"><input type="hidden" name="token" value="' . e((string) ($viewModel['token'] ?? '')) . '">';
    echo '<button type="submit">' . e(t('viewer.verify.confirm_button', 'Confirm email and continue')) . '</button></form></section>';
    render_footer();
}

/**
 * Render the reset-password password-selection form.
 *
 * @param array<string,mixed> $viewModel Controller-prepared reset presentation state.
 */
function view_render_viewer_reset_password_form(array $viewModel): void
{
    render_header(t('viewer.reset.choose_password_title', 'Choose a new viewer password'));
    echo '<section class="panel"><h1>' . e(t('viewer.reset.choose_password_title', 'Choose a new viewer password')) . '</h1>';
    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<form method="post" action="' . e((string) ($viewModel['reset_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="viewer_reset_action" value="complete">';
    echo '<label>' . e(t('viewer.common.password', 'Password')) . '<input type="password" name="password" required minlength="15" autocomplete="new-password"></label>';
    echo '<label>' . e(t('viewer.common.confirm_password', 'Confirm password')) . '<input type="password" name="password_confirmation" required minlength="15" autocomplete="new-password"></label>';
    view_render_viewer_password_policy_hint();
    echo '<button type="submit">' . e(t('viewer.reset.save_button', 'Save new password')) . '</button></form></section>';
    render_footer();
}

/**
 * Render the scanner-safe reset authorization page.
 *
 * @param array<string,mixed> $viewModel Controller-prepared reset authorization state.
 */
function view_render_viewer_reset_authorize(array $viewModel): void
{
    render_header(t('viewer.reset.title', 'Viewer password reset'));
    echo '<section class="panel"><h1>' . e(t('viewer.reset.title', 'Viewer password reset')) . '</h1>';
    echo '<p>' . e(t('viewer.reset.confirm_help', 'Continue to authorize this browser to choose a new viewer password. This GET request has not consumed the reset token.')) . '</p>';
    echo '<form method="post" action="' . e((string) ($viewModel['reset_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="viewer_reset_action" value="authorize"><input type="hidden" name="token" value="' . e((string) ($viewModel['token'] ?? '')) . '">';
    echo '<button type="submit">' . e(t('viewer.reset.continue_button', 'Continue password reset')) . '</button></form></section>';
    render_footer();
}


/**
 * Return one localized Viewer security capability status label.
 */
function view_viewer_security_status_label(string $status): string
{
    return match ($status) {
        'available' => t('viewer.admin.security.available', 'Available'),
        'unavailable' => t('viewer.admin.security.unavailable', 'Unavailable'),
        default => t('viewer.admin.security.unknown', 'Unknown'),
    };
}

/**
 * Return one localized enabled/disabled label for Viewer security presentation.
 */
function view_viewer_security_enabled_label(bool $enabled): string
{
    return $enabled
        ? t('viewer.admin.security.enabled', 'Enabled')
        : t('viewer.admin.security.disabled', 'Disabled');
}

/**
 * Return the localized Viewer registration-mode label.
 */
function view_viewer_security_mode_label(string $mode): string
{
    return match ($mode) {
        'open' => t('viewer.admin.invites.mode_open_label', 'Open registration'),
        'invite_only' => t('viewer.admin.invites.mode_invite_only_label', 'Invite only'),
        default => t('viewer.admin.invites.mode_disabled_label', 'Disabled'),
    };
}

/**
 * Return one localized Viewer rate-limit bucket label.
 */
function view_viewer_security_bucket_label(string $bucket): string
{
    return match ($bucket) {
        'viewer_register_ip' => t('viewer.admin.security.bucket_register_ip', 'Registration IP'),
        'viewer_register_subnet' => t('viewer.admin.security.bucket_register_subnet', 'Registration subnet'),
        'viewer_register_identifier' => t('viewer.admin.security.bucket_register_identifier', 'Registration identifier'),
        'viewer_register_global_day' => t('viewer.admin.security.bucket_register_global_day', 'Registration global daily'),
        'viewer_resend_verification_identifier' => t('viewer.admin.security.bucket_resend_identifier', 'Verification resend identifier'),
        'viewer_automation_ip' => t('viewer.admin.security.bucket_automation_ip', 'Anti-automation IP'),
        'viewer_automation_subnet' => t('viewer.admin.security.bucket_automation_subnet', 'Anti-automation subnet'),
        'viewer_verify_mail_email_cooldown' => t('viewer.admin.security.bucket_mail_email_cooldown', 'Verification mail email cooldown'),
        'viewer_verify_mail_email_hour' => t('viewer.admin.security.bucket_mail_email_hour', 'Verification mail email hourly'),
        'viewer_verify_mail_email_day' => t('viewer.admin.security.bucket_mail_email_day', 'Verification mail email daily'),
        'viewer_verify_mail_ip_hour' => t('viewer.admin.security.bucket_mail_ip_hour', 'Verification mail IP hourly'),
        'viewer_verify_mail_ip_day' => t('viewer.admin.security.bucket_mail_ip_day', 'Verification mail IP daily'),
        'viewer_verify_mail_subnet_hour' => t('viewer.admin.security.bucket_mail_subnet_hour', 'Verification mail subnet hourly'),
        'viewer_verify_mail_subnet_day' => t('viewer.admin.security.bucket_mail_subnet_day', 'Verification mail subnet daily'),
        'viewer_verify_mail_global_day' => t('viewer.admin.security.bucket_mail_global_day', 'Verification mail global daily'),
        default => t('viewer.admin.security.bucket_unknown', 'Viewer security limiter'),
    };
}

/**
 * Render the privacy-safe read-only Viewer security operations summary.
 *
 * @param array<string,mixed> $operations Controller-provided aggregate security snapshot.
 */
function view_render_admin_viewer_security_operations(array $operations): void
{
    $status = is_array($operations['status'] ?? null) ? $operations['status'] : [];
    $antiAutomation = is_array($status['anti_automation'] ?? null) ? $status['anti_automation'] : [];
    $storage = is_array($status['storage'] ?? null) ? $status['storage'] : [];
    $capacity = is_array($operations['capacity'] ?? null) ? $operations['capacity'] : [];
    $accountCapacity = is_array($capacity['accounts'] ?? null) ? $capacity['accounts'] : [];
    $registrationCapacity = is_array($capacity['registrations'] ?? null) ? $capacity['registrations'] : [];
    $events = is_array($operations['events'] ?? null) ? $operations['events'] : [];
    $eventStatus = (string) ($events['status'] ?? 'unknown');
    $last24 = is_array($events['last_24_hours'] ?? null) ? $events['last_24_hours'] : [];
    $last7 = is_array($events['last_7_days'] ?? null) ? $events['last_7_days'] : [];
    $trend = is_array($events['trend'] ?? null) ? $events['trend'] : [];
    $rateLimits = is_array($operations['rate_limits'] ?? null) ? $operations['rate_limits'] : [];
    $rateStatus = (string) ($rateLimits['status'] ?? 'unknown');
    $rateGroups = is_array($rateLimits['groups'] ?? null) ? $rateLimits['groups'] : [];
    $rateBuckets = is_array($rateLimits['buckets'] ?? null) ? $rateLimits['buckets'] : [];
    $globalBudgets = is_array($rateLimits['global_budgets'] ?? null) ? $rateLimits['global_budgets'] : [];

    $metricLabels = [
        'accepted_registrations' => t('viewer.admin.security.accepted_registrations', 'Accepted registration requests'),
        'verification_messages_sent' => t('viewer.admin.security.verification_messages_sent', 'Verification messages sent'),
        'verification_resend_requests' => t('viewer.admin.security.verification_resend_requests', 'Verification resend requests'),
        'verification_resend_messages_sent' => t('viewer.admin.security.verification_resend_messages_sent', 'Verification resend messages sent'),
        'verification_resend_suppressed' => t('viewer.admin.security.verification_resend_suppressed', 'Verification resend requests suppressed'),
        'automation_challenges_required' => t('viewer.admin.security.automation_challenges_required', 'Additional verification required'),
        'automation_challenges_passed' => t('viewer.admin.security.automation_challenges_passed', 'Additional verification passed'),
        'automation_challenges_failed' => t('viewer.admin.security.automation_challenges_failed', 'Additional verification failed'),
        'automation_requests_suppressed' => t('viewer.admin.security.automation_requests_suppressed', 'Requests suppressed by anti-automation'),
    ];

    echo '<section class="panel"><h2>' . e(t('viewer.admin.security.title', 'Viewer security status')) . '</h2>';
    echo '<p class="muted">' . e(t('viewer.admin.security.help', 'Read-only aggregate registration security and capacity status. No visitor identifiers or security-event context are displayed.')) . '</p>';
    echo '<div class="table-wrap"><table><tbody>';
    echo '<tr><th>' . e(t('viewer.admin.security.master_feature', 'Viewer Accounts master feature')) . '</th><td>' . e(view_viewer_security_enabled_label(!empty($status['master_feature_enabled']))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.registration_mode', 'Effective registration mode')) . '</th><td>' . e(view_viewer_security_mode_label((string) ($status['registration_mode'] ?? 'disabled'))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.open_http', 'Open registration HTTP')) . '</th><td>' . e(view_viewer_security_status_label(!empty($status['open_registration_http_available']) ? 'available' : 'unavailable')) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.resend_http', 'Verification resend HTTP')) . '</th><td>' . e(view_viewer_security_status_label(!empty($status['verification_resend_http_available']) ? 'available' : 'unavailable')) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.anti_automation', 'First-party anti-automation')) . '</th><td>' . e(view_viewer_security_enabled_label(!empty($antiAutomation['enabled']))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.pow_difficulty', 'Local proof difficulty')) . '</th><td>' . e((string) ($antiAutomation['pow_min_bits'] ?? 0) . '-' . (string) ($antiAutomation['pow_max_bits'] ?? 0) . ' ' . t('viewer.admin.security.bits', 'bits')) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.form_lifetime', 'Form ticket lifetime')) . '</th><td>' . e(t('viewer.admin.security.seconds_value', '{count} seconds', ['count' => (string) ($antiAutomation['form_lifetime_seconds'] ?? 0)])) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.auth_storage', 'Viewer auth storage')) . '</th><td>' . e(view_viewer_security_status_label((string) ($storage['viewer_auth'] ?? 'unknown'))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.registration_storage', 'Viewer registration storage')) . '</th><td>' . e(view_viewer_security_status_label((string) ($storage['viewer_registration'] ?? 'unknown'))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.event_storage', 'Viewer security-event storage')) . '</th><td>' . e(view_viewer_security_status_label((string) ($storage['viewer_security_events'] ?? 'unknown'))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.rate_storage', 'Viewer rate-limit storage')) . '</th><td>' . e(view_viewer_security_status_label((string) ($storage['viewer_rate_limits'] ?? 'unknown'))) . '</td></tr>';
    echo '</tbody></table></div>';

    echo '<h3>' . e(t('viewer.admin.security.capacity', 'Capacity')) . '</h3>';
    echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('viewer.admin.security.resource', 'Resource')) . '</th><th>' . e(t('viewer.admin.security.current', 'Current')) . '</th><th>' . e(t('viewer.admin.security.hard_cap', 'Hard cap')) . '</th></tr></thead><tbody>';
    $accountCount = $accountCapacity['current_count'] ?? null;
    $accountCurrent = $accountCount === null
        ? view_viewer_security_status_label((string) ($accountCapacity['status'] ?? 'unknown'))
        : (string) (int) $accountCount;
    $registrationCount = $registrationCapacity['current_count'] ?? null;
    $registrationCurrent = $registrationCount === null
        ? view_viewer_security_status_label((string) ($registrationCapacity['status'] ?? 'unknown'))
        : (string) (int) $registrationCount;
    echo '<tr><td>' . e(t('viewer.admin.security.viewer_accounts', 'Viewer accounts')) . '</td><td>' . e($accountCurrent) . '</td><td>' . e((string) ($accountCapacity['hard_cap'] ?? '')) . '</td></tr>';
    echo '<tr><td>' . e(t('viewer.admin.security.staged_registrations', 'Staged registration requests')) . '</td><td>' . e($registrationCurrent) . '</td><td>' . e((string) ($registrationCapacity['hard_cap'] ?? '')) . '</td></tr>';
    if ($registrationCount !== null) {
        echo '<tr><td>' . e(t('viewer.admin.security.open_origin_staging', 'Open-origin staging')) . '</td><td>' . e((string) (int) ($registrationCapacity['open_origin_count'] ?? 0)) . '</td><td>-</td></tr>';
        echo '<tr><td>' . e(t('viewer.admin.security.invitation_staging', 'Invitation-backed staging')) . '</td><td>' . e((string) (int) ($registrationCapacity['invitation_backed_count'] ?? 0)) . '</td><td>-</td></tr>';
    }
    echo '</tbody></table></div>';

    foreach ([
        ['title' => t('viewer.admin.security.last_24_hours', 'Last 24 hours'), 'values' => $last24],
        ['title' => t('viewer.admin.security.last_7_days', 'Last 7 days'), 'values' => $last7],
    ] as $window) {
        echo '<h3>' . e((string) $window['title']) . '</h3>';
        if ($eventStatus !== 'available') {
            echo '<p class="muted">' . e(view_viewer_security_status_label($eventStatus)) . '</p>';
            continue;
        }
        echo '<div class="table-wrap"><table><tbody>';
        foreach ($metricLabels as $metricKey => $label) {
            echo '<tr><th>' . e($label) . '</th><td>' . e((string) (int) (($window['values'][$metricKey] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '<h3>' . e(t('viewer.admin.security.trend_title', '7-day trend')) . '</h3>';
    echo '<p class="muted">' . e(t('viewer.admin.security.trend_help', 'Anti-automation interventions equal challenge-required events plus request-suppressed events.')) . '</p>';
    if ($eventStatus !== 'available') {
        echo '<p class="muted">' . e(view_viewer_security_status_label($eventStatus)) . '</p>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr>';
        echo '<th>' . e(t('viewer.admin.security.date', 'Date')) . '</th>';
        echo '<th>' . e(t('viewer.admin.security.accepted_registrations', 'Accepted registration requests')) . '</th>';
        echo '<th>' . e(t('viewer.admin.security.verification_messages_sent', 'Verification messages sent')) . '</th>';
        echo '<th>' . e(t('viewer.admin.security.verification_resend_messages_sent', 'Verification resend messages sent')) . '</th>';
        echo '<th>' . e(t('viewer.admin.security.anti_automation_interventions', 'Anti-automation interventions')) . '</th></tr></thead><tbody>';
        foreach ($trend as $day) {
            if (!is_array($day)) {
                continue;
            }
            echo '<tr><td>' . e((string) ($day['date'] ?? '')) . '</td>';
            echo '<td>' . e((string) (int) ($day['accepted_registrations'] ?? 0)) . '</td>';
            echo '<td>' . e((string) (int) ($day['verification_messages_sent'] ?? 0)) . '</td>';
            echo '<td>' . e((string) (int) ($day['verification_resend_messages_sent'] ?? 0)) . '</td>';
            echo '<td>' . e((string) (int) ($day['anti_automation_interventions'] ?? 0)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '<h3>' . e(t('viewer.admin.security.rate_pressure', 'Rate-limit pressure')) . '</h3>';
    if ($rateStatus !== 'available') {
        echo '<p class="muted">' . e(view_viewer_security_status_label($rateStatus)) . '</p>';
    } else {
        foreach ($rateGroups as $groupKey => $groupBuckets) {
            if (!is_array($groupBuckets)) {
                continue;
            }
            $groupLabel = match ((string) $groupKey) {
                'registration' => t('viewer.admin.security.rate_group_registration', 'Registration'),
                'verification_resend' => t('viewer.admin.security.rate_group_resend', 'Verification resend'),
                'anti_automation' => t('viewer.admin.security.rate_group_automation', 'Anti-automation'),
                'verification_mail' => t('viewer.admin.security.rate_group_mail', 'Verification mail'),
                default => t('viewer.admin.security.rate_pressure', 'Rate-limit pressure'),
            };
            echo '<h4>' . e($groupLabel) . '</h4><div class="table-wrap"><table><thead><tr>';
            echo '<th>' . e(t('viewer.admin.security.limiter', 'Limiter')) . '</th>';
            echo '<th>' . e(t('viewer.admin.security.limit', 'Limit')) . '</th>';
            echo '<th>' . e(t('viewer.admin.security.window', 'Window')) . '</th>';
            echo '<th>' . e(t('viewer.admin.security.active_subjects', 'Active subjects')) . '</th>';
            echo '<th>' . e(t('viewer.admin.security.locked_subjects', 'Locked subjects')) . '</th></tr></thead><tbody>';
            foreach ($groupBuckets as $bucket) {
                $bucket = (string) $bucket;
                $bucketData = is_array($rateBuckets[$bucket] ?? null) ? $rateBuckets[$bucket] : [];
                echo '<tr><td>' . e(view_viewer_security_bucket_label($bucket)) . '</td>';
                echo '<td>' . e((string) ($bucketData['max_attempts'] ?? '')) . '</td>';
                echo '<td>' . e(t('viewer.admin.security.seconds_value', '{count} seconds', ['count' => (string) ($bucketData['window_seconds'] ?? '')])) . '</td>';
                echo '<td>' . e((string) (int) ($bucketData['active_subjects'] ?? 0)) . '</td>';
                echo '<td>' . e((string) (int) ($bucketData['locked_subjects'] ?? 0)) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    echo '<h3>' . e(t('viewer.admin.security.global_budget', 'Global budget')) . '</h3>';
    if ($rateStatus !== 'available') {
        echo '<p class="muted">' . e(view_viewer_security_status_label($rateStatus)) . '</p>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('viewer.admin.security.resource', 'Resource')) . '</th><th>' . e(t('viewer.admin.security.usage', 'Current usage')) . '</th><th>' . e(t('viewer.admin.security.locked_subjects', 'Locked subjects')) . '</th></tr></thead><tbody>';
        foreach ([
            'viewer_register_global_day' => t('viewer.admin.security.registration_global_budget', 'Open registration global budget'),
            'viewer_verify_mail_global_day' => t('viewer.admin.security.verification_mail_global_budget', 'Verification mail global budget'),
        ] as $bucket => $label) {
            $budget = is_array($globalBudgets[$bucket] ?? null) ? $globalBudgets[$bucket] : null;
            if ($budget === null) {
                echo '<tr><td>' . e($label) . '</td><td>' . e(t('viewer.admin.security.unavailable', 'Unavailable')) . '</td><td>-</td></tr>';
                continue;
            }
            echo '<tr><td>' . e($label) . '</td><td>' . e((string) ($budget['current_attempts'] ?? 0) . ' / ' . (string) ($budget['limit'] ?? 0)) . '</td><td>' . e((string) (int) ($budget['locked_subjects'] ?? 0)) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}

/**
 * Render administrator Viewer Accounts and invitation management from controller-prepared state.
 *
 * @param array<string,mixed> $viewModel Controller-prepared account, invitation, policy, and security data.
 */
function view_render_admin_viewer_accounts(array $viewModel): void
{
    $notice = (string) ($viewModel['notice'] ?? '');
    $error = (string) ($viewModel['error'] ?? '');
    $accountShowOnce = $viewModel['account_show_once'] ?? null;
    $showOnce = $viewModel['invitation_show_once'] ?? null;
    $viewerAccounts = is_array($viewModel['viewer_accounts'] ?? null) ? $viewModel['viewer_accounts'] : [];
    $invitations = is_array($viewModel['invitations'] ?? null) ? $viewModel['invitations'] : [];
    $viewerSecurityOperations = is_array($viewModel['security_operations'] ?? null) ? $viewModel['security_operations'] : [];
    $registrationMode = (string) ($viewModel['registration_mode'] ?? 'disabled');
    $registrationRequestsEnabled = !empty($viewModel['registration_requests_enabled']);
    $accountStorageAvailable = !empty($viewModel['account_storage_available']);
    $accountCap = (int) ($viewModel['account_cap'] ?? 0);
    $csrfHtml = (string) ($viewModel['csrf_html'] ?? '');

    render_header(t('viewer.admin.accounts.title', 'Viewer accounts'));
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    if (is_array($accountShowOnce) && trim((string) ($accountShowOnce['temporary_password'] ?? '')) !== '') {
        echo '<section class="panel"><h1>' . e(t('viewer.admin.accounts.temporary_password_title', 'Temporary password')) . '</h1>';
        echo '<p>' . e(t('viewer.admin.accounts.temporary_password_show_once', 'Copy this temporary password now and send it to the viewer through a separate trusted channel. It is shown only on this page load and is never included in the notification email.')) . '</p>';
        echo '<p><strong>' . e((string) ($accountShowOnce['email'] ?? '')) . '</strong></p>';
        echo '<label>' . e(t('viewer.admin.accounts.temporary_password', 'Temporary password')) . '<input type="text" readonly value="' . e((string) $accountShowOnce['temporary_password']) . '" onclick="this.select()"></label>';
        echo '</section>';
    }
    if (is_array($showOnce) && trim((string) ($showOnce['url'] ?? '')) !== '') {
        echo '<section class="panel"><h1>' . e(t('viewer.admin.invites.created_title', 'Invitation created')) . '</h1>';
        echo '<p>' . e(t('viewer.admin.invites.show_once_help', 'Copy this invitation link now. The secret is shown only on this page load.')) . '</p>';
        echo '<label>' . e(t('viewer.admin.invites.invitation_link', 'Invitation link')) . '<input type="text" readonly value="' . e((string) $showOnce['url']) . '" onclick="this.select()"></label>';
        echo '<p class="muted">' . e(t('viewer.admin.invites.expires_at', 'Expires: {expires_at}', ['expires_at' => (string) ($showOnce['expires_at'] ?? '')])) . '</p></section>';
    }

    echo '<section class="panel"><h1>' . e(t('viewer.admin.invites.mode_title', 'Viewer accounts')) . '</h1>';
    echo '<p>' . e(t('viewer.admin.invites.mode_help', 'Choose how new viewer registrations are admitted. The global Viewer Accounts switch in Admin Features remains the outer master switch.')) . '</p>';
    echo '<form method="post" class="form-grid">' . $csrfHtml;
    echo '<input type="hidden" name="viewer_invitation_action" value="set_mode">';
    echo '<label>' . e(t('viewer.admin.invites.mode_selector_label', 'Registration mode')) . '<select name="viewer_accounts_mode" required>';
    echo '<option value="disabled"' . ($registrationMode === 'disabled' ? ' selected' : '') . '>' . e(t('viewer.admin.invites.mode_disabled_label', 'Disabled')) . '</option>';
    echo '<option value="invite_only"' . ($registrationMode === 'invite_only' ? ' selected' : '') . '>' . e(t('viewer.admin.invites.mode_invite_only_label', 'Invite only')) . '</option>';
    echo '<option value="open"' . ($registrationMode === 'open' ? ' selected' : '') . '>' . e(t('viewer.admin.invites.mode_open_label', 'Open registration')) . '</option>';
    echo '</select></label>';
    echo '<button type="submit">' . e(t('viewer.admin.invites.mode_save_button', 'Save viewer account mode')) . '</button></form>';
    echo '<p class="muted"><strong>' . e(t('viewer.admin.invites.mode_disabled_label', 'Disabled')) . ':</strong> ' . e(t('viewer.admin.invites.mode_disabled_help', 'Viewer frontend is unavailable according to the existing viewer-account feature semantics.')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('viewer.admin.invites.mode_invite_only_label', 'Invite only')) . ':</strong> ' . e(t('viewer.admin.invites.mode_invite_only_help', 'Viewer login is available, but new self-registration requires an administrator invitation.')) . '</p>';
    echo '<p class="muted"><strong>' . e(t('viewer.admin.invites.mode_open_label', 'Open registration')) . ':</strong> ' . e(t('viewer.admin.invites.mode_open_help', 'Viewer login is available and anonymous visitors may request verified-email registration. Administrator invitation links remain valid.')) . '</p>';
    echo '</section>';

    if ($viewerSecurityOperations !== []) {
        view_render_admin_viewer_security_operations($viewerSecurityOperations);
    } else {
        echo '<section class="panel"><h2>' . e(t('viewer.admin.security.title', 'Viewer security status')) . '</h2>';
        echo '<p class="muted">' . e(t('viewer.admin.security.operations_unavailable', 'Viewer security operations are currently unavailable. Viewer registration and authentication policy are unchanged.')) . '</p></section>';
    }

    echo '<section class="panel"><h1>' . e(t('viewer.admin.accounts.add_title', 'Add viewer account')) . '</h1>';
    echo '<p class="muted">' . e(t('viewer.admin.accounts.add_help', 'Create a verified viewer account immediately. The temporary password cannot establish normal viewer access until the user replaces it after the first successful sign-in. This works even while the viewer frontend is disabled.')) . '</p>';
    if ($accountStorageAvailable) {
        echo '<form method="post" class="form-grid">' . $csrfHtml;
        echo '<input type="hidden" name="viewer_invitation_action" value="create_account">';
        echo '<label>' . e(t('viewer.admin.accounts.email', 'Viewer email')) . '<input type="email" name="viewer_account_email" required autocomplete="off"></label>';
        echo '<label>' . e(t('viewer.admin.accounts.temporary_password', 'Temporary password')) . '<input type="password" name="viewer_account_temporary_password" minlength="15" autocomplete="new-password"></label>';
        echo '<p class="muted">' . e(t('viewer.admin.accounts.temporary_password_help', 'Leave blank to generate a high-entropy temporary password. The result is shown once after creation.')) . '</p>';
        echo '<label class="checkbox-label"><input type="checkbox" name="viewer_account_send_notification" value="1" checked> ' . e(t('viewer.admin.accounts.send_notification', 'Send the user an account-created notification (temporary password is never emailed)')) . '</label>';
        view_render_viewer_password_policy_hint();
        echo '<button type="submit">' . e(t('viewer.admin.accounts.add_button', 'Add viewer account')) . '</button></form>';
        echo '<p class="muted">' . e(t('viewer.admin.accounts.capacity_hint', 'Viewer accounts are capped at {count}; capacity is locked and rechecked during direct creation.', ['count' => (string) $accountCap])) . '</p>';
    } else {
        echo '<p class="muted">' . e(t('viewer.admin.accounts.storage_unavailable', 'Viewer account management storage is unavailable.')) . '</p>';
    }
    echo '</section>';

    echo '<section class="panel"><h2>' . e(t('viewer.admin.accounts.list_title', 'Existing viewer accounts')) . '</h2>';
    if ($viewerAccounts === []) {
        echo '<p class="muted">' . e(t('viewer.admin.accounts.none', 'No viewer accounts are available.')) . '</p>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr>';
        echo '<th>' . e(t('viewer.admin.accounts.email', 'Viewer email')) . '</th>';
        echo '<th>' . e(t('viewer.admin.accounts.status', 'Status')) . '</th>';
        echo '<th>' . e(t('viewer.admin.accounts.password_state', 'Password')) . '</th>';
        echo '<th>' . e(t('viewer.admin.accounts.created_at', 'Created')) . '</th>';
        echo '<th>' . e(t('viewer.admin.accounts.last_login', 'Last login')) . '</th>';
        echo '<th>' . e(t('viewer.admin.accounts.actions', 'Actions')) . '</th></tr></thead><tbody>';
        foreach ($viewerAccounts as $account) {
            $viewerAccountId = (int) ($account['id'] ?? 0);
            echo '<tr><td>' . e((string) ($account['email'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($account['status_label'] ?? '')) . '</td>';
            echo '<td>' . e(!empty($account['must_change_password'])
                ? t('viewer.admin.accounts.password_change_required', 'Change required on first login')
                : t('viewer.admin.accounts.password_set', 'Set')) . '</td>';
            echo '<td>' . e((string) ($account['created_at'] ?? '')) . '</td>';
            $lastLogin = trim((string) ($account['last_login_at'] ?? ''));
            echo '<td>' . e($lastLogin !== '' ? $lastLogin : t('viewer.admin.accounts.never', 'Never')) . '</td><td>';
            if (!empty($account['can_suspend'])) {
                echo '<form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.admin.accounts.suspend_confirm', 'Suspend this viewer account? Existing viewer sessions and persistent login authority will be revoked.')) . '">' . $csrfHtml;
                echo '<input type="hidden" name="viewer_invitation_action" value="suspend_account"><input type="hidden" name="viewer_account_id" value="' . $viewerAccountId . '">';
                echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.accounts.suspend_button', 'Suspend')) . '</button></form> ';
                echo '<form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.admin.accounts.sign_out_everywhere_confirm', 'Sign this viewer out on all devices? The account will remain active.')) . '">' . $csrfHtml;
                echo '<input type="hidden" name="viewer_invitation_action" value="revoke_sessions"><input type="hidden" name="viewer_account_id" value="' . $viewerAccountId . '">';
                echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.accounts.sign_out_everywhere_button', 'Sign out everywhere')) . '</button></form> ';
            } elseif (!empty($account['can_restore'])) {
                echo '<form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.admin.accounts.restore_confirm', 'Restore this viewer account? Previously revoked sessions and tokens will remain invalid.')) . '">' . $csrfHtml;
                echo '<input type="hidden" name="viewer_invitation_action" value="restore_account"><input type="hidden" name="viewer_account_id" value="' . $viewerAccountId . '">';
                echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.accounts.restore_button', 'Restore')) . '</button></form> ';
            }
            echo '<form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.admin.accounts.delete_confirm', 'Delete this viewer account? Viewer-owned account data will be removed. Photographs, galleries, gallery shares, and administrator accounts will not be deleted.')) . '">' . $csrfHtml;
            echo '<input type="hidden" name="viewer_invitation_action" value="delete_account"><input type="hidden" name="viewer_account_id" value="' . $viewerAccountId . '">';
            echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.accounts.delete_button', 'Delete account')) . '</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="muted">' . e(t('viewer.admin.accounts.delete_help', 'Deleting a viewer account removes only viewer-owned account state through existing lifecycle relationships. It does not delete photographs, galleries, gallery shares, or administrator accounts.')) . '</p>';
    }
    echo '</section>';

    echo '<section class="panel"><h1>' . e(t('viewer.admin.invites.title', 'Viewer invitations')) . '</h1>';
    if ($registrationRequestsEnabled) {
        echo '<p class="muted">' . e(t('viewer.admin.invites.create_help', 'Create an administrator invitation link. No viewer account is created until the recipient verifies email and chooses a password.')) . '</p>';
        echo '<form method="post" class="form-grid">' . $csrfHtml;
        echo '<input type="hidden" name="viewer_invitation_action" value="create">';
        echo '<label>' . e(t('viewer.admin.invites.intended_email', 'Intended email (optional)')) . '<input type="email" name="target_email" autocomplete="off"></label>';
        echo '<button type="submit">' . e(t('viewer.admin.invites.create_button', 'Create invitation')) . '</button></form>';
        echo '<p class="muted">' . e(t('viewer.admin.invites.capacity_hint', 'Viewer accounts are capped at {count}; capacity is rechecked atomically during activation.', ['count' => (string) $accountCap])) . '</p>';
    } else {
        echo '<p class="muted">' . e(t('viewer.admin.invites.not_enabled', 'Viewer invitations can be created only when viewer registration is enabled.')) . '</p>';
    }
    echo '</section>';

    echo '<section class="panel"><h2>' . e(t('viewer.admin.invites.list_title', 'Recent invitations')) . '</h2>';
    if ($invitations === []) {
        echo '<p class="muted">' . e(t('viewer.admin.invites.none', 'No invitations are available.')) . '</p>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('viewer.admin.invites.status', 'Status')) . '</th><th>' . e(t('viewer.admin.invites.email', 'Email')) . '</th><th>' . e(t('viewer.admin.invites.created', 'Created')) . '</th><th>' . e(t('viewer.admin.invites.expiry', 'Expires')) . '</th><th>' . e(t('viewer.admin.invites.registration', 'Registration')) . '</th><th>' . e(t('viewer.admin.invites.actions', 'Actions')) . '</th></tr></thead><tbody>';
        foreach ($invitations as $invitation) {
            $status = (string) ($invitation['invitation_status'] ?? 'unused');
            $targetEmail = trim((string) ($invitation['target_email'] ?? ''));
            $registrationEmail = trim((string) ($invitation['registration_email'] ?? ''));
            if ($targetEmail === '' && $registrationEmail !== '') {
                $targetEmail = $registrationEmail;
            }
            if ($targetEmail === '') {
                $targetEmail = !empty($invitation['email_bound'])
                    ? t('viewer.admin.invites.email_legacy_bound', 'Bound email unavailable for older invitation')
                    : t('viewer.admin.invites.email_any', 'Any email');
            }
            echo '<tr><td>' . e(t('viewer.admin.invites.state_' . $status, ucfirst($status))) . '</td>';
            echo '<td>' . e($targetEmail) . '</td>';
            echo '<td>' . e((string) ($invitation['created_at'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($invitation['expires_at'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($invitation['registration_status'] ?? '')) . '</td><td>';
            if ($status === 'unused') {
                echo '<form method="post" class="inline-form">' . $csrfHtml;
                echo '<input type="hidden" name="viewer_invitation_action" value="revoke"><input type="hidden" name="invitation_id" value="' . (int) $invitation['id'] . '">';
                echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.invites.revoke_button', 'Revoke')) . '</button></form> ';
            }
            echo '<form method="post" class="inline-form">' . $csrfHtml;
            echo '<input type="hidden" name="viewer_invitation_action" value="delete"><input type="hidden" name="invitation_id" value="' . (int) $invitation['id'] . '">';
            echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.invites.delete_button', 'Delete')) . '</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
    render_footer();
}
