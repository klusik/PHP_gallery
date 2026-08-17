<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_reorder.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Handles admin and public gallery reordering workflows.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one admin or thumbnail responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   2026-05-12
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\redirect_to;
use function Gallery\Core\now_sql;
use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\child_galleries;
use function Gallery\Services\gallery_count_dated_rows;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_folder_name_from_path;
use function Gallery\Services\gallery_path_diagnostics;
use function Gallery\Services\gallery_sort_rows_by_date_preserving_undated_positions;
use function Gallery\Services\move_gallery_folder_to_parent;
use function Gallery\Services\public_path_schema_ready;
use function Gallery\Services\regenerate_public_paths;
use function Gallery\Services\sync_gallery_parent_ids;
use function Gallery\Services\smart_gallery_validate_gallery_parent_map;
use function Gallery\Services\t;
use function Gallery\Services\write_gallery_sidecar;
use function Gallery\Services\admin_log_event;

/**
 * Handles cms admin gallery reorder logic for the gallery application.
 *
 * The Admin dashboard sends the complete flattened gallery tree after a drag
 * operation. The submitted list is validated as an exact set match against the
 * database before any filesystem move or sort_order update is attempted. Parent
 * changes are delegated to move_gallery_folder_to_parent(), so the gallery
 * folder tree remains the source of truth and database paths follow disk state.
 */
function cms_admin_reorder_galleries(): void
{
    // The reorder endpoint must return clean JSON. Some shared-hosting setups
    // print PHP warnings as HTML when display_errors is enabled, so this small
    // response buffer lets the endpoint log and discard accidental diagnostic
    // output before the final JSON payload is emitted.
    $jsonResponseBufferStarted = false;
    if (!headers_sent()) {
        ob_start();
        $jsonResponseBufferStarted = true;
    }

    require_admin();
    verify_csrf();
    // Variable $rawTree stores the JSON payload submitted by the JavaScript nested ordering handler.
    $rawTree = (string) ($_POST['gallery_tree'] ?? '[]');
    // Variable $decodedTree stores the decoded row list before it is normalized.
    $decodedTree = json_decode($rawTree, true);
    if (!is_array($decodedTree)) {
        admin_reorder_galleries_response(false, t('admin.galleries.reorder_invalid_json'), $jsonResponseBufferStarted);
        return;
    }

    // Variable $submittedEntries stores normalized id and parent-id pairs in the exact submitted order.
    $submittedEntries = [];
    foreach ($decodedTree as $entry) {
        if (!is_array($entry)) {
            admin_reorder_galleries_response(false, t('admin.galleries.reorder_invalid_row'), $jsonResponseBufferStarted);
            return;
        }
        // Variable $galleryId stores the gallery id from one submitted tree row.
        $galleryId = (int) ($entry['id'] ?? 0);
        // Variable $parentId stores the requested parent id for one submitted tree row.
        $parentId = (int) ($entry['parent_id'] ?? 0);
        if ($galleryId <= 0 || $parentId < 0) {
            admin_reorder_galleries_response(false, t('admin.galleries.reorder_invalid_gallery_id'), $jsonResponseBufferStarted);
            return;
        }
        $submittedEntries[] = ['id' => $galleryId, 'parent_id' => $parentId];
    }
    if (!$submittedEntries) {
        admin_reorder_galleries_response(false, t('admin.galleries.reorder_empty'), $jsonResponseBufferStarted);
        return;
    }

    // Variable $submittedIds stores the submitted gallery id list for set validation.
    $submittedIds = array_map(static fn (array $entry): int => (int) $entry['id'], $submittedEntries);
    if (count($submittedIds) !== count(array_unique($submittedIds))) {
        admin_reorder_galleries_response(false, t('admin.galleries.reorder_duplicates'), $jsonResponseBufferStarted);
        return;
    }

    sync_gallery_parent_ids();
    // Variable $currentRows stores current database state for validation and change detection.
    $currentRows = db()->query('SELECT id, parent_id, sort_order, title, folder_path FROM galleries ORDER BY id')->fetchAll();
    // Variable $currentIds stores all gallery ids currently known by the database.
    $currentIds = array_map(static fn (array $row): int => (int) $row['id'], $currentRows);
    // Variable $sortedSubmittedIds stores the submitted id set in sorted order.
    $sortedSubmittedIds = $submittedIds;
    sort($sortedSubmittedIds);
    sort($currentIds);
    if ($sortedSubmittedIds !== $currentIds) {
        admin_reorder_galleries_response(false, t('admin.galleries.reorder_changed'), $jsonResponseBufferStarted);
        return;
    }

    // Variable $validIds stores gallery ids as a lookup table for parent validation.
    $validIds = array_fill_keys($currentIds, true);
    // Variable $seenIds stores ids already encountered in submitted tree order.
    $seenIds = [];
    foreach ($submittedEntries as $entry) {
        // Variable $galleryId stores the current gallery id being validated.
        $galleryId = (int) $entry['id'];
        // Variable $parentId stores the requested parent id being validated.
        $parentId = (int) $entry['parent_id'];
        if ($parentId === $galleryId) {
            admin_reorder_galleries_response(false, t('admin.galleries.reorder_self_parent'), $jsonResponseBufferStarted);
            return;
        }
        if ($parentId > 0 && !isset($validIds[$parentId])) {
            admin_reorder_galleries_response(false, t('admin.galleries.reorder_missing_parent'), $jsonResponseBufferStarted);
            return;
        }
        if ($parentId > 0 && !isset($seenIds[$parentId])) {
            admin_reorder_galleries_response(false, t('admin.galleries.reorder_child_before_parent'), $jsonResponseBufferStarted);
            return;
        }
        $seenIds[$galleryId] = true;
    }

    // Variable $currentParentById stores current parent ids keyed by gallery id.
    $currentParentById = [];
    // Variable $currentSortOrderById stores current sort order values keyed by gallery id.
    $currentSortOrderById = [];
    // Variable $currentTitleById stores gallery titles for readable delta diagnostics.
    $currentTitleById = [];
    // Variable $currentFolderPathById stores gallery paths before any filesystem move.
    $currentFolderPathById = [];
    foreach ($currentRows as $row) {
        $galleryId = (int) $row['id'];
        $currentParentById[$galleryId] = (int) ($row['parent_id'] ?? 0);
        $currentSortOrderById[$galleryId] = (int) ($row['sort_order'] ?? 0);
        $currentTitleById[$galleryId] = (string) ($row['title'] ?? '');
        $currentFolderPathById[$galleryId] = normalize_relative_path((string) ($row['folder_path'] ?? ''));
    }

    // Variable $submittedParentById stores requested parent ids keyed by gallery id.
    $submittedParentById = [];
    foreach ($submittedEntries as $entry) {
        $submittedParentById[(int) $entry['id']] = (int) $entry['parent_id'];
    }

    // Variable $pdo stores the active database connection used for sibling order updates.
    $pdo = db();
    // Variable $now stores one timestamp shared by all sort_order updates.
    $now = now_sql();
    // Variable $movedCount stores how many gallery folders changed parent.
    $movedCount = 0;
    // Variable $reorderDiagnostics stores filesystem details for moved galleries if saving fails.
    $reorderDiagnostics = [];
    // Variable $activeMoveDiagnostics stores the move currently being processed when an exception is raised.
    $activeMoveDiagnostics = null;
    try {
        // Validate the complete requested hierarchy before the first filesystem move so a later
        // Smart Gallery cycle cannot leave a partially applied drag-and-drop tree operation.
        smart_gallery_validate_gallery_parent_map($submittedParentById);
        foreach ($submittedEntries as $entry) {
            // Variable $galleryId stores the gallery being checked for a parent move.
            $galleryId = (int) $entry['id'];
            // Variable $parentId stores the requested parent id, with zero meaning root.
            $parentId = (int) $entry['parent_id'];
            if (($currentParentById[$galleryId] ?? 0) === $parentId) {
                continue;
            }
            $activeMoveDiagnostics = admin_gallery_reorder_move_diagnostics($galleryId, $parentId > 0 ? $parentId : null);
            $reorderDiagnostics[] = $activeMoveDiagnostics;
            move_gallery_folder_to_parent($galleryId, $parentId > 0 ? $parentId : null, null, true);
            $movedCount++;
            $activeMoveDiagnostics = null;
        }

        // Variable $siblingPositionByParent stores the next sort index for each parent id.
        $siblingPositionByParent = [];
        // Variable $nextSortOrderById stores the calculated persisted sort order for each submitted gallery.
        $nextSortOrderById = [];
        $pdo->beginTransaction();
        // Variable $stmt stores the prepared update reused for each reordered gallery row.
        $stmt = $pdo->prepare('UPDATE galleries SET sort_order = ?, updated_at = ? WHERE id = ?');
        foreach ($submittedEntries as $entry) {
            // Variable $parentId stores the submitted parent group whose sibling order is being assigned.
            $parentId = (int) $entry['parent_id'];
            // Variable $position stores the next sibling position in this parent group.
            $position = ($siblingPositionByParent[$parentId] ?? 0) + 1;
            $siblingPositionByParent[$parentId] = $position;
            // Variable $sortOrder stores a spaced integer so future maintenance can insert between rows if needed.
            $sortOrder = $position * 10;
            $nextSortOrderById[(int) $entry['id']] = $sortOrder;
            $stmt->execute([$sortOrder, $now, (int) $entry['id']]);
        }
        $pdo->commit();

        sync_gallery_parent_ids(true);

        // Sidecar and clean URL refresh are follow-up maintenance tasks. The
        // visible tree and the database order have already been saved at this
        // point, so a stale or missing folder must not turn a successful move
        // into a red failure message for the admin.
        $maintenanceWarnings = [];
        foreach ($submittedEntries as $entry) {
            try {
                // Variable $gallery stores the refreshed row written to its gallery.json sidecar.
                $gallery = find_gallery((int) $entry['id'], true);
                if ($gallery) {
                    write_gallery_sidecar($gallery);
                }
            } catch (Throwable $sidecarException) {
                $maintenanceWarnings[] = $sidecarException->getMessage();
            }
        }
        if (public_path_schema_ready()) {
            try {
                regenerate_public_paths();
            } catch (Throwable $publicPathException) {
                $maintenanceWarnings[] = $publicPathException->getMessage();
            }
        }

        // Variable $parentChanges stores only parent deltas, so the log explains real tree moves.
        $parentChanges = [];
        // Variable $sortOrderChanges stores only order deltas, so the log stays readable after drag operations.
        $sortOrderChanges = [];
        // Variable $changedGalleryIds stores the unique gallery ids changed by parent or sort order deltas.
        $changedGalleryIds = [];
        foreach ($submittedEntries as $entry) {
            $galleryId = (int) $entry['id'];
            $oldParentId = (int) ($currentParentById[$galleryId] ?? 0);
            $newParentId = (int) ($submittedParentById[$galleryId] ?? 0);
            $oldSortOrder = (int) ($currentSortOrderById[$galleryId] ?? 0);
            $newSortOrder = (int) ($nextSortOrderById[$galleryId] ?? 0);
            if ($oldParentId !== $newParentId) {
                $parentChanges[] = [
                    'gallery_id' => $galleryId,
                    'gallery_title' => $currentTitleById[$galleryId] ?? '',
                    'old_parent_id' => $oldParentId,
                    'new_parent_id' => $newParentId,
                    'old_folder_path' => $currentFolderPathById[$galleryId] ?? '',
                ];
                $changedGalleryIds[$galleryId] = true;
            }
            if ($oldSortOrder !== $newSortOrder) {
                $sortOrderChanges[] = [
                    'gallery_id' => $galleryId,
                    'gallery_title' => $currentTitleById[$galleryId] ?? '',
                    'parent_id' => $newParentId,
                    'old_sort_order' => $oldSortOrder,
                    'new_sort_order' => $newSortOrder,
                ];
                $changedGalleryIds[$galleryId] = true;
            }
        }

        admin_log_event('info', 'gallery.reordered', t('admin.galleries.log_reordered_tree'), [
            'submitted_entries' => count($submittedEntries),
            'persisted_galleries' => count($submittedEntries),
            'changed_galleries' => count($changedGalleryIds),
            'changed_gallery_ids' => array_map('intval', array_keys($changedGalleryIds)),
            'parent_changes' => $parentChanges,
            'order_changes' => $sortOrderChanges,
            'moved_folders' => $movedCount,
            'maintenance_warnings' => array_values(array_unique($maintenanceWarnings)),
        ], [
            'subject_type' => 'gallery_tree',
            'subject_id' => 0,
        ]);

        if ($maintenanceWarnings) {
            admin_log_event('warning', 'gallery.reorder_maintenance_warning', t('admin.galleries.log_reorder_refresh_warning'), [
                'warnings' => array_values(array_unique($maintenanceWarnings)),
            ]);
            admin_reorder_galleries_response(true, t('admin.galleries.reorder_saved_with_warning'), $jsonResponseBufferStarted);
            return;
        }

        admin_reorder_galleries_response(true, $movedCount > 0 ? t('admin.galleries.reorder_moved_saved') : t('admin.galleries.reorder_order_saved'), $jsonResponseBufferStarted);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_log_event('error', 'gallery.reorder_failed', t('admin.galleries.log_reorder_failed'), [
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'previous_error' => $exception->getPrevious() ? $exception->getPrevious()->getMessage() : null,
            'submitted_entries' => $submittedEntries,
            'moved_before_failure' => $movedCount,
            'active_move' => $activeMoveDiagnostics,
            'move_diagnostics' => $reorderDiagnostics,
            'gallery_root' => gallery_path_diagnostics('', 'configured gallery root'),
        ]);
        admin_reorder_galleries_response(false, admin_reorder_galleries_user_error_message($exception), $jsonResponseBufferStarted);
    }
}

/**
 * Build diagnostic context for one requested gallery hierarchy move.
 *
 * The returned array is written only to the admin log. It intentionally keeps
 * low-level filesystem details out of the red UI message while making the real
 * configured root, source folder, parent folder, and target folder visible for
 * troubleshooting.
 *
 * @param int $galleryId Gallery identifier.
 * @param ?int $parentId Parent id identifier.
 * @return array Structured result data for the caller.
 */
function admin_gallery_reorder_move_diagnostics(int $galleryId, ?int $parentId): array
{
    // $gallery stores the gallery row before the filesystem move is attempted.
    $gallery = find_gallery($galleryId);
    // $parent stores the requested parent row before the filesystem move is attempted.
    $parent = $parentId !== null && $parentId > 0 ? find_gallery($parentId) : null;
    // $diagnostics stores the full context used by admin logs.
    $diagnostics = [
        'gallery_id' => $galleryId,
        'requested_parent_id' => $parentId,
        'gallery_found' => $gallery !== null,
        'parent_found' => $parentId === null || $parent !== null,
    ];

    if (!$gallery) {
        return $diagnostics;
    }

    // $oldPath stores the gallery folder path before the move.
    $oldPath = normalize_relative_path((string) $gallery['folder_path']);
    // $folderName stores the final directory segment that should be preserved when the gallery is moved.
    $folderName = gallery_folder_name_from_path($oldPath);
    // $newPath stores the expected destination path based on the submitted parent id.
    $newPath = $parent ? normalize_relative_path((string) $parent['folder_path'] . '/' . $folderName) : $folderName;

    $diagnostics += [
        'gallery_title' => (string) ($gallery['title'] ?? ''),
        'old_parent_id' => isset($gallery['parent_id']) ? (int) $gallery['parent_id'] : null,
        'old_folder_path' => $oldPath,
        'expected_new_folder_path' => $newPath,
        'folder_name' => $folderName,
        'parent_title' => $parent ? (string) ($parent['title'] ?? '') : null,
        'parent_folder_path' => $parent ? normalize_relative_path((string) $parent['folder_path']) : null,
        'source_path' => gallery_path_diagnostics($oldPath, 'move source'),
        'target_path' => gallery_path_diagnostics($newPath, 'move target'),
    ];

    if ($parent) {
        $diagnostics['parent_path'] = gallery_path_diagnostics((string) $parent['folder_path'], 'requested parent');
    }

    return $diagnostics;
}

/**
 * Convert internal gallery reorder exceptions into admin-facing language.
 *
 * @param Throwable $exception Original exception raised while saving the gallery tree.
 * @return string Message safe to show directly in the admin interface.
 */
function admin_reorder_galleries_user_error_message(Throwable $exception): string
{
    // Variable $message stores the technical message used only for mapping.
    $message = $exception->getMessage();

    if (str_contains($message, 'outside the configured root')) {
        return t('admin.galleries.reorder_error_folder_outside_root');
    }
    if (str_contains($message, 'target parent is outside the configured root or does not exist')) {
        return t('admin.galleries.reorder_error_destination_unavailable');
    }
    if (str_contains($message, 'Gallery target path is outside the configured root')) {
        return t('admin.galleries.reorder_error_destination_outside_root');
    }
    if (str_contains($message, 'Current gallery folder does not exist on disk')) {
        return t('admin.galleries.reorder_error_folder_missing');
    }
    if (str_contains($message, 'Destination folder already exists on disk') || str_contains($message, 'Another gallery already uses the destination folder path')) {
        return t('admin.galleries.reorder_error_destination_exists');
    }
    if (str_contains($message, 'own subgalleries')) {
        return t('admin.galleries.reorder_error_own_child');
    }
    if (str_contains($message, 'A subgallery must appear below its parent')) {
        return t('admin.galleries.reorder_error_incomplete_tree');
    }

    return t('admin.galleries.reorder_error_generic');
}

/**
 * Sends a JSON response for Admin gallery reorder requests.
 *
 * @param bool $ok Whether the operation completed successfully.
 * @param string $message Human-readable result message.
 * @param bool $cleanBufferedOutput Clean buffered output value.
 */
function admin_reorder_galleries_response(bool $ok, string $message, bool $cleanBufferedOutput = false): void
{
    // $unexpectedOutput stores accidental HTML warnings or notices generated
    // before the JSON response. This keeps the browser-side fetch parser from
    // seeing "<br /><b>Warning" before the actual JSON object.
    $unexpectedOutput = '';
    if ($cleanBufferedOutput && ob_get_level() > 0) {
        $unexpectedOutput = (string) ob_get_clean();
    }

    if ($unexpectedOutput !== '') {
        admin_log_event($ok ? 'warning' : 'error', 'gallery.reorder_response_output_discarded', 'Gallery reorder generated output before its JSON response.', [
            'operation_saved' => $ok,
            'message' => $message,
            'discarded_output_preview' => mb_substr(trim(strip_tags($unexpectedOutput)), 0, 1000),
            'discarded_output_bytes' => strlen($unexpectedOutput),
        ]);
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_THROW_ON_ERROR);
}

/**
 * Calculates a full order after replacing exactly one visible pagination slice.
 *
 * Public gallery page reordering intentionally submits only the cards that are
 * visible on the current pagination page. The server verifies that the posted
 * ids still match the same offset and count in the current database order, then
 * returns the complete sibling order with only that slice rearranged.
 *
 * @param array<int> $currentIds Complete current sibling order from the database.
 * @param array<int> $submittedIds Reordered ids submitted by the browser.
 * @param int $visibleOffset Zero-based offset of the visible pagination page.
 * @param int $visibleCount Number of ids rendered on the visible page.
 * @return array<int>|null Complete order after the visible slice is replaced, or null when validation fails.
 */
function admin_visible_page_reordered_ids(array $currentIds, array $submittedIds, int $visibleOffset, int $visibleCount): ?array
{
    if ($visibleOffset < 0 || $visibleCount < 1 || count($submittedIds) !== $visibleCount) {
        return null;
    }

    // $visibleSlice stores the database ids that belong to this exact pagination page.
    $visibleSlice = array_slice($currentIds, $visibleOffset, $visibleCount);
    if (count($visibleSlice) !== $visibleCount) {
        return null;
    }

    // $expectedIds stores the visible ids sorted for set comparison.
    $expectedIds = $visibleSlice;
    // $actualIds stores the submitted ids sorted for set comparison.
    $actualIds = $submittedIds;
    sort($expectedIds);
    sort($actualIds);
    if ($expectedIds !== $actualIds) {
        return null;
    }

    // $nextIds stores the full order with only the current visible page changed.
    $nextIds = array_values($currentIds);
    foreach ($submittedIds as $index => $submittedId) {
        $nextIds[$visibleOffset + $index] = $submittedId;
    }

    return $nextIds;
}

/**
 * Decodes and validates a JSON id order submitted by JavaScript.
 *
 * @param string $rawOrder JSON encoded id list.
 * @return array<int>|null Positive unique integer ids, or null when malformed.
 */
function admin_decode_reorder_id_list(string $rawOrder): ?array
{
    // $decodedOrder stores the decoded list before integer normalization.
    $decodedOrder = json_decode($rawOrder, true);
    if (!is_array($decodedOrder)) {
        return null;
    }

    // $submittedIds stores the positive ids in their submitted order.
    $submittedIds = array_values(array_filter(array_map('intval', $decodedOrder), static fn (int $id): bool => $id > 0));
    if (!$submittedIds || count($submittedIds) !== count(array_unique($submittedIds))) {
        return null;
    }

    return $submittedIds;
}


/**
 * Persist a date-based order for direct subgalleries from a public gallery page.
 *
 * The endpoint is admin-only and intentionally keeps parent_id unchanged. It
 * rewrites sort_order for the complete direct-child set of the current gallery,
 * using the same date-sort algorithm as the public preview: only rows with a
 * filled start date move, and undated rows keep their current positions.
 */
function cms_admin_sort_public_subgalleries_by_date(): void
{
    require_admin();
    verify_csrf();

    // $parentGalleryId stores the gallery whose direct child order is being normalized by date.
    $parentGalleryId = (int) ($_POST['gallery_id'] ?? 0);
    // $sortMode stores the requested date direction accepted from the admin toolbar.
    $sortMode = strtolower(trim((string) ($_POST['sort_mode'] ?? '')));
    // $parentGallery stores the parent gallery row used for redirect and ownership validation.
    $parentGallery = find_gallery($parentGalleryId);
    if (!$parentGallery) {
        cms_not_found();
        return;
    }

    if (!in_array($sortMode, ['asc', 'desc'], true)) {
        flash_message('public_notice', t('admin.galleries.public_subgallery_date_sort_invalid', 'Choose a valid date sort direction before saving.'));
        redirect_to(gallery_public_url($parentGallery));
    }

    // $currentRows stores every direct child currently owned by this parent gallery.
    $currentRows = child_galleries($parentGalleryId, false);
    // $datedCount stores how many children can participate in the persistent date sort.
    $datedCount = gallery_count_dated_rows($currentRows);
    if ($datedCount < 2) {
        flash_message('public_notice', t('admin.galleries.public_subgallery_date_sort_not_enough', 'At least two direct subgalleries with a filled From date are required before date sorting can be saved.'));
        redirect_to(gallery_public_url($parentGallery));
    }

    // $sortedRows stores the final direct-child order after date sorting only the dated positions.
    $sortedRows = gallery_sort_rows_by_date_preserving_undated_positions($currentRows, $sortMode);
    // $sortedIds stores the direct-child ids in the order that will become the real public order.
    $sortedIds = array_map(static fn (array $gallery): int => (int) $gallery['id'], $sortedRows);

    // $pdo stores the active database connection used for the atomic order update.
    $pdo = db();
    // $now stores one timestamp shared by all rows touched by this date-sort save.
    $now = now_sql();
    try {
        $pdo->beginTransaction();
        // $stmt stores the prepared update reused for each direct child gallery.
        $stmt = $pdo->prepare('UPDATE galleries SET sort_order = ?, updated_at = ? WHERE id = ? AND parent_id = ?');
        foreach ($sortedIds as $index => $galleryId) {
            // $sortOrder stores a normalized sibling position after the persistent date sort.
            $sortOrder = ($index + 1) * 10;
            $stmt->execute([$sortOrder, $now, $galleryId, $parentGalleryId]);
        }
        $pdo->commit();

        admin_log_event('info', 'gallery.public_subgallery_date_sorted', t('admin.galleries.log_public_subgallery_date_sorted', 'Public subgalleries were sorted by date.'), [
            'parent_gallery_id' => $parentGalleryId,
            'sort_mode' => $sortMode,
            'dated_count' => $datedCount,
            'sorted_gallery_ids' => $sortedIds,
        ]);
        flash_message('public_notice', t('admin.galleries.public_subgallery_date_sort_saved', 'Subgallery date order was saved. This is now the real order for all visitors.'));
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_log_event('error', 'gallery.public_subgallery_date_sort_failed', t('admin.galleries.log_public_subgallery_date_sort_failed', 'Public subgallery date sort failed.'), [
            'parent_gallery_id' => $parentGalleryId,
            'sort_mode' => $sortMode,
            'error' => $exception->getMessage(),
        ]);
        flash_message('public_notice', t('admin.galleries.public_subgallery_date_sort_failed_with_error', 'Subgallery date order could not be saved: {error}', ['error' => $exception->getMessage()]));
    }

    redirect_to(gallery_public_url($parentGallery));
}

/**
 * Handles public gallery page subgallery reordering for logged-in admins.
 *
 * This endpoint is intentionally narrower than the Admin dashboard tree reorder.
 * It never changes parent_id values and never nests galleries. It only reshuffles
 * the direct children of the gallery currently being viewed, and only when the
 * submitted ids match the visible pagination slice rendered into the page.
 */
function cms_admin_reorder_public_galleries(): void
{
    require_admin();
    verify_csrf();

    // $parentGalleryId stores the gallery whose direct child order is being changed.
    $parentGalleryId = (int) ($_POST['gallery_id'] ?? 0);
    // $parentGallery stores the parent gallery row used for ownership validation.
    $parentGallery = find_gallery($parentGalleryId);
    if (!$parentGallery) {
        cms_not_found();
        return;
    }

    // $submittedIds stores the visible subgallery ids in their new browser order.
    $submittedIds = admin_decode_reorder_id_list((string) ($_POST['gallery_order'] ?? '[]'));
    if ($submittedIds === null) {
        admin_reorder_public_page_response(false, t('admin.galleries.public_subgallery_reorder_invalid'));
        return;
    }

    // $visibleOffset stores the first item position rendered on the current pagination page.
    $visibleOffset = (int) ($_POST['visible_offset'] ?? -1);
    // $visibleCount stores the number of items rendered on the current pagination page.
    $visibleCount = (int) ($_POST['visible_count'] ?? 0);
    // $currentRows stores every direct child currently owned by this parent gallery.
    $currentRows = child_galleries($parentGalleryId, false);
    // $currentIds stores the complete direct-child order before the requested change.
    $currentIds = array_map(static fn (array $gallery): int => (int) $gallery['id'], $currentRows);
    // $nextIds stores the full direct-child order with only the current visible page rearranged.
    $nextIds = admin_visible_page_reordered_ids($currentIds, $submittedIds, $visibleOffset, $visibleCount);
    if ($nextIds === null) {
        admin_reorder_public_page_response(false, t('admin.galleries.public_subgallery_page_changed'));
        return;
    }

    // $pdo stores the active database connection used for the atomic order update.
    $pdo = db();
    // $now stores one timestamp shared by all rows touched by this reorder operation.
    $now = now_sql();
    try {
        $pdo->beginTransaction();
        // $stmt stores the prepared update reused for each direct child gallery.
        $stmt = $pdo->prepare('UPDATE galleries SET sort_order = ?, updated_at = ? WHERE id = ? AND parent_id = ?');
        foreach ($nextIds as $index => $galleryId) {
            // $sortOrder stores a normalized sibling position while preserving every non-visible sibling position.
            $sortOrder = ($index + 1) * 10;
            $stmt->execute([$sortOrder, $now, $galleryId, $parentGalleryId]);
        }
        $pdo->commit();

        admin_log_event('info', 'gallery.public_page_reordered', t('admin.galleries.log_public_page_reordered'), [
            'parent_gallery_id' => $parentGalleryId,
            'visible_offset' => $visibleOffset,
            'visible_count' => $visibleCount,
            'submitted_gallery_ids' => $submittedIds,
        ]);
        admin_reorder_public_page_response(true, t('admin.galleries.public_subgallery_order_saved'));
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        admin_log_event('error', 'gallery.public_page_reorder_failed', t('admin.galleries.log_public_page_reorder_failed'), [
            'parent_gallery_id' => $parentGalleryId,
            'error' => $exception->getMessage(),
        ]);
        admin_reorder_public_page_response(false, t('admin.galleries.public_subgallery_reorder_failed_with_error', ['error' => $exception->getMessage()]));
    }
}

/**
 * Returns a JSON payload for public gallery page ordering requests.
 *
 * @param bool $ok Whether the operation completed successfully.
 * @param string $message Human-readable result for the inline toolbar.
 */
function admin_reorder_public_page_response(bool $ok, string $message): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_THROW_ON_ERROR);
}
