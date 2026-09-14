<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_access.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the Access tab of the gallery editor.
 *
 * Responsibilities:
 *   - Edit visibility, optional password locking, and share-link expiry
 *   - Show or hide share-link generation according to verified token storage
 *   - Distinguish a pending migration from an inspection failure in every notice
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
 *   - Loaded by app/controllers/admin_galleries_edit_page.php; do not require this file directly.
 *   - Share tokens are displayed only when encrypted token persistence is verified.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\gallery_share_token_for_admin;
use function Gallery\Services\nsfw_guard_schema_status;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_gallery_access_fields;
use function Gallery\Views\view_render_admin_tab_intro;

/**
 * Render the Access tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 * @param array<string, mixed> $capabilities Resolved editor capabilities.
 */
function admin_edit_gallery_render_access_tab(array $gallery, string $activeEditTab, array $capabilities): void
{
    $accessSchemaStatus = $capabilities['access_schema_status'];
    $accessReady = (bool) $capabilities['access_ready'];
    $shareTokenSchemaStatus = $capabilities['share_token_schema_status'];
    $shareTokenReady = (bool) $capabilities['share_token_ready'];
    $hasPassword = !empty($gallery['access_password_hash']);
    $hasShareTokenHash = !empty($gallery['access_token_hash']);

    // $currentAccessType stores an intermediate value used by the surrounding gallery workflow.
    $currentAccessType = ((string) ($gallery['access_mode'] ?? 'normal') === 'password' && $hasPassword)
        ? 'password'
        : 'normal';
    $shareExpiryValue = !empty($gallery['access_token_expires_at'])
        ? date('Y-m-d\TH:i', strtotime((string) $gallery['access_token_expires_at']))
        : '';

    $shareUrl = null;
    $shareLabel = null;
    $shareUnavailableMessage = null;
    if ($accessReady) {
        // $newShareToken stores an intermediate value used by the surrounding gallery workflow.
        $newShareToken = (string) ($_SESSION['new_gallery_share_token_' . (int) $gallery['id']] ?? '');
        unset($_SESSION['new_gallery_share_token_' . (int) $gallery['id']]);

        // $visibleShareToken is readable only when encrypted token persistence is verified.
        $visibleShareToken = $newShareToken !== '' ? $newShareToken : ($shareTokenReady ? gallery_share_token_for_admin($gallery) : null);
        if ($visibleShareToken !== null && $visibleShareToken !== '') {
            // $shareLabel stores an intermediate value used by the surrounding gallery workflow.
            $shareLabel = $newShareToken !== ''
                ? t('admin.gallery_editor.generated_share_link', 'Generated share link')
                : t('admin.gallery_editor.active_share_link', 'Active share link');
            $shareUrl = gallery_share_url((int) $gallery['id'], $visibleShareToken);
        } elseif ($hasShareTokenHash) {
            $shareExpiry = !empty($gallery['access_token_expires_at'])
                ? t('admin.gallery_editor.share_link_until', 'until {time}', ['time' => (string) $gallery['access_token_expires_at']])
                : t('admin.gallery_editor.share_link_no_expiry', 'with no expiry');
            $shareUnavailableMessage = t('admin.gallery_editor.share_link_hidden_token', 'A share link is active {expiry}, but the original token cannot be displayed because it is stored as hash-only or cannot be decrypted on this server. Regenerate the link once to make a new copyable link visible here.', ['expiry' => $shareExpiry]);
        }
    }

    if ($shareTokenReady) {
        $shareStorageMessage = t('admin.gallery_editor.share_link_help', 'Generated direct links use the verified protected-token path. They remain useful for private galleries without making them appear in listings.');
    } elseif (schema_inspection_is_unknown($shareTokenSchemaStatus)) {
        $shareStorageMessage = t('admin.gallery_editor.share_token_schema_unknown', 'Share-link generation is temporarily disabled because the token-storage schema could not be verified. Existing validating hashes can still be revoked. Check System Health.');
    } else {
        $shareStorageMessage = t('admin.gallery_editor.share_token_migration_required', 'Share-link generation requires the current share-token migration. Existing validating hashes can still be revoked.');
    }

    $accessUnavailableMessage = null;
    if (!$accessReady) {
        $accessUnavailableMessage = schema_inspection_is_unknown($accessSchemaStatus)
            ? t('admin.gallery_editor.access_schema_unknown', 'Protected gallery settings are unavailable because the required access schema could not be inspected. Check System Health before changing access policy.')
            : t('admin.gallery_editor.protected_settings_migration_hidden', 'Protected gallery settings are hidden until the gallery access database migration is fully applied.');
    }

    // $nsfwSchemaStatus distinguishes a pending migration from an operational inspection failure.
    $nsfwSchemaStatus = nsfw_guard_schema_status();

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.access_kicker', 'Access'),
        'title' => t('admin.gallery_editor.visibility_and_protection', 'Visibility and protection'),
        'description' => t('admin.gallery_editor.access_help', 'Visibility decides discoverability. Passwords and generated links are optional on top of it.'),
    ]);
    view_render_admin_gallery_access_fields([
        'access_ready' => $accessReady,
        'share_token_ready' => $shareTokenReady,
        'visibility_options_html' => visibility_options((string) $gallery['visibility']),
        'current_access_type' => $currentAccessType,
        'has_password' => $hasPassword,
        'has_share_token_hash' => $hasShareTokenHash,
        'share_expiry_value' => $shareExpiryValue,
        'share_url' => $shareUrl,
        'share_label' => $shareLabel,
        'share_unavailable_message' => $shareUnavailableMessage,
        'share_storage_message' => $shareStorageMessage,
        'access_unavailable_message' => $accessUnavailableMessage,
        'nsfw_state' => (string) ($nsfwSchemaStatus['state'] ?? 'missing'),
        'nsfw_enabled' => (int) ($gallery['nsfw_enabled'] ?? 0) === 1,
        'labels' => [
            'visibility' => t('admin.gallery_editor.visibility', 'Visibility'),
            'visibility_help' => t('admin.gallery_editor.visibility_help', 'Public galleries are listed. Unpublished galleries are hidden but open from their normal URL. Private galleries are admin-only except for supported direct-token access.'),
            'password_lock' => t('admin.gallery_editor.password_lock', 'Password lock'),
            'no_password' => t('admin.gallery_editor.no_password', 'No password'),
            'require_password' => t('admin.gallery_editor.require_password', 'Require password'),
            'password_lock_help' => t('admin.gallery_editor.password_lock_help', 'Password locking is independent of public, unpublished, or private visibility.'),
            'new_gallery_password' => t('admin.gallery_editor.new_gallery_password', 'New gallery password'),
            'keep_password_help' => t('admin.gallery_editor.keep_password_help', 'Leave empty to keep the current gallery password.'),
            'clear_password' => t('admin.gallery_editor.clear_password', 'Clear current gallery password'),
            'share_link_expiry' => t('admin.gallery_editor.share_link_expiry', 'Share link expiry'),
            'non_expiring_link_help' => t('admin.gallery_editor.non_expiring_link_help', 'Leave empty for a non-expiring generated link.'),
            'no_active_share_link' => t('admin.gallery_editor.no_active_share_link', 'No share link is active.'),
            'generate_regenerate_share_link' => t('admin.gallery_editor.generate_regenerate_share_link', 'Generate/regenerate share link'),
            'revoke_share_link' => t('admin.gallery_editor.revoke_share_link', 'Revoke share link'),
            'mark_nsfw' => t('admin.gallery_editor.mark_nsfw', 'Mark as NSFW / 18+'),
            'nsfw_help' => t('admin.gallery_editor.nsfw_help', 'When enabled, this gallery and all subgalleries require an 18+ confirmation before anonymous visitors can view photos or media files. Before publishing NSFW content, make sure your hosting provider or web hosting terms allow it.'),
            'nsfw_inspection_failed' => t('admin.gallery_editor.nsfw_inspection_failed', 'NSFW Guard controls are unavailable because the application could not inspect the required database schema. Check System Health for diagnostic guidance.'),
            'nsfw_migration_hidden' => t('admin.gallery_editor.nsfw_migration_hidden', 'NSFW Guard controls will be available after the database migration is applied.'),
        ],
    ]);
    render_admin_tab_panel('admin-edit-access', (string) ob_get_clean(), $activeEditTab === 'admin-edit-access');
}
