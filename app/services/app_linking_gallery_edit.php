<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_gallery_edit.php
 * Module Type: Linked Gallery Metadata Service
 * Purpose: Edit individual descriptive fields under explicit native consent.
 * Responsibilities:
 *   - Return complete edit snapshots without truncating stored descriptions.
 *   - Recheck live grants and revisions under the shared gallery writer lease.
 *   - Persist only one selected field and verify database and sidecar completion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\now_sql;
use function Gallery\Models\admin_operation_model_lock_owned;
use function Gallery\Models\app_link_model_create_transaction_begin;
use function Gallery\Models\app_link_model_create_transaction_end;
use function Gallery\Models\gallery_model_find_by_id;
use function Gallery\Models\gallery_model_linked_visibility_lock;
use function Gallery\Models\gallery_model_update_fields;

/**
 * Preserve the complete database TEXT description in one explicit edit.
 * Type: int. Units: UTF-8 bytes. Scope: native gallery metadata snapshots.
 * Consumers: projection and edit validation. Rationale: 65,535 is the TEXT
 * column capacity; an oversized existing value must fail rather than truncate.
 */
const APP_LINKING_GALLERY_DESCRIPTION_MAX_BYTES = 65535;

/**
 * Match the persisted gallery display-title column capacity.
 * Type: int. Units: Unicode characters. Scope: native title editing.
 * Consumers: projection and validation. Rationale: VARCHAR(255) stores 255.
 */
const APP_LINKING_GALLERY_EDIT_TITLE_MAX_CHARS = 255;

/**
 * Bound JSON metadata posts while preserving complete UTF-8 descriptions.
 * Type: int. Units: bytes. Scope: the fixed native media POST endpoint.
 * Consumers: native media controller. Rationale: 512 KiB holds a full TEXT
 * value even with six-byte JSON escapes and the revision envelope.
 */
const APP_LINKING_GALLERY_EDIT_REQUEST_MAX_BYTES = 512 * 1024;

/**
 * Check separately approved metadata authority without widening old grants.
 *
 * @param string $scope Exact registered bearer scope.
 * @return bool True only for an Admin Photos bundle with gallery:edit.
 */
function app_linking_scope_allows_gallery_edit(string $scope): bool
{
    return app_linking_scope_allows_admin_media($scope)
        && str_contains($scope, APP_LINKING_GALLERY_EDIT_SUFFIX);
}

/**
 * Verify all existing columns needed for an exact native edit snapshot.
 *
 * @return bool True only when metadata and revision storage are confirmed.
 */
function app_linking_gallery_edit_ready(): bool
{
    return schema_inspection_is_available(app_linking_schema_status(
        'app_linking.gallery_edit',
        ['galleries' => ['id', 'title', 'description', 'edit_revision',
            'visibility', 'nsfw_enabled', 'access_listing']],
        ['galleries' => ['PRIMARY' => ['id']]], ['galleries'],
    ));
}

/**
 * Project a full current row to the native single-field editor contract.
 *
 * @param array{id:int|string,title:string,description:?string,edit_revision:int|string,visibility:string,access_listing:string,nsfw_enabled:int|string,...} $row Current internal gallery row.
 * @return array{id:int,title:string,description:string,revision:string,visibility:string,listed:bool,nsfw:bool} Canonical, non-secret editable snapshot.
 * @throws RuntimeException When any stored field cannot be represented exactly.
 */
function app_linking_gallery_edit_projection(array $row): array
{
    $description = $row['description'] ?? '';
    $revision = gallery_edit_revision($row);
    if (!is_string($row['title'] ?? null)
        || preg_match('//u', $row['title']) !== 1
        || mb_strlen($row['title'], 'UTF-8') > APP_LINKING_GALLERY_EDIT_TITLE_MAX_CHARS
        || !is_string($description) || preg_match('//u', $description) !== 1
        || strlen($description) > APP_LINKING_GALLERY_DESCRIPTION_MAX_BYTES
        || $revision === ''
        || !in_array($row['visibility'] ?? null, ['public', 'private', 'draft', 'unpublished'], true)
        || !in_array($row['access_listing'] ?? null, ['listed', 'unlisted'], true)
        || !in_array($row['nsfw_enabled'] ?? null, [0, 1, '0', '1'], true)) {
        throw new RuntimeException('Gallery edit snapshot is unavailable.');
    }
    return [
        'id' => (int) $row['id'], 'title' => $row['title'],
        'description' => $description, 'revision' => $revision,
        'visibility' => $row['visibility'],
        'listed' => $row['access_listing'] === 'listed',
        'nsfw' => (bool) $row['nsfw_enabled'],
    ];
}

/**
 * Read complete metadata only after checking live, separately approved access.
 *
 * @param array{grant_id:int,user_id:int,username:string,scope:string} $identity Current bearer identity.
 * @param string $token Presented bearer, never persisted.
 * @param string $issuer Exact configured installation issuer.
 * @param int $galleryId Selected physical gallery identity.
 * @return array{id:int,title:string,description:string,revision:string,visibility:string,listed:bool,nsfw:bool} Complete current snapshot.
 * @throws RuntimeException When authority, schema or gallery is unavailable.
 */
function app_linking_gallery_edit_read(
    array $identity, string $token, string $issuer, int $galleryId,
): array {
    if (!app_linking_scope_allows_gallery_edit($identity['scope'])) {
        throw new RuntimeException('Gallery edit was not approved.');
    }
    if (!app_linking_gallery_edit_ready()) {
        throw new RuntimeException('Gallery edit schema is unavailable.');
    }
    $row = app_linking_admin_media_gallery($identity, $token, $issuer, $galleryId);
    if ($row === null) {
        throw new RuntimeException('Gallery is unavailable.');
    }
    gallery_visibility_marker_assert_importable((string) $row['folder_path']);
    return app_linking_gallery_edit_projection($row);
}

/**
 * Save exactly one reviewed field, preserving all unrelated server settings.
 *
 * Title changes rename the physical subtree through the common move owner.
 * Grant and revision locks remain held through the physical/DB transition.
 * A failed sidecar after commit is an unknown outcome and is never replayed.
 *
 * @param array{grant_id:int,user_id:int,username:string,scope:string} $identity Current bearer identity.
 * @param string $token Presented bearer, never persisted.
 * @param string $issuer Exact configured installation issuer.
 * @param int $galleryId Selected physical gallery identity.
 * @param string $revision Exact revision presented in the fresh editor.
 * @param string $field One of title, description, nsfw or listed. Listing
 *     changes require public visibility; private/unpublished keep the override.
 * @param string|bool $value Desired text or explicit boolean.
 * @return array{id:int,title:string,description:string,revision:string,visibility:string,listed:bool,nsfw:bool} Confirmed canonical persisted snapshot.
 * @throws LinkedGalleryVisibilityConflict When the revision changed before writing.
 * @throws Throwable On refusal or uncertain database/sidecar completion.
 */
function app_linking_gallery_edit_save(
    array $identity, string $token, string $issuer, int $galleryId,
    string $revision, string $field, string|bool $value,
): array {
    app_linking_gallery_edit_read($identity, $token, $issuer, $galleryId);
    if (!in_array($field, ['title', 'description', 'nsfw', 'listed'], true)
        || gallery_edit_revision(['edit_revision' => $revision]) === '') {
        throw new RuntimeException('Invalid gallery edit.');
    }
    if (in_array($field, ['nsfw', 'listed'], true)) {
        if (!is_bool($value)) {
            throw new RuntimeException('Invalid gallery switch value.');
        }
        gallery_access_assert_public_policy_available();
        nsfw_guard_assert_public_policy_available();
    } elseif (!is_string($value) || preg_match('//u', $value) !== 1
        || ($field === 'title' && (trim($value) === ''
            || mb_strlen($value, 'UTF-8') > APP_LINKING_GALLERY_EDIT_TITLE_MAX_CHARS
            || preg_match('/[\x00-\x1f\x7f]/u', $value) === 1))
        || ($field === 'description'
            && strlen($value) > APP_LINKING_GALLERY_DESCRIPTION_MAX_BYTES)) {
        throw new RuntimeException('Invalid gallery text value.');
    }
    $hash = app_linking_access_token_hash($token);
    if ($hash === null) {
        throw new RuntimeException('Gallery authority is unavailable.');
    }
    $writer = gallery_edit_writer_begin();
    $transaction = false;
    $folderMove = null;
    try {
        if (!app_link_model_create_transaction_begin(
            $identity['user_id'], $identity['grant_id'], $hash, $issuer,
            APP_LINKING_WINAPP_CLIENT_ID, $identity['scope'],
        )) {
            throw new RuntimeException('Gallery authority is unavailable.');
        }
        $transaction = true;
        app_linking_recheck_wide_scope(APP_LINKING_WINAPP_CLIENT_ID, $identity['scope']);
        $current = gallery_model_linked_visibility_lock($galleryId);
        if ($current === null || gallery_edit_revision($current) !== $revision) {
            throw new LinkedGalleryVisibilityConflict('Gallery revision changed.');
        }
        if ($field === 'listed' && ($current['visibility'] ?? null) !== 'public') {
            throw new LinkedGalleryVisibilityConflict('Listing requires a public gallery.');
        }
        gallery_visibility_marker_assert_importable((string) $current['folder_path']);
        $column = match ($field) {
            'nsfw' => 'nsfw_enabled', 'listed' => 'access_listing', default => $field,
        };
        $stored = match ($field) {
            'nsfw' => (int) $value, 'listed' => $value ? 'listed' : 'unlisted',
            default => $value,
        };
        if (!admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Gallery writer is unavailable.');
        }
        if ($field === 'title') {
            $folderMove = move_gallery_folder_to_parent_owned(
                $galleryId, empty($current['parent_id']) ? null : (int) $current['parent_id'],
                null, false, (string) $stored, true,
                 numberTitleCollision: true,
            );
        }
        if ($folderMove === null || !$folderMove['moved']) {
            gallery_model_update_fields($galleryId, [$column => $stored], now_sql());
        }
        $updated = gallery_model_linked_visibility_lock($galleryId);
        if ($updated === null
            || gallery_edit_revision($updated) !== app_linking_visibility_next_revision($revision)
            || (string) $updated[$column] !== (string) $stored) {
            throw new RuntimeException('Gallery edit readback is unavailable.');
        }
        $before = $current;
        $after = $updated;
        unset($before[$column], $before['updated_at'], $before['edit_revision']);
        unset($after[$column], $after['updated_at'], $after['edit_revision']);
        if ($folderMove !== null && $folderMove['moved']) {
            if (($updated['folder_path'] ?? null) !== $folderMove['to']
                || ($updated['folder_path_hash'] ?? null) !== hash('sha256', $folderMove['to'])) {
                throw new RuntimeException('Gallery folder readback is unavailable.');
            }
            unset($before['folder_path'], $before['folder_path_hash']);
            unset($after['folder_path'], $after['folder_path_hash']);
        }
        if ($before !== $after || !admin_operation_model_lock_owned($writer)) {
            throw new RuntimeException('Unrelated gallery settings changed.');
        }
        app_link_model_create_transaction_end(true);
        $transaction = false;
        if ($folderMove !== null && $folderMove['moved']) {
            gallery_folder_move_complete(
                gallery_subtree_ids($galleryId),
                (int) ($current['parent_id'] ?? 0), (int) ($current['parent_id'] ?? 0),
                true, true,
            );
            $confirmed = gallery_model_find_by_id($galleryId);
            if ($confirmed === null || $confirmed['title'] !== $stored
                || $confirmed['folder_path'] !== $folderMove['to']
                || !admin_operation_model_lock_owned($writer)) {
                throw new RuntimeException('Gallery rename completion is unconfirmed.');
            }
            return app_linking_gallery_edit_projection($confirmed);
        }
        gallery_visit_content_update([$galleryId]);
        if (!admin_operation_model_lock_owned($writer)
            || !write_gallery_sidecar($updated)
            || gallery_model_find_by_id($galleryId) !== $updated) {
            throw new RuntimeException('Gallery edit completion is unconfirmed.');
        }
        return app_linking_gallery_edit_projection($updated);
    } catch (Throwable $error) {
        if ($error instanceof DeferredGalleryFolderMoveFailure) {
            $folderMove = $error->move;
        }
        if ($transaction) {
            // Only a confirmed database rollback permits moving bytes back.
            // An ambiguous/lost commit keeps the physical receipt for recovery.
            app_link_model_create_transaction_end(false);
            $transaction = false;
            if ($folderMove !== null) {
                gallery_folder_move_rollback($folderMove);
            }
        }
        throw $error;
    } finally {
        gallery_edit_writer_end($writer);
    }
}
