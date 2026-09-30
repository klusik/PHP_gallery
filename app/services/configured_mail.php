<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/configured_mail.php
 * Module Type: Service
 * Purpose: Reuse the existing configured SMTP/PHP-mail transport across administrator workflows.
 * Responsibilities: Own mail configuration and delivery; retain controller compatibility wrappers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;
use function Gallery\Core\cms_config;

/**
 * Return password reset settings with safe defaults.
 *
 * @return array<string,mixed> Configured settings or bounded delivery metadata for the caller.
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
 * @param resource $socket Connected SMTP stream.
 * @return array<string,mixed> Configured settings or bounded delivery metadata for the caller.
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
 * @param resource $socket Connected SMTP stream.
 * @param string $command Command value.
 * @param list<int> $expectedCodes Expected codes value.
 * @param array<string,mixed> $details Details value.
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
 * @param array<string,mixed> $settings Settings used by this workflow.
 * @param string $recipient Recipient value.
 * @param string $subject Subject value.
 * @param string $body Body value.
 * @param array<string,mixed> $details Details value.
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
    $helloName = (string) (strrchr((string) $settings['from_email'], '@') ?: '@localhost');
    $helloName = preg_replace('/[^a-zA-Z0-9.-]/', '', substr($helloName, 1)) ?: 'localhost';
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
 * @return array<string,mixed> Configured settings or bounded delivery metadata for the caller.
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
    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $recipient)) {
        $details['reason'] = t('admin.account.no_recovery_email');
        return $details;
    }
    if (!filter_var($settings['from_email'], FILTER_VALIDATE_EMAIL)) {
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
