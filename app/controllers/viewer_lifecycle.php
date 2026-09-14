<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/viewer_lifecycle.php
 * Module Type: Controller Module
 *
 * Purpose:
 *   Exposes the narrow Phase 1.2 viewer account lifecycle HTTP boundary.
 *
 * Responsibilities:
 *   - Require bounded recent viewer reauthentication for sensitive lifecycle mutations
 *   - Orchestrate existing password-change, staged email-change, and account-deletion services
 *   - Keep verification GET requests scanner-safe and final lifecycle transitions POST-only
 *   - Preserve administrator identity while clearing only viewer-local authority after lifecycle changes
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
 *   - Viewer authentication is never gallery authorization.
 *   - No collections, sharing, public profiles, uploads, or open registration are implemented here.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\e;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Services\current_viewer;
use function Gallery\Services\request_client_ip;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\viewer_account_delete;
use function Gallery\Services\viewer_account_deletion_schema_status;
use function Gallery\Services\viewer_change_password;
use function Gallery\Services\viewer_clear_reauthentication;
use function Gallery\Services\viewer_csrf_namespace_key;
use function Gallery\Services\viewer_email_change_authorize;
use function Gallery\Services\viewer_email_change_confirm;
use function Gallery\Services\viewer_email_change_confirmation_state;
use function Gallery\Services\viewer_email_change_request_inspect;
use function Gallery\Services\viewer_email_change_request_start;
use function Gallery\Services\viewer_email_normalize;
use function Gallery\Services\viewer_lifecycle_schema_status;
use function Gallery\Services\viewer_password_input_is_acceptable;
use function Gallery\Services\viewer_password_reset_state_clear;
use function Gallery\Services\viewer_reauthenticate_password;
use function Gallery\Services\viewer_recent_reauthentication_required;
use function Gallery\Services\viewer_registration_activation_clear;
use function Gallery\Core\viewer_identity_remember_cookie_clear;
use function Gallery\Services\viewer_security_event_record_best_effort;
use function Gallery\Services\viewer_security_url;

/**
 * Return whether the Phase 1.2 lifecycle HTTP boundary is currently usable.
 *
 * @return bool True only when ordinary viewer auth and lifecycle storage are available.
 */
function viewer_lifecycle_http_available(): bool
{
    return viewer_http_auth_available()
        && schema_inspection_is_available(viewer_lifecycle_schema_status());
}

/**
 * Return whether destructive account deletion has every required storage dependency.
 *
 * @return bool True only when the lifecycle and deletion-specific schema are available.
 */
function viewer_account_deletion_http_available(): bool
{
    return viewer_http_auth_available()
        && schema_inspection_is_available(viewer_account_deletion_schema_status());
}

/**
 * Map one bounded recent-reauthentication destination to its internal route.
 *
 * @param string $destination Server-owned lifecycle action identifier.
 * @return ?string Internal route name, or null when the identifier is not allowlisted.
 */
function viewer_reauthentication_destination_route(string $destination): ?string
{
    return match ($destination) {
        'password' => 'viewer_account_password',
        'email' => 'viewer_account_email',
        'email_confirm' => 'viewer_email_change_confirm',
        'delete' => 'viewer_account_delete',
        default => null,
    };
}

/**
 * Read one bounded recent-reauthentication destination from the current request.
 *
 * Arbitrary URLs are never accepted. Invalid values fall back to the private account page.
 *
 * @return string Allowlisted lifecycle action identifier or an empty string.
 */
function viewer_reauthentication_destination_from_request(): string
{
    $candidate = request_method() === 'POST'
        ? (string) ($_POST['destination'] ?? '')
        : (string) ($_GET['destination'] ?? '');

    return viewer_reauthentication_destination_route($candidate) !== null ? $candidate : '';
}

/**
 * Redirect a sensitive lifecycle request to bounded recent reauthentication when required.
 *
 * @param string $destination Allowlisted lifecycle action identifier.
 */
function viewer_require_recent_reauthentication(string $destination): void
{
    if (!viewer_recent_reauthentication_required()) {
        return;
    }

    if (viewer_reauthentication_destination_route($destination) === null) {
        redirect_to(url_for('viewer_account'));
    }
    redirect_to(url_for('viewer_account_reauth', ['destination' => $destination]));
}

/**
 * Clear viewer-only browser/session authority after a terminal lifecycle transition.
 *
 * Administrator state intentionally remains untouched.
 */
function viewer_clear_local_lifecycle_state(): void
{
    viewer_identity_remember_cookie_clear();
    viewer_clear_reauthentication();
    viewer_registration_activation_clear();
    viewer_password_reset_state_clear();
    unset($_SESSION[viewer_csrf_namespace_key()]);
}


/**
 * Capture one existing lifecycle presentation helper as a trusted HTML fragment.
 *
 * @param callable():void $renderer Presentation callback.
 */
function viewer_lifecycle_capture_html(callable $renderer): string
{
    ob_start();
    $renderer();
    return (string) ob_get_clean();
}

/**
 * Render and process the bounded viewer recent-reauthentication form.
 */
function cms_viewer_account_reauth(): void
{
    viewer_http_no_store();
    if (!viewer_lifecycle_http_available()) {
        viewer_render_unavailable();
        return;
    }
    if (!in_array(request_method(), ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        viewer_render_unavailable(405);
        return;
    }

    $viewer = current_viewer();
    if ($viewer === null) {
        redirect_to(url_for('viewer_login'));
    }

    $destination = viewer_reauthentication_destination_from_request();
    $destinationRoute = viewer_reauthentication_destination_route($destination);
    if ($destinationRoute === null) {
        redirect_to(url_for('viewer_account'));
    }

    if (!viewer_recent_reauthentication_required()) {
        redirect_to(url_for($destinationRoute));
    }

    $error = '';
    if (request_method() === 'POST') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }

        try {
            $result = viewer_reauthenticate_password((string) ($_POST['password'] ?? ''), request_client_ip());
        } catch (Throwable) {
            $result = ['reauthenticated' => false, 'reason' => 'unavailable'];
        }
        if (!empty($result['reauthenticated'])) {
            redirect_to(url_for($destinationRoute));
        }
        $error = t('viewer.reauth.failed', 'Password confirmation failed. Check your password and try again.');
    }

    \Gallery\Views\view_render_viewer_reauthentication([
        'error' => $error,
        'action_url' => url_for('viewer_account_reauth'),
        'csrf_html' => viewer_csrf_field(),
        'destination' => $destination,
        'account_url' => url_for('viewer_account'),
    ]);
}

/**
 * Render and process viewer password changes through the Phase 0.7 lifecycle service.
 */
function cms_viewer_account_password(): void
{
    viewer_http_no_store();
    if (!viewer_lifecycle_http_available()) {
        viewer_render_unavailable();
        return;
    }
    if (!in_array(request_method(), ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        viewer_render_unavailable(405);
        return;
    }
    if (current_viewer() === null) {
        redirect_to(url_for('viewer_login'));
    }

    if (request_method() === 'POST' && !viewer_verify_csrf_or_render_error()) {
        return;
    }
    viewer_require_recent_reauthentication('password');

    $error = '';
    if (request_method() === 'POST') {
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        if ($password !== $confirmation) {
            $error = t('viewer.password.confirmation_mismatch', 'The password confirmation does not match.');
        } elseif (!viewer_password_input_is_acceptable($password)) {
            $error = t('viewer.password.policy_error', 'Choose a password with at least 15 characters.');
        } else {
            try {
                $result = viewer_change_password($password);
            } catch (Throwable) {
                $result = ['changed' => false, 'reason' => 'unavailable'];
            }
            if (!empty($result['changed'])) {
                viewer_clear_local_lifecycle_state();
                redirect_to(url_for('viewer_login', ['password_changed' => '1']));
            }
            if (($result['reason'] ?? '') === 'reauthentication_required') {
                redirect_to(url_for('viewer_account_reauth', ['destination' => 'password']));
            }
            $error = t('viewer.password_change.failed', 'The password could not be changed. Reopen the page and try again.');
        }
    }

    \Gallery\Views\view_render_viewer_password_change([
        'error' => $error,
        'csrf_html' => viewer_csrf_field(),
        'password_policy_html' => viewer_lifecycle_capture_html(static function (): void {
            viewer_render_password_policy_hint();
        }),
        'account_url' => url_for('viewer_account'),
    ]);
}

/**
 * Render and process the staged viewer email-change request.
 */
function cms_viewer_account_email(): void
{
    viewer_http_no_store();
    if (!viewer_lifecycle_http_available()) {
        viewer_render_unavailable();
        return;
    }
    if (!in_array(request_method(), ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        viewer_render_unavailable(405);
        return;
    }
    $viewer = current_viewer();
    if ($viewer === null) {
        redirect_to(url_for('viewer_login'));
    }

    if (request_method() === 'POST' && !viewer_verify_csrf_or_render_error()) {
        return;
    }
    viewer_require_recent_reauthentication('email');

    $notice = '';
    $error = '';
    if (request_method() === 'POST') {
        $newEmail = trim((string) ($_POST['email'] ?? ''));
        try {
            $result = viewer_email_change_request_start($newEmail, request_client_ip());
        } catch (Throwable) {
            $result = ['requested' => false, 'reason' => 'unavailable'];
        }

        if (!empty($result['requested']) && !empty($result['verification_token'])) {
            $normalizedEmail = viewer_email_normalize($newEmail);
            $verificationUrl = viewer_security_url('index.php', [
                'page' => 'viewer_email_change_verify',
                'token' => (string) $result['verification_token'],
            ]);
            if ($normalizedEmail !== null && $verificationUrl !== null) {
                $subject = t('viewer.email.change_subject', '{site} viewer email change', ['site' => site_name()]);
                $body = t('viewer.email.change_body', "A change of email address was requested for your viewer account.\n\nUse this link to confirm the new email address:\n{verification_url}\n\nIf you did not request this change, ignore this message.", [
                    'verification_url' => $verificationUrl,
                ]);
                try {
                    $delivery = viewer_send_security_mail($normalizedEmail, $subject, $body, (string) ($result['expires_at'] ?? ''));
                } catch (Throwable) {
                    $delivery = ['sent' => false];
                }
                if (!empty($delivery['sent'])) {
                    viewer_security_event_record_best_effort('viewer.email_change_verification_sent', (int) $viewer['id'], 'success', ['action' => 'email_change']);
                    $notice = t('viewer.email_change.sent', 'Verification email sent. Your current email remains unchanged until you confirm the new address.');
                } else {
                    $error = t('viewer.email_change.mail_failed', 'The verification email could not be sent. Your current email has not changed. Try again later.');
                }
            } else {
                $error = t('viewer.email_change.failed', 'The email change could not be started. Check the address and try again later.');
            }
        } else {
            if (($result['reason'] ?? '') === 'reauthentication_required') {
                redirect_to(url_for('viewer_account_reauth', ['destination' => 'email']));
            }
            $error = t('viewer.email_change.failed', 'The email change could not be started. Check the address and try again later.');
        }
    }

    \Gallery\Views\view_render_viewer_email_change([
        'notice' => $notice,
        'error' => $error,
        'current_email' => (string) $viewer['email'],
        'csrf_html' => viewer_csrf_field(),
        'account_url' => url_for('viewer_account'),
    ]);
}

/**
 * Inspect one email-change token on GET and exchange it into bounded server-side confirmation authority.
 */
function cms_viewer_email_change_verify(): void
{
    viewer_http_no_store();
    if (!viewer_lifecycle_http_available()) {
        viewer_render_unavailable();
        return;
    }
    if (request_method() !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        viewer_render_unavailable(405);
        return;
    }

    $token = (string) ($_GET['token'] ?? '');
    try {
        $inspected = viewer_email_change_request_inspect($token);
    } catch (Throwable) {
        $inspected = null;
    }
    if ($inspected === null) {
        viewer_render_unavailable();
        return;
    }

    $viewer = current_viewer();
    if ($viewer === null) {
        \Gallery\Views\view_render_viewer_email_verify_login_required([
            'login_url' => url_for('viewer_login'),
        ]);
        return;
    }
    if ((int) $viewer['id'] !== (int) $inspected['account_id']
        || (int) $viewer['security_version'] !== (int) $inspected['security_version']) {
        viewer_render_unavailable();
        return;
    }

    try {
        $authorized = viewer_email_change_authorize($token);
    } catch (Throwable) {
        $authorized = false;
    }
    if (!$authorized) {
        viewer_render_unavailable();
        return;
    }

    viewer_require_recent_reauthentication('email_confirm');

    \Gallery\Views\view_render_viewer_email_verify([
        'new_email' => (string) $inspected['new_email'],
        'confirm_url' => url_for('viewer_email_change_confirm'),
        'csrf_html' => viewer_csrf_field(),
        'account_url' => url_for('viewer_account'),
    ]);
}

/**
 * Perform the final tokenless viewer email change from server-side confirmation authority.
 */
function cms_viewer_email_change_confirm(): void
{
    viewer_http_no_store();
    if (!viewer_lifecycle_http_available()) {
        viewer_render_unavailable();
        return;
    }
    if (current_viewer() === null) {
        redirect_to(url_for('viewer_login'));
    }
    if (viewer_email_change_confirmation_state() === null) {
        viewer_render_unavailable();
        return;
    }

    if (request_method() === 'GET') {
        viewer_require_recent_reauthentication('email_confirm');
        \Gallery\Views\view_render_viewer_email_confirm([
            'confirm_url' => url_for('viewer_email_change_confirm'),
            'csrf_html' => viewer_csrf_field(),
        ]);
        return;
    }
    if (request_method() !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        viewer_render_unavailable(405);
        return;
    }
    if (!viewer_verify_csrf_or_render_error()) {
        return;
    }
    viewer_require_recent_reauthentication('email_confirm');

    try {
        $result = viewer_email_change_confirm();
    } catch (Throwable) {
        $result = ['changed' => false, 'reason' => 'unavailable'];
    }
    if (!empty($result['changed'])) {
        viewer_clear_local_lifecycle_state();
        redirect_to(url_for('viewer_login', ['email_changed' => '1']));
    }
    if (($result['reason'] ?? '') === 'reauthentication_required') {
        redirect_to(url_for('viewer_account_reauth', ['destination' => 'email_confirm']));
    }

    \Gallery\Views\view_render_viewer_email_confirm_failed([
        'restart_url' => url_for('viewer_account_email'),
    ]);
}

/**
 * Render and process permanent viewer self-deletion through the Phase 0.7 lifecycle service.
 */
function cms_viewer_account_delete(): void
{
    viewer_http_no_store();
    if (!viewer_account_deletion_http_available()) {
        viewer_render_unavailable();
        return;
    }
    if (!in_array(request_method(), ['GET', 'POST'], true)) {
        http_response_code(405);
        header('Allow: GET, POST');
        viewer_render_unavailable(405);
        return;
    }
    if (current_viewer() === null) {
        redirect_to(url_for('viewer_login'));
    }

    if (request_method() === 'POST' && !viewer_verify_csrf_or_render_error()) {
        return;
    }
    viewer_require_recent_reauthentication('delete');

    $error = '';
    if (request_method() === 'POST') {
        if ((string) ($_POST['confirm_delete'] ?? '') !== '1') {
            $error = t('viewer.delete.confirm_required', 'Confirm that you understand this permanently deletes the viewer account.');
        } else {
            try {
                $result = viewer_account_delete();
            } catch (Throwable) {
                $result = ['deleted' => false, 'reason' => 'unavailable'];
            }
            if (!empty($result['deleted'])) {
                viewer_clear_local_lifecycle_state();
                \Gallery\Views\view_render_viewer_account_deleted([
                    'home_url' => url_for('home'),
                ]);
                return;
            }
            if (($result['reason'] ?? '') === 'reauthentication_required') {
                redirect_to(url_for('viewer_account_reauth', ['destination' => 'delete']));
            }
            $error = t('viewer.delete.failed', 'The viewer account could not be deleted. Reopen the page and try again.');
        }
    }

    \Gallery\Views\view_render_viewer_account_delete([
        'error' => $error,
        'csrf_html' => viewer_csrf_field(),
        'account_url' => url_for('viewer_account'),
    ]);
}
