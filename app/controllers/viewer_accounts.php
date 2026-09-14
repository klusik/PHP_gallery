<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/viewer_accounts.php
 * Module Type: Controller Module
 *
 * Purpose:
 *   Exposes viewer account HTTP flows plus administrator viewer provisioning and invitation management.
 *
 * Responsibilities:
 *   - Let administrators create/delete/suspend/restore viewer accounts, revoke viewer sessions, and create/list/revoke viewer invitations
 *   - Force administrator-created temporary passwords to be replaced before normal viewer authority is established
 *   - Orchestrate open/invitation registration, scanner-safe verification, login, logout, remember-me, and password-reset flows
 *   - Keep viewer identity, CSRF, persistent login, and security events separate from administrator authority
 *   - Render only the minimal private viewer account landing page required by Phase 1.0
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
 *   - Viewer collections/favourites are separate controllers; no public profiles, uploads, or optional Phase 5 authentication are implemented here.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\asset_url;
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\viewer_identity_remember_cookie_clear;
use function Gallery\Core\viewer_identity_remember_cookie_parse_request;
use function Gallery\Core\viewer_identity_remember_cookie_set;
use function Gallery\Core\viewer_identity_remember_revoke_current_cookie;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\viewer_admin_account_create;
use function Gallery\Services\viewer_admin_account_delete;
use function Gallery\Services\viewer_admin_account_list;
use function Gallery\Services\viewer_admin_account_storage_available;
use function Gallery\Services\viewer_first_login_password_complete;
use function Gallery\Services\viewer_first_login_password_state;
use function Gallery\Services\viewer_first_login_password_state_clear;
use function Gallery\Services\current_viewer;
use function Gallery\Services\request_client_ip;
use function Gallery\Services\t;
use function Gallery\Services\viewer_account_cap;
use function Gallery\Services\viewer_account_restore;
use function Gallery\Services\viewer_account_suspend;
use function Gallery\Services\viewer_anti_automation_authorize_submission;
use function Gallery\Services\viewer_anti_automation_enabled;
use function Gallery\Services\viewer_anti_automation_form_issue;
use function Gallery\Services\viewer_accounts_enabled;
use function Gallery\Services\viewer_accounts_set_admin_registration_mode;
use function Gallery\Services\viewer_auth_storage_available;
use function Gallery\Services\viewer_authenticate_password;
use function Gallery\Services\viewer_clear_reauthentication;
use function Gallery\Services\viewer_csrf_namespace_key;
use function Gallery\Services\viewer_csrf_token;
use function Gallery\Services\viewer_csrf_verify;
use function Gallery\Services\viewer_email_normalize;
use function Gallery\Services\viewer_invitation_delete;
use function Gallery\Services\viewer_invitation_inspect;
use function Gallery\Services\viewer_invitation_issue;
use function Gallery\Services\viewer_invitation_list_for_admin;
use function Gallery\Services\viewer_invitation_revoke;
use function Gallery\Services\viewer_http_invite_registration_available;
use function Gallery\Services\viewer_http_open_registration_available;
use function Gallery\Services\viewer_http_registration_verification_available;
use function Gallery\Services\viewer_http_verification_resend_available;
use function Gallery\Services\viewer_mail_authorize_send;
use function Gallery\Services\viewer_password_input_is_acceptable;
use function Gallery\Services\viewer_password_reset_complete;
use function Gallery\Services\viewer_password_reset_inspect;
use function Gallery\Services\viewer_password_reset_request;
use function Gallery\Services\viewer_password_reset_state;
use function Gallery\Services\viewer_password_reset_state_clear;
use function Gallery\Services\viewer_password_reset_authorize;
use function Gallery\Services\viewer_registration_activate_verified;
use function Gallery\Services\viewer_registration_activation_clear;
use function Gallery\Services\viewer_registration_activation_state;
use function Gallery\Services\viewer_registration_mark_verification_sent;
use function Gallery\Services\viewer_registration_mode;
use function Gallery\Services\viewer_registration_requests_enabled;
use function Gallery\Services\viewer_registration_request_begin;
use function Gallery\Services\viewer_registration_storage_available;
use function Gallery\Services\viewer_registration_verification_confirm;
use function Gallery\Services\viewer_registration_verification_resend_deliver_locked;
use function Gallery\Services\viewer_registration_verification_resend_discard;
use function Gallery\Services\viewer_registration_verification_resend_prepare;
use function Gallery\Services\viewer_registration_verification_validate;




use function Gallery\Services\viewer_remember_token_issue;
use function Gallery\Services\viewer_remember_token_revoke;
use function Gallery\Services\viewer_security_event_record_best_effort;
use function Gallery\Services\viewer_security_operations_snapshot;
use function Gallery\Services\viewer_security_transport_allowed;
use function Gallery\Services\viewer_security_url;
use function Gallery\Services\viewer_session_revoke_all;
use function Gallery\Services\viewer_session_revoke_current;
use const Gallery\Services\VIEWER_ANTI_AUTOMATION_ACTION_REGISTER;
use const Gallery\Services\VIEWER_ANTI_AUTOMATION_ACTION_RESEND;
use const Gallery\Services\VIEWER_ANTI_AUTOMATION_RESULT_ALLOW;
use const Gallery\Services\VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED;
use const Gallery\Services\VIEWER_ANTI_AUTOMATION_RESULT_INVALID;
use const Gallery\Services\VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS;
use const Gallery\Services\VIEWER_ACCOUNT_STATUS_ACTIVE;
use const Gallery\Services\VIEWER_ACCOUNT_STATUS_DISABLED;
use const Gallery\Services\VIEWER_ACCOUNT_STATUS_PENDING_VERIFICATION;
use const Gallery\Services\VIEWER_ACCOUNT_STATUS_SUSPENDED;
use const Gallery\Services\VIEWER_MAIL_ACTION_VERIFICATION;

/**
 * Send an explicit private/no-store policy for every viewer/pre-auth response.
 */
function viewer_http_no_store(): void
{
    if (headers_sent()) {
        return;
    }
    clear_response_cache_headers();
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
}

/**
 * Return a viewer-specific CSRF hidden field without reusing administrator authority.
 *
 * @return string HTML hidden field.
 */
function viewer_csrf_field(): string
{
    return \Gallery\Views\view_viewer_csrf_field(viewer_csrf_token());
}

/**
 * Verify the viewer/pre-auth CSRF token and render a bounded 400 response on failure.
 *
 * @return bool True only when CSRF verification succeeds.
 */
function viewer_verify_csrf_or_render_error(): bool
{
    $token = (string) ($_POST['viewer_csrf_token'] ?? '');
    if (viewer_csrf_verify($token)) {
        return true;
    }
    http_response_code(400);
    viewer_http_no_store();
    \Gallery\Views\view_render_viewer_invalid_request();
    return false;
}


/**
 * Return the first-party anti-automation hidden form fields for one protected action.
 *
 * The randomized honeypot is hidden from visual, keyboard, and accessibility presentation while
 * remaining a normal successful form control if automated software chooses to populate it.
 *
 * @param string $action Protected anti-automation action.
 * @return string Signed ticket and randomized honeypot markup, or an empty string when disabled.
 */
function viewer_anti_automation_form_fields(string $action): string
{
    if (!viewer_anti_automation_enabled()) {
        return '';
    }
    return \Gallery\Views\view_viewer_anti_automation_form_fields(viewer_anti_automation_form_issue($action));
}

/**
 * Render the local adaptive challenge without exposing account or registration state.
 *
 * @param string $action Protected anti-automation action.
 * @param string $email Submitted email carried forward only as ordinary form input.
 * @param string $pageName Existing route/page name receiving the continued POST.
 * @param string $pageTitle Localized page title.
 * @param array<string,mixed> $challenge Signed challenge state returned by the service.
 */
function viewer_render_anti_automation_challenge(
    string $action,
    string $email,
    string $pageName,
    string $pageTitle,
    array $challenge
): void {
    $ticket = (string) ($challenge['ticket'] ?? '');
    $nonce = (string) ($challenge['challenge'] ?? '');
    $difficulty = (int) ($challenge['difficulty'] ?? 0);
    $maxCounter = (int) ($challenge['max_counter'] ?? 0);
    if ($ticket === '' || $nonce === '' || $difficulty <= 0 || $maxCounter <= 0) {
        viewer_render_unavailable(400);
        return;
    }

    $scriptPath = dirname(__DIR__, 2) . '/public/assets/viewer-anti-automation.js';
    \Gallery\Views\view_render_viewer_anti_automation_challenge([
        'page_title' => $pageTitle,
        'action_url' => url_for($pageName),
        'action' => $action,
        'email' => $email,
        'ticket' => $ticket,
        'challenge' => $nonce,
        'difficulty' => $difficulty,
        'max_counter' => $maxCounter,
        'csrf_html' => viewer_csrf_field(),
        'script_url' => asset_url('assets/viewer-anti-automation.js') . '?v=' . (is_file($scriptPath) ? filemtime($scriptPath) : time()),
    ]);
}

/**
 * Render a generic unavailable response for disabled, insecure, or unavailable viewer routes.
 *
 * @param int $status HTTP status code.
 */
function viewer_render_unavailable(int $status = 404): void
{
    http_response_code($status);
    viewer_http_no_store();
    \Gallery\Views\view_render_viewer_unavailable();
}

/**
 * Return whether the ordinary viewer authentication HTTP boundary is available.
 *
 * @return bool True only when viewer accounts, secure transport, and storage are available.
 */
function viewer_http_auth_available(): bool
{
    return viewer_accounts_enabled() && viewer_security_transport_allowed() && viewer_auth_storage_available();
}

/**
 * Deliver one viewer security message through the already configured project mail transport.
 *
 * This wrapper deliberately reuses the administrator password-reset transport configuration
 * and SMTP/PHP-mail implementation. Abuse-budget authorization remains a separate viewer
 * service decision and must occur before this function is called.
 *
 * @param string $recipient Recipient email address.
 * @param string $subject Plain-text subject.
 * @param string $body Plain-text body.
 * @param string $expiresAt Optional expiry metadata for safe delivery diagnostics.
 * @return array<string,mixed> Existing mail transport result.
 */
function viewer_send_security_mail(string $recipient, string $subject, string $body, string $expiresAt = ''): array
{
    if (!function_exists(__NAMESPACE__ . '\\cms_send_configured_password_reset_mail')) {
        return ['sent' => false, 'reason' => 'mail_transport_unavailable'];
    }
    return cms_send_configured_password_reset_mail($recipient, $subject, $body, $expiresAt);
}

/**
 * Authorize and deliver one staged registration verification message when eligible.
 *
 * The registration service is the only source of the plaintext verification capability.
 * This HTTP helper never renders or logs it, and marks delivery only after the configured
 * transport reports successful handoff.
 *
 * @param string $email Submitted registration email.
 * @param array<string,mixed> $result Registration-service result.
 * @param bool $invitationBacked Whether invitation-specific email wording should be used.
 */
function viewer_deliver_registration_verification(string $email, array $result, bool $invitationBacked): void
{
    if (empty($result['mail_eligible']) || empty($result['verification_token'])) {
        return;
    }

    $normalizedEmail = viewer_email_normalize($email);
    if ($normalizedEmail === null) {
        return;
    }

    $mailDecision = viewer_mail_authorize_send(VIEWER_MAIL_ACTION_VERIFICATION, $normalizedEmail, request_client_ip());
    if (empty($mailDecision['allowed'])) {
        return;
    }

    $verificationUrl = viewer_security_url('index.php', [
        'page' => 'viewer_verify',
        'token' => (string) $result['verification_token'],
    ]);
    if ($verificationUrl === null) {
        return;
    }

    $subject = t('viewer.email.verification_subject', '{site} viewer account verification', [
        'site' => \Gallery\Services\site_name(),
    ]);
    if ($invitationBacked) {
        $body = t(
            'viewer.email.verification_body',
            "You were invited to create a viewer account.\n\nVerify and complete your account using this link:\n{verification_url}\n\nIf you did not expect this invitation, ignore this message.",
            ['verification_url' => $verificationUrl]
        );
    } else {
        $body = t(
            'viewer.email.open_verification_body',
            "A viewer account registration was requested for this email address.\n\nConfirm the email address and complete the account using this link:\n{verification_url}\n\nIf you did not request this registration, ignore this message.",
            ['verification_url' => $verificationUrl]
        );
    }

    $delivery = viewer_send_security_mail($normalizedEmail, $subject, $body, (string) ($result['expires_at'] ?? ''));
    if (!empty($delivery['sent']) && !empty($result['request_id'])) {
        viewer_registration_mark_verification_sent((int) $result['request_id']);
        viewer_security_event_record_best_effort('viewer.verification_sent', null, 'success', [
            'action' => $invitationBacked ? 'invitation_verification' : 'open_registration_verification',
        ]);
    }
}

/**
 * Render the neutral verification-resend recovery link only while its HTTP surface is available.
 */
function viewer_render_verification_resend_link(): void
{
    \Gallery\Views\view_render_verification_resend_link(
        viewer_http_verification_resend_available(),
        url_for('viewer_resend_verification')
    );
}

/**
 * Render an invalid/expired verification result with generic resend recovery when available.
 */
function viewer_render_verification_unavailable(): void
{
    http_response_code(404);
    viewer_http_no_store();
    \Gallery\Views\view_render_verification_unavailable([
        'resend_available' => viewer_http_verification_resend_available(),
        'resend_url' => url_for('viewer_resend_verification'),
        'login_url' => url_for('viewer_login'),
    ]);
}

/**
 * Authorize and deliver one explicitly requested sibling verification message.
 *
 * Recipient and origin metadata come only from the registration-service result. The existing
 * verification-mail budgets are reserved before transport. The final transport callback runs
 * under registration-state revalidation so restrictive mode changes serialize with resend.
 *
 * @param array<string,mixed> $result Prepared resend-service result.
 * @return array{sent:bool,reason:string} Bounded internal orchestration outcome.
 */
function viewer_deliver_registration_verification_resend(array $result): array
{
    if (empty($result['mail_eligible'])
        || empty($result['verification_token'])
        || empty($result['request_id'])
        || empty($result['verification_authority_id'])
        || empty($result['recipient_email'])) {
        return ['sent' => false, 'reason' => (string) ($result['reason'] ?? 'request_ineligible')];
    }

    $requestId = (int) $result['request_id'];
    $authorityId = (int) $result['verification_authority_id'];
    $recipient = viewer_email_normalize((string) $result['recipient_email']);
    if ($recipient === null) {
        try {
            viewer_registration_verification_resend_discard($requestId, $authorityId);
        } catch (Throwable) {
            // Prepared child authority remains bounded by expiry/cascade cleanup when immediate cleanup is unavailable.
        }
        return ['sent' => false, 'reason' => 'recipient_unavailable'];
    }

    $mailDecision = viewer_mail_authorize_send(VIEWER_MAIL_ACTION_VERIFICATION, $recipient, request_client_ip());
    if (empty($mailDecision['allowed'])) {
        try {
            viewer_registration_verification_resend_discard($requestId, $authorityId);
        } catch (Throwable) {
            // Prepared child authority remains unsent and therefore unusable.
        }
        return ['sent' => false, 'reason' => (string) ($mailDecision['reason'] ?? 'mail_suppressed')];
    }

    $verificationUrl = viewer_security_url('index.php', [
        'page' => 'viewer_verify',
        'token' => (string) $result['verification_token'],
    ]);
    if ($verificationUrl === null) {
        try {
            viewer_registration_verification_resend_discard($requestId, $authorityId);
        } catch (Throwable) {
            // Prepared child authority remains unsent and therefore unusable.
        }
        return ['sent' => false, 'reason' => 'security_url_unavailable'];
    }

    $subject = t('viewer.email.verification_subject', '{site} viewer account verification', [
        'site' => \Gallery\Services\site_name(),
    ]);
    $invitationBacked = !empty($result['invitation_backed']);
    if ($invitationBacked) {
        $body = t(
            'viewer.email.verification_body',
            "You were invited to create a viewer account.\n\nVerify and complete your account using this link:\n{verification_url}\n\nIf you did not expect this invitation, ignore this message.",
            ['verification_url' => $verificationUrl]
        );
    } else {
        $body = t(
            'viewer.email.open_verification_body',
            "A viewer account registration was requested for this email address.\n\nConfirm the email address and complete the account using this link:\n{verification_url}\n\nIf you did not request this registration, ignore this message.",
            ['verification_url' => $verificationUrl]
        );
    }

    try {
        $deliveryResult = viewer_registration_verification_resend_deliver_locked(
            $requestId,
            $authorityId,
            static fn (): array => viewer_send_security_mail(
                $recipient,
                $subject,
                $body,
                (string) ($result['expires_at'] ?? '')
            )
        );
    } catch (Throwable) {
        try {
            viewer_registration_verification_resend_discard($requestId, $authorityId);
        } catch (Throwable) {
            // A failed post-handoff state update must never trigger a second mail attempt.
        }
        return ['sent' => false, 'reason' => 'delivery_state_unavailable'];
    }

    return [
        'sent' => !empty($deliveryResult['sent']),
        'reason' => (string) ($deliveryResult['reason'] ?? 'mail_delivery_failed'),
    ];
}

/**
 * Render one shared viewer password policy hint.
 */
function viewer_render_password_policy_hint(): void
{
    \Gallery\Views\view_render_viewer_password_policy_hint();
}

/**
 * Parse one viewer account identifier without overflow or permissive numeric coercion.
 *
 * @param mixed $value Raw request value.
 * @return int Positive account id, or zero when invalid.
 */
function viewer_admin_account_id_parse($value): int
{
    if (!is_int($value) && !is_string($value)) {
        return 0;
    }

    $raw = (string) $value;
    if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
        return 0;
    }

    $maximum = (string) PHP_INT_MAX;
    if (strlen($raw) > strlen($maximum)
        || (strlen($raw) === strlen($maximum) && strcmp($raw, $maximum) > 0)) {
        return 0;
    }

    return (int) $raw;
}

/**
 * Return one localized administrator-facing viewer account status label.
 *
 * @param string $status Durable viewer account status.
 * @return string Localized status label.
 */
function viewer_admin_account_status_label(string $status): string
{
    return match ($status) {
        VIEWER_ACCOUNT_STATUS_ACTIVE => t('viewer.admin.accounts.status_active', 'Active'),
        VIEWER_ACCOUNT_STATUS_SUSPENDED => t('viewer.admin.accounts.status_suspended', 'Suspended'),
        VIEWER_ACCOUNT_STATUS_DISABLED => t('viewer.admin.accounts.status_disabled', 'Disabled'),
        VIEWER_ACCOUNT_STATUS_PENDING_VERIFICATION => t('viewer.admin.accounts.status_pending_verification', 'Pending verification'),
        default => t('viewer.admin.accounts.status_unknown', 'Unavailable'),
    };
}

/**
 * Return one localized operations capability state label.
 *
 * @param string $status Normalized available, unavailable, or unknown state.
 * @return string Localized administrator-facing label.
 */
function viewer_admin_security_operations_status_label(string $status): string
{
    return match ($status) {
        'available' => t('viewer.admin.security.available', 'Available'),
        'unavailable' => t('viewer.admin.security.unavailable', 'Unavailable'),
        default => t('viewer.admin.security.unknown', 'Unknown'),
    };
}

/**
 * Return one localized enabled/disabled label for the operations summary.
 *
 * @param bool $enabled Current normalized state.
 * @return string Localized label.
 */
function viewer_admin_security_operations_enabled_label(bool $enabled): string
{
    return $enabled
        ? t('viewer.admin.security.enabled', 'Enabled')
        : t('viewer.admin.security.disabled', 'Disabled');
}

/**
 * Return the localized label for one effective registration mode.
 *
 * @param string $mode Effective Viewer registration mode.
 * @return string Localized mode label.
 */
function viewer_admin_security_operations_mode_label(string $mode): string
{
    return match ($mode) {
        'open' => t('viewer.admin.invites.mode_open_label', 'Open registration'),
        'invite_only' => t('viewer.admin.invites.mode_invite_only_label', 'Invite only'),
        default => t('viewer.admin.invites.mode_disabled_label', 'Disabled'),
    };
}

/**
 * Return one localized fixed Viewer rate-limit bucket label.
 *
 * @param string $bucket Application-owned limiter bucket.
 * @return string Localized administrator-facing label.
 */
function viewer_admin_security_operations_bucket_label(string $bucket): string
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
 * Render the privacy-safe read-only Phase 4.4 Viewer security operations summary.
 *
 * @param array<string,mixed> $operations Snapshot returned by viewer_security_operations_snapshot().
 */
function viewer_render_admin_security_operations(array $operations): void
{
    \Gallery\Views\view_render_admin_viewer_security_operations($operations);
}

/**
 * Render the administrator viewer-account page and handle account/invitation mutations.
 */
function cms_admin_viewer_invitations(): void
{
    require_admin();
    viewer_http_no_store();
    $user = current_user();
    if (!$user || (string) ($user['role'] ?? '') !== 'admin') {
        return;
    }

    $notice = '';
    $error = '';
    if (request_method() === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['viewer_invitation_action'] ?? '');
        if ($action === 'set_mode') {
            $requestedMode = trim((string) ($_POST['viewer_accounts_mode'] ?? ''));
            if (!in_array($requestedMode, ['disabled', 'invite_only', 'open'], true)) {
                flash_message('viewer_admin_invite_notice', t('viewer.admin.invites.mode_invalid', 'Choose a valid viewer registration mode.'));
                redirect_to(url_for('admin_viewer_invitations'));
            }

            $oldMode = viewer_registration_mode();
            try {
                $cancelledOpenOriginStagingCount = viewer_accounts_set_admin_registration_mode($requestedMode);
                admin_log_event('info', 'viewer.accounts_mode_changed', 'Admin changed viewer account availability.', [
                    'old_mode' => $oldMode,
                    'new_mode' => $requestedMode,
                    'cancelled_open_origin_staging_count' => $cancelledOpenOriginStagingCount,
                ], [
                    'category' => 'security',
                    'severity' => 'notice',
                    'route_name' => 'admin_viewer_invitations',
                ]);
                $noticeKey = match ($requestedMode) {
                    'open' => 'viewer.admin.invites.mode_open',
                    'invite_only' => 'viewer.admin.invites.mode_enabled',
                    default => 'viewer.admin.invites.mode_disabled',
                };
                $noticeFallback = match ($requestedMode) {
                    'open' => 'Viewer accounts enabled with open verified-email registration.',
                    'invite_only' => 'Viewer accounts enabled in invite-only mode.',
                    default => 'Viewer accounts disabled. Existing viewer account data was kept.',
                };
                flash_message('viewer_admin_invite_notice', t($noticeKey, $noticeFallback));
            } catch (Throwable $exception) {
                admin_log_event('error', 'viewer.accounts_mode_change_failed', 'Admin could not change viewer account availability.', [
                    'old_mode' => $oldMode,
                    'requested_mode' => $requestedMode,
                    'exception' => $exception->getMessage(),
                ], [
                    'category' => 'security',
                    'severity' => 'error',
                    'route_name' => 'admin_viewer_invitations',
                ]);
                flash_message('viewer_admin_invite_notice', t('viewer.admin.invites.mode_save_failed', 'Viewer account mode could not be changed.'));
            }
            redirect_to(url_for('admin_viewer_invitations'));
        } elseif ($action === 'create_account') {
            $email = trim((string) ($_POST['viewer_account_email'] ?? ''));
            $temporaryPassword = (string) ($_POST['viewer_account_temporary_password'] ?? '');
            try {
                $created = viewer_admin_account_create(
                    (int) $user['id'],
                    $email,
                    trim($temporaryPassword) === '' ? null : $temporaryPassword
                );
                if (!empty($created['created'])) {
                    $accountId = (int) ($created['account_id'] ?? 0);
                    $_SESSION['viewer_admin_account_show_once'] = [
                        'email' => (string) ($created['email'] ?? $email),
                        'temporary_password' => (string) ($created['temporary_password'] ?? ''),
                        'password_generated' => !empty($created['password_generated']),
                    ];
                    $notificationRequested = !empty($_POST['viewer_account_send_notification']);
                    $notificationSent = false;
                    if ($notificationRequested) {
                        $loginUrl = viewer_security_url('index.php', ['page' => 'viewer_login']);
                        if ($loginUrl !== null) {
                            $subject = t('viewer.email.admin_created_subject', '{site} viewer account created', [
                                'site' => \Gallery\Services\site_name(),
                            ]);
                            $body = t(
                                'viewer.email.admin_created_body',
                                "An administrator created a viewer account for you on {site}.\n\nSign in here:\n{login_url}\n\nUse the temporary password supplied to you separately by the administrator. You will be required to choose a new password immediately after the first successful sign-in.\n\nNo password is included in this email.",
                                ['site' => \Gallery\Services\site_name(), 'login_url' => $loginUrl]
                            );
                            $delivery = viewer_send_security_mail((string) ($created['email'] ?? $email), $subject, $body);
                            $notificationSent = !empty($delivery['sent']);
                        }
                    }
                    admin_log_event('info', 'viewer.account_admin_created', 'Admin created a viewer account.', [
                        'viewer_account_id' => $accountId,
                        'notification_requested' => $notificationRequested,
                        'notification_sent' => $notificationSent,
                    ], [
                        'category' => 'security',
                        'severity' => 'notice',
                        'route_name' => 'admin_viewer_invitations',
                    ]);
                    if ($notificationRequested && !$notificationSent) {
                        flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.created_notification_failed', 'Viewer account created, but the account notification could not be sent. The temporary password is shown once below.'));
                    } else {
                        flash_message('viewer_admin_invite_notice', $notificationSent
                            ? t('viewer.admin.accounts.created_notification_sent', 'Viewer account created. A notification without the temporary password was sent to the user.')
                            : t('viewer.admin.accounts.created', 'Viewer account created.'));
                    }
                    redirect_to(url_for('admin_viewer_invitations'));
                }
                $reason = (string) ($created['reason'] ?? 'create_failed');
                $error = match ($reason) {
                    'invalid_email' => t('viewer.admin.accounts.invalid_email', 'Enter a valid viewer email address.'),
                    'password_policy' => t('viewer.admin.accounts.password_policy', 'The temporary password must satisfy the viewer password policy.'),
                    'account_exists' => t('viewer.admin.accounts.exists', 'A viewer account already uses this email address.'),
                    'account_capacity' => t('viewer.admin.accounts.capacity', 'The configured viewer-account capacity has been reached.'),
                    'storage_unavailable' => t('viewer.admin.accounts.storage_unavailable', 'Viewer account management storage is unavailable.'),
                    default => t('viewer.admin.accounts.create_failed', 'The viewer account could not be created.'),
                };
            } catch (Throwable $exception) {
                admin_log_event('error', 'viewer.account_admin_create_failed', 'Admin could not create a viewer account.', [
                    'exception' => $exception->getMessage(),
                ], [
                    'category' => 'security',
                    'severity' => 'error',
                    'route_name' => 'admin_viewer_invitations',
                ]);
                $error = t('viewer.admin.accounts.create_failed', 'The viewer account could not be created.');
            }
        } elseif ($action === 'delete_account') {
            $viewerAccountId = viewer_admin_account_id_parse($_POST['viewer_account_id'] ?? null);
            try {
                if ($viewerAccountId <= 0) {
                    throw new \InvalidArgumentException('Invalid viewer account id.');
                }
                $deleted = viewer_admin_account_delete((int) $user['id'], $viewerAccountId);
                if (!empty($deleted['deleted'])) {
                    admin_log_event('info', 'viewer.account_admin_deleted', 'Admin deleted a viewer account.', [
                        'viewer_account_id' => $viewerAccountId,
                    ], [
                        'category' => 'security',
                        'severity' => 'notice',
                        'route_name' => 'admin_viewer_invitations',
                    ]);
                    flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.deleted', 'Viewer account deleted. Viewer-owned account data was removed by the existing lifecycle cascades.'));
                } else {
                    $message = (string) ($deleted['reason'] ?? '') === 'not_found'
                        ? t('viewer.admin.accounts.not_found', 'The viewer account no longer exists.')
                        : t('viewer.admin.accounts.delete_failed', 'The viewer account could not be deleted safely.');
                    flash_message('viewer_admin_invite_notice', $message);
                }
            } catch (Throwable $exception) {
                admin_log_event('error', 'viewer.account_admin_delete_failed', 'Admin could not delete a viewer account.', [
                    'viewer_account_id' => $viewerAccountId,
                    'exception' => $exception->getMessage(),
                ], [
                    'category' => 'security',
                    'severity' => 'error',
                    'route_name' => 'admin_viewer_invitations',
                ]);
                flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.delete_failed', 'The viewer account could not be deleted safely.'));
            }
            redirect_to(url_for('admin_viewer_invitations'));
        } elseif ($action === 'suspend_account') {
            $viewerAccountId = viewer_admin_account_id_parse($_POST['viewer_account_id'] ?? null);
            try {
                if ($viewerAccountId <= 0) {
                    throw new \InvalidArgumentException('Invalid viewer account id.');
                }
                if (viewer_account_suspend($viewerAccountId)) {
                    admin_log_event('info', 'viewer.account_admin_suspended', 'Admin suspended a viewer account.', [
                        'viewer_account_id' => $viewerAccountId,
                        'result' => 'success',
                    ], [
                        'category' => 'security',
                        'severity' => 'notice',
                        'route_name' => 'admin_viewer_invitations',
                    ]);
                    flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.suspended', 'Viewer account suspended. Existing viewer authentication authority was revoked.'));
                } else {
                    flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.not_found', 'The viewer account no longer exists.'));
                }
            } catch (Throwable) {
                admin_log_event('error', 'viewer.account_admin_suspend_failed', 'Admin could not suspend a viewer account.', [
                    'viewer_account_id' => $viewerAccountId,
                    'result' => 'failed',
                ], [
                    'category' => 'security',
                    'severity' => 'error',
                    'route_name' => 'admin_viewer_invitations',
                ]);
                flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.security_action_failed', 'The viewer account security action could not be completed safely.'));
            }
            redirect_to(url_for('admin_viewer_invitations'));
        } elseif ($action === 'restore_account') {
            $viewerAccountId = viewer_admin_account_id_parse($_POST['viewer_account_id'] ?? null);
            try {
                if ($viewerAccountId <= 0) {
                    throw new \InvalidArgumentException('Invalid viewer account id.');
                }
                if (viewer_account_restore($viewerAccountId)) {
                    admin_log_event('info', 'viewer.account_admin_restored', 'Admin restored a viewer account.', [
                        'viewer_account_id' => $viewerAccountId,
                        'result' => 'success',
                    ], [
                        'category' => 'security',
                        'severity' => 'notice',
                        'route_name' => 'admin_viewer_invitations',
                    ]);
                    flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.restored', 'Viewer account restored. A fresh viewer login is required.'));
                } else {
                    flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.not_found', 'The viewer account no longer exists.'));
                }
            } catch (Throwable) {
                admin_log_event('error', 'viewer.account_admin_restore_failed', 'Admin could not restore a viewer account.', [
                    'viewer_account_id' => $viewerAccountId,
                    'result' => 'failed',
                ], [
                    'category' => 'security',
                    'severity' => 'error',
                    'route_name' => 'admin_viewer_invitations',
                ]);
                flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.security_action_failed', 'The viewer account security action could not be completed safely.'));
            }
            redirect_to(url_for('admin_viewer_invitations'));
        } elseif ($action === 'revoke_sessions') {
            $viewerAccountId = viewer_admin_account_id_parse($_POST['viewer_account_id'] ?? null);
            try {
                if ($viewerAccountId <= 0) {
                    throw new \InvalidArgumentException('Invalid viewer account id.');
                }
                $newSecurityVersion = viewer_session_revoke_all($viewerAccountId);
                viewer_security_event_record_best_effort('viewer.account_admin_sessions_revoked', $viewerAccountId, 'success', [
                    'admin_user_id' => (int) $user['id'],
                    'security_version' => $newSecurityVersion,
                ]);
                admin_log_event('info', 'viewer.account_admin_sessions_revoked', 'Admin revoked all viewer login sessions.', [
                    'viewer_account_id' => $viewerAccountId,
                    'result' => 'success',
                    'security_version' => $newSecurityVersion,
                ], [
                    'category' => 'security',
                    'severity' => 'notice',
                    'route_name' => 'admin_viewer_invitations',
                ]);
                flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.signed_out_everywhere', 'Viewer signed out everywhere. The account remains active and a fresh login is required.'));
            } catch (Throwable) {
                admin_log_event('error', 'viewer.account_admin_sessions_revoke_failed', 'Admin could not revoke all viewer login sessions.', [
                    'viewer_account_id' => $viewerAccountId,
                    'result' => 'failed',
                ], [
                    'category' => 'security',
                    'severity' => 'error',
                    'route_name' => 'admin_viewer_invitations',
                ]);
                flash_message('viewer_admin_invite_notice', t('viewer.admin.accounts.security_action_failed', 'The viewer account security action could not be completed safely.'));
            }
            redirect_to(url_for('admin_viewer_invitations'));
        } elseif ($action === 'create') {
            if (!viewer_registration_requests_enabled()) {
                $error = t('viewer.admin.invites.not_enabled', 'Viewer invitations can be created only when viewer registration is enabled.');
            } else {
                try {
                    $targetEmail = trim((string) ($_POST['target_email'] ?? ''));
                    $issued = viewer_invitation_issue((int) $user['id'], $targetEmail === '' ? null : $targetEmail);
                    $inviteUrl = viewer_security_url('index.php', [
                        'page' => 'viewer_invite',
                        'token' => (string) $issued['token'],
                    ]);
                    if ($inviteUrl === null) {
                        viewer_invitation_revoke((int) $issued['id']);
                        throw new \RuntimeException('Trusted viewer invitation URL could not be built.');
                    }
                    $_SESSION['viewer_invitation_show_once'] = [
                        'url' => $inviteUrl,
                        'expires_at' => (string) $issued['expires_at'],
                    ];
                    viewer_security_event_record_best_effort('viewer.invitation_created', null, 'success', ['action' => 'create']);
                    redirect_to(url_for('admin_viewer_invitations'));
                } catch (Throwable $exception) {
                    $error = $exception instanceof \InvalidArgumentException
                        ? t('viewer.admin.invites.invalid_email', 'Enter a valid intended email address or leave the field empty.')
                        : t('viewer.admin.invites.create_failed', 'The invitation could not be created. Check viewer configuration, capacity, and database availability.');
                }
            }
        } elseif ($action === 'revoke') {
            $invitationId = (int) ($_POST['invitation_id'] ?? 0);
            try {
                if ($invitationId > 0 && viewer_invitation_revoke($invitationId)) {
                    viewer_security_event_record_best_effort('viewer.invitation_revoked', null, 'success', ['action' => 'revoke']);
                    flash_message('viewer_admin_invite_notice', t('viewer.admin.invites.revoked', 'Invitation revoked.'));
                }
            } catch (Throwable) {
                flash_message('viewer_admin_invite_notice', t('viewer.admin.invites.revoke_failed', 'The invitation could not be revoked.'));
            }
            redirect_to(url_for('admin_viewer_invitations'));
        } elseif ($action === 'delete') {
            $invitationId = (int) ($_POST['invitation_id'] ?? 0);
            try {
                if ($invitationId > 0 && viewer_invitation_delete($invitationId)) {
                    viewer_security_event_record_best_effort('viewer.invitation_deleted', null, 'success', ['action' => 'delete']);
                    flash_message('viewer_admin_invite_notice', t('viewer.admin.invites.deleted', 'Invitation deleted. Its link is no longer valid.'));
                } else {
                    flash_message('viewer_admin_invite_notice', t('viewer.admin.invites.delete_failed', 'The invitation could not be deleted.'));
                }
            } catch (Throwable) {
                flash_message('viewer_admin_invite_notice', t('viewer.admin.invites.delete_failed', 'The invitation could not be deleted.'));
            }
            redirect_to(url_for('admin_viewer_invitations'));
        } else {
            $error = t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.');
        }
    }

    $showOnce = $_SESSION['viewer_invitation_show_once'] ?? null;
    unset($_SESSION['viewer_invitation_show_once']);
    $accountShowOnce = $_SESSION['viewer_admin_account_show_once'] ?? null;
    unset($_SESSION['viewer_admin_account_show_once']);
    if ($flash = flash_message('viewer_admin_invite_notice')) {
        $notice = $flash;
    }
    $viewerAccountStorageAvailable = viewer_admin_account_storage_available();
    try {
        $viewerAccounts = $viewerAccountStorageAvailable ? viewer_admin_account_list(250) : [];
        if (!$viewerAccountStorageAvailable) {
            $error = t('viewer.admin.accounts.storage_unavailable', 'Viewer account management storage is unavailable.');
        }
    } catch (Throwable) {
        $viewerAccounts = [];
        $error = t('viewer.admin.accounts.storage_unavailable', 'Viewer account management storage is unavailable.');
    }
    try {
        $invitations = viewer_registration_storage_available() ? viewer_invitation_list_for_admin(100) : [];
    } catch (Throwable) {
        $invitations = [];
        if ($error === '') {
            $error = t('viewer.admin.invites.storage_unavailable', 'Viewer invitation storage is unavailable. Existing gallery and administrator behavior is unaffected.');
        }
    }
    try {
        $viewerSecurityOperations = viewer_security_operations_snapshot();
    } catch (Throwable) {
        $viewerSecurityOperations = [];
    }

    $viewerAccountRows = [];
    foreach ($viewerAccounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $accountStatus = (string) ($account['status'] ?? '');
        $viewerAccountRows[] = [
            'id' => (int) ($account['id'] ?? 0),
            'email' => (string) ($account['email'] ?? ''),
            'status_label' => viewer_admin_account_status_label($accountStatus),
            'must_change_password' => !empty($account['must_change_password']),
            'created_at' => (string) ($account['created_at'] ?? ''),
            'last_login_at' => (string) ($account['last_login_at'] ?? ''),
            'can_suspend' => $accountStatus === VIEWER_ACCOUNT_STATUS_ACTIVE,
            'can_restore' => $accountStatus === VIEWER_ACCOUNT_STATUS_SUSPENDED,
        ];
    }

    \Gallery\Views\view_render_admin_viewer_accounts([
        'notice' => $notice,
        'error' => $error,
        'account_show_once' => $accountShowOnce,
        'invitation_show_once' => $showOnce,
        'viewer_accounts' => $viewerAccountRows,
        'invitations' => $invitations,
        'security_operations' => $viewerSecurityOperations,
        'registration_mode' => viewer_registration_mode(),
        'registration_requests_enabled' => viewer_registration_requests_enabled(),
        'account_storage_available' => $viewerAccountStorageAvailable,
        'account_cap' => viewer_account_cap(),
        'csrf_html' => csrf_field(),
    ]);
}

/**
 * Render and process anonymous verified-email open registration.
 */
function cms_viewer_register(): void
{
    viewer_http_no_store();
    if (!viewer_http_open_registration_available()) {
        viewer_render_unavailable();
        return;
    }
    if (current_viewer() !== null) {
        redirect_to(url_for('viewer_account'));
    }

    $antiAutomationError = '';
    if (request_method() === 'POST') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $complete = false;
        if (viewer_email_normalize($email) === null) {
            // Invalid syntax never reaches registration or mail work and keeps the same generic public completion result.
            $complete = true;
        } else {
            $antiAutomation = viewer_anti_automation_authorize_submission(
                VIEWER_ANTI_AUTOMATION_ACTION_REGISTER,
                $_POST,
                request_client_ip()
            );
            $antiAutomationResult = (string) ($antiAutomation['result'] ?? VIEWER_ANTI_AUTOMATION_RESULT_INVALID);
            if ($antiAutomationResult === VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED) {
                viewer_render_anti_automation_challenge(
                    VIEWER_ANTI_AUTOMATION_ACTION_REGISTER,
                    $email,
                    'viewer_register',
                    t('viewer.register.title', 'Create viewer account'),
                    is_array($antiAutomation['challenge'] ?? null) ? $antiAutomation['challenge'] : []
                );
                return;
            }
            if ($antiAutomationResult === VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS) {
                $complete = true;
            } elseif ($antiAutomationResult === VIEWER_ANTI_AUTOMATION_RESULT_INVALID) {
                $antiAutomationError = t(
                    'viewer.automation.retry',
                    'The local verification state expired or could not be validated. Please try again.'
                );
            } elseif ($antiAutomationResult === VIEWER_ANTI_AUTOMATION_RESULT_ALLOW) {
                try {
                    $result = viewer_registration_request_begin($email, null, request_client_ip());
                    if (!empty($result['accepted'])) {
                        viewer_security_event_record_best_effort('viewer.registration_requested', null, 'accepted', [
                            'action' => 'open_registration',
                        ]);
                    }
                    viewer_deliver_registration_verification($email, $result, false);
                } catch (Throwable) {
                    // Preserve one externally equivalent response for account, capacity, limiter, storage, and mail outcomes.
                }
                $complete = true;
            }
        }

        if ($complete) {
            \Gallery\Views\view_render_viewer_register([
                'complete' => true,
                'resend_available' => viewer_http_verification_resend_available(),
                'resend_url' => url_for('viewer_resend_verification'),
                'login_url' => url_for('viewer_login'),
            ]);
            return;
        }
    }

    \Gallery\Views\view_render_viewer_register([
        'complete' => false,
        'anti_automation_error' => $antiAutomationError,
        'register_url' => url_for('viewer_register'),
        'csrf_html' => viewer_csrf_field(),
        'anti_automation_html' => viewer_anti_automation_form_fields(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER),
        'resend_available' => viewer_http_verification_resend_available(),
        'resend_url' => url_for('viewer_resend_verification'),
        'login_url' => url_for('viewer_login'),
    ]);
}

/**
 * Display one scanner-safe invitation bearer and accept an invitation-backed registration request.
 */
function cms_viewer_invite(): void
{
    viewer_http_no_store();
    if (!viewer_http_invite_registration_available()) {
        viewer_render_unavailable();
        return;
    }

    if (request_method() === 'POST') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }
        $token = (string) ($_POST['token'] ?? '');
        $email = trim((string) ($_POST['email'] ?? ''));
        $notice = t('viewer.invite.request_received', 'If the invitation can be accepted, a verification message will be sent to the submitted email address.');
        try {
            $result = viewer_registration_request_begin($email, $token, request_client_ip());
            if (!empty($result['accepted'])) {
                viewer_security_event_record_best_effort('viewer.registration_requested', null, 'accepted', ['action' => 'invite_registration']);
            }
            viewer_deliver_registration_verification($email, $result, true);
        } catch (Throwable) {
            // Preserve the same public response for invalid, expired, consumed, throttled, or unavailable invitations.
        }

        \Gallery\Views\view_render_viewer_invite([
            'complete' => true,
            'notice' => $notice,
            'resend_available' => viewer_http_verification_resend_available(),
            'resend_url' => url_for('viewer_resend_verification'),
            'login_url' => url_for('viewer_login'),
        ]);
        return;
    }

    $token = (string) ($_GET['token'] ?? '');
    try {
        $invitation = viewer_invitation_inspect($token);
    } catch (Throwable) {
        $invitation = null;
    }
    if ($invitation === null) {
        viewer_render_unavailable();
        return;
    }

    \Gallery\Views\view_render_viewer_invite([
        'complete' => false,
        'email_bound' => !empty($invitation['email_bound']),
        'invite_url' => url_for('viewer_invite'),
        'csrf_html' => viewer_csrf_field(),
        'token' => $token,
    ]);
}

/**
 * Render and process the generic explicit verification-resend recovery flow.
 */
function cms_viewer_resend_verification(): void
{
    viewer_http_no_store();
    if (!viewer_http_verification_resend_available()) {
        viewer_render_unavailable();
        return;
    }

    $error = '';
    $submitted = false;
    if (request_method() === 'POST') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if (viewer_email_normalize($email) === null) {
            $error = t('viewer.resend.invalid_email', 'Enter a valid email address.');
        } else {
            $antiAutomation = viewer_anti_automation_authorize_submission(
                VIEWER_ANTI_AUTOMATION_ACTION_RESEND,
                $_POST,
                request_client_ip()
            );
            $antiAutomationResult = (string) ($antiAutomation['result'] ?? VIEWER_ANTI_AUTOMATION_RESULT_INVALID);
            if ($antiAutomationResult === VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED) {
                viewer_render_anti_automation_challenge(
                    VIEWER_ANTI_AUTOMATION_ACTION_RESEND,
                    $email,
                    'viewer_resend_verification',
                    t('viewer.resend.title', 'Request another verification message'),
                    is_array($antiAutomation['challenge'] ?? null) ? $antiAutomation['challenge'] : []
                );
                return;
            }
            if ($antiAutomationResult === VIEWER_ANTI_AUTOMATION_RESULT_INVALID) {
                $error = t(
                    'viewer.automation.retry',
                    'The local verification state expired or could not be validated. Please try again.'
                );
            } elseif ($antiAutomationResult === VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS) {
                // Keep syntactically valid suppressed submissions externally equivalent to ordinary resend completion.
                $submitted = true;
            } elseif ($antiAutomationResult === VIEWER_ANTI_AUTOMATION_RESULT_ALLOW) {
                $submitted = true;
                viewer_security_event_record_best_effort(
                    'viewer.verification_resend_requested',
                    null,
                    'received',
                    ['action' => 'verification_resend']
                );

                try {
                    $result = viewer_registration_verification_resend_prepare($email);
                    if (!empty($result['mail_eligible'])) {
                        $delivery = viewer_deliver_registration_verification_resend($result);
                        if (!empty($delivery['sent'])) {
                            viewer_security_event_record_best_effort(
                                'viewer.verification_resent',
                                null,
                                'success',
                                ['action' => 'verification_resend']
                            );
                        } else {
                            viewer_security_event_record_best_effort(
                                'viewer.verification_resend_suppressed',
                                null,
                                'suppressed',
                                [
                                    'action' => 'verification_resend',
                                    'reason' => (string) ($delivery['reason'] ?? 'suppressed'),
                                ]
                            );
                        }
                    } else {
                        viewer_security_event_record_best_effort(
                            'viewer.verification_resend_suppressed',
                            null,
                            'suppressed',
                            [
                                'action' => 'verification_resend',
                                'reason' => (string) ($result['reason'] ?? 'suppressed'),
                            ]
                        );
                    }
                } catch (Throwable) {
                    viewer_security_event_record_best_effort(
                        'viewer.verification_resend_suppressed',
                        null,
                        'suppressed',
                        ['action' => 'verification_resend', 'reason' => 'unavailable']
                    );
                }
            }
        }
    }

    \Gallery\Views\view_render_viewer_resend_verification([
        'submitted' => $submitted,
        'error' => $error,
        'resend_url' => url_for('viewer_resend_verification'),
        'login_url' => url_for('viewer_login'),
        'csrf_html' => viewer_csrf_field(),
        'anti_automation_html' => viewer_anti_automation_form_fields(VIEWER_ANTI_AUTOMATION_ACTION_RESEND),
    ]);
}

/**
 * Inspect a verification token on GET, explicitly confirm it on POST, then activate from tokenless session authority.
 */
function cms_viewer_verify(): void
{
    viewer_http_no_store();
    if (!viewer_http_registration_verification_available()) {
        viewer_render_unavailable();
        return;
    }

    $action = request_method() === 'POST' ? (string) ($_POST['viewer_verify_action'] ?? '') : '';
    if ($action === 'authorize') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }
        $token = (string) ($_POST['token'] ?? '');
        try {
            $confirmed = viewer_registration_verification_confirm($token);
        } catch (Throwable) {
            $confirmed = null;
        }
        if ($confirmed === null) {
            viewer_render_verification_unavailable();
            return;
        }
        viewer_security_event_record_best_effort('viewer.verification_confirmed', null, 'success', ['action' => 'verification_confirm']);
        redirect_to(url_for('viewer_verify'));
    }

    if ($action === 'activate') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        $error = '';
        if ($password !== $confirmation) {
            $error = t('viewer.password.confirmation_mismatch', 'The password confirmation does not match.');
        } elseif (!viewer_password_input_is_acceptable($password)) {
            $error = t('viewer.password.policy_error', 'Choose a password with at least 15 characters.');
        } else {
            try {
                $result = viewer_registration_activate_verified($password);
            } catch (Throwable) {
                $result = ['activated' => false, 'reason' => 'unavailable'];
            }
            if (!empty($result['activated'])) {
                viewer_registration_activation_clear();
                redirect_to(url_for('viewer_login', ['activated' => '1']));
            }
            $error = t('viewer.verify.activation_failed', 'The viewer account could not be activated. Reopen the verification link or start registration again if it is available.');
        }

        \Gallery\Views\view_render_viewer_verification_password([
            'error' => $error,
            'show_help' => false,
            'verify_url' => url_for('viewer_verify'),
            'csrf_html' => viewer_csrf_field(),
        ]);
        return;
    }

    $activation = viewer_registration_activation_state();
    if ($activation !== null) {
        \Gallery\Views\view_render_viewer_verification_password([
            'error' => '',
            'show_help' => true,
            'verify_url' => url_for('viewer_verify'),
            'csrf_html' => viewer_csrf_field(),
        ]);
        return;
    }

    $token = (string) ($_GET['token'] ?? '');
    try {
        $verification = viewer_registration_verification_validate($token);
    } catch (Throwable) {
        $verification = null;
    }
    if ($verification === null) {
        viewer_render_verification_unavailable();
        return;
    }

    \Gallery\Views\view_render_viewer_verification_confirm([
        'verify_url' => url_for('viewer_verify'),
        'csrf_html' => viewer_csrf_field(),
        'token' => $token,
    ]);
}

/**
 * Authenticate a viewer password and optionally issue the dedicated remember-me credential.
 */
function cms_viewer_login(): void
{
    viewer_http_no_store();
    if (!viewer_http_auth_available()) {
        viewer_render_unavailable();
        return;
    }
    if (viewer_first_login_password_state() !== null) {
        redirect_to(url_for('viewer_first_login_password'));
    }
    if (current_viewer() !== null) {
        redirect_to(url_for('viewer_account'));
    }

    $error = '';
    if (request_method() === 'POST') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }
        $email = (string) ($_POST['email'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        try {
            $result = viewer_authenticate_password($email, $password, request_client_ip());
        } catch (Throwable) {
            $result = ['authenticated' => false, 'reason' => 'unavailable', 'retry_after_seconds' => 0];
        }
        if (!empty($result['authenticated'])) {
            viewer_registration_activation_clear();
            viewer_password_reset_state_clear();
            if (!empty($result['password_change_required'])) {
                viewer_identity_remember_revoke_current_cookie();
                redirect_to(url_for('viewer_first_login_password'));
            }
            $viewer = current_viewer();
            if ($viewer !== null && !empty($_POST['remember_me'])) {
                try {
                    $credential = viewer_remember_token_issue((int) $viewer['id'], (int) $viewer['security_version']);
                    if (!viewer_identity_remember_cookie_set($credential)) {
                        viewer_remember_token_revoke((string) $credential['selector']);
                    } else {
                        viewer_security_event_record_best_effort('viewer.remember_created', (int) $viewer['id'], 'success', ['action' => 'remember_me']);
                    }
                } catch (Throwable) {
                    viewer_identity_remember_cookie_clear();
                }
            } else {
                viewer_identity_remember_revoke_current_cookie();
            }
            redirect_to(url_for('viewer_account'));
        }
        if (in_array((string) ($result['reason'] ?? ''), ['rate_limited', 'limiter_unavailable'], true)) {
            viewer_security_event_record_best_effort('viewer.login_throttled', null, 'denied', [
                'reason' => 'rate_limited',
                'retry_after_seconds' => (int) ($result['retry_after_seconds'] ?? 0),
            ]);
        }
        $error = t('viewer.login.failed', 'Sign-in failed. Check the email and password and try again.');
    }

    $notices = [];
    if ((string) ($_GET['activated'] ?? '') === '1') {
        $notices[] = t('viewer.login.activated', 'Viewer account activated. You can sign in now.');
    }
    if ((string) ($_GET['reset'] ?? '') === '1') {
        $notices[] = t('viewer.login.reset_completed', 'Password reset completed. Sign in with the new password.');
    }
    if ((string) ($_GET['password_changed'] ?? '') === '1') {
        $notices[] = t('viewer.login.password_changed', 'Password changed. Sign in again with the new password.');
    }
    if ((string) ($_GET['email_changed'] ?? '') === '1') {
        $notices[] = t('viewer.login.email_changed', 'Email changed. Sign in again with the new verified email address.');
    }

    \Gallery\Views\view_render_viewer_login([
        'notices' => $notices,
        'error' => $error,
        'csrf_html' => viewer_csrf_field(),
        'forgot_url' => url_for('viewer_forgot_password'),
        'resend_available' => viewer_http_verification_resend_available(),
        'resend_url' => url_for('viewer_resend_verification'),
        'registration_available' => viewer_http_open_registration_available(),
        'register_url' => url_for('viewer_register'),
    ]);
}

/**
 * Require administrator-provisioned viewers to replace their temporary password before normal login.
 */
function cms_viewer_first_login_password(): void
{
    viewer_http_no_store();
    if (!viewer_http_auth_available()) {
        viewer_first_login_password_state_clear();
        viewer_render_unavailable();
        return;
    }
    if (current_viewer() !== null) {
        viewer_first_login_password_state_clear();
        redirect_to(url_for('viewer_account'));
    }

    $state = viewer_first_login_password_state();
    if ($state === null) {
        redirect_to(url_for('viewer_login'));
    }

    $error = '';
    if (request_method() === 'POST') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        if ($password !== $confirmation) {
            $error = t('viewer.password.confirmation_mismatch', 'The password confirmation does not match.');
        } elseif (!viewer_password_input_is_acceptable($password)) {
            $error = t('viewer.password.policy_error', 'Choose a password with at least 15 characters.');
        } else {
            try {
                $result = viewer_first_login_password_complete($password);
            } catch (Throwable) {
                $result = ['changed' => false, 'reason' => 'unavailable'];
            }
            if (!empty($result['changed'])) {
                redirect_to(url_for('viewer_account', ['password_initialized' => '1']));
            }
            $reason = (string) ($result['reason'] ?? '');
            if ($reason === 'password_reuse') {
                $error = t('viewer.first_login.password_reuse', 'Choose a new password that is different from the temporary password.');
            } elseif ($reason === 'first_login_state_invalid') {
                viewer_first_login_password_state_clear();
                redirect_to(url_for('viewer_login'));
            } else {
                $error = t('viewer.first_login.failed', 'The new password could not be saved. Please try again or restart sign-in.');
            }
        }
    }

    \Gallery\Views\view_render_viewer_first_login_password([
        'error' => $error,
        'email' => (string) ($state['email'] ?? ''),
        'password_url' => url_for('viewer_first_login_password'),
        'logout_url' => url_for('viewer_logout'),
        'csrf_html' => viewer_csrf_field(),
    ]);
}

/**
 * Log out only the viewer principal through a viewer-CSRF-protected POST.
 */
function cms_viewer_logout(): void
{
    viewer_http_no_store();
    if (request_method() !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        viewer_render_unavailable(405);
        return;
    }
    if (!viewer_verify_csrf_or_render_error()) {
        return;
    }

    $viewer = current_viewer();
    $viewerAccountId = $viewer !== null ? (int) $viewer['id'] : null;
    $hadRemember = viewer_identity_remember_cookie_parse_request() !== null;
    viewer_identity_remember_revoke_current_cookie();
    viewer_session_revoke_current();
    viewer_clear_reauthentication();
    viewer_registration_activation_clear();
    viewer_password_reset_state_clear();
    viewer_first_login_password_state_clear();
    unset($_SESSION[viewer_csrf_namespace_key()]);
    if ($viewerAccountId !== null) {
        viewer_security_event_record_best_effort('viewer.logout', $viewerAccountId, 'success', ['action' => 'logout']);
        if ($hadRemember) {
            viewer_security_event_record_best_effort('viewer.remember_revoked', $viewerAccountId, 'success', ['action' => 'logout']);
        }
    }
    redirect_to(url_for('home'));
}

/**
 * Request a viewer password-reset email with externally generic behavior.
 */
function cms_viewer_forgot_password(): void
{
    viewer_http_no_store();
    if (!viewer_http_auth_available()) {
        viewer_render_unavailable();
        return;
    }
    if (current_viewer() !== null) {
        redirect_to(url_for('viewer_account'));
    }

    $notice = '';
    if (request_method() === 'POST') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }
        $email = trim((string) ($_POST['email'] ?? ''));
        try {
            $result = viewer_password_reset_request($email, request_client_ip());
            if (!empty($result['mail_eligible']) && !empty($result['reset_token'])) {
                $normalizedEmail = viewer_email_normalize($email);
                $resetUrl = viewer_security_url('index.php', [
                    'page' => 'viewer_reset_password',
                    'token' => (string) $result['reset_token'],
                ]);
                if ($normalizedEmail !== null && $resetUrl !== null) {
                    $subject = t('viewer.email.reset_subject', '{site} viewer password reset', ['site' => \Gallery\Services\site_name()]);
                    $body = t('viewer.email.reset_body', "A password reset was requested for your viewer account.\n\nUse this link to continue:\n{reset_url}\n\nIf you did not request this, ignore this message.", [
                        'reset_url' => $resetUrl,
                    ]);
                    viewer_send_security_mail($normalizedEmail, $subject, $body, (string) ($result['expires_at'] ?? ''));
                }
            }
        } catch (Throwable) {
            // Preserve externally equivalent known/unknown/unavailable account responses.
        }
        $notice = t('viewer.forgot.request_received', 'If the viewer account can receive a reset message, a password-reset link has been sent.');
    }

    \Gallery\Views\view_render_viewer_forgot_password([
        'notice' => $notice,
        'csrf_html' => viewer_csrf_field(),
        'login_url' => url_for('viewer_login'),
    ]);
}

/**
 * Inspect a reset link on GET, exchange it on explicit POST, then complete from tokenless session authority.
 */
function cms_viewer_reset_password(): void
{
    viewer_http_no_store();
    if (!viewer_http_auth_available()) {
        viewer_render_unavailable();
        return;
    }

    $action = request_method() === 'POST' ? (string) ($_POST['viewer_reset_action'] ?? '') : '';
    if ($action === 'authorize') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }
        try {
            $authorized = viewer_password_reset_authorize((string) ($_POST['token'] ?? ''));
        } catch (Throwable) {
            $authorized = false;
        }
        if (!$authorized) {
            viewer_render_unavailable();
            return;
        }
        redirect_to(url_for('viewer_reset_password'));
    }

    if ($action === 'complete') {
        if (!viewer_verify_csrf_or_render_error()) {
            return;
        }
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        $error = '';
        if ($password !== $confirmation) {
            $error = t('viewer.password.confirmation_mismatch', 'The password confirmation does not match.');
        } elseif (!viewer_password_input_is_acceptable($password)) {
            $error = t('viewer.password.policy_error', 'Choose a password with at least 15 characters.');
        } else {
            try {
                $result = viewer_password_reset_complete($password);
            } catch (Throwable) {
                $result = ['reset' => false, 'reason' => 'unavailable'];
            }
            if (!empty($result['reset'])) {
                viewer_identity_remember_cookie_clear();
                redirect_to(url_for('viewer_login', ['reset' => '1']));
            }
            $error = t('viewer.reset.failed', 'The password reset could not be completed. Request a new reset link and try again.');
        }

        \Gallery\Views\view_render_viewer_reset_password_form([
            'error' => $error,
            'reset_url' => url_for('viewer_reset_password'),
            'csrf_html' => viewer_csrf_field(),
        ]);
        return;
    }

    if (viewer_password_reset_state() !== null) {
        \Gallery\Views\view_render_viewer_reset_password_form([
            'error' => '',
            'reset_url' => url_for('viewer_reset_password'),
            'csrf_html' => viewer_csrf_field(),
        ]);
        return;
    }

    $token = (string) ($_GET['token'] ?? '');
    try {
        $reset = viewer_password_reset_inspect($token);
    } catch (Throwable) {
        $reset = null;
    }
    if ($reset === null) {
        viewer_render_unavailable();
        return;
    }

    \Gallery\Views\view_render_viewer_reset_authorize([
        'reset_url' => url_for('viewer_reset_password'),
        'csrf_html' => viewer_csrf_field(),
        'token' => $token,
    ]);
}

/**
 * Render the minimal authenticated viewer landing page.
 */
function cms_viewer_account(): void
{
    viewer_http_no_store();
    if (!viewer_http_auth_available()) {
        viewer_render_unavailable();
        return;
    }
    $viewer = current_viewer();
    if ($viewer === null) {
        redirect_to(url_for('viewer_login'));
    }

    \Gallery\Views\view_render_viewer_account([
        'password_initialized' => (string) ($_GET['password_initialized'] ?? '') === '1',
        'email' => (string) ($viewer['email'] ?? ''),
        'favourites_url' => url_for('viewer_favourites'),
        'collections_url' => url_for('viewer_collections'),
        'password_url' => url_for('viewer_account_password'),
        'email_url' => url_for('viewer_account_email'),
        'delete_url' => url_for('viewer_account_delete'),
        'logout_url' => url_for('viewer_logout'),
        'csrf_html' => viewer_csrf_field(),
    ]);
}
