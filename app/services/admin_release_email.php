<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/admin_release_email.php
 * Module Type: Service
 * Purpose: Send simple localized administrator release notifications.
 * Responsibilities:
 *   - Render one fixed version template for both tests and completed updates.
 *   - Reuse configured mail and canonical Features opt-out without a mail queue.
 *   - Keep transport failures independent of account saves and update completion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Models\auth_model_release_email_recipients;

/**
 * Render the same short release message for an update or an explicit address test.
 *
 * @param string $version Installed gallery version, without the release tag prefix.
 * @param string $language Administrator interface language, independent of session.
 * @return array{subject:string,body:string} Localized plain text and fixed GitHub links.
 */
function admin_release_email_message(string $version, string $language): array
{
    $parameters = [
        'version' => $version,
        'release_url' => 'https://github.com/klusik/PHP_gallery/releases/tag/v_'
            . rawurlencode($version),
        'github_url' => 'https://github.com/klusik/PHP_gallery',
    ];
    $message = [];
    foreach (['subject', 'body'] as $part) {
        $key = 'admin.release_email.' . $part;
        // Permanent catalog fallback: unsupported/custom languages use English,
        // matching the translation service; no browser/session language is changed.
        $template = translation_catalog_value($language, $key)
            ?? translation_catalog_value('en', $key);
        $message[$part] = translation_interpolate((string) $template, $parameters);
    }
    return ['subject' => $message['subject'], 'body' => $message['body']];
}

/**
 * Attempt one administrator message without letting mail failure undo saved work.
 *
 * @param int $userId Persisted administrator identity used only for safe logging.
 * @param string $email Saved destination address, validated by the transport.
 * @param string $version Installed gallery version to include in the message.
 * @param string $language Saved administrator interface language.
 * @return bool True only when the configured transport accepted the message.
 */
function admin_release_email_send(
    int $userId,
    string $email,
    string $version,
    string $language,
): bool {
    $sent = false;
    try {
        $message = admin_release_email_message($version, $language);
        $delivery = cms_send_configured_mail(
            $email, $message['subject'], $message['body'],
        );
        $sent = $delivery['sent'];
    } catch (Throwable) {
        // Do not expose addresses, credentials, SMTP replies or exception text.
    }
    try {
        admin_log_event(
            $sent ? 'info' : 'warning',
            'update.release_email',
            $sent ? 'Release email accepted by transport.'
                : 'Release email could not be sent. Check account mail settings.',
            ['user_id' => $userId, 'version' => $version, 'sent' => $sent],
        );
    } catch (Throwable) {
        // Logging is best effort too; the account/update is already committed.
    }
    return $sent;
}

/**
 * Notify administrators once from an updater's durably completed version change.
 *
 * @param string $previousVersion Version recorded when the update started.
 * @param string $version Successfully installed gallery version.
 * @return void Attempts mail only for an upgrade with verified email storage and
 *     the canonical release_email_notifications capability enabled.
 */
function admin_release_email_notify_update(
    string $previousVersion,
    string $version,
): void {
    if ($previousVersion === '' || $version === ''
        || !version_compare($version, $previousVersion, '>')
        || !feature_capability_effective_enabled('release_email_notifications')
        || !schema_inspection_is_available(auth_user_email_schema_status())) {
        return;
    }
    foreach (auth_model_release_email_recipients() as $recipient) {
        $userId = (int) $recipient['id'];
        admin_release_email_send(
            $userId,
            $recipient['email'],
            $version,
            (string) app_setting(
                'admin_interface_language.' . $userId,
                translation_default_language(),
            ),
        );
    }
}
