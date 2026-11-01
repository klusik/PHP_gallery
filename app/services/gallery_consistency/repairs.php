<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_consistency/repairs.php
 * Module Type: Service Part
 * Purpose: Repair only explicit findings selected from a completed report.
 * Responsibilities: Revalidate leases, preserve originals and valid derivatives.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loaded only by gallery_consistency.php; report shapes belong there.
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Models\gallery_consistency_model_snapshot;
use function Gallery\Models\gallery_consistency_model_galleries;
use function Gallery\Models\gallery_model_update_fields;

/**
 * Prepare exact repair tasks from server-owned findings, never client paths.
 * @param ConsistencyJob $job Completed report updated into a repair job.
 * @param list<string> $keys Selected report group identities.
 * @param bool $all Whether the user explicitly selected every repairable group.
 * @param bool $browserEnabled Saved browser-processing availability supplied by
 *     its settings owner; false refuses image work before any repair starts.
 * @return void Refuses stale/incomplete reports or an empty repair selection.
 */
function gallery_consistency_prepare_repairs(
    array &$job,
    array $keys,
    bool $all = false,
    bool $browserEnabled = true,
): void {
    gallery_consistency_reconcile_repairs($job);
    if (!in_array($job['mode'], ['report', 'repair'], true)
        || $job['status'] !== 'complete'
    ) {
        throw new RuntimeException('consistency_report_required');
    }
    gallery_consistency_assert_catalog($job);
    $tasks = [];
    foreach ($job['groups'] as $key => $group) {
        if (!$all && !in_array($key, $keys, true)) {
            continue;
        }
        foreach ($group['issues'] as $issue) {
            if ($issue['repair'] === '') {
                continue;
            }
            $identity = $key . ':' . gallery_consistency_repair_identity($issue);
            $tasks[$identity] = ['key' => $key, 'issue' => $issue];
        }
    }
    if ($tasks === []) {
        throw new RuntimeException('consistency_selection_empty');
    }
    if (!$browserEnabled && array_filter($tasks,
        static fn(array $task): bool => in_array(
            $task['issue']['repair'], ['image', 'source'], true,
        )) !== []
    ) {
        throw new RuntimeException('consistency_browser_unavailable');
    }
    $job['queue'] = array_values($tasks);
    $job['mode'] = 'repair';
    $job['phase'] = 'repairs';
    $job['status'] = 'running';
    $job['processed'] = 0;
    $job['total'] = count($tasks);
    $job['repaired'] = 0;
    $job['failed'] = 0;
    $job['results_start'] = count($job['results']);
    $job['remaining_ready'] = false;
    $job['sequence']++;
}

/**
 * Identify findings completed by the same guarded repair operation.
 * @param ConsistencyIssue $issue Server-owned repair intent and exact target.
 * @return string Stable gallery, image or unindexed-file task identity.
 */
function gallery_consistency_repair_identity(array $issue): string
{
    return $issue['repair'] === 'gallery' ? 'gallery'
        : $issue['repair'] . ':' . $issue['image_id']
            . ($issue['image_id'] === 0 ? ':' . $issue['path'] : '');
}

/**
 * Remove confirmed repair findings and keep the remaining report selectable.
 * @param ConsistencyJob $job Completed successful queue, or an unchanged active,
 *     failed or already reconciled checkpoint. Existing completed jobs are
 *     adopted once without a new photograph or filesystem inspection.
 * @return void Keeps manual/unselected findings and history; refreshes only
 *     catalog identities and pure proposals, never the catalog fingerprint.
 */
function gallery_consistency_reconcile_repairs(array &$job): void
{
    if (($job['remaining_ready'] ?? false) || $job['mode'] !== 'repair'
        || $job['status'] !== 'complete' || $job['failed'] > 0
        || $job['processed'] < $job['total']
    ) return;

    $completed = [];
    foreach ($job['queue'] as $task) {
        $completed[$task['key']][gallery_consistency_repair_identity(
            $task['issue'],
        )] = true;
    }
    $job['galleries'] = gallery_consistency_model_galleries();
    $catalog = [];
    $job['paths'] = [];
    foreach ($job['galleries'] as $gallery) {
        $catalog[(int) $gallery['id']] = $gallery;
        $job['paths'][$gallery['folder_path']] = (int) $gallery['id'];
    }
    foreach ($job['groups'] as $key => $group) {
        $group['issues'] = array_values(array_filter($group['issues'],
            static fn(array $issue): bool => $issue['repair'] === ''
                || !isset($completed[$key][gallery_consistency_repair_identity($issue)])));
        if ($group['issues'] === []) {
            unset($job['groups'][$key]);
            continue;
        }
        $gallery = $catalog[$group['id']] ?? null;
        if ($gallery !== null) {
            $group['title'] = (string) $gallery['title'];
            $group['path'] = (string) $gallery['folder_path'];
            $sidecar = array_values(array_filter($group['changes'] ?? [],
                static fn(array $change): bool => $change['field'] === 'sidecar_value'));
            $hasGalleryRepair = array_filter($group['issues'],
                static fn(array $issue): bool => $issue['repair'] === 'gallery') !== [];
            $group['changes'] = [];
            if ($hasGalleryRepair) {
                try {
                    $probe = $job;
                    $probe['groups'] = [];
                    gallery_consistency_check_catalog_gallery($probe, $gallery);
                    $group['changes'] = array_merge(
                        $probe['groups']['g' . $group['id']]['changes'] ?? [], $sidecar,
                    );
                } catch (Throwable) {
                    // A now-unsafe catalog path keeps its original finding.
                    // Do not invent a proposal or conceal the completed repair;
                    // the catalog lease still refuses later conflicting writes.
                }
            }
        }
        $job['groups'][$key] = $group;
    }
    $job['remaining_ready'] = true;
}

/**
 * Refuse a report whose catalog changed since its last confirmed receipt.
 * @param ConsistencyJob $job Report/repair lease to validate.
 * @return void Throws before any target mutation on a concurrent catalog edit.
 */
function gallery_consistency_assert_catalog(array $job): void
{
    $snapshot = gallery_consistency_model_snapshot(($job['scope'] ?? '') !== 'names');
    if (!hash_equals($job['fingerprint'], $snapshot['fingerprint'])) {
        throw new RuntimeException('consistency_stale');
    }
}

/**
 * Perform one repair task while retaining exact failure and completion receipts.
 * @param ConsistencyJob $job Session-owned repair queue.
 * @return void Advances progress only after the operation returned or failed;
 *     failures remain visible and are never silently retried.
 */
function gallery_consistency_repair_step(array &$job): void
{
    gallery_consistency_load_maintenance_owner();
    gallery_consistency_assert_catalog($job);
    $task = $job['queue'][$job['processed']] ?? null;
    if ($task === null) {
        $job['status'] = 'complete';
        $job['phase'] = 'finished';
        gallery_consistency_reconcile_repairs($job);
        return;
    }
    $issue = $task['issue'];
    $group = $job['groups'][$task['key']];
    $failure = null;
    $lock = gallery_edit_writer_begin();
    try {
        // Check again inside the shared writer lease, before the first write.
        gallery_consistency_assert_catalog($job);
        mutation_schema_assert_available(
            gallery_move_schema_status(), 'gallery.consistency_repair',
            'Current gallery ownership storage is required.',
            'Gallery ownership storage could not be verified.',
        );
        thumbnail_metadata_preflight_write_schema('gallery.consistency_repair');
        if (gallery_image_move_pending() !== []) {
            throw new RuntimeException('consistency_pending_move');
        }
        gallery_consistency_repair_target($job, $task['key'], $issue);
        if ($job['status'] === 'browser') {
            $job['fingerprint'] = gallery_consistency_model_snapshot(
                ($job['scope'] ?? '') !== 'names',
            )['fingerprint'];
            return; // Browser output must be verified before counting this task.
        }
        $job['repaired']++;
        $status = 'repaired';
    } catch (Throwable $exception) {
        // Private technical receipts retain provenance without raw exception
        // messages, SQL, absolute paths, tokens or configuration values.
        $failure = ['exception' => get_class($exception),
            'owner' => basename($exception->getFile()),
            'line' => $exception->getLine()];
        if (str_starts_with($exception->getMessage(), 'consistency_')
            && preg_match('/^consistency_[a-z_]+$/', $exception->getMessage()) === 1
        ) $failure['reason'] = $exception->getMessage();
        $job['failed']++;
        $job['status'] = 'blocked';
        $status = 'failed';
        // Do not conceal an uncertain partial filesystem/catalog result.
        admin_log_event(
            'warning', 'gallery.consistency_repair_failed',
            'A selected consistency repair did not complete.',
            ['gallery_id' => $group['id'], 'image_id' => $issue['image_id'],
                'finding' => $issue['code'], 'failure' => $failure],
        );
    } finally {
        gallery_edit_writer_end($lock);
    }
    $job['results'][] = [
        'key' => $task['key'], 'code' => $issue['code'], 'status' => $status,
    ] + ($failure === null ? [] : ['failure' => $failure]);
    $job['processed']++;
    $job['fingerprint'] = gallery_consistency_model_snapshot(
        ($job['scope'] ?? '') !== 'names',
    )['fingerprint'];
    if ($job['status'] !== 'blocked' && $job['processed'] >= $job['total']) {
        $job['status'] = 'complete';
        $job['phase'] = 'finished';
        gallery_consistency_reconcile_repairs($job);
    }
}

/**
 * Revalidate and repair one exact server-owned target through existing owners.
 * @param ConsistencyJob $job Source hashes and report groups.
 * @param string $key Selected stable report group identifier.
 * @param ConsistencyIssue $issue Finding carrying an allowlisted repair intent.
 * @return void Throws on changed sources, ambiguous ownership or failed writes.
 */
function gallery_consistency_repair_target(
    array &$job,
    string $key,
    array $issue,
): void {
    $group = $job['groups'][$key];
    $gallery = $group['id'] > 0 ? find_gallery($group['id'], true) : null;
    if (in_array($issue['repair'], ['import', 'scan'], true)) {
        $relative = $group['path'] . '/' . $issue['path'];
        $target = gallery_abs_path($relative);
        if (is_link($target) || !is_file($target) || !hash_equals(
            $job['disk_hashes'][$relative] ?? '',
            (string) hash_file('sha256', $target),
        )) {
            throw new RuntimeException('consistency_source_changed');
        }
    }
    if ($issue['repair'] === 'import') {
        $path = $group['path'];
        gallery_import_assert_canonical_targets([$path]);
        $ancestors = ensure_gallery_ancestors_for_path($path);
        $gallery = create_gallery_row_for_folder($path);
        if (!$gallery) {
            throw new RuntimeException('consistency_import_failed');
        }
        $job['groups'][$key]['id'] = (int) $gallery['id'];
        // Each photograph remains a separate repair task/progress receipt.
        scan_gallery_selected_images((int) $gallery['id'], [$issue['path']]);
        foreach (gallery_normalize_imported_folders(array_merge(
            $ancestors, [(int) $gallery['id']],
        )) as $move) {
            gallery_consistency_relocate_report($job, $move['from'], $move['to']);
        }
        $gallery = find_gallery((int) $gallery['id'], true);
        gallery_consistency_relocate_report(
            $job, $path, (string) $gallery['folder_path'],
        );
        return;
    }
    if (!$gallery) {
        throw new RuntimeException('consistency_gallery_missing');
    }
    if ($issue['repair'] === 'gallery') {
        $oldPath = (string) $gallery['folder_path'];
        $title = gallery_disk_source_title($oldPath, (string) $gallery['title']);
        $ancestors = ensure_gallery_ancestors_for_path($oldPath);
        $parent = find_parent_gallery_for_path($oldPath);
        $parentId = $parent ? (int) $parent['id'] : null;
        move_gallery_folder_to_parent(
            (int) $gallery['id'], $parentId, null, false, $title,
        );
        gallery_model_update_fields((int) $gallery['id'], [
            'title' => $title, 'parent_id' => $parentId,
        ], \Gallery\Core\now_sql());
        gallery_visit_content_update([(int) $gallery['id']]);
        $updated = find_gallery((int) $gallery['id'], true);
        if (!$updated || !write_gallery_sidecar($updated)) {
            throw new RuntimeException('consistency_sidecar_failed');
        }
        $newPath = (string) $updated['folder_path'];
        gallery_consistency_relocate_report($job, $oldPath, $newPath);
        foreach (gallery_normalize_imported_folders($ancestors) as $move) {
            gallery_consistency_relocate_report($job, $move['from'], $move['to']);
        }
        $job['groups'][$key]['title'] = $title;
        return;
    }
    if ($issue['repair'] === 'scan') {
        scan_gallery_selected_images((int) $gallery['id'], [$issue['path']]);
        if (!find_image_by_path((int) $gallery['id'], $issue['path'])) {
            throw new RuntimeException('consistency_scan_failed');
        }
        return;
    }
    $image = find_image($issue['image_id'], true);
    if (!$image || (int) $image['gallery_id'] !== (int) $gallery['id']) {
        throw new RuntimeException('consistency_source_changed');
    }
    $source = image_abs_path($image, $gallery);
    if (is_link($source) || !is_file($source)
        || !hash_equals(
            $job['source_hashes'][(int) $image['id']] ?? '',
            (string) hash_file('sha256', $source),
        )
    ) {
        throw new RuntimeException('consistency_source_changed');
    }
    thumbnail_assert_source_identity_owned($image);
    if ($issue['repair'] === 'source') {
        scan_gallery_selected_images((int) $gallery['id'], [
            (string) $image['relative_path'],
        ]);
        $image = find_image((int) $image['id'], true);
    }
    if ($issue['repair'] === 'metadata') {
        thumbnail_metadata_refresh_image($image, $gallery, null, false);
        $probe = $job;
        $probe['groups'] = [];
        gallery_consistency_check_image($probe, $image);
        foreach ($probe['groups'] as $findings) {
            foreach ($findings['issues'] as $finding) {
                if ($finding['repair'] === 'metadata') {
                    throw new RuntimeException('consistency_metadata_failed');
                }
            }
        }
        if (!schema_inspection_is_available(thumbnail_metadata_mutation_schema_status())) {
            throw new RuntimeException('consistency_metadata_failed');
        }
        return;
    }
    if (in_array($issue['repair'], ['image', 'source'], true)) {
        // Reinspect now: another repair may already have fixed this source.
        $probe = $job;
        $probe['groups'] = [];
        $probe['scope'] = 'files';
        gallery_consistency_check_image($probe, $image);
        $replace = [];
        $force = [];
        $displayWork = false;
        foreach ($probe['groups'] as $findings) {
            foreach ($findings['issues'] as $finding) {
                if ($finding['repair'] === 'image' && $finding['details'] !== '') {
                    $replace[] = $finding['details'];
                } elseif ($finding['repair'] === 'image') {
                    $displayWork = true;
                }
            }
        }
        foreach ($group['issues'] as $reported) {
            if ($reported['image_id'] === (int) $image['id']
                && $reported['code'] === 'thumbnail_decode_failed'
            ) {
                $replace[] = $reported['details'];
                $force[] = $reported['details'];
            }
        }
        if ($replace === [] && !$displayWork) return;
        if (image_uses_dng_display_derivatives($image)
            || in_array(strtolower(pathinfo($source, PATHINFO_EXTENSION)),
                ['heic', 'heif'], true)
        ) {
            throw new RuntimeException('consistency_browser_unsupported');
        }
        $job['browser_pending'] = [
            'image_id' => (int) $image['id'], 'gallery_id' => (int) $gallery['id'],
            'gallery_path' => (string) $gallery['folder_path'],
            'relative_path' => (string) $image['relative_path'],
            'source_hash' => $job['source_hashes'][(int) $image['id']],
            'variants' => array_values(array_unique($replace)),
            'force' => array_values(array_unique($force)), 'uploaded' => [],
            'token' => $job['token'], 'sequence' => $job['sequence'],
        ];
        $job['status'] = 'browser';
        $job['phase'] = 'browser';
        return;
    }
    throw new RuntimeException('consistency_action_unsupported');
}

/**
 * Resolve one session-owned browser ticket and revalidate its exact source.
 * @param string $token Opaque report token supplied with source/upload requests.
 * @param int $sequence Exact prepared task sequence, never a client file target.
 * @return ConsistencyBrowserTarget Authorized target or a stable refusal.
 */
function gallery_consistency_browser_target(string $token, int $sequence): array
{
    $job = \Gallery\Core\session_context_get('gallery_consistency_job');
    if (!is_array($job) || ($job['status'] ?? '') !== 'browser'
        || !hash_equals((string) ($job['token'] ?? ''), $token)
        || ($job['sequence'] ?? -1) !== $sequence
        || !is_array($job['browser_pending'] ?? null)
    ) {
        throw new RuntimeException('consistency_browser_stale');
    }
    $target = $job['browser_pending'];
    $image = find_image($target['image_id'], true);
    $gallery = find_gallery($target['gallery_id'], true);
    if (!$image || !$gallery
        || (int) $image['gallery_id'] !== $target['gallery_id']
        || $image['relative_path'] !== $target['relative_path']
        || $gallery['folder_path'] !== $target['gallery_path']
    ) {
        throw new RuntimeException('consistency_browser_stale');
    }
    $source = image_abs_path($image, $gallery);
    if (is_link($source) || !is_file($source)
        || !hash_equals($target['source_hash'], (string) hash_file('sha256', $source))
    ) {
        throw new RuntimeException('consistency_browser_stale');
    }
    thumbnail_assert_source_identity_owned($image);
    return $target;
}

/**
 * Confirm browser-published variants before admitting the next repair task.
 * @param ConsistencyJob $job Current browser checkpoint, updated on confirmation.
 * @return void Leaves incomplete work resumable; never generates image pixels.
 */
function gallery_consistency_browser_verify(array &$job): void
{
    if ($job['status'] !== 'browser') return;
    gallery_consistency_load_maintenance_owner();
    $lock = gallery_edit_writer_begin();
    try {
        gallery_consistency_assert_catalog($job);
        $target = gallery_consistency_browser_target($job['token'], $job['sequence']);
        $image = find_image($target['image_id'], true);
        $gallery = find_gallery($target['gallery_id'], true);
        $source = image_abs_path($image, $gallery);
        $geometry = thumbnail_source_geometry_dimensions($source, $image);
        $missing = [];
        foreach ($target['variants'] as $variant) {
            [$size, $format] = explode(':', $variant);
            $path = thumbnail_abs_path($image, $gallery, (int) $size, $format);
            $valid = is_file($path) && is_array($geometry)
                && filemtime($path) >= filemtime($source)
                && !empty(thumbnail_file_geometry_status($path,
                    (int) $geometry['width'], (int) $geometry['height'],
                    (int) $size)['valid']);
            if (!$valid || (in_array($variant, $target['force'], true)
                && !in_array($variant, $target['uploaded'], true))) {
                $missing[] = $variant;
            }
        }
        if ($missing !== []) {
            $job['browser_pending']['variants'] = $missing;
            return;
        }
        thumbnail_metadata_preflight_write_schema('gallery.consistency_browser_verify');
        thumbnail_metadata_refresh_image($image, $gallery, null, false);
        $probe = $job;
        $probe['scope'] = 'files';
        $probe['groups'] = [];
        gallery_consistency_check_image($probe, $image);
        foreach ($probe['groups'] as $group) {
            foreach ($group['issues'] as $finding) {
                if (in_array($finding['repair'], ['image', 'metadata'], true)) {
                    throw new RuntimeException('consistency_browser_incomplete');
                }
            }
        }
        $task = $job['queue'][$job['processed']];
        $job['results'][] = ['key' => $task['key'],
            'code' => $task['issue']['code'], 'status' => 'repaired',
            'variants' => count(array_unique(array_merge(
                $target['variants'], $target['uploaded'],
            )))];
        $job['repaired']++;
        $job['processed']++;
        $job['sequence']++;
        unset($job['browser_pending']);
        $job['status'] = $job['processed'] >= $job['total'] ? 'complete' : 'running';
        $job['phase'] = $job['status'] === 'complete' ? 'finished' : 'repairs';
        gallery_consistency_reconcile_repairs($job);
        thumbnail_maintenance_summary_cache_clear();
    } finally {
        gallery_edit_writer_end($lock);
    }
}

/**
 * Keep descendant report paths and disk hashes aligned after an admitted move.
 * @param ConsistencyJob $job Report containing pending descendant repairs.
 * @param string $oldPath Original root-relative folder prefix.
 * @param string $newPath Confirmed new folder prefix.
 * @return void Rewrites only descendants of this operation's exact old prefix.
 */
function gallery_consistency_relocate_report(
    array &$job,
    string $oldPath,
    string $newPath,
): void {
    foreach ($job['groups'] as &$affected) {
        if ($affected['path'] === $oldPath
            || str_starts_with($affected['path'], $oldPath . '/')
        ) {
            $affected['path'] = $newPath . substr(
                $affected['path'], strlen($oldPath),
            );
        }
    }
    unset($affected);
    $hashes = [];
    foreach ($job['disk_hashes'] as $path => $hash) {
        $mapped = str_starts_with($path, $oldPath . '/')
            ? $newPath . substr($path, strlen($oldPath)) : $path;
        $hashes[$mapped] = $hash;
    }
    $job['disk_hashes'] = $hashes;
}
