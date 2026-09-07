<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_trash.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the Admin gallery trash bin and handles restore, purge, and empty actions.
 *
 * Responsibilities:
 *   - Require administrator authentication and CSRF validation before every mutation
 *   - Keep destructive actions POST-only
 *   - Return the canonical Admin mutation envelope for enhanced requests
 *   - Preserve a working redirect fallback for direct-page and no-JavaScript use
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
 *   2026-09-07
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\empty_gallery_trash;
use function Gallery\Services\gallery_trash_entries;
use function Gallery\Services\gallery_trash_entry;
use function Gallery\Services\gallery_trash_retention_days;
use function Gallery\Services\gallery_trash_purge_batch_size;
use function Gallery\Services\gallery_trash_summary;
use function Gallery\Services\purge_gallery_trash_entry;
use function Gallery\Services\restore_gallery_trash_entry;
use function Gallery\Services\set_gallery_trash_settings;
use function Gallery\Services\gallery_trash_normalize_retention_days;
use function Gallery\Services\gallery_trash_normalize_purge_batch_size;
use function Gallery\Services\t;
use function Gallery\Views\render_admin_trash_page;

/**
 * Send one JSON mutation response for an enhanced Admin trash request.
 *
 * @param array $payload Canonical mutation envelope.
 * @param int $status HTTP status code.
 */
function admin_trash_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private, max-age=0');
    // $json stores the encoded envelope, or a safe fallback when encoding fails.
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    echo $json === false
        ? '{"ok":false,"error":"The server could not encode the mutation response.","error_code":"json_encode_failed"}'
        : $json;
}

/**
 * Return the shared panel metadata used by every trash mutation envelope.
 *
 * @return array<string,mixed> Panel refresh metadata.
 */
function admin_trash_panel_metadata(): array
{
    return admin_mutation_panel_metadata('gallery_trash', url_for('admin_trash', ['panel' => 1]));
}


/**
 * Return the dashboard deep link that owns the primary Trash UI.
 *
 * @return string Admin Maintenance > Trash URL including its dashboard hash.
 */
function admin_trash_dashboard_url(): string
{
    return url_for('admin', ['maintenance_tab' => 'trash']) . '#admin-tab-maintenance';
}

/**
 * Read and validate the trash token submitted by an Admin trash mutation.
 *
 * @return string Submitted trash token, or an empty string when absent.
 */
function admin_trash_requested_token(): string
{
    // $token stores the submitted trash entry identifier.
    $token = trim((string) ($_POST['trash_token'] ?? ''));
    return preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $token : '';
}

/**
 * Finish one Admin trash mutation with either JSON or a redirect fallback.
 *
 * @param bool $wantsJson Whether the caller requested the enhanced JSON path.
 * @param bool $ok Whether the mutation succeeded.
 * @param string $message Safe human-readable result message.
 * @param string $action Stable mutation action name.
 * @param string $errorCode Stable machine-readable error category.
 * @param array<string,mixed> $extra Optional controller-specific success metadata.
 */
function admin_trash_finish(bool $wantsJson, bool $ok, string $message, string $action, string $errorCode = 'gallery_trash_failed', array $extra = []): void
{
    // $redirect stores the direct-page fallback destination.
    $redirect = admin_trash_dashboard_url();
    // $mutation stores the typed descriptor shared by both response paths.
    $mutation = admin_mutation_descriptor('gallery.trash', 'gallery_trash', $action);

    if ($wantsJson) {
        $payload = $ok
            ? admin_mutation_success_envelope($message, $mutation, admin_trash_panel_metadata(), [], ['redirect_url' => $redirect])
            : admin_mutation_error_envelope($message, $errorCode, $mutation, ['redirect_url' => $redirect]);
        if ($ok && $extra) {
            $payload = array_merge($payload, $extra);
        }
        admin_trash_json_response($payload, $ok ? 200 : 422);
        return;
    }

    flash_message('admin_notice', $message);
    redirect_to($redirect);
}

/**
 * Serve the legacy Trash route as a panel fragment or dashboard compatibility redirect.
 *
 * The primary user interface lives in Admin > Maintenance > Trash. Keeping the
 * fragment endpoint preserves canonical mutation panel refresh behavior without
 * maintaining a second standalone Trash page.
 */
function cms_admin_trash(): void
{
    require_admin();

    if (request_method() === 'POST') {
        cms_admin_trash_settings();
        return;
    }

    if (!empty($_GET['panel'])) {
        // $entries stores all currently active/problem trash rows so BROKEN entries cannot disappear from Admin.
        $entries = gallery_trash_entries(['status' => 'active']);
        // $summary stores the aggregate counters shown in the fragment header.
        $summary = gallery_trash_summary();
        render_admin_trash_page($entries, $summary, true);
        return;
    }

    redirect_to(admin_trash_dashboard_url());
}

/**
 * Persist Trash settings from the Maintenance subtab.
 */
function cms_admin_trash_settings(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    // $enabled stores whether user-facing deletes should remain recoverable.
    $enabled = (string) ($_POST['gallery_trash_enabled'] ?? '0') === '1';
    // $autoPurgeEnabled stores whether scheduled maintenance may destroy expired entries.
    $autoPurgeEnabled = (string) ($_POST['gallery_trash_auto_purge_enabled'] ?? '0') === '1';
    // $retentionDays stores the validated retention window submitted by the administrator.
    $retentionDays = gallery_trash_normalize_retention_days(
        (int) ($_POST['gallery_trash_retention_days'] ?? gallery_trash_retention_days())
    );
    // $purgeBatch stores the bounded per-slice cleanup size used by maintenance and Empty Trash.
    $purgeBatch = gallery_trash_normalize_purge_batch_size(
        (int) ($_POST['gallery_trash_purge_batch'] ?? gallery_trash_purge_batch_size())
    );

    try {
        set_gallery_trash_settings($enabled, $autoPurgeEnabled, $retentionDays, $purgeBatch);
        admin_log_event('info', 'gallery.trash_settings_saved', 'Admin saved gallery trash bin settings.', [
            'enabled' => $enabled,
            'auto_purge_enabled' => $autoPurgeEnabled,
            'retention_days' => $retentionDays,
            'purge_batch' => $purgeBatch,
        ]);
        flash_message('admin_notice', t('admin.trash.settings_saved', 'Trash bin settings saved.'));
    } catch (Throwable) {
        // Fail closed if the schema cannot be verified while enabling a destructive maintenance path.
        admin_log_event('warning', 'gallery.trash_settings_refused', 'Gallery trash settings could not be applied safely.', [
            'requested_enabled' => $enabled,
            'requested_auto_purge_enabled' => $autoPurgeEnabled,
        ]);
        flash_message('admin_error', t('admin.trash.settings_save_failed', 'Trash settings could not be applied safely. Automatic deletion was not enabled; verify database migrations and try again.'));
    }
    redirect_to(admin_trash_dashboard_url());
}

/**
 * Restore one trashed gallery subtree back to its original location.
 */
function cms_admin_trash_restore(): void
{
    // $wantsJson stores whether the caller uses the enhanced side-panel path.
    $wantsJson = admin_wants_json();
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    // $token stores the validated trash entry identifier.
    $token = admin_trash_requested_token();
    if ($token === '') {
        admin_trash_finish($wantsJson, false, t('admin.trash.entry_not_found', 'That trash entry was not found.'), 'restore', 'gallery_trash_not_found');
        return;
    }

    // $entry stores the trash row used for logging and messages.
    $entry = gallery_trash_entry($token);
    try {
        // $restored stores the restore result counters.
        $restored = restore_gallery_trash_entry($token);
        admin_log_event('warning', 'gallery.trash_restored', 'Admin restored a gallery from the trash bin.', [
            'trash_token' => $token,
            'folder_path' => (string) $restored['folder_path'],
            'galleries' => (int) $restored['gallery_count'],
            'images' => (int) $restored['image_count'],
            'metadata_only' => !empty($restored['metadata_only']),
        ]);
        $message = !empty($restored['metadata_only'])
            ? t('admin.trash.restore_metadata_only', 'Restored {galleries} gallery folder(s) from metadata. The original photo files were already missing and could not be restored.', [
                'galleries' => (int) $restored['gallery_count'],
            ])
            : t('admin.trash.restore_success', 'Restored {galleries} gallery folder(s) and {images} photo(s).', [
                'galleries' => (int) $restored['gallery_count'],
                'images' => (int) $restored['image_count'],
            ]);
        admin_trash_finish($wantsJson, true, $message, 'restore');
    } catch (Throwable $exception) {
        admin_log_event('error', 'gallery.trash_restore_failed', 'Gallery restore from the trash bin failed.', [
            'trash_token' => $token,
            'title' => (string) ($entry['title'] ?? ''),
            'error' => $exception->getMessage(),
        ]);
        admin_trash_finish($wantsJson, false, t('admin.trash.restore_failed', 'Restore failed: {error}', ['error' => $exception->getMessage()]), 'restore', 'gallery_trash_restore_failed');
    }
}

/**
 * Permanently destroy one trash entry.
 */
function cms_admin_trash_purge(): void
{
    // $wantsJson stores whether the caller uses the enhanced side-panel path.
    $wantsJson = admin_wants_json();
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    // $token stores the validated trash entry identifier.
    $token = admin_trash_requested_token();
    if ($token === '') {
        admin_trash_finish($wantsJson, false, t('admin.trash.entry_not_found', 'That trash entry was not found.'), 'purge', 'gallery_trash_not_found');
        return;
    }

    try {
        // $purged stores the permanent deletion result.
        $purged = purge_gallery_trash_entry($token);
        admin_log_event('warning', 'gallery.trash_purged', 'Admin permanently deleted a gallery from the trash bin.', [
            'trash_token' => $token,
            'title' => (string) $purged['title'],
        ]);
        admin_trash_finish($wantsJson, true, t('admin.trash.purge_success', 'Permanently deleted "{title}".', ['title' => (string) $purged['title']]), 'purge');
    } catch (Throwable $exception) {
        admin_log_event('error', 'gallery.trash_purge_failed', 'Permanent deletion from the trash bin failed.', [
            'trash_token' => $token,
            'error' => $exception->getMessage(),
        ]);
        admin_trash_finish($wantsJson, false, t('admin.trash.purge_failed', 'Permanent deletion failed: {error}', ['error' => $exception->getMessage()]), 'purge', 'gallery_trash_purge_failed');
    }
}

/**
 * Permanently destroy every entry currently sitting in the trash.
 */
function cms_admin_trash_empty(): void
{
    // $wantsJson stores whether the caller uses the enhanced Maintenance-panel path.
    $wantsJson = admin_wants_json();
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    try {
        // One HTTP request owns one bounded batch. The browser enhancement repeats this
        // endpoint while remaining > 0; direct/no-JavaScript callers can submit again.
        $result = empty_gallery_trash(['limit' => gallery_trash_purge_batch_size()]);
        $purged = (int) ($result['purged'] ?? 0);
        $failed = (int) ($result['failed'] ?? 0);
        $remaining = (int) ($result['remaining'] ?? 0);

        admin_log_event('warning', 'gallery.trash_empty_batch', 'Admin ran one bounded gallery trash empty batch.', [
            'purged' => $purged,
            'failed' => $failed,
            'remaining' => $remaining,
        ]);
        $message = ($remaining > 0 || $failed > 0)
            ? t('admin.trash.empty_partial', 'Permanently deleted {count} trashed gallery folder(s); {remaining} remain and {failed} failed.', [
                'count' => $purged,
                'remaining' => $remaining,
                'failed' => $failed,
            ])
            : t('admin.trash.empty_success', 'Permanently deleted {count} trashed gallery folder(s).', ['count' => $purged]);
        admin_trash_finish($wantsJson, true, $message, 'empty', 'gallery_trash_failed', [
            'trash_batch' => [
                'purged' => $purged,
                'failed' => $failed,
                'remaining' => $remaining,
            ],
        ]);
    } catch (Throwable $exception) {
        admin_log_event('error', 'gallery.trash_empty_failed', 'Emptying the gallery trash bin failed.', [
            'error' => $exception->getMessage(),
        ]);
        admin_trash_finish($wantsJson, false, t('admin.trash.empty_failed', 'Emptying the trash failed: {error}', ['error' => $exception->getMessage()]), 'empty', 'gallery_trash_empty_failed');
    }
}
