<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_release_email_test.php
 * Module Type: Regression Test
 * Purpose: Verify localized release mail with isolated transport and SQLite data.
 * Responsibilities:
 *   - Exercise real account selection, capability defaults and mail rendering.
 *   - Preserve opt-out, schema refusal and independent password-reset controls.
 *   - Never contact a mail server or an installed gallery database.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Supply deterministic mail defaults. @return array{default_language:string} English fallback. */
    function cms_config(): array
    {
        return ['default_language' => 'en'];
    }
    /** Return the isolated database. @return \PDO In-memory account fixture. */
    function db(): \PDO
    {
        return $GLOBALS['release_mail_db'];
    }
    /** Supply an ordinary administrator. @return array{id:int,role:string} Fixture identity. */
    function current_user(): array
    {
        return ['id' => 1, 'role' => 'admin'];
    }
    /** Escape view fixture text. @param string $value Plain text. @return string HTML-safe text. */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
namespace Gallery\Services {
    /** Read isolated settings. @param string $key Setting key. @param string|null $default Absent-value default. @return string|null Stored value or default. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['release_mail_settings'][$key] ?? $default;
    }
    /** Save isolated settings. @param string $key Setting key. @param string $value Canonical text. @return void Updates only the fixture map. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['release_mail_settings'][$key] = $value;
    }
    /** Supply a safe sender label. @return string Fixture site name. */
    function site_name(): string
    {
        return 'Fixture Gallery';
    }
    /** Capture safe delivery events. @param string $level Severity. @param string $event Event key. @param string $message Safe prose. @param array<string,scalar|null> $context Safe delivery facts. @return void Appends a fixture event. */
    function admin_log_event(string $level, string $event, string $message, array $context = []): void
    {
        $GLOBALS['release_mail_logs'][] = [$level, $event, $message, $context];
    }
    /** Supply controlled email readiness. @return array{state:string} Available, missing or unknown. */
    function auth_user_email_schema_status(): array
    {
        $GLOBALS['release_mail_schema_reads']++;
        return ['state' => $GLOBALS['release_mail_schema']];
    }
    /** Read explicit schema readiness. @param array{state:string} $status Observed state. @return bool True only for available. */
    function schema_inspection_is_available(array $status): bool
    {
        return $status['state'] === 'available';
    }
    /** Capture PHP mail without external delivery. @param string $recipient Destination. @param string $subject Header-safe subject. @param string $body Plain text. @param string $headers MIME and optional sender headers. @param string $parameters Optional sender argument. @return bool Controlled transport acceptance. */
    function mail(string $recipient, string $subject, string $body, string $headers, string $parameters = ''): bool
    {
        $GLOBALS['release_mail_messages'][] = compact(
            'recipient', 'subject', 'body', 'headers', 'parameters',
        );
        return !$GLOBALS['release_mail_transport_fail'];
    }
    /** Refuse real SMTP connections at the transport boundary. @param string $address Requested server. @param int $errorCode Output socket error. @param string $errorMessage Output socket message. @param float $timeout Existing transport deadline. @param int $flags Native connection flags. @param resource $context TLS context; no native PHP resource type exists. @return false Always refuses the isolated connection. */
    function stream_socket_client(string $address, int &$errorCode, string &$errorMessage, float $timeout, int $flags, mixed $context): bool
    {
        $GLOBALS['release_mail_smtp_attempts']++;
        $errorCode = 0;
        $errorMessage = '';
        return false;
    }
}
namespace {
    require_once __DIR__ . '/../app/models/auth.php';
    require_once __DIR__ . '/../app/services/translations.php';
    require_once __DIR__ . '/../app/services/feature_flags.php';
    require_once __DIR__ . '/../app/services/configured_mail.php';
    require_once __DIR__ . '/../app/services/admin_release_email.php';
    require_once __DIR__ . '/../app/views/admin_chrome.php';

    /** Require a release-mail invariant. @param bool $condition Expected behavior. @param string $message Failure context. @return void Throws on mismatch. */
    function release_mail_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $GLOBALS['release_mail_settings'] = [];
    $GLOBALS['release_mail_messages'] = [];
    $GLOBALS['release_mail_logs'] = [];
    $GLOBALS['release_mail_schema'] = 'available';
    $GLOBALS['release_mail_schema_reads'] = 0;
    $GLOBALS['release_mail_smtp_attempts'] = 0;
    $GLOBALS['release_mail_transport_fail'] = false;
    foreach ([['role' => 'admin', 'email' => 'saved@example.test'],
        ['role' => 'viewer', 'email' => null],
        ['role' => 'admin', 'email' => null, 'support_access' => true]] as $user) {
        ob_start();
        \Gallery\Views\view_render_missing_admin_email_notice($user, 'home', '/account#account-email');
        release_mail_assert(ob_get_clean() === '',
            'Saved addresses, viewers and support sessions must not be prompted.');
    }
    ob_start();
    \Gallery\Views\view_render_missing_admin_email_notice(
        ['role' => 'admin', 'email' => null], 'home', '/account#account-email',
    );
    release_mail_assert(str_contains((string) ob_get_clean(), '/account#account-email'),
        'A missing administrator address needs a web reminder at its email field.');
    $GLOBALS['release_mail_db'] = new \PDO('sqlite::memory:');
    $GLOBALS['release_mail_db']->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $GLOBALS['release_mail_db']->exec(
        'CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, email TEXT)',
    );
    $insert = $GLOBALS['release_mail_db']->prepare('INSERT INTO users VALUES (?, ?, ?)');
    // IDs are independent fixture identities, not an operational recipient cap.
    foreach ([[1, 'admin', 'admin@example.test'], [2, 'admin', ''],
        [3, 'admin', 'de@example.test'], [4, 'viewer', 'viewer@example.test'],
        [5, 'support', 'support@example.test']] as $row) {
        $insert->execute($row);
    }
    release_mail_assert(count(\Gallery\Models\auth_model_release_email_recipients()) === 2,
        'Only saved nonempty administrator addresses may be selected.');
    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $message = \Gallery\Services\admin_release_email_message('1.2.0', $language);
        release_mail_assert(str_contains($message['subject'], '1.2.0')
            && str_contains($message['body'], '/releases/tag/v_1.2.0')
            && !str_contains($message['body'], '{version}')
            && !str_contains($message['body'], 'admin.release_email.'),
            'Every supported catalog must supply a complete version message.');
    }
    release_mail_assert(\Gallery\Services\admin_release_email_message('1.2.0', 'xx')
        === \Gallery\Services\admin_release_email_message('1.2.0', 'en'),
        'Unknown catalog languages retain the English fallback.');
    \Gallery\Services\translation_set_active_language('cs');
    \Gallery\Services\translation_remember_admin_language(3, 'de');
    release_mail_assert($GLOBALS['release_mail_settings']['admin_interface_language.1'] === 'cs',
        'A language change must persist for a sessionless updater.');
    \Gallery\Services\admin_release_email_notify_update('1.1.2', '1.2.0');
    $messages = $GLOBALS['release_mail_messages'];
    release_mail_assert(count($messages) === 2
        && str_contains($messages[0]['body'], 'Vaše galerie')
        && str_contains($messages[1]['body'], 'Ihre Galerie'),
        'Each administrator must receive the saved interface language.');
    release_mail_assert($messages[0]['parameters'] === ''
        && !str_contains($messages[0]['headers'], 'From:')
        && $GLOBALS['release_mail_smtp_attempts'] === 0,
        'Default delivery must try PHP mail with the hosting sender and no SMTP.');
    release_mail_assert(\Gallery\Services\translation_admin_language() === 'cs',
        'Sending another language must not alter the browser language.');
    $reads = $GLOBALS['release_mail_schema_reads'];
    \Gallery\Services\set_feature_capability_enabled('release_email_notifications', false);
    \Gallery\Services\admin_release_email_notify_update('1.2.0', '1.3.0');
    release_mail_assert(count($GLOBALS['release_mail_messages']) === 2
        && $GLOBALS['release_mail_schema_reads'] === $reads,
        'Opt-out must avoid both mail and optional schema work.');
    \Gallery\Services\set_feature_capability_enabled('release_email_notifications', true);
    foreach (['missing', 'unknown'] as $state) {
        $GLOBALS['release_mail_schema'] = $state;
        \Gallery\Services\admin_release_email_notify_update('1.2.0', '1.3.0');
    }
    $GLOBALS['release_mail_schema'] = 'available';
    \Gallery\Services\admin_release_email_notify_update('1.2.0', '1.2.0');
    \Gallery\Services\admin_release_email_notify_update('1.2.0', '1.1.2');
    release_mail_assert(count($GLOBALS['release_mail_messages']) === 2,
        'Unavailable schema, reinstalls and downgrades must not send announcements.');
    release_mail_assert(!\Gallery\Services\cms_send_configured_password_reset_mail(
        'admin@example.test', 'Reset', 'Reset body',
    )['sent'], 'Update mail must not enable password recovery.');
    $GLOBALS['release_mail_transport_fail'] = true;
    release_mail_assert(!\Gallery\Services\admin_release_email_send(
        1, 'admin@example.test', '1.2.0', 'cs',
    ), 'A refused PHP send must remain a reported failure.');
    $beforeInvalid = count($GLOBALS['release_mail_messages']);
    release_mail_assert(!\Gallery\Services\admin_release_email_send(
        1, "admin@example.test\r\nBcc: other@example.test", '1.2.0', 'en',
    ) && count($GLOBALS['release_mail_messages']) === $beforeInvalid,
        'Recipient header injection must never reach mail().');
    $GLOBALS['release_mail_settings']['password_reset_transport'] = 'smtp';
    $GLOBALS['release_mail_settings']['password_reset_smtp_host'] = 'smtp.example.test';
    $GLOBALS['release_mail_settings']['password_reset_from_email'] = 'sender@example.test';
    release_mail_assert(!\Gallery\Services\admin_release_email_send(
        1, 'admin@example.test', '1.2.0', 'en',
    ) && $GLOBALS['release_mail_smtp_attempts'] === 1
        && count($GLOBALS['release_mail_messages']) === $beforeInvalid,
        'Explicit SMTP uses its transport and must not silently fall back to PHP.');
    echo "Administrator release email checks passed.\n";
}
