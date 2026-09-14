<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_auth.php
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

use Throwable;
use Gallery\Services\AuthenticationSchemaUnavailableException;
use const Gallery\Services\OPENAI_TEXT_ASSIST_DEFAULT_MODEL;
use function Gallery\Core\absolute_public_url;
use function Gallery\Core\cms_config;
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_login_return_target;
use function Gallery\Core\current_user;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\sanitize_login_return_target;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\visitor_hash;
use function Gallery\Services\app_setting;
use function Gallery\Services\auth_account_cleanup_password_reset_tokens;
use function Gallery\Services\auth_account_complete_password_reset;
use function Gallery\Services\auth_account_email_taken;
use function Gallery\Services\auth_account_find_by_email;
use function Gallery\Services\auth_account_find_by_username;
use function Gallery\Services\auth_account_find_valid_password_reset;
use function Gallery\Services\auth_account_normalize_email;
use function Gallery\Services\auth_account_password_matches;
use function Gallery\Services\auth_account_profile_row;
use function Gallery\Services\auth_account_replace_password_reset_token;
use function Gallery\Services\auth_account_update_profile;
use function Gallery\Services\auth_account_username_taken;
use function Gallery\Core\admin_auth_issue_persistent_login_for_request;
use function Gallery\Services\auth_password_reset_schema_status;
use function Gallery\Services\auth_schema_assert_known;
use function Gallery\Services\auth_user_email_schema_status;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\google_auth_configuration_ready;
use function Gallery\Services\google_auth_schema_status;
use function Gallery\Services\auth_persistence_config;
use function Gallery\Services\auth_persistent_login_schema_status;
use function Gallery\Services\auth_persistent_login_ready;
use function Gallery\Core\admin_auth_revoke_current_persistent_login_for_request;
use function Gallery\Core\admin_auth_revoke_user_persistent_logins_for_request;
use function Gallery\Services\auth_throttle_check;
use function Gallery\Services\auth_throttle_clear;
use function Gallery\Services\auth_throttle_log;
use function Gallery\Services\auth_throttle_normalize_identifier;
use function Gallery\Services\auth_throttle_record_attempt;
use function Gallery\Core\admin_auth_throttle_visitor_subject_for_request;
use function Gallery\Services\telemetry_request_id;
use function Gallery\Services\db_column_exists;
use function Gallery\Services\db_table_exists;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\google_auth_authorization_url;
use function Gallery\Services\google_auth_claims_from_code;
use function Gallery\Services\google_auth_config;
use function Gallery\Services\google_auth_consume_state;
use function Gallery\Services\google_auth_disconnect_account;
use function Gallery\Services\google_auth_link_account;
use function Gallery\Services\google_auth_linked_account;
use function Gallery\Services\google_auth_ready;
use function Gallery\Services\google_auth_schema_ready;
use function Gallery\Services\google_auth_touch_login;
use function Gallery\Services\google_auth_user_by_subject;
use function Gallery\Services\openai_text_assist_available;
use function Gallery\Services\openai_text_assist_image_input_column_ready;
use function Gallery\Services\openai_text_assist_model_catalog;
use function Gallery\Services\openai_text_assist_normalize_model;
use function Gallery\Services\openai_text_assist_save_user_settings;
use function Gallery\Services\openai_text_assist_schema_ready;
use function Gallery\Services\openai_text_assist_user_settings;
use function Gallery\Services\application_update_safe_error;
use function Gallery\Services\application_update_start_job;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_settings_url;

/**
 * Admin authentication controller model.
 * 
 * This module handles login, logout, account updates, and reset workflows. It does not touch theme configuration or visual customization.
 */

/**
 * Resolve an admin login identifier against email first, then username.
 *
 * Email is tried first to keep username-or-email login deterministic when one
 * user's username happens to be the same string as another user's email.
 *
 * @param string $identifier Identifier value.
 * @return ?array Structured result data for the caller.
 */
function cms_find_admin_user_by_identifier(string $identifier): ?array
{
    // $normalizedIdentifier stores an intermediate value used by the surrounding gallery workflow.
    $normalizedIdentifier = trim($identifier);
    if ($normalizedIdentifier === '') {
        return null;
    }

    // Email lookup has a proven legacy fallback only when the column is confirmed missing.
    $emailSchemaStatus = auth_user_email_schema_status();
    auth_schema_assert_known($emailSchemaStatus, 'auth_user_email');
    if (schema_inspection_is_available($emailSchemaStatus)) {
        // Variable $user stores this steps working value.
        $user = auth_account_find_by_email($normalizedIdentifier);
        if ($user) {
            return $user;
        }
    }

    // Confirmed pre-email installations continue to support username login.
    return auth_account_find_by_username($normalizedIdentifier);
}


/**
 * Return password reset settings with safe defaults.
 *
 * @return array Structured result data for the caller.
 */
function cms_password_reset_settings(): array
{
    // $config stores an intermediate value used by the surrounding gallery workflow.
    $config = cms_config();
    // $configSettings stores an intermediate value used by the surrounding gallery workflow.
    $configSettings = is_array($config['password_reset'] ?? null) ? $config['password_reset'] : [];
    // $enabledDefault stores the installed configuration fallback used before the DB setting exists.
    $enabledDefault = !empty($configSettings['enabled']) ? '1' : '0';
    // $fromEmailDefault stores the installed configuration fallback used before the DB setting exists.
    $fromEmailDefault = trim((string) ($configSettings['from_email'] ?? ''));
    // $fromNameDefault stores the installed configuration fallback used before the DB setting exists.
    $fromNameDefault = trim((string) ($configSettings['from_name'] ?? site_name()));
    // $lifetimeDefault stores the installed configuration fallback used before the DB setting exists.
    $lifetimeDefault = (string) ((int) ($configSettings['token_lifetime_minutes'] ?? 60));

    return [
        'enabled' => app_setting('password_reset_enabled', $enabledDefault) === '1',
        'transport' => app_setting('password_reset_transport', 'php_mail') === 'smtp' ? 'smtp' : 'php_mail',
        'from_email' => trim((string) app_setting('password_reset_from_email', $fromEmailDefault)),
        'from_name' => trim((string) app_setting('password_reset_from_name', $fromNameDefault !== '' ? $fromNameDefault : site_name())),
        'token_lifetime_minutes' => max(15, min(1440, (int) app_setting('password_reset_token_lifetime_minutes', $lifetimeDefault))),
        'smtp_host' => trim((string) app_setting('password_reset_smtp_host', '')),
        'smtp_port' => max(1, min(65535, (int) app_setting('password_reset_smtp_port', '587'))),
        'smtp_encryption' => in_array(app_setting('password_reset_smtp_encryption', 'tls'), ['none', 'tls', 'ssl'], true) ? app_setting('password_reset_smtp_encryption', 'tls') : 'tls',
        'smtp_username' => trim((string) app_setting('password_reset_smtp_username', '')),
        'smtp_password' => (string) app_setting('password_reset_smtp_password', ''),
    ];
}

/**
 * Persist admin-managed password reset delivery settings.
 *
 * @param array $input Input value.
 * @return array Structured result data for the caller.
 */
function cms_save_password_reset_settings(array $input): array
{
    // $enabled stores an intermediate value used by the surrounding gallery workflow.
    $enabled = isset($input['password_reset_enabled']);
    // $transport stores the selected delivery method for reset emails.
    $transport = (string) ($input['password_reset_transport'] ?? 'php_mail');
    $transport = $transport === 'smtp' ? 'smtp' : 'php_mail';
    // $fromEmail stores an intermediate value used by the surrounding gallery workflow.
    $fromEmail = auth_account_normalize_email((string) ($input['password_reset_from_email'] ?? ''));
    // $fromName stores an intermediate value used by the surrounding gallery workflow.
    $fromName = trim((string) ($input['password_reset_from_name'] ?? ''));
    // $lifetimeMinutes stores an intermediate value used by the surrounding gallery workflow.
    $lifetimeMinutes = (int) ($input['password_reset_token_lifetime_minutes'] ?? 60);
    // $smtpHost stores the SMTP server hostname without protocol prefixes.
    $smtpHost = trim((string) ($input['password_reset_smtp_host'] ?? ''));
    // $smtpPort stores the SMTP server port number.
    $smtpPort = (int) ($input['password_reset_smtp_port'] ?? 587);
    // $smtpEncryption stores whether SMTP uses STARTTLS, implicit TLS, or no encryption.
    $smtpEncryption = (string) ($input['password_reset_smtp_encryption'] ?? 'tls');
    $smtpEncryption = in_array($smtpEncryption, ['none', 'tls', 'ssl'], true) ? $smtpEncryption : 'tls';
    // $smtpUsername stores the SMTP username if authentication is required.
    $smtpUsername = trim((string) ($input['password_reset_smtp_username'] ?? ''));
    // $smtpPassword stores the SMTP password if authentication is required.
    $smtpPassword = (string) ($input['password_reset_smtp_password'] ?? '');
    // $existingSettings stores current values so leaving the password field empty does not erase it accidentally.
    $existingSettings = cms_password_reset_settings();
    if ($smtpPassword === '' && !empty($input['keep_existing_smtp_password'])) {
        $smtpPassword = (string) $existingSettings['smtp_password'];
    }
    // $errors stores an intermediate value used by the surrounding gallery workflow.
    $errors = [];

    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = t('admin.account.error_password_reset_sender_email_invalid');
    }
    if ($enabled && $fromEmail === '') {
        $errors[] = t('admin.account.error_password_reset_sender_required_when_enabled');
    }
    if ($fromName === '') {
        $fromName = site_name();
    }
    if ($lifetimeMinutes < 15 || $lifetimeMinutes > 1440) {
        $errors[] = t('admin.account.error_password_reset_lifetime_range');
    }
    if ($transport === 'smtp') {
        if ($smtpHost === '') {
            $errors[] = t('admin.account.error_smtp_host_required');
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            $errors[] = t('admin.account.error_smtp_port_range');
        }
        if ($smtpUsername !== '' && $smtpPassword === '') {
            $errors[] = t('admin.account.error_smtp_password_required_with_username');
        }
    }
    if ($errors !== []) {
        return $errors;
    }

    set_app_setting('password_reset_enabled', $enabled ? '1' : '0');
    set_app_setting('password_reset_transport', $transport);
    set_app_setting('password_reset_from_email', $fromEmail);
    set_app_setting('password_reset_from_name', $fromName);
    set_app_setting('password_reset_token_lifetime_minutes', (string) $lifetimeMinutes);
    set_app_setting('password_reset_smtp_host', $smtpHost);
    set_app_setting('password_reset_smtp_port', (string) $smtpPort);
    set_app_setting('password_reset_smtp_encryption', $smtpEncryption);
    set_app_setting('password_reset_smtp_username', $smtpUsername);
    set_app_setting('password_reset_smtp_password', $smtpPassword);

    admin_log_event('info', 'auth.password_reset_settings_updated', t('admin.account.log_password_reset_settings_updated'), [
        'enabled' => $enabled,
        'transport' => $transport,
        'from_email_set' => $fromEmail !== '',
        'from_email_domain' => $fromEmail !== '' && str_contains($fromEmail, '@') ? substr(strrchr($fromEmail, '@'), 1) : '',
        'token_lifetime_minutes' => $lifetimeMinutes,
        'smtp_host' => $transport === 'smtp' ? $smtpHost : '',
        'smtp_port' => $transport === 'smtp' ? $smtpPort : null,
        'smtp_encryption' => $transport === 'smtp' ? $smtpEncryption : '',
        'smtp_username_set' => $transport === 'smtp' && $smtpUsername !== '',
        'smtp_password_set' => $transport === 'smtp' && $smtpPassword !== '',
    ]);

    return [];
}

/**
 * Return true when the password reset token table exists.
 *
 * @return bool True when the condition matches.
 */
function cms_password_reset_schema_ready(): bool
{
    return schema_inspection_is_available(auth_password_reset_schema_status());
}

/**
 * Remove expired or already used password reset rows so the table stays small.
 */
function cms_cleanup_password_reset_tokens(): void
{
    $schemaStatus = auth_password_reset_schema_status();
    auth_schema_assert_known($schemaStatus, 'auth_password_reset');
    if (!schema_inspection_is_available($schemaStatus)) {
        return;
    }
    auth_account_cleanup_password_reset_tokens();
}

/**
 * Create a one-time password reset token and return the public selector/token pair.
 *
 * @param int $userId User id identifier.
 * @return ?array Structured result data for the caller.
 */
function cms_create_password_reset_token(int $userId): ?array
{
    $schemaStatus = auth_password_reset_schema_status();
    auth_schema_assert_known($schemaStatus, 'auth_password_reset');
    if (!schema_inspection_is_available($schemaStatus)) {
        return null;
    }

    cms_cleanup_password_reset_tokens();
    // $settings stores an intermediate value used by the surrounding gallery workflow.
    $settings = cms_password_reset_settings();
    // $selector stores an intermediate value used by the surrounding gallery workflow.
    $selector = bin2hex(random_bytes(9));
    // $token stores an intermediate value used by the surrounding gallery workflow.
    $token = bin2hex(random_bytes(32));
    // $tokenHash stores an intermediate value used by the surrounding gallery workflow.
    $tokenHash = hash('sha256', $token);
    // $expiresAt stores an intermediate value used by the surrounding gallery workflow.
    $expiresAt = date('Y-m-d H:i:s', time() + ((int) $settings['token_lifetime_minutes'] * 60));
    // $requestHash stores an intermediate value used by the surrounding gallery workflow.
    $requestHash = visitor_hash();

    // Invalidate older unused tokens for the same user before issuing a new one.
    auth_account_replace_password_reset_token($userId, $selector, $tokenHash, $expiresAt, $requestHash);

    return ['selector' => $selector, 'token' => $token, 'expires_at' => $expiresAt];
}

/**
 * Build an absolute password reset URL suitable for email messages.
 *
 * @param string $selector Selector value.
 * @param string $token Token value.
 * @return string Text result for the caller.
 */
function cms_password_reset_url(string $selector, string $token): string
{
    return absolute_public_url(url_for('admin_reset_password', ['selector' => $selector, 'token' => $token]));
}

/**
 * Return a privacy-safe masked email string for diagnostic logs.
 *
 * @param string $email Email value.
 * @return string Text result for the caller.
 */
function cms_mask_email_for_log(string $email): string
{
    // $email stores an intermediate value used by the surrounding gallery workflow.
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    // $visibleLocal stores an intermediate value used by the surrounding gallery workflow.
    $visibleLocal = substr($local, 0, min(2, strlen($local)));
    return $visibleLocal . str_repeat('*', max(2, strlen($local) - strlen($visibleLocal))) . '@' . $domain;
}

/**
 * Sanitize a mail header value so user-controlled newlines cannot inject extra headers.
 *
 * @param string $value Value to process.
 * @return string Text result for the caller.
 */
function cms_mail_header_value(string $value): string
{
    return trim(str_replace(["\r", "\n"], '', $value));
}

/**
 * Read one SMTP response and return its numeric code plus raw lines for diagnostics.
 *
 * @param mixed $socket Socket value.
 * @return array Structured result data for the caller.
 */
function cms_smtp_read_response($socket): array
{
    // $lines stores the raw SMTP response lines without exposing message bodies or credentials.
    $lines = [];
    // $code stores the last numeric SMTP response code.
    $code = 0;
    while (($line = fgets($socket, 515)) !== false) {
        $line = rtrim($line, "\r\n");
        $lines[] = $line;
        if (preg_match('/^(\d{3})([ -])/', $line, $matches)) {
            $code = (int) $matches[1];
            if ($matches[2] === ' ') {
                break;
            }
        } else {
            break;
        }
    }
    return ['code' => $code, 'lines' => $lines];
}

/**
 * Send an SMTP command and validate the response code.
 *
 * @param mixed $socket Socket value.
 * @param string $command Command value.
 * @param array $expectedCodes Expected codes value.
 * @param array $details Details value.
 * @param string $stage Stage value.
 * @return bool True when the condition matches.
 */
function cms_smtp_command($socket, string $command, array $expectedCodes, array &$details, string $stage): bool
{
    fwrite($socket, $command . "\r\n");
    // $response stores the SMTP server response for this command.
    $response = cms_smtp_read_response($socket);
    $details['smtp_stage'] = $stage;
    $details['smtp_last_code'] = $response['code'];
    $details['smtp_last_response'] = array_slice($response['lines'], -3);
    return in_array((int) $response['code'], $expectedCodes, true);
}

/**
 * Send a plain text message through an explicitly configured SMTP server.
 *
 * @param array $settings Settings used by this workflow.
 * @param string $recipient Recipient value.
 * @param string $subject Subject value.
 * @param string $body Body value.
 * @param array $details Details value.
 * @return bool True when the condition matches.
 */
function cms_send_smtp_email(array $settings, string $recipient, string $subject, string $body, array &$details): bool
{
    // $host stores the configured SMTP host.
    $host = (string) $settings['smtp_host'];
    // $port stores the configured SMTP port.
    $port = (int) $settings['smtp_port'];
    // $encryption stores the configured SMTP encryption mode.
    $encryption = (string) $settings['smtp_encryption'];
    // $remoteHost stores the socket target for implicit TLS or plain SMTP.
    $remoteHost = ($encryption === 'ssl' ? 'ssl://' : '') . $host;
    // $errno stores the socket error number when connection fails.
    $errno = 0;
    // $errstr stores the socket error text when connection fails.
    $errstr = '';

    $details['smtp_host'] = $host;
    $details['smtp_port'] = $port;
    $details['smtp_encryption'] = $encryption;
    $details['smtp_username_set'] = (string) $settings['smtp_username'] !== '';
    $details['smtp_password_set'] = (string) $settings['smtp_password'] !== '';
    $details['smtp_stage'] = 'connect';

    // $context stores conservative TLS options. Certificate validation stays enabled.
    $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    // $socket stores the active SMTP connection.
    $socket = @stream_socket_client($remoteHost . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        $details['reason'] = t('admin.account.smtp_connection_failed', ['error' => $errstr]);
        $details['smtp_errno'] = $errno;
        return false;
    }
    stream_set_timeout($socket, 20);

    // $greeting stores the first SMTP server response.
    $greeting = cms_smtp_read_response($socket);
    $details['smtp_stage'] = 'greeting';
    $details['smtp_last_code'] = $greeting['code'];
    $details['smtp_last_response'] = array_slice($greeting['lines'], -3);
    if ((int) $greeting['code'] !== 220) {
        fclose($socket);
        $details['reason'] = t('admin.account.smtp_greeting_failed');
        return false;
    }

    // $helloName stores a valid EHLO hostname fallback.
    $helloName = $_SERVER['SERVER_NAME'] ?? 'localhost';
    if (!cms_smtp_command($socket, 'EHLO ' . $helloName, [250], $details, 'ehlo')) {
        fclose($socket);
        $details['reason'] = t('admin.account.smtp_ehlo_failed');
        return false;
    }

    if ($encryption === 'tls') {
        if (!cms_smtp_command($socket, 'STARTTLS', [220], $details, 'starttls')) {
            fclose($socket);
            $details['reason'] = t('admin.account.smtp_starttls_failed');
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            $details['reason'] = t('admin.account.smtp_tls_failed');
            return false;
        }
        if (!cms_smtp_command($socket, 'EHLO ' . $helloName, [250], $details, 'ehlo_after_starttls')) {
            fclose($socket);
            $details['reason'] = t('admin.account.smtp_ehlo_after_starttls_failed');
            return false;
        }
    }

    if ((string) $settings['smtp_username'] !== '') {
        if (!cms_smtp_command($socket, 'AUTH LOGIN', [334], $details, 'auth_login')) {
            fclose($socket);
            $details['reason'] = t('admin.account.smtp_auth_login_rejected');
            return false;
        }
        if (!cms_smtp_command($socket, base64_encode((string) $settings['smtp_username']), [334], $details, 'auth_username')) {
            fclose($socket);
            $details['reason'] = t('admin.account.smtp_username_rejected');
            return false;
        }
        if (!cms_smtp_command($socket, base64_encode((string) $settings['smtp_password']), [235], $details, 'auth_password')) {
            fclose($socket);
            $details['reason'] = t('admin.account.smtp_password_rejected');
            return false;
        }
    }

    // $fromEmail stores the sanitized sender address used for SMTP envelope and headers.
    $fromEmail = cms_mail_header_value((string) $settings['from_email']);
    // $fromName stores the sanitized display name used in the From header.
    $fromName = cms_mail_header_value((string) $settings['from_name']);
    if (!cms_smtp_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], $details, 'mail_from')) {
        fclose($socket);
        $details['reason'] = t('admin.account.smtp_mail_from_rejected');
        return false;
    }
    if (!cms_smtp_command($socket, 'RCPT TO:<' . $recipient . '>', [250, 251], $details, 'rcpt_to')) {
        fclose($socket);
        $details['reason'] = t('admin.account.smtp_recipient_rejected');
        return false;
    }
    if (!cms_smtp_command($socket, 'DATA', [354], $details, 'data')) {
        fclose($socket);
        $details['reason'] = t('admin.account.smtp_data_rejected');
        return false;
    }

    // $message stores the RFC 5322 message passed to the SMTP DATA command.
    $message = 'From: ' . $fromName . ' <' . $fromEmail . ">\r\n"
        . 'To: <' . $recipient . ">\r\n"
        . 'Subject: ' . cms_mail_header_value($subject) . "\r\n"
        . 'MIME-Version: 1.0' . "\r\n"
        . 'Content-Type: text/plain; charset=UTF-8' . "\r\n"
        . 'Content-Transfer-Encoding: 8bit' . "\r\n"
        . 'Date: ' . date(DATE_RFC2822) . "\r\n"
        . "\r\n"
        . str_replace(["\r\n.", "\n."], ["\r\n..", "\n.."], str_replace("\r\n", "\n", $body)) . "\r\n.";
    fwrite($socket, $message . "\r\n");
    // $response stores the final DATA acceptance response.
    $response = cms_smtp_read_response($socket);
    $details['smtp_stage'] = 'data_finish';
    $details['smtp_last_code'] = $response['code'];
    $details['smtp_last_response'] = array_slice($response['lines'], -3);
    cms_smtp_command($socket, 'QUIT', [221, 250], $details, 'quit');
    fclose($socket);

    if ((int) $response['code'] !== 250) {
        $details['reason'] = t('admin.account.smtp_message_rejected');
        return false;
    }

    $details['reason'] = t('admin.account.smtp_message_accepted');
    return true;
}

/**
 * Send a plain text email using the configured password reset transport.
 *
 * @param string $recipient Recipient value.
 * @param string $subject Subject value.
 * @param string $body Body value.
 * @param string $expiresAt Expires at value.
 * @return array Structured result data for the caller.
 */
function cms_send_configured_password_reset_mail(string $recipient, string $subject, string $body, string $expiresAt = ''): array
{
    // $settings stores an intermediate value used by the surrounding gallery workflow.
    $settings = cms_password_reset_settings();
    // $details stores safe delivery metadata for the admin log.
    $details = [
        'sent' => false,
        'transport' => (string) $settings['transport'],
        'enabled' => (bool) $settings['enabled'],
        'reason' => '',
        'recipient_masked' => cms_mask_email_for_log($recipient),
        'from_email_masked' => cms_mask_email_for_log((string) $settings['from_email']),
        'expires_at' => $expiresAt,
    ];

    if (!$settings['enabled']) {
        $details['reason'] = t('admin.account.password_reset_email_disabled');
        return $details;
    }
    if ($recipient === '') {
        $details['reason'] = t('admin.account.no_recovery_email');
        return $details;
    }
    if ($settings['from_email'] === '') {
        $details['reason'] = t('admin.account.password_reset_sender_empty');
        return $details;
    }

    if ($settings['transport'] === 'smtp') {
        if ($settings['smtp_host'] === '') {
            $details['reason'] = t('admin.account.smtp_host_empty');
            return $details;
        }
        $details['sent'] = cms_send_smtp_email($settings, $recipient, $subject, $body, $details);
        return $details;
    }

    if (!function_exists('mail')) {
        $details['reason'] = t('admin.account.php_mail_unavailable');
        return $details;
    }

    // $fromName stores an intermediate value used by the surrounding gallery workflow.
    $fromName = cms_mail_header_value((string) $settings['from_name']);
    // $fromEmail stores an intermediate value used by the surrounding gallery workflow.
    $fromEmail = cms_mail_header_value((string) $settings['from_email']);
    // $headers stores an intermediate value used by the surrounding gallery workflow.
    $headers = "From: " . $fromName . " <" . $fromEmail . ">\r\n"
        . "Reply-To: " . $fromEmail . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    // $extraParameters stores the envelope sender used by many shared hosts for SPF alignment.
    $extraParameters = $fromEmail !== '' ? '-f' . escapeshellarg($fromEmail) : '';

    $details['sent'] = $extraParameters !== ''
        ? mail($recipient, $subject, $body, $headers, $extraParameters)
        : mail($recipient, $subject, $body, $headers);
    $details['reason'] = $details['sent'] ? t('admin.account.php_mail_accepted') : t('admin.account.php_mail_failed');
    return $details;
}

/**
 * Send a password reset message using the configured transport.
 *
 * @param array $user User value.
 * @param string $resetUrl Reset url URL.
 * @param string $expiresAt Expires at value.
 * @return array Structured result data for the caller.
 */
function cms_send_password_reset_email(array $user, string $resetUrl, string $expiresAt): array
{
    // $recipient stores an intermediate value used by the surrounding gallery workflow.
    $recipient = trim((string) ($user['email'] ?? ''));
    // $subject stores an intermediate value used by the surrounding gallery workflow.
    $subject = t('admin.auth.password_reset_subject', ['site' => site_name()]);
    // $body stores an intermediate value used by the surrounding gallery workflow.
    $body = t('admin.auth.password_reset_body', [
        'reset_url' => $resetUrl,
        'expires_at' => $expiresAt,
    ]);
    return cms_send_configured_password_reset_mail($recipient, $subject, $body, $expiresAt);
}

/**
 * Resolve and validate a selector/token pair from a reset link.
 *
 * @param string $selector Selector value.
 * @param string $token Token value.
 * @return ?array Structured result data for the caller.
 */
function cms_find_valid_password_reset_token(string $selector, string $token): ?array
{
    if ($selector === '' || $token === '') {
        return null;
    }
    $schemaStatus = auth_password_reset_schema_status();
    auth_schema_assert_known($schemaStatus, 'auth_password_reset');
    if (!schema_inspection_is_available($schemaStatus)) {
        return null;
    }
    // Variable $row stores this steps working value.
    $row = auth_account_find_valid_password_reset($selector);
    if (!$row || !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
        return null;
    }
    return $row;
}


/**
 * Start Google login or account linking through OpenID Connect.
 */
function cms_admin_google_start(): void
{
    // $mode stores whether Google should authenticate a login or link the current profile.
    $mode = (string) ($_GET['mode'] ?? 'login');
    $mode = $mode === 'link' ? 'link' : 'login';
    // $returnTarget stores the local page that should reopen after successful authentication.
    $returnTarget = sanitize_login_return_target((string) ($_GET['return'] ?? ''), url_for('admin'));

    $googleConfig = google_auth_config();
    if (!google_auth_configuration_ready($googleConfig)) {
        flash_message('admin_notice', t('admin.google.not_configured', 'Google login is not configured yet. Add the OAuth client ID and secret to config.php, then run the database migrations.'));
        redirect_to($mode === 'link' ? url_for('admin_account') : url_for('admin_login', ['return' => $returnTarget]));
    }
    $googleSchemaStatus = google_auth_schema_status();
    if (schema_inspection_is_unknown($googleSchemaStatus)) {
        flash_message('admin_notice', t('admin.auth.schema_temporarily_unavailable', 'Authentication storage is temporarily unavailable. Try again after the database/schema inspection issue is resolved.'));
        redirect_to($mode === 'link' ? url_for('admin_account') : url_for('admin_login', ['return' => $returnTarget]));
    }
    if (schema_inspection_is_missing($googleSchemaStatus)) {
        flash_message('admin_notice', t('admin.google.migration_required', 'Google login is configured, but its database migration has not been applied yet.'));
        redirect_to($mode === 'link' ? url_for('admin_account') : url_for('admin_login', ['return' => $returnTarget]));
    }

    if ($mode === 'link') {
        require_admin();
    }

    redirect_to(google_auth_authorization_url($mode, $returnTarget));
}

/**
 * Handle the Google OpenID Connect callback for login and account linking.
 */
function cms_admin_google_callback(): void
{
    // $state stores the returned OAuth state used to prevent request forgery.
    $state = (string) ($_GET['state'] ?? '');
    // $stateEntry stores the local state metadata saved before redirecting to Google.
    $stateEntry = function_exists('Gallery\\Services\\google_auth_consume_state') ? google_auth_consume_state($state) : null;
    if (!$stateEntry) {
        flash_message('admin_notice', t('admin.google.state_invalid', 'Google login expired or returned an invalid state. Try again.'));
        redirect_to(url_for('admin_login'));
    }

    // $mode stores whether this callback belongs to login or profile linking.
    $mode = (string) ($stateEntry['mode'] ?? 'login');
    // $returnTarget stores the local target restored after successful login.
    $returnTarget = sanitize_login_return_target((string) ($stateEntry['return'] ?? ''), url_for('admin'));

    if (!empty($_GET['error'])) {
        flash_message('admin_notice', t('admin.google.callback_error', 'Google login failed: {error}', ['error' => (string) $_GET['error']]));
        redirect_to($mode === 'link' ? url_for('admin_account') : url_for('admin_login', ['return' => $returnTarget]));
    }

    // $code stores the authorization code returned by Google.
    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') {
        flash_message('admin_notice', t('admin.google.code_missing', 'Google did not return an authorization code.'));
        redirect_to($mode === 'link' ? url_for('admin_account') : url_for('admin_login', ['return' => $returnTarget]));
    }

    try {
        // $claims stores verified Google identity claims.
        $claims = google_auth_claims_from_code($code);
        if ($mode === 'link') {
            // $currentUser stores the admin profile that initiated linking.
            $currentUser = current_user();
            if (!$currentUser || (int) $currentUser['id'] !== (int) ($stateEntry['user_id'] ?? 0)) {
                flash_message('admin_notice', t('admin.google.link_session_expired', 'Your admin session expired before Google linking finished. Log in again and retry linking.'));
                redirect_to(url_for('admin_login', ['return' => url_for('admin_account')]));
            }
            google_auth_link_account((int) $currentUser['id'], $claims);
            admin_log_event('info', 'auth.google_linked', t('admin.google.log_linked', 'Admin linked a Google account.'), [
                'user_id' => (int) $currentUser['id'],
                'google_sub_sha256' => hash('sha256', (string) ($claims['sub'] ?? '')),
                'email_domain' => str_contains((string) ($claims['email'] ?? ''), '@') ? substr(strrchr((string) $claims['email'], '@'), 1) : '',
            ]);
            redirect_to(url_for('admin_account', ['google' => 'linked']));
        }

        // $linkedUser stores the existing admin account connected to this Google account.
        $linkedUser = google_auth_user_by_subject((string) ($claims['sub'] ?? ''));
        if (!$linkedUser || (string) ($linkedUser['role'] ?? '') !== 'admin') {
            admin_log_event('warning', 'auth.google_unlinked_login', t('admin.google.log_unlinked_login', 'Google login was rejected because the Google account is not linked to an admin profile.'), [
                'google_sub_sha256' => hash('sha256', (string) ($claims['sub'] ?? '')),
                'email_domain' => str_contains((string) ($claims['email'] ?? ''), '@') ? substr(strrchr((string) $claims['email'], '@'), 1) : '',
            ]);
            redirect_to(url_for('admin_login', ['google' => 'not_linked', 'return' => $returnTarget]));
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $linkedUser['id'];
        if (function_exists('Gallery\\Core\\admin_auth_issue_persistent_login_for_request')) {
            try {
                admin_auth_issue_persistent_login_for_request((int) $linkedUser['id']);
            } catch (AuthenticationSchemaUnavailableException $exception) {
                admin_log_event('warning', 'auth.persistent_login_schema_unavailable', 'Persistent login was not issued because its schema state is unknown.', [
                    'operation' => 'google_login_issue',
                    'feature' => $exception->feature(),
                    'user_id' => (int) $linkedUser['id'],
                ]);
                flash_message('admin_notice', t('admin.auth.remember_temporarily_unavailable', 'You are signed in for this browser session, but persistent login is temporarily unavailable until the database/schema inspection issue is resolved.'));
            }
        }
        if (function_exists('Gallery\\Services\\google_auth_touch_login')) {
            google_auth_touch_login((int) $linkedUser['google_account_id']);
        }
        admin_log_event('info', 'auth.google_login', t('admin.google.log_login', 'Admin logged in with Google.'), [
            'user_id' => (int) $linkedUser['id'],
            'username' => (string) $linkedUser['username'],
        ]);
        redirect_to($returnTarget);
    } catch (AuthenticationSchemaUnavailableException $exception) {
        admin_log_event('warning', 'auth.google_schema_unavailable', 'Google authentication was refused because required schema state is unknown.', [
            'mode' => $mode,
            'feature' => $exception->feature(),
        ]);
        flash_message('admin_notice', t('admin.auth.schema_temporarily_unavailable', 'Authentication storage is temporarily unavailable. Try again after the database/schema inspection issue is resolved.'));
        redirect_to($mode === 'link' ? url_for('admin_account') : url_for('admin_login', ['return' => $returnTarget]));
    } catch (Throwable $exception) {
        admin_log_event('warning', 'auth.google_callback_failed', t('admin.google.log_callback_failed', 'Google login callback failed.'), [
            'mode' => $mode,
            'error_type' => $exception::class,
        ]);
        flash_message('admin_notice', t('admin.google.callback_failed_safe', 'Google login could not be completed. Check System Health and the Admin logs, then try again.'));
        redirect_to($mode === 'link' ? url_for('admin_account') : url_for('admin_login', ['return' => $returnTarget]));
    }
}


/**
 * Handle cms admin login.
 *
 * Used by HTTP controller routing for this workflow.
 */
function cms_admin_login(): void
{
    // $returnTarget stores the local page that should reopen after successful authentication.
    $returnTarget = sanitize_login_return_target((string) ($_POST['return'] ?? $_GET['return'] ?? ''), url_for('admin'));

    if (request_method() === 'POST') {
        verify_csrf();
        // Variable $identifier stores this steps working value.
        $identifier = (string) ($_POST['identifier'] ?? '');
        // $normalizedIdentifier stores the submitted login identifier after trimming and lowercasing for safe throttling.
        $normalizedIdentifier = auth_throttle_normalize_identifier($identifier);
        // $visitorSubject stores the privacy-safe visitor identifier used by the login throttling service.
        $visitorSubject = admin_auth_throttle_visitor_subject_for_request();
        // $visitorThrottle stores the current visitor-level login throttle status.
        $visitorThrottle = auth_throttle_check('admin_login_visitor', $visitorSubject);
        // $identifierThrottle stores the current identifier-level login throttle status.
        $identifierThrottle = $normalizedIdentifier !== ''
            ? auth_throttle_check('admin_login_identifier', $normalizedIdentifier)
            : ['allowed' => true, 'retry_after_seconds' => 0, 'attempts' => 0];

        if (!$visitorThrottle['allowed'] || !$identifierThrottle['allowed']) {
            if (!$visitorThrottle['allowed']) {
                auth_throttle_log('auth.login_rate_limited', t('admin.auth.log_login_rate_limited'), 'admin_login_visitor', $visitorSubject, $visitorThrottle);
            }
            if (!$identifierThrottle['allowed'] && $normalizedIdentifier !== '') {
                auth_throttle_log('auth.login_rate_limited', t('admin.auth.log_login_rate_limited'), 'admin_login_identifier', $normalizedIdentifier, $identifierThrottle);
            }
            $error = t('admin.auth.too_many_attempts');
        } else {
            // Variable $user stores this steps working value.
            try {
                $user = cms_find_admin_user_by_identifier($identifier);
            } catch (AuthenticationSchemaUnavailableException $exception) {
                admin_log_event('warning', 'auth.login_schema_unavailable', 'Password login identity lookup was refused because schema inspection is unknown.', [
                    'feature' => $exception->feature(),
                ]);
                $error = t('admin.auth.schema_temporarily_unavailable', 'Authentication storage is temporarily unavailable. Try again after the database/schema inspection issue is resolved.');
                $user = null;
            }

            if (!isset($error) && $user && password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
                auth_throttle_clear('admin_login_visitor', $visitorSubject);
                if ($normalizedIdentifier !== '') {
                    auth_throttle_clear('admin_login_identifier', $normalizedIdentifier);
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                if (!empty($_POST['remember_login']) && function_exists('Gallery\Core\admin_auth_issue_persistent_login_for_request')) {
                    try {
                        admin_auth_issue_persistent_login_for_request((int) $user['id']);
                    } catch (AuthenticationSchemaUnavailableException $exception) {
                        admin_log_event('warning', 'auth.persistent_login_issue_refused', 'Persistent login token issuance was refused because schema inspection is unknown.', [
                            'feature' => $exception->feature(),
                            'user_id' => (int) $user['id'],
                        ]);
                        flash_message('admin_notice', t('admin.auth.remember_temporarily_unavailable', 'You are signed in for this session, but persistent login is temporarily unavailable.'));
                    }
                }
                // Redirect to the page where the visitor clicked the login link, not always to the admin dashboard.
                redirect_to($returnTarget);
            }

            // Metadata inspection failure is operational, not an invalid credential attempt.
            if (!isset($error)) {
                auth_throttle_record_attempt('admin_login_visitor', $visitorSubject);
                if ($normalizedIdentifier !== '') {
                    auth_throttle_record_attempt('admin_login_identifier', $normalizedIdentifier);
                }
                // Variable $error stores this steps working value.
                $error = t('admin.auth.invalid_login');
            }
        }
    }
    // $notices contains only controller-resolved request/session messages for the login view.
    $notices = [];
    if (isset($_GET['reset'])) {
        $notices[] = t('admin.auth.password_reset_completed');
    }
    if ((string) ($_GET['google'] ?? '') === 'not_linked') {
        $notices[] = t('admin.google.login_not_linked', 'This Google account is not linked to an admin profile yet. Log in with your password first, then link Google in Account settings.');
    }
    $flash = flash_message('admin_notice');
    if ($flash !== null && $flash !== '') {
        $notices[] = $flash;
    }
    // $rememberConfig stores persistent login defaults for the checkbox below.
    $rememberConfig = function_exists('Gallery\Services\auth_persistence_config') ? auth_persistence_config() : ['persistent_login_default_checked' => true];
    // $rememberSchemaStatus distinguishes migration absence from metadata inspection failure.
    $rememberSchemaStatus = auth_persistent_login_schema_status();
    // $rememberReady stores whether DB-backed persistent login can be issued on this installation.
    $rememberReady = function_exists('Gallery\Services\auth_persistent_login_ready') && auth_persistent_login_ready();
    // $googleSchemaStatus distinguishes migration absence from metadata inspection failure.
    $googleSchemaStatus = google_auth_schema_status();
    // $googleReady stores whether Google sign-in can be used on this installation.
    $googleReady = function_exists('Gallery\Services\google_auth_ready') && google_auth_ready();

    \Gallery\Views\view_render_admin_login([
        'notices' => $notices,
        'error' => isset($error) ? (string) $error : '',
        'csrf_html' => csrf_field(),
        'return_target' => $returnTarget,
        'remember_ready' => $rememberReady,
        'remember_default_checked' => !empty($rememberConfig['persistent_login_default_checked']),
        'show_remember_schema_unknown' => !empty($rememberConfig['persistent_login_enabled']) && schema_inspection_is_unknown($rememberSchemaStatus),
        'google_ready' => $googleReady,
        'show_google_schema_unknown' => google_auth_configuration_ready() && schema_inspection_is_unknown($googleSchemaStatus),
        'google_login_url' => url_for('admin_google_start', ['mode' => 'login', 'return' => $returnTarget]),
        'forgot_password_url' => url_for('admin_forgot_password'),
    ]);
}


/**
 * Request a password reset link for an admin account with a recovery email.
 */
function cms_admin_forgot_password(): void
{
    if (current_user()) {
        redirect_to(url_for('admin_account'));
    }
    // $notice stores an intermediate value used by the surrounding gallery workflow.
    $notice = '';
    if (request_method() === 'POST') {
        verify_csrf();
        // $identifier stores an intermediate value used by the surrounding gallery workflow.
        $identifier = (string) ($_POST['identifier'] ?? '');
        // $normalizedIdentifier stores the submitted reset identifier after trimming and lowercasing for safe throttling.
        $normalizedIdentifier = auth_throttle_normalize_identifier($identifier);
        // $visitorSubject stores the privacy-safe visitor identifier used by the reset throttling service.
        $visitorSubject = admin_auth_throttle_visitor_subject_for_request();
        // $visitorThrottle stores the current visitor-level reset throttle status.
        $visitorThrottle = auth_throttle_check('password_reset_visitor', $visitorSubject);
        // $identifierThrottle stores the current identifier-level reset throttle status.
        $identifierThrottle = $normalizedIdentifier !== ''
            ? auth_throttle_check('password_reset_identifier', $normalizedIdentifier)
            : ['allowed' => true, 'retry_after_seconds' => 0, 'attempts' => 0];

        if (!$visitorThrottle['allowed'] || !$identifierThrottle['allowed']) {
            if (!$visitorThrottle['allowed']) {
                auth_throttle_log('auth.reset_rate_limited', t('admin.auth.log_reset_rate_limited'), 'password_reset_visitor', $visitorSubject, $visitorThrottle);
            }
            if (!$identifierThrottle['allowed'] && $normalizedIdentifier !== '') {
                auth_throttle_log('auth.reset_rate_limited', t('admin.auth.log_reset_rate_limited'), 'password_reset_identifier', $normalizedIdentifier, $identifierThrottle);
            }
        } else {
            auth_throttle_record_attempt('password_reset_visitor', $visitorSubject);
            if ($normalizedIdentifier !== '') {
                auth_throttle_record_attempt('password_reset_identifier', $normalizedIdentifier);
            }

            // Reset issuance requires both the token table and users.email.
            try {
                $resetSchemaStatus = auth_password_reset_schema_status();
                auth_schema_assert_known($resetSchemaStatus, 'auth_password_reset');
                if (!schema_inspection_is_available($resetSchemaStatus)) {
                    $notice = t('admin.auth.password_reset_migration_required', 'Password reset is unavailable until the required database migration is applied.');
                    $user = null;
                } else {
                    // Variable $user stores this steps working value.
                    $user = cms_find_admin_user_by_identifier($identifier);
                }
            } catch (AuthenticationSchemaUnavailableException $exception) {
                admin_log_event('warning', 'auth.password_reset_schema_unavailable', 'Password reset was refused because required schema inspection is unknown.', [
                    'feature' => $exception->feature(),
                    'request_id' => function_exists('Gallery\Services\telemetry_request_id') ? telemetry_request_id() : '',
                ]);
                $notice = t('admin.auth.schema_temporarily_unavailable', 'Authentication storage is temporarily unavailable. Try again after the database/schema inspection issue is resolved.');
                $user = null;
            }

            if ($user && trim((string) ($user['email'] ?? '')) !== '') {
                // $token stores an intermediate value used by the surrounding gallery workflow.
                $token = cms_create_password_reset_token((int) $user['id']);
                if ($token) {
                    // $resetUrl stores an intermediate value used by the surrounding gallery workflow.
                    $resetUrl = cms_password_reset_url((string) $token['selector'], (string) $token['token']);
                    // $delivery stores safe mail diagnostics for the admin log without storing the submitted identifier or token value.
                    $delivery = cms_send_password_reset_email($user, $resetUrl, (string) $token['expires_at']);
                    admin_log_event(!empty($delivery['sent']) ? 'info' : 'warning', 'auth.password_reset_requested', !empty($delivery['sent']) ? t('admin.auth.log_password_reset_email_sent') : t('admin.auth.log_password_reset_token_created_no_email'), [
                        'identifier_sha256' => hash('sha256', auth_account_normalize_email($identifier)),
                        'identifier_looks_like_email' => filter_var(trim($identifier), FILTER_VALIDATE_EMAIL) !== false,
                        'visitor_hash' => visitor_hash(),
                        'request_id' => function_exists('Gallery\Services\telemetry_request_id') ? telemetry_request_id() : '',
                        'user_id' => (int) $user['id'],
                        'username' => (string) $user['username'],
                        'email_delivery' => $delivery,
                    ]);
                }
            }
        }
        if ($notice === '') {
            // Preserve account-enumeration resistance for normal available-schema flow.
            $notice = t('admin.auth.reset_link_sent_if_possible');
        }
    }

    \Gallery\Views\view_render_admin_forgot_password([
        'notice' => $notice,
        'csrf_html' => csrf_field(),
        'login_url' => url_for('admin_login'),
    ]);
}

/**
 * Accept a one-time password reset link and store the new password.
 */
function cms_admin_reset_password(): void
{
    if (current_user()) {
        redirect_to(url_for('admin_account'));
    }
    // $selector stores an intermediate value used by the surrounding gallery workflow.
    $selector = (string) ($_GET['selector'] ?? $_POST['selector'] ?? '');
    // $token stores an intermediate value used by the surrounding gallery workflow.
    $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
    // $resetRow stores an intermediate value used by the surrounding gallery workflow.
    try {
        $resetRow = cms_find_valid_password_reset_token($selector, $token);
    } catch (AuthenticationSchemaUnavailableException $exception) {
        admin_log_event('warning', 'auth.password_reset_consume_schema_unavailable', 'Password reset token consumption was refused because schema inspection is unknown.', [
            'feature' => $exception->feature(),
        ]);
        \Gallery\Views\view_render_admin_reset_password([
            'state' => 'schema_unavailable',
        ]);
        return;
    }
    // Variable $error stores this steps working value.
    $error = '';

    if (!$resetRow) {
        \Gallery\Views\view_render_admin_reset_password([
            'state' => 'invalid',
            'forgot_password_url' => url_for('admin_forgot_password'),
        ]);
        return;
    }

    if (request_method() === 'POST') {
        verify_csrf();
        // $newPassword stores an intermediate value used by the surrounding gallery workflow.
        $newPassword = (string) ($_POST['new_password'] ?? '');
        // $confirmPassword stores an intermediate value used by the surrounding gallery workflow.
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
        if (strlen($newPassword) < 8) {
            $error = t('admin.auth.password_min_length');
        } elseif ($newPassword !== $confirmPassword) {
            $error = t('admin.auth.password_confirmation_mismatch');
        } else {
            auth_account_complete_password_reset((int) $resetRow['user_id'], (int) $resetRow['id'], $newPassword);
            if (function_exists('Gallery\Core\admin_auth_revoke_user_persistent_logins_for_request')) {
                admin_auth_revoke_user_persistent_logins_for_request((int) $resetRow['user_id']);
            }
            admin_log_event('info', 'auth.password_reset_completed', t('admin.auth.log_password_reset_completed'), [
                'user_id' => (int) $resetRow['user_id'],
            ]);
            redirect_to(url_for('admin_login', ['reset' => 1]));
        }
    }

    \Gallery\Views\view_render_admin_reset_password([
        'state' => 'form',
        'error' => $error,
        'username' => (string) $resetRow['username'],
        'selector' => $selector,
        'token' => $token,
        'csrf_html' => csrf_field(),
    ]);
}

/**
 * Handles cms admin logout logic for the gallery application.
 */
function cms_admin_logout(): void
{
    if (function_exists('Gallery\\Core\\admin_auth_revoke_current_persistent_login_for_request')) {
        admin_auth_revoke_current_persistent_login_for_request();
    }
    unset($_SESSION['user_id']);
    unset($_SESSION['csrf_token']);
    session_regenerate_id(true);
    redirect_to(url_for('home'));
}

/**
 * Handles cms admin account logic for the gallery application.
 */
function cms_admin_account(): void
{
    require_admin();
    // Variable $user stores this steps working value.
    $user = current_user();
    if (!$user) {
        redirect_to(url_for('admin_login', ['return' => current_login_return_target()]));
    }

    // $emailSchemaStatus distinguishes a proven legacy users table from a metadata inspection failure.
    $emailSchemaStatus = auth_user_email_schema_status();
    // $emailSchemaAvailable permits recovery-email reads and writes only after the column is verified.
    $emailSchemaAvailable = schema_inspection_is_available($emailSchemaStatus);
    // $resetSchemaStatus represents the complete password-reset storage capability.
    $resetSchemaStatus = auth_password_reset_schema_status();
    // $googleSchemaStatus represents the linked external-identity storage capability.
    $googleSchemaStatus = google_auth_schema_status();

    if (request_method() === 'POST') {
        verify_csrf();
        // $accountAction stores the submitted account section so profile and mail settings can validate independently.
        $accountAction = (string) ($_POST['account_action'] ?? 'profile');

        if ($accountAction === 'password_reset_settings') {
            // $errors stores this steps working value.
            $errors = cms_save_password_reset_settings($_POST);
            if ($errors === []) {
                redirect_to(url_for('admin_account', ['reset_settings_saved' => 1]));
            }
            // Variable $error stores this steps working value.
            $error = implode(' ', $errors);
        } elseif ($accountAction === 'password_reset_test_email') {
            if (schema_inspection_is_unknown($resetSchemaStatus)) {
                $error = t('admin.auth.schema_temporarily_unavailable', 'Authentication storage is temporarily unavailable. Try again after the database/schema inspection issue is resolved.');
            } elseif (schema_inspection_is_missing($resetSchemaStatus)) {
                $error = t('admin.auth.password_reset_migration_required', 'Password reset storage is not installed yet. Apply the pending database migration before using password reset.');
            } else {
            // $testRecipient stores the current account recovery email used for a live delivery test.
            $testRecipient = trim((string) ($user['email'] ?? ''));
            if ($testRecipient === '') {
                $error = t('admin.account.error_test_email_needs_recovery');
            } else {
                // $testDelivery stores safe diagnostics from the configured mail transport.
                $testDelivery = cms_send_configured_password_reset_mail(
                    $testRecipient,
                    t('admin.account.test_email_subject', ['site' => site_name()]),
                    t('admin.account.test_email_body'),
                    ''
                );
                admin_log_event(!empty($testDelivery['sent']) ? 'info' : 'warning', 'auth.password_reset_test_email', !empty($testDelivery['sent']) ? t('admin.account.log_test_email_sent') : t('admin.account.log_test_email_failed'), [
                    'user_id' => (int) $user['id'],
                    'username' => (string) $user['username'],
                    'email_delivery' => $testDelivery,
                ]);
                redirect_to(url_for('admin_account', ['test_email' => !empty($testDelivery['sent']) ? 'sent' : 'failed']));
            }
            }
        } elseif ($accountAction === 'google_disconnect') {
            if (schema_inspection_is_unknown($googleSchemaStatus)) {
                $error = t('admin.auth.schema_temporarily_unavailable', 'Authentication storage is temporarily unavailable. Try again after the database/schema inspection issue is resolved.');
            } elseif (schema_inspection_is_missing($googleSchemaStatus)) {
                $error = t('admin.google.migration_required', 'Database migration required before Google login can be configured.');
            } else {
            // $currentPassword stores the profile password used to authorize Google unlinking.
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            if (!auth_account_password_matches((int) $user['id'], $currentPassword)) {
                $error = t('admin.account.error_current_password_required');
            } else {
                if (function_exists('Gallery\\Services\\google_auth_disconnect_account')) {
                    google_auth_disconnect_account((int) $user['id']);
                }
                admin_log_event('info', 'auth.google_disconnected', t('admin.google.log_disconnected', 'Admin disconnected a Google account.'), [
                    'user_id' => (int) $user['id'],
                    'username' => (string) $user['username'],
                ]);
                redirect_to(url_for('admin_account', ['google' => 'disconnected']));
            }
            }
        } elseif ($accountAction === 'openai_text_settings') {
            if (function_exists('Gallery\\Services\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('openai_text_assist')) {
                $error = t('admin.openai.feature_disabled', 'OpenAI text assistance is disabled in Admin > Features.');
            } else {
            // $currentPassword stores the profile password used to authorize credential changes.
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            if (!auth_account_password_matches((int) $user['id'], $currentPassword)) {
                $error = t('admin.account.error_current_password_required');
            } else {
                // $result stores the validated and saved OpenAI profile settings.
                $result = openai_text_assist_save_user_settings((int) $user['id'], $_POST);
                if (!empty($result['ok'])) {
                    if (function_exists('Gallery\\Services\\admin_log_event')) {
                        admin_log_event('info', 'openai_text_assist.settings_updated', t('admin.openai.log_settings_updated', 'Admin updated OpenAI text-assistance profile settings.'), [
                            'user_id' => (int) $user['id'],
                            'enabled' => (bool) ($result['enabled'] ?? false),
                            'api_key_set' => (string) ($result['api_key_hint'] ?? '') !== '',
                            'model' => (string) ($result['model'] ?? ''),
                        ]);
                    }
                    redirect_to(url_for('admin_account', ['openai_saved' => 1]));
                }
                $error = implode(' ', (array) ($result['errors'] ?? []));
            }
            }
        } else {
            // Variable $currentPassword stores this steps working value.
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            // Variable $newUsername stores this steps working value.
            $newUsername = trim((string) ($_POST['username'] ?? ''));
            // Variable $newEmail stores this steps working value only when the optional email column is verified.
            $newEmail = $emailSchemaAvailable ? auth_account_normalize_email((string) ($_POST['email'] ?? '')) : '';
            // Variable $newPassword stores this steps working value.
            $newPassword = (string) ($_POST['new_password'] ?? '');
            // Variable $confirmPassword stores this steps working value.
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            // Variable $errors stores this steps working value.
            $errors = [];

            // Variable $account stores this steps working value.
            $account = auth_account_profile_row((int) $user['id'], $emailSchemaAvailable);
            if (!$account || !auth_account_password_matches((int) $user['id'], $currentPassword)) {
                $errors[] = t('admin.account.error_current_password_required');
            }
            if ($newUsername === '') {
                $errors[] = t('admin.account.error_username_required');
            }
            if ($emailSchemaAvailable && $newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('admin.account.error_recovery_email_invalid');
            }
            if ($newPassword !== '' && $newPassword !== $confirmPassword) {
                $errors[] = t('admin.account.error_password_confirmation');
            }
            if ($newPassword !== '' && strlen($newPassword) < 8) {
                $errors[] = t('admin.account.error_password_length');
            }
            if ($newUsername !== '') {
                if (auth_account_username_taken($newUsername, (int) $user['id'])) {
                    $errors[] = t('admin.account.error_username_taken');
                }
            }
            if ($emailSchemaAvailable && $newEmail !== '') {
                if (auth_account_email_taken($newEmail, (int) $user['id'])) {
                    $errors[] = t('admin.account.error_recovery_email_taken');
                }
            }
            if (!$errors) {
                auth_account_update_profile((int) $user['id'], $newUsername, $newEmail, $emailSchemaAvailable, $newPassword);
                if ($newPassword !== '' && function_exists('Gallery\Core\admin_auth_revoke_user_persistent_logins_for_request')) {
                    admin_auth_revoke_user_persistent_logins_for_request((int) $user['id']);
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                if ($newPassword !== '' && function_exists('Gallery\Core\admin_auth_issue_persistent_login_for_request')) {
                    try {
                        admin_auth_issue_persistent_login_for_request((int) $user['id']);
                    } catch (AuthenticationSchemaUnavailableException $exception) {
                        admin_log_event('warning', 'auth.persistent_login_schema_unavailable', 'Persistent login was not reissued after a password change because its schema state is unknown.', [
                            'operation' => 'account_password_change_issue',
                            'feature' => $exception->feature(),
                            'user_id' => (int) $user['id'],
                        ]);
                        flash_message('admin_notice', t('admin.auth.remember_temporarily_unavailable', 'You are signed in for this browser session, but persistent login is temporarily unavailable until the database/schema inspection issue is resolved.'));
                    }
                }
                redirect_to(url_for('admin_account', ['saved' => 1]));
            }
            // Variable $error stores this steps working value.
            $error = implode(' ', $errors);
        }
    }

    // Variable $user stores this steps working value.
    $user = current_user() ?: $user;
    // $resetSettings stores an intermediate value used by the surrounding gallery workflow.
    $resetSettings = cms_password_reset_settings();
    // $accountEmail stores an intermediate value used by the surrounding gallery workflow.
    $accountEmail = trim((string) ($user['email'] ?? ''));
    // $resetReady stores an intermediate value used by the surrounding gallery workflow.
    $resetReady = schema_inspection_is_available($resetSchemaStatus) && $resetSettings['enabled'] && $accountEmail !== '' && $resetSettings['from_email'] !== '';
    // $openaiFeatureEnabled stores whether OpenAI profile controls should be visible.
    $openaiFeatureEnabled = !function_exists('Gallery\\Services\\feature_capability_effective_enabled') || feature_capability_effective_enabled('openai_text_assist');
    // $openaiSettings stores the current user's optional OpenAI profile integration settings.
    $openaiSettings = $openaiFeatureEnabled && function_exists('Gallery\\Services\\openai_text_assist_user_settings') ? openai_text_assist_user_settings((int) $user['id']) : [];
    // $openaiSchemaReady stores whether the required optional OpenAI settings table exists.
    $openaiSchemaReady = $openaiFeatureEnabled && function_exists('Gallery\\Services\\openai_text_assist_schema_ready') && openai_text_assist_schema_ready();
    // $openaiReady stores whether the current account can use OpenAI text assistance right now.
    $openaiReady = $openaiFeatureEnabled && function_exists('Gallery\\Services\\openai_text_assist_available') && openai_text_assist_available((int) $user['id']);
    // $openaiImageInputColumnReady stores whether the optional thumbnail-consent setting can be saved yet.
    $openaiImageInputColumnReady = $openaiFeatureEnabled && function_exists('Gallery\\Services\\openai_text_assist_image_input_column_ready') && openai_text_assist_image_input_column_ready();
    // $googleSchemaReady stores whether the linked Google account table exists and was verified.
    $googleSchemaReady = schema_inspection_is_available($googleSchemaStatus);
    // $googleConfig stores the OAuth client readiness state and callback URL.
    $googleConfig = function_exists('Gallery\\Services\\google_auth_config') ? google_auth_config() : ['redirect_uri' => ''];
    // $googleReady stores whether Google login has complete config and verified database support.
    $googleReady = google_auth_configuration_ready($googleConfig) && $googleSchemaReady;
    // $googleLinkedAccount stores the Google identity linked to the current admin profile only after schema verification.
    $googleLinkedAccount = $googleSchemaReady && function_exists('Gallery\\Services\\google_auth_linked_account') ? google_auth_linked_account((int) $user['id']) : null;

    // $notices contains request/session messages resolved before presentation.
    $notices = [];
    if (isset($_GET['saved'])) {
        $notices[] = t('admin.account.notice_saved');
    }
    if (isset($_GET['reset_settings_saved'])) {
        $notices[] = t('admin.account.notice_reset_settings_saved');
    }
    if (isset($_GET['test_email'])) {
        $notices[] = $_GET['test_email'] === 'sent' ? t('admin.account.notice_test_email_sent') : t('admin.account.notice_test_email_failed');
    }
    if (isset($_GET['openai_saved'])) {
        $notices[] = t('admin.openai.notice_saved', 'OpenAI text-assistance settings were saved.');
    }
    if ((string) ($_GET['google'] ?? '') === 'linked') {
        $notices[] = t('admin.google.notice_linked', 'Google account linked. You can now use Continue with Google on the login page.');
    }
    if ((string) ($_GET['google'] ?? '') === 'disconnected') {
        $notices[] = t('admin.google.notice_disconnected', 'Google account disconnected. Password login remains available.');
    }
    $flash = flash_message('admin_notice');
    if ($flash !== null && $flash !== '') {
        $notices[] = $flash;
    }

    // $googleStatus is a presentation-safe enum derived from schema/config/account policy.
    if (schema_inspection_is_unknown($googleSchemaStatus)) {
        $googleStatus = 'schema_unknown';
    } elseif (!$googleSchemaReady) {
        $googleStatus = 'migration_required';
    } elseif (!$googleReady) {
        $googleStatus = 'config_required';
    } elseif ($googleLinkedAccount) {
        $googleStatus = 'linked';
    } else {
        $googleStatus = 'ready_to_link';
    }

    // OpenAI presentation values are normalized here so the view performs no domain-service lookup.
    $openaiEnabled = $openaiSchemaReady && (int) ($openaiSettings['enabled'] ?? 0) === 1;
    $openaiAllowImageInput = $openaiSchemaReady && (int) ($openaiSettings['allow_image_input'] ?? 0) === 1;
    $openaiKeyHint = $openaiSchemaReady ? (string) ($openaiSettings['api_key_hint'] ?? '') : '';
    $openaiModel = $openaiSchemaReady
        ? openai_text_assist_normalize_model((string) ($openaiSettings['model'] ?? OPENAI_TEXT_ASSIST_DEFAULT_MODEL))
        : OPENAI_TEXT_ASSIST_DEFAULT_MODEL;
    $openaiModels = $openaiSchemaReady && function_exists('Gallery\Services\openai_text_assist_model_catalog')
        ? openai_text_assist_model_catalog()
        : [];

    \Gallery\Views\view_render_admin_account([
        'user' => $user,
        'notices' => $notices,
        'error' => isset($error) ? (string) $error : '',
        'csrf_html' => csrf_field(),
        'site_name' => site_name(),
        'central_settings_url' => admin_settings_url('advanced'),
        'account_email' => $accountEmail,
        'email_schema_available' => $emailSchemaAvailable,
        'email_schema_unknown' => schema_inspection_is_unknown($emailSchemaStatus),
        'reset_settings' => $resetSettings,
        'reset_ready' => $resetReady,
        'google_ready' => $googleReady,
        'google_status' => $googleStatus,
        'google_redirect_uri' => (string) ($googleConfig['redirect_uri'] ?? ''),
        'google_linked_account' => $googleLinkedAccount,
        'google_link_url' => url_for('admin_google_start', ['mode' => 'link', 'return' => url_for('admin_account')]),
        'openai_feature_enabled' => $openaiFeatureEnabled,
        'openai_schema_ready' => $openaiSchemaReady,
        'openai_ready' => $openaiReady,
        'openai_image_input_column_ready' => $openaiImageInputColumnReady,
        'openai_enabled' => $openaiEnabled,
        'openai_allow_image_input' => $openaiAllowImageInput,
        'openai_key_hint' => $openaiKeyHint,
        'openai_model' => $openaiModel,
        'openai_models' => $openaiModels,
    ]);
}

/**
 * Handles cms admin reset logic for the gallery application.
 */
function cms_admin_reset(): void
{
    require_admin();
    // $error stores an intermediate value used by the surrounding gallery workflow.
    $error = null;
    // $notice stores an intermediate value used by the surrounding gallery workflow.
    $notice = '';

    if (request_method() === 'POST') {
        verify_csrf();
        try {
            // $result stores the durable restore job returned by the shared updater engine.
            $result = application_update_start_job('stable_restore', [], 'admin');
            admin_log_event('info', 'update.stable_restore_started', t('admin.reset.log_restore_started'), [
                'job_id' => (string) ($result['id'] ?? ''),
                'stage' => (string) ($result['stage'] ?? ''),
            ]);
            // $notice stores an intermediate value used by the surrounding gallery workflow.
            $notice = t('admin.reset.restore_started_notice');
        } catch (Throwable $exception) {
            $safeError = application_update_safe_error($exception);
            admin_log_event('warning', 'update.reset_failed', t('admin.reset.log_reset_failed'), [
                'reference' => (string) ($safeError['reference'] ?? ''),
            ]);
            // $error stores only the updater redaction boundary result.
            $error = (string) ($safeError['message'] ?? t('admin.reset.log_reset_failed'))
                . ' Reference: ' . (string) ($safeError['reference'] ?? '');
        }
    }

    \Gallery\Views\view_render_admin_stable_reset([
        'notice' => $notice,
        'error' => $error !== null ? $error : '',
        'dashboard_url' => url_for('admin'),
        'updates_url' => url_for('admin_update'),
        'csrf_html' => csrf_field(),
    ]);
}

