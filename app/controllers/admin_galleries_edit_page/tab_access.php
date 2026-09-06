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

use function Gallery\Core\e;
use function Gallery\Core\render_admin_tab_panel;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\gallery_share_token_for_admin;
use function Gallery\Services\nsfw_guard_schema_status;
use function Gallery\Services\t;
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

    ob_start();
    view_render_admin_tab_intro([
        'kicker' => t('admin.gallery_editor.access_kicker', 'Access'),
        'title' => t('admin.gallery_editor.visibility_and_protection', 'Visibility and protection'),
        'description' => t('admin.gallery_editor.access_help', 'Visibility decides discoverability. Passwords and generated links are optional on top of it.'),
    ]);
    echo '<div class="admin-edit-card-grid">';
    echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.visibility', 'Visibility')) . '<select name="visibility">' . visibility_options((string) $gallery['visibility']) . '</select></label><p class="muted">' . e(t('admin.gallery_editor.visibility_help', 'Public galleries are listed. Unpublished galleries are hidden but open from their normal URL. Private galleries are admin-only except for supported direct-token access.')) . '</p></div>';
    if ($accessReady) {
        // $newShareToken stores an intermediate value used by the surrounding gallery workflow.
        $newShareToken = (string) ($_SESSION['new_gallery_share_token_' . (int) $gallery['id']] ?? '');
        unset($_SESSION['new_gallery_share_token_' . (int) $gallery['id']]);
        // $currentAccessType stores an intermediate value used by the surrounding gallery workflow.
        $currentAccessType = 'normal';
        if ((string) ($gallery['access_mode'] ?? 'normal') === 'password' && !empty($gallery['access_password_hash'])) {
            // $currentAccessType stores an intermediate value used by the surrounding gallery workflow.
            $currentAccessType = 'password';
        }
        echo '<div class="admin-edit-card"><label>' . e(t('admin.gallery_editor.password_lock', 'Password lock')) . '<select name="access_type"><option value="normal"' . ($currentAccessType === 'normal' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.no_password', 'No password')) . '</option><option value="password"' . ($currentAccessType === 'password' ? ' selected' : '') . '>' . e(t('admin.gallery_editor.require_password', 'Require password')) . '</option></select><span class="muted">' . e(t('admin.gallery_editor.password_lock_help', 'Password locking is independent of public, unpublished, or private visibility.')) . '</span></label><label>' . e(t('admin.gallery_editor.new_gallery_password', 'New gallery password')) . '<input name="access_password" type="password" autocomplete="new-password"><span class="muted">' . e(t('admin.gallery_editor.keep_password_help', 'Leave empty to keep the current gallery password.')) . '</span></label>';
        if (!empty($gallery['access_password_hash'])) {
            echo '<label class="checkbox-label"><input type="checkbox" name="clear_access_password" value="1"> ' . e(t('admin.gallery_editor.clear_password', 'Clear current gallery password')) . '</label>';
        }
        echo '</div>';
        echo '<div class="admin-edit-card is-wide"><label>' . e(t('admin.gallery_editor.share_link_expiry', 'Share link expiry')) . '<input name="access_token_expires_at" type="datetime-local" value="' . e(!empty($gallery['access_token_expires_at']) ? date('Y-m-d\TH:i', strtotime((string) $gallery['access_token_expires_at'])) : '') . '"><span class="muted">' . e(t('admin.gallery_editor.non_expiring_link_help', 'Leave empty for a non-expiring generated link.')) . '</span></label>';
        // $visibleShareToken is readable only when encrypted token persistence is verified.
        $visibleShareToken = $newShareToken !== '' ? $newShareToken : ($shareTokenReady ? gallery_share_token_for_admin($gallery) : null);
        if ($visibleShareToken !== null && $visibleShareToken !== '') {
            // $shareLabel stores an intermediate value used by the surrounding gallery workflow.
            $shareLabel = $newShareToken !== '' ? t('admin.gallery_editor.generated_share_link', 'Generated share link') : t('admin.gallery_editor.active_share_link', 'Active share link');
            echo '<label>' . $shareLabel . '<input readonly value="' . e(gallery_share_url((int) $gallery['id'], $visibleShareToken)) . '"></label>';
        } elseif (!empty($gallery['access_token_hash'])) {
            $shareExpiry = !empty($gallery['access_token_expires_at'])
                ? t('admin.gallery_editor.share_link_until', 'until {time}', ['time' => (string) $gallery['access_token_expires_at']])
                : t('admin.gallery_editor.share_link_no_expiry', 'with no expiry');
            echo '<p class="muted">' . e(t('admin.gallery_editor.share_link_hidden_token', 'A share link is active {expiry}, but the original token cannot be displayed because it is stored as hash-only or cannot be decrypted on this server. Regenerate the link once to make a new copyable link visible here.', ['expiry' => $shareExpiry])) . '</p>';
        } else {
            echo '<p class="muted">' . e(t('admin.gallery_editor.no_active_share_link', 'No share link is active.')) . '</p>';
        }
        echo '<div class="bulk-row">';
        if ($shareTokenReady) {
            echo '<button type="submit" class="secondary" name="access_action" value="generate_link">' . e(t('admin.gallery_editor.generate_regenerate_share_link', 'Generate/regenerate share link')) . '</button>';
        }
        if (!empty($gallery['access_token_hash'])) {
            echo '<button type="submit" class="secondary" name="access_action" value="revoke_link">' . e(t('admin.gallery_editor.revoke_share_link', 'Revoke share link')) . '</button>';
        }
        echo '</div>';
        if ($shareTokenReady) {
            echo '<p class="muted">' . e(t('admin.gallery_editor.share_link_help', 'Generated direct links use the verified protected-token path. They remain useful for private galleries without making them appear in listings.')) . '</p>';
        } elseif (schema_inspection_is_unknown($shareTokenSchemaStatus)) {
            echo '<p class="muted">' . e(t('admin.gallery_editor.share_token_schema_unknown', 'Share-link generation is temporarily disabled because the token-storage schema could not be verified. Existing validating hashes can still be revoked. Check System Health.')) . '</p>';
        } else {
            echo '<p class="muted">' . e(t('admin.gallery_editor.share_token_migration_required', 'Share-link generation requires the current share-token migration. Existing validating hashes can still be revoked.')) . '</p>';
        }
        echo '</div>';
    } else {
        if (schema_inspection_is_unknown($accessSchemaStatus)) {
            echo '<div class="notice">' . e(t('admin.gallery_editor.access_schema_unknown', 'Protected gallery settings are unavailable because the required access schema could not be inspected. Check System Health before changing access policy.')) . '</div>';
        } else {
            echo '<div class="notice">' . e(t('admin.gallery_editor.protected_settings_migration_hidden', 'Protected gallery settings are hidden until the gallery access database migration is fully applied.')) . '</div>';
        }
    }
    // $nsfwSchemaStatus distinguishes a pending migration from an operational inspection failure.
    $nsfwSchemaStatus = nsfw_guard_schema_status();
    if (($nsfwSchemaStatus['state'] ?? '') === 'available') {
        echo '<div class="admin-edit-card is-wide"><input type="hidden" name="nsfw_field_present" value="1"><label class="checkbox-label"><input type="checkbox" name="nsfw_enabled" value="1"' . ((int) ($gallery['nsfw_enabled'] ?? 0) === 1 ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.mark_nsfw', 'Mark as NSFW / 18+')) . '</label><p class="muted">' . e(t('admin.gallery_editor.nsfw_help', 'When enabled, this gallery and all subgalleries require an 18+ confirmation before anonymous visitors can view photos or media files. Before publishing NSFW content, make sure your hosting provider or web hosting terms allow it.')) . '</p></div>';
    } elseif (($nsfwSchemaStatus['state'] ?? '') === 'unknown') {
        echo '<div class="admin-edit-card is-wide"><p class="muted">' . e(t('admin.gallery_editor.nsfw_inspection_failed', 'NSFW Guard controls are unavailable because the application could not inspect the required database schema. Check System Health for diagnostic guidance.')) . '</p></div>';
    } else {
        echo '<div class="admin-edit-card is-wide"><p class="muted">' . e(t('admin.gallery_editor.nsfw_migration_hidden', 'NSFW Guard controls will be available after the database migration is applied.')) . '</p></div>';
    }
    echo '</div>';
    render_admin_tab_panel('admin-edit-access', (string) ob_get_clean(), $activeEditTab === 'admin-edit-access');
}
