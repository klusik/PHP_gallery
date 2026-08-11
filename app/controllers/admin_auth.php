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
use const Gallery\Services\OPENAI_TEXT_ASSIST_DEFAULT_MODEL;
use function Gallery\Core\absolute_public_url;
use function Gallery\Core\cms_config;
use function Gallery\Core\csrf_field;
use function Gallery\Core\current_login_return_target;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\now_sql;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\sanitize_login_return_target;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\visitor_hash;
use function Gallery\Services\app_setting;
use function Gallery\Services\auth_issue_persistent_login;
use function Gallery\Services\auth_persistence_config;
use function Gallery\Services\auth_persistent_login_ready;
use function Gallery\Services\auth_revoke_current_persistent_login;
use function Gallery\Services\auth_revoke_user_persistent_logins;
use function Gallery\Services\auth_throttle_check;
use function Gallery\Services\auth_throttle_clear;
use function Gallery\Services\auth_throttle_log;
use function Gallery\Services\auth_throttle_normalize_identifier;
use function Gallery\Services\auth_throttle_record_attempt;
use function Gallery\Services\auth_throttle_visitor_subject;
use function Gallery\Services\telemetry_request_id;
use function Gallery\Services\db_column_exists;
use function Gallery\Services\db_table_exists;
use function Gallery\Services\feature_flag_enabled;
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
use function Gallery\Services\restore_application_stable_release;
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
 * Normalize an optional account email value before validation or storage.
 *
 * @param string $email Email value.
 * @return string Text result for the caller.
 */
function cms_normalize_account_email(string $email): string
{
    return trim(strtolower($email));
}

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

    if (function_exists('Gallery\\Services\\db_column_exists') && db_column_exists('users', 'email')) {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT * FROM users WHERE email IS NOT NULL AND LOWER(email) = LOWER(?) LIMIT 1');
        $stmt->execute([$normalizedIdentifier]);
        // Variable $user stores this steps working value.
        $user = $stmt->fetch();
        if ($user) {
            return $user;
        }
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$normalizedIdentifier]);
    // Variable $user stores this steps working value.
    $user = $stmt->fetch();
    return $user ?: null;
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
    $fromEmail = cms_normalize_account_email((string) ($input['password_reset_from_email'] ?? ''));
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
    return function_exists('Gallery\\Services\\db_table_exists') && db_table_exists('password_reset_tokens');
}

/**
 * Remove expired or already used password reset rows so the table stays small.
 */
function cms_cleanup_password_reset_tokens(): void
{
    if (!cms_password_reset_schema_ready()) {
        return;
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('DELETE FROM password_reset_tokens WHERE expires_at < ? OR used_at IS NOT NULL');
    $stmt->execute([now_sql()]);
}

/**
 * Create a one-time password reset token and return the public selector/token pair.
 *
 * @param int $userId User id identifier.
 * @return ?array Structured result data for the caller.
 */
function cms_create_password_reset_token(int $userId): ?array
{
    if (!cms_password_reset_schema_ready()) {
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
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL');
    $stmt->execute([now_sql(), $userId]);

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('INSERT INTO password_reset_tokens (user_id, selector, token_hash, requested_at, expires_at, request_hash) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $selector, $tokenHash, now_sql(), $expiresAt, $requestHash]);

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
    if (!cms_password_reset_schema_ready() || $selector === '' || $token === '') {
        return null;
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT prt.*, u.username, u.email FROM password_reset_tokens prt INNER JOIN users u ON u.id = prt.user_id WHERE prt.selector = ? AND prt.used_at IS NULL AND prt.expires_at >= ? LIMIT 1');
    $stmt->execute([$selector, now_sql()]);
    // Variable $row stores this steps working value.
    $row = $stmt->fetch();
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

    if (!function_exists('Gallery\\Services\\google_auth_ready') || !google_auth_ready()) {
        flash_message('admin_notice', t('admin.google.not_configured', 'Google login is not configured yet. Add the OAuth client ID and secret to config.php, then run the database migrations.'));
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
        if (function_exists('Gallery\\Services\\auth_issue_persistent_login')) {
            auth_issue_persistent_login((int) $linkedUser['id']);
        }
        if (function_exists('Gallery\\Services\\google_auth_touch_login')) {
            google_auth_touch_login((int) $linkedUser['google_account_id']);
        }
        admin_log_event('info', 'auth.google_login', t('admin.google.log_login', 'Admin logged in with Google.'), [
            'user_id' => (int) $linkedUser['id'],
            'username' => (string) $linkedUser['username'],
        ]);
        redirect_to($returnTarget);
    } catch (Throwable $exception) {
        admin_log_event('warning', 'auth.google_callback_failed', t('admin.google.log_callback_failed', 'Google login callback failed.'), [
            'mode' => $mode,
            'error' => $exception->getMessage(),
        ]);
        flash_message('admin_notice', t('admin.google.callback_failed', 'Google login could not be completed: {error}', ['error' => $exception->getMessage()]));
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
        $visitorSubject = auth_throttle_visitor_subject();
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
            $user = cms_find_admin_user_by_identifier($identifier);
            if ($user && password_verify((string) ($_POST['password'] ?? ''), (string) $user['password_hash'])) {
                auth_throttle_clear('admin_login_visitor', $visitorSubject);
                if ($normalizedIdentifier !== '') {
                    auth_throttle_clear('admin_login_identifier', $normalizedIdentifier);
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                if (!empty($_POST['remember_login']) && function_exists('Gallery\\Services\\auth_issue_persistent_login')) {
                    auth_issue_persistent_login((int) $user['id']);
                }
                // Redirect to the page where the visitor clicked the login link, not always to the admin dashboard.
                redirect_to($returnTarget);
            }
            auth_throttle_record_attempt('admin_login_visitor', $visitorSubject);
            if ($normalizedIdentifier !== '') {
                auth_throttle_record_attempt('admin_login_identifier', $normalizedIdentifier);
            }
            // Variable $error stores this steps working value.
            $error = t('admin.auth.invalid_login');
        }
    }
    render_header(t('admin.auth.login_title'));
    if (isset($_GET['reset'])) {
        echo '<div class="notice">' . e(t('admin.auth.password_reset_completed')) . '</div>';
    }
    if ((string) ($_GET['google'] ?? '') === 'not_linked') {
        echo '<div class="notice">' . e(t('admin.google.login_not_linked', 'This Google account is not linked to an admin profile yet. Log in with your password first, then link Google in Account settings.')) . '</div>';
    }
    if ($flash = flash_message('admin_notice')) {
        echo '<div class="notice">' . e($flash) . '</div>';
    }
    if (isset($error)) {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    // $rememberConfig stores persistent login defaults for the checkbox below.
    $rememberConfig = function_exists('Gallery\\Services\\auth_persistence_config') ? auth_persistence_config() : ['persistent_login_default_checked' => true];
    // $rememberReady stores whether DB-backed persistent login can be issued on this installation.
    $rememberReady = function_exists('Gallery\\Services\\auth_persistent_login_ready') && auth_persistent_login_ready();
    // $googleReady stores whether Google sign-in can be used on this installation.
    $googleReady = function_exists('Gallery\\Services\\google_auth_ready') && google_auth_ready();
    echo '<section class="panel"><h1>' . e(t('admin.auth.login_title')) . '</h1><form method="post" class="form-grid">';
    echo csrf_field();
    // Keep the sanitized return target through failed login attempts without exposing unsafe redirect data.
    echo '<input type="hidden" name="return" value="' . e($returnTarget) . '">';
    echo '<label>' . e(t('admin.auth.username_or_email')) . '<input name="identifier" required autocomplete="username"></label>';
    echo '<label>' . e(t('admin.auth.password')) . '<input name="password" type="password" required autocomplete="current-password"></label>';
    if ($rememberReady) {
        echo '<label class="account-settings-toggle account-settings-compact-toggle"><input type="checkbox" name="remember_login" value="1"' . (!empty($rememberConfig['persistent_login_default_checked']) ? ' checked' : '') . '> <span><strong>' . e(t('admin.auth.keep_signed_in', 'Keep me signed in')) . '</strong><small>' . e(t('admin.auth.keep_signed_in_help', 'Uses a hashed browser token so the admin session can survive normal shared-host PHP session cleanup.')) . '</small></span></label>';
    }
    echo '<button type="submit">' . e(t('admin.auth.login_button')) . '</button></form>';
    if ($googleReady) {
        echo '<div class="admin-google-login-choice"><span>' . e(t('admin.auth.or', 'or')) . '</span><a class="button secondary" href="' . e(url_for('admin_google_start', ['mode' => 'login', 'return' => $returnTarget])) . '">' . e(t('admin.google.continue_with_google', 'Continue with Google')) . '</a></div>';
    }
    echo '<p class="muted"><a href="' . e(url_for('admin_forgot_password')) . '">' . e(t('admin.auth.forgot_password_link')) . '</a></p></section>';
    render_footer();
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
        $visitorSubject = auth_throttle_visitor_subject();
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
            // Variable $user stores this steps working value.
            $user = cms_find_admin_user_by_identifier($identifier);
            if ($user && trim((string) ($user['email'] ?? '')) !== '') {
                // $token stores an intermediate value used by the surrounding gallery workflow.
                $token = cms_create_password_reset_token((int) $user['id']);
                if ($token) {
                    // $resetUrl stores an intermediate value used by the surrounding gallery workflow.
                    $resetUrl = cms_password_reset_url((string) $token['selector'], (string) $token['token']);
                    // $delivery stores safe mail diagnostics for the admin log without storing the submitted identifier or token value.
                    $delivery = cms_send_password_reset_email($user, $resetUrl, (string) $token['expires_at']);
                    admin_log_event(!empty($delivery['sent']) ? 'info' : 'warning', 'auth.password_reset_requested', !empty($delivery['sent']) ? t('admin.auth.log_password_reset_email_sent') : t('admin.auth.log_password_reset_token_created_no_email'), [
                        'identifier_sha256' => hash('sha256', cms_normalize_account_email($identifier)),
                        'identifier_looks_like_email' => filter_var(trim($identifier), FILTER_VALIDATE_EMAIL) !== false,
                        'visitor_hash' => visitor_hash(),
                        'request_id' => function_exists('Gallery\\Services\\telemetry_request_id') ? telemetry_request_id() : '',
                        'user_id' => (int) $user['id'],
                        'username' => (string) $user['username'],
                        'token_selector' => (string) $token['selector'],
                        'email_delivery' => $delivery,
                    ]);
                }
            }
        }
        $notice = t('admin.auth.reset_link_sent_if_possible');
    }

    render_header(t('admin.auth.forgot_password_title'));
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('admin.auth.forgot_password_title')) . '</h1>';
    echo '<p class="muted">' . e(t('admin.auth.forgot_password_help')) . '</p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<label>' . e(t('admin.auth.username_or_recovery_email')) . '<input name="identifier" required autocomplete="username"></label>';
    echo '<button type="submit">' . e(t('admin.auth.request_reset_link')) . '</button></form>';
    echo '<p class="muted"><a href="' . e(url_for('admin_login')) . '">' . e(t('admin.auth.back_to_login')) . '</a></p></section>';
    render_footer();
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
    $resetRow = cms_find_valid_password_reset_token($selector, $token);
    // Variable $error stores this steps working value.
    $error = '';

    if (!$resetRow) {
        render_header(t('admin.auth.reset_password_title'));
        echo '<section class="panel"><h1>' . e(t('admin.auth.reset_password_title')) . '</h1><div class="notice">' . e(t('admin.auth.reset_link_invalid')) . '</div>';
        echo '<p><a class="button secondary" href="' . e(url_for('admin_forgot_password')) . '">' . e(t('admin.auth.request_new_reset_link')) . '</a></p></section>';
        render_footer();
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
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), now_sql(), (int) $resetRow['user_id']]);
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('UPDATE password_reset_tokens SET used_at = ? WHERE id = ?');
            $stmt->execute([now_sql(), (int) $resetRow['id']]);
            admin_log_event('info', 'auth.password_reset_completed', t('admin.auth.log_password_reset_completed'), [
                'user_id' => (int) $resetRow['user_id'],
            ]);
            redirect_to(url_for('admin_login', ['reset' => 1]));
        }
    }

    render_header(t('admin.auth.reset_password_title'));
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    echo '<section class="panel"><h1>' . e(t('admin.auth.reset_password_title')) . '</h1>';
    echo '<p class="muted">' . e(t('admin.auth.set_new_password_for', ['username' => (string) $resetRow['username']])) . '</p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="selector" value="' . e($selector) . '">';
    echo '<input type="hidden" name="token" value="' . e($token) . '">';
    echo '<label>' . e(t('admin.auth.new_password')) . '<input name="new_password" type="password" required minlength="8" autocomplete="new-password"></label>';
    echo '<label>' . e(t('admin.auth.confirm_new_password')) . '<input name="confirm_password" type="password" required minlength="8" autocomplete="new-password"></label>';
    echo '<button type="submit">' . e(t('admin.auth.save_new_password')) . '</button></form></section>';
    render_footer();
}

/**
 * Handles cms admin logout logic for the gallery application.
 */
function cms_admin_logout(): void
{
    if (function_exists('Gallery\\Services\\auth_revoke_current_persistent_login')) {
        auth_revoke_current_persistent_login();
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
        } elseif ($accountAction === 'google_disconnect') {
            // $currentPassword stores the profile password used to authorize Google unlinking.
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            // $stmt stores the password hash for the authenticated profile owner.
            $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([(int) $user['id']]);
            // $account stores the account row needed for the password challenge.
            $account = $stmt->fetch();
            if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
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
        } elseif ($accountAction === 'openai_text_settings') {
            if (function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled('openai_text_assist')) {
                $error = t('admin.openai.feature_disabled', 'OpenAI text assistance is disabled in Admin > Features.');
            } else {
            // $currentPassword stores the profile password used to authorize credential changes.
            $currentPassword = (string) ($_POST['current_password'] ?? '');
            // $stmt stores the password hash for the authenticated profile owner.
            $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
            $stmt->execute([(int) $user['id']]);
            // $account stores the account row needed for the password challenge.
            $account = $stmt->fetch();
            if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
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
            // Variable $newEmail stores this steps working value.
            $newEmail = cms_normalize_account_email((string) ($_POST['email'] ?? ''));
            // Variable $newPassword stores this steps working value.
            $newPassword = (string) ($_POST['new_password'] ?? '');
            // Variable $confirmPassword stores this steps working value.
            $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
            // Variable $errors stores this steps working value.
            $errors = [];

            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare('SELECT username, email, password_hash FROM users WHERE id = ?');
            $stmt->execute([(int) $user['id']]);
            // Variable $account stores this steps working value.
            $account = $stmt->fetch();
            if (!$account || !password_verify($currentPassword, (string) $account['password_hash'])) {
                $errors[] = t('admin.account.error_current_password_required');
            }
            if ($newUsername === '') {
                $errors[] = t('admin.account.error_username_required');
            }
            if ($newEmail !== '' && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $errors[] = t('admin.account.error_recovery_email_invalid');
            }
            if ($newPassword !== '' && $newPassword !== $confirmPassword) {
                $errors[] = t('admin.account.error_password_confirmation');
            }
            if ($newPassword !== '' && strlen($newPassword) < 8) {
                $errors[] = t('admin.account.error_password_length');
            }
            if ($newUsername !== '') {
                // Variable $stmt stores this steps working value.
                $stmt = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
                $stmt->execute([$newUsername, (int) $user['id']]);
                if ($stmt->fetch()) {
                    $errors[] = t('admin.account.error_username_taken');
                }
            }
            if ($newEmail !== '') {
                // Variable $stmt stores this steps working value.
                $stmt = db()->prepare('SELECT id FROM users WHERE email IS NOT NULL AND LOWER(email) = LOWER(?) AND id <> ?');
                $stmt->execute([$newEmail, (int) $user['id']]);
                if ($stmt->fetch()) {
                    $errors[] = t('admin.account.error_recovery_email_taken');
                }
            }
            if (!$errors) {
                // $sql stores an intermediate value used by the surrounding gallery workflow.
                $sql = 'UPDATE users SET username = ?, email = ?, updated_at = ?';
                // Variable $params stores this steps working value.
                $params = [$newUsername, $newEmail === '' ? null : $newEmail, now_sql()];
                if ($newPassword !== '') {
                    $sql .= ', password_hash = ?';
                    $params[] = password_hash($newPassword, PASSWORD_DEFAULT);
                }
                $sql .= ' WHERE id = ?';
                $params[] = (int) $user['id'];
                // Variable $stmt stores this steps working value.
                $stmt = db()->prepare($sql);
                $stmt->execute($params);
                if ($newPassword !== '' && function_exists('Gallery\\Services\\auth_revoke_user_persistent_logins')) {
                    auth_revoke_user_persistent_logins((int) $user['id']);
                }
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int) $user['id'];
                if ($newPassword !== '' && function_exists('Gallery\\Services\\auth_issue_persistent_login')) {
                    auth_issue_persistent_login((int) $user['id']);
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
    $resetReady = cms_password_reset_schema_ready() && $resetSettings['enabled'] && $accountEmail !== '' && $resetSettings['from_email'] !== '';
    // $openaiFeatureEnabled stores whether OpenAI profile controls should be visible.
    $openaiFeatureEnabled = !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('openai_text_assist');
    // $openaiSettings stores the current user's optional OpenAI profile integration settings.
    $openaiSettings = $openaiFeatureEnabled && function_exists('Gallery\\Services\\openai_text_assist_user_settings') ? openai_text_assist_user_settings((int) $user['id']) : [];
    // $openaiSchemaReady stores whether the required optional OpenAI settings table exists.
    $openaiSchemaReady = $openaiFeatureEnabled && function_exists('Gallery\\Services\\openai_text_assist_schema_ready') && openai_text_assist_schema_ready();
    // $openaiReady stores whether the current account can use OpenAI text assistance right now.
    $openaiReady = $openaiFeatureEnabled && function_exists('Gallery\\Services\\openai_text_assist_available') && openai_text_assist_available((int) $user['id']);
    // $openaiImageInputColumnReady stores whether the optional thumbnail-consent setting can be saved yet.
    $openaiImageInputColumnReady = $openaiFeatureEnabled && function_exists('Gallery\\Services\\openai_text_assist_image_input_column_ready') && openai_text_assist_image_input_column_ready();
    // $googleSchemaReady stores whether the linked Google account table exists.
    $googleSchemaReady = function_exists('Gallery\\Services\\google_auth_schema_ready') && google_auth_schema_ready();
    // $googleReady stores whether Google login has complete config and database support.
    $googleReady = function_exists('Gallery\\Services\\google_auth_ready') && google_auth_ready();
    // $googleConfig stores the OAuth client readiness state and callback URL.
    $googleConfig = function_exists('Gallery\\Services\\google_auth_config') ? google_auth_config() : ['redirect_uri' => ''];
    // $googleLinkedAccount stores the Google identity linked to the current admin profile.
    $googleLinkedAccount = function_exists('Gallery\\Services\\google_auth_linked_account') ? google_auth_linked_account((int) $user['id']) : null;

    render_header(t('admin.account.title'));
    if (isset($_GET['saved'])) {
        echo '<div class="notice">' . e(t('admin.account.notice_saved')) . '</div>';
    }
    if (isset($_GET['reset_settings_saved'])) {
        echo '<div class="notice">' . e(t('admin.account.notice_reset_settings_saved')) . '</div>';
    }
    if (isset($_GET['test_email'])) {
        echo '<div class="notice">' . e($_GET['test_email'] === 'sent' ? t('admin.account.notice_test_email_sent') : t('admin.account.notice_test_email_failed')) . '</div>';
    }
    if (isset($_GET['openai_saved'])) {
        echo '<div class="notice">' . e(t('admin.openai.notice_saved', 'OpenAI text-assistance settings were saved.')) . '</div>';
    }
    if ((string) ($_GET['google'] ?? '') === 'linked') {
        echo '<div class="notice">' . e(t('admin.google.notice_linked', 'Google account linked. You can now use Continue with Google on the login page.')) . '</div>';
    }
    if ((string) ($_GET['google'] ?? '') === 'disconnected') {
        echo '<div class="notice">' . e(t('admin.google.notice_disconnected', 'Google account disconnected. Password login remains available.')) . '</div>';
    }
    if ($flash = flash_message('admin_notice')) {
        echo '<div class="notice">' . e($flash) . '</div>';
    }
    if (isset($error)) {
        echo '<div class="notice">' . e($error) . '</div>';
    }

    echo '<section class="panel account-settings-page">';
    echo '<div class="account-settings-hero">';
    echo '<div><p class="account-settings-kicker">' . e(t('admin.account.kicker')) . '</p><h1>' . e(t('admin.account.title')) . '</h1><p class="muted">' . e(t('admin.account.description')) . '</p></div>';
    echo '<div class="nav"><a class="button secondary" href="' . e(admin_settings_url('advanced')) . '">' . e(t('admin.settings.open_centralized', 'Open centralized settings')) . '</a></div>';
    echo '<div class="account-settings-status ' . ($resetReady ? 'is-ready' : 'is-incomplete') . '">';
    echo '<span class="account-settings-status-label">' . e(t('admin.account.password_reset')) . '</span>';
    echo '<strong>' . e($resetReady ? t('admin.account.status_ready') : t('admin.account.status_needs_setup')) . '</strong>';
    echo '<small>' . e($resetReady ? t('admin.account.status_ready_help') : t('admin.account.status_needs_setup_help')) . '</small>';
    echo '</div></div>';
    echo '<div class="account-settings-grid">';

    echo '<article class="account-settings-card">';
    echo '<div class="account-settings-card-header"><div><h2>' . e(t('admin.account.profile_title')) . '</h2><p class="muted">' . e(t('admin.account.profile_description')) . '</p></div></div>';
    echo '<form method="post" class="form-grid account-settings-form">';
    echo csrf_field();
    echo '<input type="hidden" name="account_action" value="profile">';
    echo '<label>' . e(t('admin.account.username')) . '<input name="username" required autocomplete="username" value="' . e((string) $user['username']) . '"></label>';
    echo '<label>' . e(t('admin.account.recovery_email')) . '<input name="email" type="email" autocomplete="email" value="' . e($accountEmail) . '" placeholder="admin@example.com"></label>';
    echo '<p class="account-settings-help">' . e(t('admin.account.recovery_email_help')) . '</p>';
    echo '<div class="account-settings-callout"><strong>' . e(t('admin.account.before_save')) . '</strong> ' . e(t('admin.account.before_save_help')) . '</div>';
    echo '<label>' . e(t('admin.account.current_password')) . '<input name="current_password" type="password" required autocomplete="current-password"></label>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.new_password')) . '<input name="new_password" type="password" autocomplete="new-password" placeholder="' . e(t('admin.account.new_password_placeholder')) . '"></label>';
    echo '<label>' . e(t('admin.account.confirm_new_password')) . '<input name="confirm_password" type="password" autocomplete="new-password" placeholder="' . e(t('admin.account.confirm_new_password_placeholder')) . '"></label>';
    echo '</div>';
    echo '<p class="account-settings-help">' . e(t('admin.account.password_optional_help')) . '</p>';
    echo '<div class="account-settings-actions"><button type="submit">' . e(t('admin.account.save_account')) . '</button></div></form></article>';

    echo '<article class="account-settings-card">';
    echo '<div class="account-settings-card-header"><div><h2>' . e(t('admin.account.reset_email_title')) . '</h2><p class="muted">' . e(t('admin.account.reset_email_description')) . '</p></div></div>';
    echo '<div class="account-settings-readiness">';
    echo '<strong>' . e(t('admin.account.recovery_status')) . '</strong> ' . e($resetReady ? t('admin.account.recovery_status_ready') : t('admin.account.recovery_status_incomplete')) . '</div>';
    echo '<form method="post" class="form-grid account-settings-form account-settings-reset-form">' . csrf_field();
    echo '<input type="hidden" name="account_action" value="password_reset_settings">';
    echo '<label class="account-settings-toggle"><input type="checkbox" name="password_reset_enabled" value="1"' . ($resetSettings['enabled'] ? ' checked' : '') . '> <span><strong>' . e(t('admin.account.enable_reset_emails')) . '</strong><small>' . e(t('admin.account.enable_reset_emails_help')) . '</small></span></label>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.mail_transport')) . '<select name="password_reset_transport"><option value="php_mail"' . ($resetSettings['transport'] === 'php_mail' ? ' selected' : '') . '>' . e(t('admin.account.transport_php_mail')) . '</option><option value="smtp"' . ($resetSettings['transport'] === 'smtp' ? ' selected' : '') . '>' . e(t('admin.account.transport_smtp')) . '</option></select></label>';
    echo '<label>' . e(t('admin.account.reset_link_lifetime')) . '<input name="password_reset_token_lifetime_minutes" type="number" min="15" max="1440" step="1" value="' . e((string) $resetSettings['token_lifetime_minutes']) . '"></label>';
    echo '</div>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.sender_email')) . '<input name="password_reset_from_email" type="email" autocomplete="email" value="' . e((string) $resetSettings['from_email']) . '" placeholder="no-reply@example.com"></label>';
    echo '<label>' . e(t('admin.account.sender_name')) . '<input name="password_reset_from_name" value="' . e((string) $resetSettings['from_name']) . '" placeholder="' . e(site_name()) . '"></label>';
    echo '</div>';
    echo '<details class="account-settings-details" open><summary>' . e(t('admin.account.smtp_settings')) . '</summary>';
    echo '<p class="account-settings-help">' . e(t('admin.account.smtp_help')) . '</p>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.smtp_host')) . '<input name="password_reset_smtp_host" value="' . e((string) $resetSettings['smtp_host']) . '" placeholder="smtp.example.com"></label>';
    echo '<label>' . e(t('admin.account.smtp_port')) . '<input name="password_reset_smtp_port" type="number" min="1" max="65535" step="1" value="' . e((string) $resetSettings['smtp_port']) . '"></label>';
    echo '</div>';
    echo '<label>' . e(t('admin.account.smtp_encryption')) . '<select name="password_reset_smtp_encryption"><option value="tls"' . ($resetSettings['smtp_encryption'] === 'tls' ? ' selected' : '') . '>STARTTLS</option><option value="ssl"' . ($resetSettings['smtp_encryption'] === 'ssl' ? ' selected' : '') . '>' . e(t('admin.account.smtp_implicit_tls')) . '</option><option value="none"' . ($resetSettings['smtp_encryption'] === 'none' ? ' selected' : '') . '>' . e(t('admin.common.none')) . '</option></select></label>';
    echo '<div class="account-settings-two-column">';
    echo '<label>' . e(t('admin.account.smtp_username')) . '<input name="password_reset_smtp_username" autocomplete="username" value="' . e((string) $resetSettings['smtp_username']) . '"></label>';
    echo '<label>' . e(t('admin.account.smtp_password')) . '<input name="password_reset_smtp_password" type="password" autocomplete="new-password" placeholder="' . e(!empty($resetSettings['smtp_password']) ? t('admin.account.smtp_password_placeholder_keep') : '') . '"></label>';
    echo '</div>';
    echo '<input type="hidden" name="keep_existing_smtp_password" value="1">';
    echo '<p class="account-settings-help">' . e(t('admin.account.smtp_password_help')) . '</p>';
    echo '</details>';
    echo '<div class="account-settings-actions"><button type="submit">' . e(t('admin.account.save_reset_settings')) . '</button></div></form>';
    echo '<form method="post" class="account-settings-test-form">' . csrf_field();
    echo '<input type="hidden" name="account_action" value="password_reset_test_email">';
    echo '<div><strong>' . e(t('admin.account.delivery_test')) . '</strong><p class="muted">' . e(t('admin.account.delivery_test_help')) . '</p></div>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.account.send_test_email')) . '</button></form></article>';

    echo '<article class="account-settings-card account-google-settings-card">';
    echo '<div class="account-settings-card-header"><div><h2>' . e(t('admin.google.profile_title', 'Google login')) . '</h2><p class="muted">' . e(t('admin.google.profile_description', 'Optional Google sign-in for this admin account. Password login remains available.')) . '</p></div></div>';
    echo '<div class="account-settings-readiness ' . ($googleReady ? 'is-ready' : 'is-incomplete') . '">';
    echo '<strong>' . e(t('admin.google.status', 'Status')) . '</strong> ';
    if (!$googleSchemaReady) {
        echo e(t('admin.google.status_migration_required', 'Database migration required before Google login can be configured.'));
    } elseif (!$googleReady) {
        echo e(t('admin.google.status_config_required', 'Add Google OAuth client ID and secret to config.php before linking accounts.'));
    } elseif ($googleLinkedAccount) {
        echo e(t('admin.google.status_linked', 'Linked and ready for login.'));
    } else {
        echo e(t('admin.google.status_ready_to_link', 'Configured. Link this profile to a Google account before using Google login.'));
    }
    echo '</div>';
    echo '<p class="account-settings-help"><strong>' . e(t('admin.google.callback_url', 'Authorized redirect URI')) . ':</strong> <code>' . e((string) ($googleConfig['redirect_uri'] ?? '')) . '</code></p>';
    if ($googleLinkedAccount) {
        echo '<div class="account-settings-callout"><strong>' . e(t('admin.google.linked_account', 'Linked Google account')) . '</strong> ' . e(trim((string) ($googleLinkedAccount['email'] ?? '')) !== '' ? (string) $googleLinkedAccount['email'] : t('admin.google.linked_account_no_email', 'Google account is linked without a stored email.')) . '</div>';
        if (!empty($googleLinkedAccount['name'])) {
            echo '<p class="account-settings-help">' . e(t('admin.google.linked_name', 'Google display name')) . ': ' . e((string) $googleLinkedAccount['name']) . '</p>';
        }
        echo '<form method="post" class="form-grid account-settings-form account-settings-google-form">' . csrf_field();
        echo '<input type="hidden" name="account_action" value="google_disconnect">';
        echo '<label>' . e(t('admin.account.current_password')) . '<input name="current_password" type="password" required autocomplete="current-password"></label>';
        echo '<div class="account-settings-actions"><button type="submit" class="button secondary">' . e(t('admin.google.disconnect', 'Disconnect Google account')) . '</button></div></form>';
    } elseif ($googleReady) {
        echo '<p class="account-settings-help">' . e(t('admin.google.link_help', 'Linking must be started while you are logged in with your normal admin password. After that, Google login will accept only this linked Google account.')) . '</p>';
        echo '<div class="account-settings-actions"><a class="button" href="' . e(url_for('admin_google_start', ['mode' => 'link', 'return' => url_for('admin_account')])) . '">' . e(t('admin.google.link_button', 'Link Google account')) . '</a></div>';
    }
    echo '</article>';

    if ($openaiFeatureEnabled) {
    echo '<article class="account-settings-card account-openai-settings-card">';
    echo '<div class="account-settings-card-header"><div><h2>' . e(t('admin.openai.profile_title', 'OpenAI text assistance')) . '</h2><p class="muted">' . e(t('admin.openai.profile_description', 'Optional profile-level API access for gallery description drafts and text cleanup.')) . '</p></div></div>';
    echo '<div class="account-settings-readiness ' . ($openaiReady ? 'is-ready' : 'is-incomplete') . '">';
    echo '<strong>' . e(t('admin.openai.status', 'Status')) . '</strong> ';
    if (!$openaiSchemaReady) {
        echo e(t('admin.openai.status_migration_required', 'Database migration required before this optional feature can be configured.'));
    } elseif ($openaiReady) {
        echo e(t('admin.openai.status_ready', 'Enabled and ready for this account.'));
    } else {
        echo e(t('admin.openai.status_disabled', 'Disabled. Gallery editors will not show AI controls.'));
    }
    echo '</div>';
    if ($openaiSchemaReady) {
        $openaiEnabled = (int) ($openaiSettings['enabled'] ?? 0) === 1;
        $openaiAllowImageInput = (int) ($openaiSettings['allow_image_input'] ?? 0) === 1;
        $openaiKeyHint = (string) ($openaiSettings['api_key_hint'] ?? '');
        $openaiModel = openai_text_assist_normalize_model((string) ($openaiSettings['model'] ?? OPENAI_TEXT_ASSIST_DEFAULT_MODEL));
        $openaiModels = function_exists('Gallery\\Services\\openai_text_assist_model_catalog') ? openai_text_assist_model_catalog() : [];
        echo '<form method="post" class="form-grid account-settings-form account-settings-openai-form">' . csrf_field();
        echo '<input type="hidden" name="account_action" value="openai_text_settings">';
        echo '<label class="account-settings-toggle"><input type="checkbox" name="openai_text_enabled" value="1"' . ($openaiEnabled ? ' checked' : '') . '> <span><strong>' . e(t('admin.openai.enable', 'Enable OpenAI text assistance')) . '</strong><small>' . e(t('admin.openai.enable_help', 'When enabled and a key is saved, selected editors can request reviewable AI text suggestions.')) . '</small></span></label>';
        if ($openaiKeyHint !== '') {
            echo '<p class="account-settings-key-status"><strong>' . e(t('admin.openai.saved_key', 'Saved key')) . ':</strong> ' . e($openaiKeyHint) . '</p>';
        }
        echo '<div class="account-settings-two-column">';
        echo '<label>' . e(t('admin.openai.api_key', 'OpenAI API key')) . '<input name="openai_text_api_key" type="password" autocomplete="new-password" placeholder="' . e($openaiKeyHint !== '' ? t('admin.openai.api_key_placeholder_keep', 'Leave blank to keep the saved key') : t('admin.openai.api_key_placeholder_new', 'sk-...')) . '"></label>';
        echo '<label>' . e(t('admin.openai.model', 'Model')) . '<select name="openai_text_model">';
        foreach ($openaiModels as $modelId => $modelInfo) {
            echo '<option value="' . e($modelId) . '"' . ($openaiModel === $modelId ? ' selected' : '') . '>' . e((string) ($modelInfo['label'] ?? $modelId)) . '</option>';
        }
        echo '</select></label>';
        echo '</div>';
        echo '<p class="account-settings-help">' . e(t('admin.openai.api_key_help', 'The key is encrypted before database storage. It is never shown again and is never written to admin logs.')) . '</p>';
        echo '<div class="account-openai-model-list" aria-label="' . e(t('admin.openai.model_choices', 'Available OpenAI models')) . '">';
        foreach ($openaiModels as $modelId => $modelInfo) {
            $isSelected = $openaiModel === $modelId;
            echo '<div class="account-openai-model-card' . ($isSelected ? ' is-selected' : '') . '">';
            echo '<div><strong>' . e((string) ($modelInfo['label'] ?? $modelId)) . '</strong><code>' . e($modelId) . '</code></div>';
            echo '<span>' . e((string) ($modelInfo['badge'] ?? '')) . '</span>';
            echo '<p>' . e((string) ($modelInfo['description'] ?? '')) . '</p>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p class="account-settings-help">' . e(t('admin.openai.model_help', 'Default: GPT-5.4 mini. You can change this later without changing gallery data.')) . '</p>';
        echo '<label class="account-settings-toggle"><input type="checkbox" name="openai_text_allow_image_input" value="1"' . ($openaiAllowImageInput ? ' checked' : '') . ($openaiImageInputColumnReady ? '' : ' disabled') . '> <span><strong>' . e(t('admin.openai.enable_image_input', 'Allow AI tools to send small image thumbnails to OpenAI')) . '</strong><small>' . e($openaiImageInputColumnReady ? t('admin.openai.enable_image_input_help', 'Default off. When enabled, photo and gallery AI actions may send small generated thumbnails, not originals, to describe visible content.') : t('admin.openai.enable_image_input_help_migration', 'Apply the latest database migration to save this optional thumbnail-consent setting.')) . '</small></span></label>';
        if ($openaiKeyHint !== '') {
            echo '<label class="account-settings-toggle account-settings-compact-toggle"><input type="checkbox" name="openai_text_clear_key" value="1"> <span><strong>' . e(t('admin.openai.clear_key', 'Clear saved API key')) . '</strong><small>' . e(t('admin.openai.clear_key_help', 'This disables OpenAI text assistance unless a new key is saved.')) . '</small></span></label>';
        }
        echo '<div class="account-settings-callout"><strong>' . e(t('admin.account.before_save')) . '</strong> ' . e(t('admin.account.before_save_help')) . '</div>';
        echo '<label>' . e(t('admin.openai.current_password', 'Current password')) . '<input name="current_password" type="password" required autocomplete="current-password"></label>';
        echo '<div class="account-settings-actions"><button type="submit">' . e(t('admin.openai.save_settings', 'Save OpenAI settings')) . '</button></div></form>';
    }
    echo '</article>';
    }

    echo '</div></section>';
    render_footer();
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
            // $result stores an intermediate value used by the surrounding gallery workflow.
            $result = restore_application_stable_release();
            admin_log_event('info', 'update.stable_restored', t('admin.reset.log_stable_restored'), $result);
            // $notice stores an intermediate value used by the surrounding gallery workflow.
            $notice = t('admin.reset.restored_notice', ['files' => (string) (int) $result['files_copied']]);
        } catch (Throwable $exception) {
            admin_log_event('warning', 'update.reset_failed', t('admin.reset.log_reset_failed'), ['error' => $exception->getMessage()]);
            // $error stores an intermediate value used by the surrounding gallery workflow.
            $error = $exception->getMessage();
        }
    }

    render_header(t('admin.reset.title'));
    echo '<section class="hero"><h1>' . e(t('admin.reset.title')) . '</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.back_to_dashboard')) . '</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_update')) . '">' . e(t('admin.reset.open_updates')) . '</a>';
    echo '</nav></section>';
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="notice">' . e(t('admin.reset.failed_value', ['error' => $error])) . '</div>';
    }
    echo '<section class="panel"><h2>' . e(t('admin.reset.restore_stable_title')) . '</h2>';
    echo '<p>' . e(t('admin.reset.restore_stable_description')) . '</p>';
    echo '<p class="muted">' . e(t('admin.reset.restore_stable_help')) . '</p>';
    echo '<form method="post" class="form-grid">' . csrf_field();
    echo '<button type="submit" class="button danger">' . e(t('admin.reset.button')) . '</button>';
    echo '</form></section>';
    render_footer();
}

