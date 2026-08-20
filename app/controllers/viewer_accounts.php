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
use function Gallery\Services\viewer_remember_cookie_clear;
use function Gallery\Services\viewer_remember_cookie_parse;
use function Gallery\Services\viewer_remember_cookie_set;
use function Gallery\Services\viewer_remember_revoke_current_cookie;
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
    return '<input type="hidden" name="viewer_csrf_token" value="' . e(viewer_csrf_token()) . '">';
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
    render_header(t('viewer.common.invalid_request_title', 'Invalid request'));
    echo '<section class="panel"><h1>' . e(t('viewer.common.invalid_request_title', 'Invalid request')) . '</h1>';
    echo '<p>' . e(t('viewer.common.invalid_request_message', 'The request could not be verified. Please reopen the page and try again.')) . '</p></section>';
    render_footer();
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
    $state = viewer_anti_automation_form_issue($action);
    return '<input type="hidden" name="viewer_aa_form_ticket" value="' . e((string) $state['ticket']) . '">'
        . '<div hidden aria-hidden="true">'
        . '<input type="text" name="' . e((string) $state['honeypot_field']) . '" value="" tabindex="-1" autocomplete="off">'
        . '</div>';
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

    render_header($pageTitle);
    echo '<section class="panel"><h1>' . e(t('viewer.automation.title', 'Additional verification')) . '</h1>';
    echo '<p>' . e(t('viewer.automation.message', 'This site is performing a local anti-automation check. No external service is used.')) . '</p>';
    echo '<form method="post" action="' . e(url_for($pageName)) . '" class="form-grid" data-viewer-anti-automation'
        . ' data-viewer-aa-action="' . e($action) . '"'
        . ' data-viewer-aa-challenge="' . e($nonce) . '"'
        . ' data-viewer-aa-difficulty="' . $difficulty . '"'
        . ' data-viewer-aa-max-counter="' . $maxCounter . '"'
        . ' data-viewer-aa-ready="' . e(t('viewer.automation.ready', 'Local verification is ready. Continue to submit your request.')) . '"'
        . ' data-viewer-aa-failed="' . e(t('viewer.automation.failed', 'Automatic local verification is unavailable. Use the first-party fallback below.')) . '">';
    echo viewer_csrf_field();
    echo '<input type="hidden" name="email" value="' . e($email) . '">';
    echo '<input type="hidden" name="viewer_aa_challenge_ticket" value="' . e($ticket) . '">';
    echo '<input type="hidden" name="viewer_aa_pow_counter" value="" data-viewer-aa-counter>';
    echo '<p class="muted" id="viewer-aa-status" role="status" aria-live="polite" data-viewer-aa-status>' . e(t('viewer.automation.progress', 'Performing local verification...')) . '</p>';
    echo '<progress data-viewer-aa-progress max="100" value="0" aria-describedby="viewer-aa-status"></progress>';
    echo '<button type="submit" data-viewer-aa-continue disabled>' . e(t('viewer.automation.continue', 'Continue')) . '</button>';
    echo '<div hidden data-viewer-aa-fallback><p class="muted">' . e(t('viewer.automation.fallback_help', 'If the browser calculation is unavailable, use the first-party fallback.')) . '</p>';
    echo '<button type="submit" class="button secondary" name="viewer_aa_fallback" value="1">' . e(t('viewer.automation.fallback', 'Continue without browser calculation')) . '</button></div>';
    echo '<noscript><p class="muted">' . e(t('viewer.automation.fallback_help', 'If the browser calculation is unavailable, use the first-party fallback.')) . '</p>';
    echo '<button type="submit" class="button secondary" name="viewer_aa_fallback" value="1">' . e(t('viewer.automation.fallback', 'Continue without browser calculation')) . '</button></noscript>';
    echo '</form></section>';
    $scriptPath = dirname(__DIR__, 2) . '/public/assets/viewer-anti-automation.js';
    echo '<script src="' . e(asset_url('assets/viewer-anti-automation.js')) . '?v=' . (is_file($scriptPath) ? filemtime($scriptPath) : time()) . '"></script>';
    render_footer();
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
    render_header(t('viewer.common.unavailable_title', 'Viewer account unavailable'));
    echo '<section class="panel"><h1>' . e(t('viewer.common.unavailable_title', 'Viewer account unavailable')) . '</h1>';
    echo '<p>' . e(t('viewer.common.unavailable_message', 'This viewer account operation is unavailable.')) . '</p></section>';
    render_footer();
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
    if (!viewer_http_verification_resend_available()) {
        return;
    }

    echo '<p class="muted">' . e(t('viewer.resend.prompt', "Didn't receive the verification email?")) . ' ';
    echo '<a href="' . e(url_for('viewer_resend_verification')) . '">' . e(t('viewer.resend.link', 'Request another verification message')) . '</a></p>';
}

/**
 * Render an invalid/expired verification result with generic resend recovery when available.
 */
function viewer_render_verification_unavailable(): void
{
    http_response_code(404);
    viewer_http_no_store();
    render_header(t('viewer.verify.invalid_title', 'Verification link unavailable'));
    echo '<section class="panel"><h1>' . e(t('viewer.verify.invalid_title', 'Verification link unavailable')) . '</h1>';
    echo '<p>' . e(t('viewer.verify.invalid_message', 'This verification link is invalid, expired, or no longer usable. You can request another message if the staged registration is still eligible.')) . '</p>';
    viewer_render_verification_resend_link();
    echo '<p><a class="button secondary" href="' . e(url_for('viewer_login')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
    render_footer();
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
    echo '<p class="muted">' . e(t('viewer.password.policy_hint', 'Use at least 15 characters.')) . '</p>';
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
    echo '<tr><th>' . e(t('viewer.admin.security.master_feature', 'Viewer Accounts master feature')) . '</th><td>' . e(viewer_admin_security_operations_enabled_label(!empty($status['master_feature_enabled']))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.registration_mode', 'Effective registration mode')) . '</th><td>' . e(viewer_admin_security_operations_mode_label((string) ($status['registration_mode'] ?? 'disabled'))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.open_http', 'Open registration HTTP')) . '</th><td>' . e(viewer_admin_security_operations_status_label(!empty($status['open_registration_http_available']) ? 'available' : 'unavailable')) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.resend_http', 'Verification resend HTTP')) . '</th><td>' . e(viewer_admin_security_operations_status_label(!empty($status['verification_resend_http_available']) ? 'available' : 'unavailable')) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.anti_automation', 'First-party anti-automation')) . '</th><td>' . e(viewer_admin_security_operations_enabled_label(!empty($antiAutomation['enabled']))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.pow_difficulty', 'Local proof difficulty')) . '</th><td>' . e((string) ($antiAutomation['pow_min_bits'] ?? 0) . '-' . (string) ($antiAutomation['pow_max_bits'] ?? 0) . ' ' . t('viewer.admin.security.bits', 'bits')) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.form_lifetime', 'Form ticket lifetime')) . '</th><td>' . e(t('viewer.admin.security.seconds_value', '{count} seconds', ['count' => (string) ($antiAutomation['form_lifetime_seconds'] ?? 0)])) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.auth_storage', 'Viewer auth storage')) . '</th><td>' . e(viewer_admin_security_operations_status_label((string) ($storage['viewer_auth'] ?? 'unknown'))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.registration_storage', 'Viewer registration storage')) . '</th><td>' . e(viewer_admin_security_operations_status_label((string) ($storage['viewer_registration'] ?? 'unknown'))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.event_storage', 'Viewer security-event storage')) . '</th><td>' . e(viewer_admin_security_operations_status_label((string) ($storage['viewer_security_events'] ?? 'unknown'))) . '</td></tr>';
    echo '<tr><th>' . e(t('viewer.admin.security.rate_storage', 'Viewer rate-limit storage')) . '</th><td>' . e(viewer_admin_security_operations_status_label((string) ($storage['viewer_rate_limits'] ?? 'unknown'))) . '</td></tr>';
    echo '</tbody></table></div>';

    echo '<h3>' . e(t('viewer.admin.security.capacity', 'Capacity')) . '</h3>';
    echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('viewer.admin.security.resource', 'Resource')) . '</th><th>' . e(t('viewer.admin.security.current', 'Current')) . '</th><th>' . e(t('viewer.admin.security.hard_cap', 'Hard cap')) . '</th></tr></thead><tbody>';
    $accountCount = $accountCapacity['current_count'] ?? null;
    $accountCurrent = $accountCount === null
        ? viewer_admin_security_operations_status_label((string) ($accountCapacity['status'] ?? 'unknown'))
        : (string) (int) $accountCount;
    $registrationCount = $registrationCapacity['current_count'] ?? null;
    $registrationCurrent = $registrationCount === null
        ? viewer_admin_security_operations_status_label((string) ($registrationCapacity['status'] ?? 'unknown'))
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
            echo '<p class="muted">' . e(viewer_admin_security_operations_status_label($eventStatus)) . '</p>';
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
        echo '<p class="muted">' . e(viewer_admin_security_operations_status_label($eventStatus)) . '</p>';
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
        echo '<p class="muted">' . e(viewer_admin_security_operations_status_label($rateStatus)) . '</p>';
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
                echo '<tr><td>' . e(viewer_admin_security_operations_bucket_label($bucket)) . '</td>';
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
        echo '<p class="muted">' . e(viewer_admin_security_operations_status_label($rateStatus)) . '</p>';
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
    try {
        $viewerAccounts = viewer_admin_account_storage_available() ? viewer_admin_account_list(250) : [];
        if (!viewer_admin_account_storage_available()) {
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

    $registrationMode = viewer_registration_mode();
    echo '<section class="panel"><h1>' . e(t('viewer.admin.invites.mode_title', 'Viewer accounts')) . '</h1>';
    echo '<p>' . e(t('viewer.admin.invites.mode_help', 'Choose how new viewer registrations are admitted. The global Viewer Accounts switch in Admin Features remains the outer master switch.')) . '</p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
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
        viewer_render_admin_security_operations($viewerSecurityOperations);
    } else {
        echo '<section class="panel"><h2>' . e(t('viewer.admin.security.title', 'Viewer security status')) . '</h2>';
        echo '<p class="muted">' . e(t('viewer.admin.security.operations_unavailable', 'Viewer security operations are currently unavailable. Viewer registration and authentication policy are unchanged.')) . '</p></section>';
    }

    echo '<section class="panel"><h1>' . e(t('viewer.admin.accounts.add_title', 'Add viewer account')) . '</h1>';
    echo '<p class="muted">' . e(t('viewer.admin.accounts.add_help', 'Create a verified viewer account immediately. The temporary password cannot establish normal viewer access until the user replaces it after the first successful sign-in. This works even while the viewer frontend is disabled.')) . '</p>';
    if (viewer_admin_account_storage_available()) {
        echo '<form method="post" class="form-grid">' . csrf_field();
        echo '<input type="hidden" name="viewer_invitation_action" value="create_account">';
        echo '<label>' . e(t('viewer.admin.accounts.email', 'Viewer email')) . '<input type="email" name="viewer_account_email" required autocomplete="off"></label>';
        echo '<label>' . e(t('viewer.admin.accounts.temporary_password', 'Temporary password')) . '<input type="password" name="viewer_account_temporary_password" minlength="15" autocomplete="new-password"></label>';
        echo '<p class="muted">' . e(t('viewer.admin.accounts.temporary_password_help', 'Leave blank to generate a high-entropy temporary password. The result is shown once after creation.')) . '</p>';
        echo '<label class="checkbox-label"><input type="checkbox" name="viewer_account_send_notification" value="1" checked> ' . e(t('viewer.admin.accounts.send_notification', 'Send the user an account-created notification (temporary password is never emailed)')) . '</label>';
        viewer_render_password_policy_hint();
        echo '<button type="submit">' . e(t('viewer.admin.accounts.add_button', 'Add viewer account')) . '</button></form>';
        echo '<p class="muted">' . e(t('viewer.admin.accounts.capacity_hint', 'Viewer accounts are capped at {count}; capacity is locked and rechecked during direct creation.', ['count' => (string) viewer_account_cap()])) . '</p>';
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
            $accountStatus = (string) ($account['status'] ?? '');
            $viewerAccountId = (int) ($account['id'] ?? 0);
            echo '<tr><td>' . e((string) ($account['email'] ?? '')) . '</td>';
            echo '<td>' . e(viewer_admin_account_status_label($accountStatus)) . '</td>';
            echo '<td>' . e(!empty($account['must_change_password'])
                ? t('viewer.admin.accounts.password_change_required', 'Change required on first login')
                : t('viewer.admin.accounts.password_set', 'Set')) . '</td>';
            echo '<td>' . e((string) ($account['created_at'] ?? '')) . '</td>';
            $lastLogin = trim((string) ($account['last_login_at'] ?? ''));
            echo '<td>' . e($lastLogin !== '' ? $lastLogin : t('viewer.admin.accounts.never', 'Never')) . '</td><td>';
            if ($accountStatus === VIEWER_ACCOUNT_STATUS_ACTIVE) {
                echo '<form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.admin.accounts.suspend_confirm', 'Suspend this viewer account? Existing viewer sessions and persistent login authority will be revoked.')) . '">' . csrf_field();
                echo '<input type="hidden" name="viewer_invitation_action" value="suspend_account"><input type="hidden" name="viewer_account_id" value="' . $viewerAccountId . '">';
                echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.accounts.suspend_button', 'Suspend')) . '</button></form> ';
                echo '<form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.admin.accounts.sign_out_everywhere_confirm', 'Sign this viewer out on all devices? The account will remain active.')) . '">' . csrf_field();
                echo '<input type="hidden" name="viewer_invitation_action" value="revoke_sessions"><input type="hidden" name="viewer_account_id" value="' . $viewerAccountId . '">';
                echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.accounts.sign_out_everywhere_button', 'Sign out everywhere')) . '</button></form> ';
            } elseif ($accountStatus === VIEWER_ACCOUNT_STATUS_SUSPENDED) {
                echo '<form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.admin.accounts.restore_confirm', 'Restore this viewer account? Previously revoked sessions and tokens will remain invalid.')) . '">' . csrf_field();
                echo '<input type="hidden" name="viewer_invitation_action" value="restore_account"><input type="hidden" name="viewer_account_id" value="' . $viewerAccountId . '">';
                echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.accounts.restore_button', 'Restore')) . '</button></form> ';
            }
            echo '<form method="post" class="inline-form" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('viewer.admin.accounts.delete_confirm', 'Delete this viewer account? Viewer-owned account data will be removed. Photographs, galleries, gallery shares, and administrator accounts will not be deleted.')) . '">' . csrf_field();
            echo '<input type="hidden" name="viewer_invitation_action" value="delete_account"><input type="hidden" name="viewer_account_id" value="' . $viewerAccountId . '">';
            echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.accounts.delete_button', 'Delete account')) . '</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="muted">' . e(t('viewer.admin.accounts.delete_help', 'Deleting a viewer account removes only viewer-owned account state through existing lifecycle relationships. It does not delete photographs, galleries, gallery shares, or administrator accounts.')) . '</p>';
    }
    echo '</section>';

    echo '<section class="panel"><h1>' . e(t('viewer.admin.invites.title', 'Viewer invitations')) . '</h1>';
    if (viewer_registration_requests_enabled()) {
        echo '<p class="muted">' . e(t('viewer.admin.invites.create_help', 'Create an administrator invitation link. No viewer account is created until the recipient verifies email and chooses a password.')) . '</p>';
        echo '<form method="post" class="form-grid">' . csrf_field();
        echo '<input type="hidden" name="viewer_invitation_action" value="create">';
        echo '<label>' . e(t('viewer.admin.invites.intended_email', 'Intended email (optional)')) . '<input type="email" name="target_email" autocomplete="off"></label>';
        echo '<button type="submit">' . e(t('viewer.admin.invites.create_button', 'Create invitation')) . '</button></form>';
        echo '<p class="muted">' . e(t('viewer.admin.invites.capacity_hint', 'Viewer accounts are capped at {count}; capacity is rechecked atomically during activation.', ['count' => (string) viewer_account_cap()])) . '</p>';
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
                echo '<form method="post" class="inline-form">' . csrf_field();
                echo '<input type="hidden" name="viewer_invitation_action" value="revoke"><input type="hidden" name="invitation_id" value="' . (int) $invitation['id'] . '">';
                echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.invites.revoke_button', 'Revoke')) . '</button></form> ';
            }
            echo '<form method="post" class="inline-form">' . csrf_field();
            echo '<input type="hidden" name="viewer_invitation_action" value="delete"><input type="hidden" name="invitation_id" value="' . (int) $invitation['id'] . '">';
            echo '<button type="submit" class="button secondary">' . e(t('viewer.admin.invites.delete_button', 'Delete')) . '</button></form>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
    render_footer();
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
            render_header(t('viewer.register.title', 'Create viewer account'));
            echo '<section class="panel"><h1>' . e(t('viewer.register.title', 'Create viewer account')) . '</h1>';
            echo '<div class="notice">' . e(t('viewer.register.request_received', 'If the registration request can be accepted, a verification message will be sent to the submitted email address.')) . '</div>';
            viewer_render_verification_resend_link();
            echo '<p><a class="button secondary" href="' . e(url_for('viewer_login')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
            render_footer();
            return;
        }
    }

    render_header(t('viewer.register.title', 'Create viewer account'));
    echo '<section class="panel"><h1>' . e(t('viewer.register.title', 'Create viewer account')) . '</h1>';
    if ($antiAutomationError !== '') {
        echo '<div class="notice">' . e($antiAutomationError) . '</div>';
    }
    echo '<p>' . e(t('viewer.register.help', 'Enter your email address. If registration can proceed, a verification message will be sent. No viewer account is created until you confirm the email and choose a password.')) . '</p>';
    echo '<form method="post" action="' . e(url_for('viewer_register')) . '" class="form-grid">' . viewer_csrf_field();
    echo viewer_anti_automation_form_fields(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER);
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="email"></label>';
    echo '<button type="submit">' . e(t('viewer.register.button', 'Send verification email')) . '</button></form>';
    viewer_render_verification_resend_link();
    echo '<p class="muted"><a href="' . e(url_for('viewer_login')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
    render_footer();
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

        render_header(t('viewer.invite.title', 'Viewer invitation'));
        echo '<section class="panel"><h1>' . e(t('viewer.invite.title', 'Viewer invitation')) . '</h1><div class="notice">' . e($notice) . '</div>';
        viewer_render_verification_resend_link();
        echo '<p><a class="button secondary" href="' . e(url_for('viewer_login')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
        render_footer();
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

    render_header(t('viewer.invite.title', 'Viewer invitation'));
    echo '<section class="panel"><h1>' . e(t('viewer.invite.title', 'Viewer invitation')) . '</h1>';
    echo '<p>' . e(!empty($invitation['email_bound'])
        ? t('viewer.invite.bound_help', 'Enter the email address this invitation was issued for. A verification email is required before any account can be created.')
        : t('viewer.invite.help', 'Enter the email address you want to verify for this viewer account. A verification email is required before any account can be created.')) . '</p>';
    echo '<form method="post" action="' . e(url_for('viewer_invite')) . '" class="form-grid">' . viewer_csrf_field();
    echo '<input type="hidden" name="token" value="' . e($token) . '">';
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="email"></label>';
    echo '<button type="submit">' . e(t('viewer.invite.continue_button', 'Send verification email')) . '</button></form></section>';
    render_footer();
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

    render_header(t('viewer.resend.title', 'Request another verification message'));
    echo '<section class="panel"><h1>' . e(t('viewer.resend.title', 'Request another verification message')) . '</h1>';
    if ($submitted) {
        echo '<div class="notice">' . e(t('viewer.resend.request_received', 'If a verification message can be sent for this address, it will be sent.')) . '</div>';
        echo '<p><a class="button secondary" href="' . e(url_for('viewer_login')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
        render_footer();
        return;
    }

    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<p>' . e(t('viewer.resend.help', 'Enter the email address used for the staged viewer registration. The response does not reveal whether a matching registration exists.')) . '</p>';
    echo '<form method="post" action="' . e(url_for('viewer_resend_verification')) . '" class="form-grid">' . viewer_csrf_field();
    echo viewer_anti_automation_form_fields(VIEWER_ANTI_AUTOMATION_ACTION_RESEND);
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="email"></label>';
    echo '<button type="submit">' . e(t('viewer.resend.button', 'Request verification message')) . '</button></form>';
    echo '<p class="muted"><a href="' . e(url_for('viewer_login')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
    render_footer();
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

        render_header(t('viewer.verify.choose_password_title', 'Choose viewer password'));
        echo '<section class="panel"><h1>' . e(t('viewer.verify.choose_password_title', 'Choose viewer password')) . '</h1><div class="notice">' . e($error) . '</div>';
        echo '<form method="post" action="' . e(url_for('viewer_verify')) . '" class="form-grid">' . viewer_csrf_field();
        echo '<input type="hidden" name="viewer_verify_action" value="activate">';
        echo '<label>' . e(t('viewer.common.password', 'Password')) . '<input type="password" name="password" required minlength="15" autocomplete="new-password"></label>';
        echo '<label>' . e(t('viewer.common.confirm_password', 'Confirm password')) . '<input type="password" name="password_confirmation" required minlength="15" autocomplete="new-password"></label>';
        viewer_render_password_policy_hint();
        echo '<button type="submit">' . e(t('viewer.verify.activate_button', 'Activate viewer account')) . '</button></form></section>';
        render_footer();
        return;
    }

    $activation = viewer_registration_activation_state();
    if ($activation !== null) {
        render_header(t('viewer.verify.choose_password_title', 'Choose viewer password'));
        echo '<section class="panel"><h1>' . e(t('viewer.verify.choose_password_title', 'Choose viewer password')) . '</h1>';
        echo '<p>' . e(t('viewer.verify.password_help', 'Email ownership is verified. Choose the password that will protect this viewer account.')) . '</p>';
        echo '<form method="post" action="' . e(url_for('viewer_verify')) . '" class="form-grid">' . viewer_csrf_field();
        echo '<input type="hidden" name="viewer_verify_action" value="activate">';
        echo '<label>' . e(t('viewer.common.password', 'Password')) . '<input type="password" name="password" required minlength="15" autocomplete="new-password"></label>';
        echo '<label>' . e(t('viewer.common.confirm_password', 'Confirm password')) . '<input type="password" name="password_confirmation" required minlength="15" autocomplete="new-password"></label>';
        viewer_render_password_policy_hint();
        echo '<button type="submit">' . e(t('viewer.verify.activate_button', 'Activate viewer account')) . '</button></form></section>';
        render_footer();
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

    render_header(t('viewer.verify.title', 'Verify viewer account'));
    echo '<section class="panel"><h1>' . e(t('viewer.verify.title', 'Verify viewer account')) . '</h1>';
    echo '<p>' . e(t('viewer.verify.confirm_help', 'Confirm this email address for the viewer account. This GET request has not consumed the verification token or activated an account.')) . '</p>';
    echo '<form method="post" action="' . e(url_for('viewer_verify')) . '" class="form-grid">' . viewer_csrf_field();
    echo '<input type="hidden" name="viewer_verify_action" value="authorize"><input type="hidden" name="token" value="' . e($token) . '">';
    echo '<button type="submit">' . e(t('viewer.verify.confirm_button', 'Confirm email and continue')) . '</button></form></section>';
    render_footer();
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
                viewer_remember_revoke_current_cookie();
                redirect_to(url_for('viewer_first_login_password'));
            }
            $viewer = current_viewer();
            if ($viewer !== null && !empty($_POST['remember_me'])) {
                try {
                    $credential = viewer_remember_token_issue((int) $viewer['id'], (int) $viewer['security_version']);
                    if (!viewer_remember_cookie_set($credential)) {
                        viewer_remember_token_revoke((string) $credential['selector']);
                    } else {
                        viewer_security_event_record_best_effort('viewer.remember_created', (int) $viewer['id'], 'success', ['action' => 'remember_me']);
                    }
                } catch (Throwable) {
                    viewer_remember_cookie_clear();
                }
            } else {
                viewer_remember_revoke_current_cookie();
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

    render_header(t('viewer.login.title', 'Viewer login'));
    if ((string) ($_GET['activated'] ?? '') === '1') {
        echo '<div class="notice">' . e(t('viewer.login.activated', 'Viewer account activated. You can sign in now.')) . '</div>';
    }
    if ((string) ($_GET['reset'] ?? '') === '1') {
        echo '<div class="notice">' . e(t('viewer.login.reset_completed', 'Password reset completed. Sign in with the new password.')) . '</div>';
    }
    if ((string) ($_GET['password_changed'] ?? '') === '1') {
        echo '<div class="notice">' . e(t('viewer.login.password_changed', 'Password changed. Sign in again with the new password.')) . '</div>';
    }
    if ((string) ($_GET['email_changed'] ?? '') === '1') {
        echo '<div class="notice">' . e(t('viewer.login.email_changed', 'Email changed. Sign in again with the new verified email address.')) . '</div>';
    }
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.login.title', 'Viewer login')) . '</h1>';
    echo '<form method="post" class="form-grid">' . viewer_csrf_field();
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="username"></label>';
    echo '<label>' . e(t('viewer.common.password', 'Password')) . '<input type="password" name="password" required autocomplete="current-password"></label>';
    echo '<label class="account-settings-toggle account-settings-compact-toggle"><input type="checkbox" name="remember_me" value="1"> <span><strong>' . e(t('viewer.login.remember_me', 'Remember me')) . '</strong><small>' . e(t('viewer.login.remember_help', 'Uses a dedicated rotating viewer token. It does not create administrator authentication or recent reauthentication.')) . '</small></span></label>';
    echo '<button type="submit">' . e(t('viewer.login.button', 'Sign in')) . '</button></form>';
    echo '<p class="muted"><a href="' . e(url_for('viewer_forgot_password')) . '">' . e(t('viewer.login.forgot_password', 'Forgot password?')) . '</a></p>';
    viewer_render_verification_resend_link();
    if (viewer_http_open_registration_available()) {
        echo '<p class="muted"><a href="' . e(url_for('viewer_register')) . '">' . e(t('viewer.login.register_link', 'Create viewer account')) . '</a></p>';
    }
    echo '</section>';
    render_footer();
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

    render_header(t('viewer.first_login.title', 'Choose a new viewer password'));
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.first_login.title', 'Choose a new viewer password')) . '</h1>';
    echo '<p>' . e(t('viewer.first_login.help', 'Your administrator-created account is using a temporary password. Choose a new password before the normal viewer session can start.')) . '</p>';
    echo '<p class="muted">' . e(t('viewer.first_login.signed_in_as', 'Account: {email}', ['email' => (string) ($state['email'] ?? '')])) . '</p>';
    echo '<form method="post" action="' . e(url_for('viewer_first_login_password')) . '" class="form-grid">' . viewer_csrf_field();
    echo '<label>' . e(t('viewer.first_login.new_password', 'New password')) . '<input type="password" name="password" required minlength="15" autocomplete="new-password"></label>';
    echo '<label>' . e(t('viewer.first_login.confirm_password', 'Confirm new password')) . '<input type="password" name="password_confirmation" required minlength="15" autocomplete="new-password"></label>';
    viewer_render_password_policy_hint();
    echo '<button type="submit">' . e(t('viewer.first_login.save_button', 'Save password and continue')) . '</button></form>';
    echo '<form method="post" action="' . e(url_for('viewer_logout')) . '">' . viewer_csrf_field();
    echo '<button type="submit" class="button secondary">' . e(t('viewer.first_login.cancel_button', 'Cancel sign-in')) . '</button></form>';
    echo '</section>';
    render_footer();
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
    $hadRemember = viewer_remember_cookie_parse() !== null;
    viewer_remember_revoke_current_cookie();
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

    render_header(t('viewer.forgot.title', 'Reset viewer password'));
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.forgot.title', 'Reset viewer password')) . '</h1>';
    echo '<p class="muted">' . e(t('viewer.forgot.help', 'Enter the verified viewer email address. The response is the same whether or not an eligible account exists.')) . '</p>';
    echo '<form method="post" class="form-grid">' . viewer_csrf_field();
    echo '<label>' . e(t('viewer.common.email', 'Email')) . '<input type="email" name="email" required autocomplete="email"></label>';
    echo '<button type="submit">' . e(t('viewer.forgot.button', 'Request reset link')) . '</button></form>';
    echo '<p class="muted"><a href="' . e(url_for('viewer_login')) . '">' . e(t('viewer.common.back_to_login', 'Back to viewer login')) . '</a></p></section>';
    render_footer();
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
                viewer_remember_cookie_clear();
                redirect_to(url_for('viewer_login', ['reset' => '1']));
            }
            $error = t('viewer.reset.failed', 'The password reset could not be completed. Request a new reset link and try again.');
        }

        render_header(t('viewer.reset.choose_password_title', 'Choose a new viewer password'));
        echo '<section class="panel"><h1>' . e(t('viewer.reset.choose_password_title', 'Choose a new viewer password')) . '</h1><div class="notice">' . e($error) . '</div>';
        echo '<form method="post" action="' . e(url_for('viewer_reset_password')) . '" class="form-grid">' . viewer_csrf_field();
        echo '<input type="hidden" name="viewer_reset_action" value="complete">';
        echo '<label>' . e(t('viewer.common.password', 'Password')) . '<input type="password" name="password" required minlength="15" autocomplete="new-password"></label>';
        echo '<label>' . e(t('viewer.common.confirm_password', 'Confirm password')) . '<input type="password" name="password_confirmation" required minlength="15" autocomplete="new-password"></label>';
        viewer_render_password_policy_hint();
        echo '<button type="submit">' . e(t('viewer.reset.save_button', 'Save new password')) . '</button></form></section>';
        render_footer();
        return;
    }

    if (viewer_password_reset_state() !== null) {
        render_header(t('viewer.reset.choose_password_title', 'Choose a new viewer password'));
        echo '<section class="panel"><h1>' . e(t('viewer.reset.choose_password_title', 'Choose a new viewer password')) . '</h1>';
        echo '<form method="post" action="' . e(url_for('viewer_reset_password')) . '" class="form-grid">' . viewer_csrf_field();
        echo '<input type="hidden" name="viewer_reset_action" value="complete">';
        echo '<label>' . e(t('viewer.common.password', 'Password')) . '<input type="password" name="password" required minlength="15" autocomplete="new-password"></label>';
        echo '<label>' . e(t('viewer.common.confirm_password', 'Confirm password')) . '<input type="password" name="password_confirmation" required minlength="15" autocomplete="new-password"></label>';
        viewer_render_password_policy_hint();
        echo '<button type="submit">' . e(t('viewer.reset.save_button', 'Save new password')) . '</button></form></section>';
        render_footer();
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

    render_header(t('viewer.reset.title', 'Viewer password reset'));
    echo '<section class="panel"><h1>' . e(t('viewer.reset.title', 'Viewer password reset')) . '</h1>';
    echo '<p>' . e(t('viewer.reset.confirm_help', 'Continue to authorize this browser to choose a new viewer password. This GET request has not consumed the reset token.')) . '</p>';
    echo '<form method="post" action="' . e(url_for('viewer_reset_password')) . '" class="form-grid">' . viewer_csrf_field();
    echo '<input type="hidden" name="viewer_reset_action" value="authorize"><input type="hidden" name="token" value="' . e($token) . '">';
    echo '<button type="submit">' . e(t('viewer.reset.continue_button', 'Continue password reset')) . '</button></form></section>';
    render_footer();
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

    render_header(t('viewer.account.title', 'Viewer account'));
    if ((string) ($_GET['password_initialized'] ?? '') === '1') {
        echo '<div class="notice">' . e(t('viewer.first_login.completed', 'Temporary password replaced. Your normal viewer session is now active.')) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('viewer.account.title', 'Viewer account')) . '</h1>';
    echo '<p>' . e(t('viewer.account.signed_in_as', 'Signed in as {email}', ['email' => (string) $viewer['email']])) . '</p>';
    echo '<div class="button-row">';
    echo '<a class="button" href="' . e(url_for('viewer_favourites')) . '">' . e(t('viewer.favourites.open', 'Open favourites')) . '</a>';
    echo '<a class="button" href="' . e(url_for('viewer_collections')) . '">' . e(t('viewer.collections.open', 'Open collections')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('viewer_account_password')) . '">' . e(t('viewer.account.change_password', 'Change password')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('viewer_account_email')) . '">' . e(t('viewer.account.change_email', 'Change email')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('viewer_account_delete')) . '">' . e(t('viewer.account.delete', 'Delete account')) . '</a>';
    echo '</div>';
    echo '<form method="post" action="' . e(url_for('viewer_logout')) . '">' . viewer_csrf_field();
    echo '<button type="submit" class="button secondary">' . e(t('viewer.account.logout_button', 'Log out')) . '</button></form>';
    echo '<p class="muted">' . e(t('viewer.account.phase_note', 'Collection sharing and public viewer profiles are not available.')) . '</p></section>';
    render_footer();
}
