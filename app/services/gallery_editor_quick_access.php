<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_editor_quick_access.php
 * Module Type: Service
 * Purpose: Change current-gallery password protection without altering unrelated settings.
 * Responsibilities:
 *   - Change one gallery password through existing revision and persistence owners.
 *   - Preserve visibility, listing, share tokens and unrelated gallery preferences.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

require_once __DIR__ . '/gallery_editor_mutations.php';

/** An approved password validation refusal containing only translated, bounded guidance. */
final class GalleryQuickAccessRefusal extends RuntimeException
{
}

/**
 * Project acknowledged gallery access state without returning credentials or unrelated fields.
 *
 * @param array<string,mixed> $gallery Authoritative row after the editor mutation.
 * @return array{id:int,visibility:string,visibility_label:string,own_password_enabled:bool,access_label:string,edit_revision:string} Bounded localized transport state.
 */
function gallery_editor_quick_access_state(array $gallery): array
{
    $ownPassword = (string) ($gallery['access_mode'] ?? 'normal') === 'password' && !empty($gallery['access_password_hash']);
    $visibility = gallery_effective_visibility($gallery);
    return [
        'id' => (int) $gallery['id'],
        'visibility' => $visibility,
        'visibility_label' => gallery_visibility_label($visibility),
        'own_password_enabled' => $ownPassword,
        'access_label' => (string) ($gallery['access_mode'] ?? 'normal') === 'password'
            ? ($ownPassword ? t('admin.dashboard.access_password_locked', 'Password locked') : t('admin.dashboard.access_direct_link_token', 'Direct-link token'))
            : t('admin.dashboard.access_no_password', 'No password'),
        'edit_revision' => gallery_edit_revision($gallery),
    ];
}

/**
 * Prepare the two owned password fields while retaining token-based protection.
 *
 * @param array<string,mixed> $gallery Current row protected by the editor lease.
 * @param bool $enabled Whether to install a new own password.
 * @param string $password Plaintext input; trimmed with the existing editor's password semantics.
 * @return array{access_password_hash:?string,access_mode:string} Narrow persistence map without unrelated access fields.
 */
function gallery_editor_own_password_fields(array $gallery, bool $enabled, string $password): array
{
    $password = trim($password);
    if ($enabled && $password === '') {
        throw new GalleryQuickAccessRefusal(t('gallery.password_required', 'Enter a gallery password.'));
    }
    $hash = $enabled ? password_hash($password, PASSWORD_DEFAULT) : null;
    if ($enabled && !is_string($hash)) {
        throw new GalleryQuickAccessRefusal(t('gallery.password_save_failed', 'Gallery password could not be saved.'));
    }
    return [
        'access_password_hash' => $hash,
        'access_mode' => $enabled || !empty($gallery['access_token_hash']) ? 'password' : 'normal',
    ];
}

/**
 * Change only the current gallery's password after verified access and revision preflight.
 *
 * Removing an own password retains token-only protection and never removes an ancestor's protection.
 *
 * @param array<string,mixed> $gallery Prepared authoritative gallery row.
 * @param int|string|null $expectedRevision Submitted decimal revision; every other transport type is deliberately passed unchanged to gallery_edit_begin() and refused as malformed.
 * @param bool $enabled Whether a nonempty new password should be installed.
 * @param string $password Untrusted plaintext, hashed in memory and never returned or logged.
 * @return array{id:int|string,edit_revision:int|string,visibility:string,access_mode:string,access_password_hash:?string,access_token_hash?:?string,access_listing?:string} Updated authoritative row for bounded projection; all unrelated stored columns remain present for the existing sidecar writer.
 */
function gallery_editor_change_own_password(array $gallery, mixed $expectedRevision, bool $enabled, string $password): array
{
    if (!gallery_access_schema_ready()) {
        throw new GalleryQuickAccessRefusal(t('admin.gallery_editor.access_schema_save_refused', 'Gallery save was refused because password/access schema is incomplete or could not be inspected. Check System Health before changing gallery visibility or protection.'));
    }
    $password = trim($password);
    if ($enabled && $password === '') {
        throw new GalleryQuickAccessRefusal(t('gallery.password_required', 'Enter a gallery password.'));
    }
    $lease = gallery_edit_begin($gallery, $expectedRevision, true);
    try {
        $current = $lease['gallery'];
        gallery_editor_update_fields((int) $current['id'], gallery_editor_own_password_fields($current, $enabled, $password));
        $updated = find_gallery((int) $current['id'], true);
        if (!$updated) throw new RuntimeException(t('admin.gallery_not_found', 'Gallery not found.'));
        write_gallery_sidecar($updated);
        return $updated;
    } finally {
        gallery_edit_end($lease);
    }
}
