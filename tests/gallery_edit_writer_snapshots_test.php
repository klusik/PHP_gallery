<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_edit_writer_snapshots_test.php
 * Module Type: Regression Test
 * Purpose: Reject stale gallery, image, plan and migration-job state after writer acquisition.
 * Responsibilities:
 *   - Exercise actual guarded implementation bodies with in-memory persistence adapters
 *   - Prove old paths and vanished rows cannot become sidecar or thumbnail targets
 *   - Preserve explicit rename selections and durable migration progress
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';
require_once __DIR__ . '/support/gallery_edit_runtime.php';
require_once __DIR__ . '/support/gallery_edit_writer_source.php';

use Gallery\Services\GalleryEditConflict;

/**
 * Execute an explicit fixture adapter; there is no fallback to application storage.
 *
 * @param string $call Requested read, write or domain adapter.
 * @param int|string|bool|array<array-key,mixed>|null ...$arguments Semantic row IDs, row/field maps, selections or schema flags from the actual function body.
 * @return int|string|bool|array<array-key,mixed>|null Fixture row/job/selection data, counters or write acknowledgement; unknown adapters throw.
 */
function gallery_snapshot_fixture_call(string $call, mixed ...$arguments): mixed
{
    $state = &$GLOBALS['gallery_snapshot_fixture'];
    $state['events'][] = [$call, $arguments];
    switch ($call) {
        case 'find_gallery':
        case 'find_image':
            gallery_writer_check(($arguments[1] ?? false) === true, 'A writer reused cached ownership.');
            $collection = $call === 'find_gallery' ? 'rows' : 'images';
            return $state[$collection][(int) $arguments[0]] ?? null;
        case 'gallery_model_update_fields':
            $id = (int) $arguments[0];
            gallery_writer_check(isset($state['rows'][$id]), 'Updated a vanished gallery.');
            $state['rows'][$id] = array_replace($state['rows'][$id], $arguments[1]);
            return null;
        case 'now_sql':
            return '2026-09-20 12:00:00';
        case 'write_gallery_sidecar':
            $state['sidecars'][] = $arguments[0];
            return null;
        case 'media_renamer_plan_for_gallery_selection':
            gallery_writer_check($arguments[1] !== [], 'An empty snapshot expanded to all current images.');
            return $state['rebuilt'];
        case 'gallery_migration_load_job':
            gallery_writer_check($arguments[0] === $state['job']['job_id'], 'Migration ignored its semantic job identity.');
            return $state['job'];
        case 'gallery_migration_manifest_asset_refs':
            gallery_writer_check($arguments[0] === $state['job']['manifest'], 'Migration reused its stale manifest.');
            return $arguments[0]['fixture_assets'] ?? [];
        case 'gallery_migration_asset_key':
            return $arguments[0]['fixture_key'];
        case 'gallery_migration_target_gallery_id':
            return (int) $arguments[0]['gallery_map'][(string) $arguments[1]];
        case 'gallery_migration_recover_existing_asset':
            gallery_writer_check(isset($arguments[3]['assets_received']['received-newer']), 'Recovery discarded durable progress.');
            return ['source_image_id' => 17, 'image_id' => 19];
        case 'gallery_migration_save_job':
            $state['saved_job'] = $arguments[0];
            return null;
        case 'gallery_migration_t':
            return $arguments[1];
        case 'thumbnail_bounds_schema_ready':
        case 'function_exists':
            return true;
        case 'thumbnail_bound_gallery_branch_ids':
            gallery_writer_check($arguments[0]['folder_path'] === 'current/branch', 'Recursive bounds followed the obsolete branch.');
            return [7, 8];
        case 'gallery_model_set_thumbnail_bounds':
            gallery_writer_check($arguments[0] === [7, 8], 'Bounds targeted the wrong descendants.');
            return 2;
    }
    throw new RuntimeException('Unexpected snapshot fixture adapter.');
}

/**
 * Bind one actual implementation to only the listed inert adapters.
 *
 * @param string $module Service module entry filename.
 * @param string $name Function whose source is exercised.
 * @param list<string> $calls Explicit substituted calls; all other pure behavior stays real.
 * @return Closure(mixed...):mixed Bound implementation retaining the selected service signature and return type; only listed storage calls are substituted.
 */
function gallery_snapshot_subject(string $module, string $name, array $calls): Closure
{
    $adapters = [];
    foreach ($calls as $call) {
        $adapters[$call] =
            /**
             * Route this selected dependency exclusively to the named in-memory adapter.
             * @param int|string|bool|array<array-key,mixed>|null ...$arguments Original semantic arguments passed by the extracted service body.
             * @return int|string|bool|array<array-key,mixed>|null Fixture row/job/selection data, counters or write acknowledgement.
             * @author Rudolf Klusal
             */
            static fn (mixed ...$arguments): mixed => gallery_snapshot_fixture_call($call, ...$arguments);
    }
    return gallery_writer_fixture_function(
        module_source(dirname(__DIR__) . '/app/services/' . $module),
        $name,
        $adapters
    );
}

/**
 * Reset in-memory row/image/event state for an independent snapshot case.
 *
 * @param array<string,mixed> $overrides Case-specific authoritative state.
 * @return void
 */
function gallery_snapshot_reset(array $overrides = []): void
{
    $GLOBALS['gallery_snapshot_fixture'] = array_replace([
        'rows' => [], 'images' => [], 'events' => [], 'sidecars' => [], 'saved_job' => null,
    ], $overrides);
}

/**
 * Assert that a closure refuses changed ownership without exposing credential material.
 *
 * @param Closure():array<string,mixed> $operation Snapshot case expected to throw before producing its row or plan result.
 * @return void
 */
function gallery_snapshot_expect_conflict(Closure $operation): void
{
    try {
        $operation();
        throw new RuntimeException('Stale snapshot was accepted.');
    } catch (GalleryEditConflict $exception) {
        gallery_writer_check(!isset($exception->latest['access_password_hash'], $exception->latest['access_token_hash']),
            'Snapshot conflict exposed a credential hash.');
    }
}

$old = ['id' => 7, 'folder_path' => 'obsolete/branch', 'title' => 'Before', 'cover_image_id' => 11,
    'edit_revision' => '4', 'access_password_hash' => 'fixture-private-hash'];
$current = array_replace($old, ['folder_path' => 'current/branch', 'title' => 'After', 'edit_revision' => '9']);
$cover = gallery_snapshot_subject('gallery_editor_mutations.php', 'gallery_editor_set_cover_image_owned',
    ['find_gallery', 'find_image', 'gallery_model_update_fields', 'now_sql', 'write_gallery_sidecar']);
gallery_snapshot_reset(['rows' => [7 => $current], 'images' => [13 => ['id' => 13, 'gallery_id' => 7]]]);
$result = $cover(7, 13, $old);
gallery_writer_check($result['folder_path'] === 'current/branch' && $result['cover_image_id'] === 13,
    'Cover setter returned obsolete gallery data.');
gallery_writer_check($GLOBALS['gallery_snapshot_fixture']['sidecars'] === [$result], 'Cover wrote an obsolete sidecar.');
gallery_snapshot_reset(['images' => [13 => ['id' => 13, 'gallery_id' => 7]]]);
gallery_snapshot_expect_conflict(
    /**
     * Attempt cover persistence after the target gallery vanished.
     * @return array<string,mixed> Would-be gallery row; this case must throw instead.
     * @author Rudolf Klusal
     */
    static fn () => $cover(7, 13, $old));
gallery_writer_check($GLOBALS['gallery_snapshot_fixture']['sidecars'] === [], 'Vanished gallery reused its fallback path.');
gallery_snapshot_reset(['rows' => [7 => $current], 'images' => [13 => ['id' => 13, 'gallery_id' => 99]]]);
gallery_snapshot_expect_conflict(
    /**
     * Attempt to select an image that now belongs to another gallery.
     * @return array<string,mixed> Would-be gallery row; changed ownership must throw instead.
     * @author Rudolf Klusal
     */
    static fn () => $cover(7, 13, $old));
gallery_writer_check($GLOBALS['gallery_snapshot_fixture']['rows'][7] === $current, 'Moved image became a foreign gallery cover.');

$copySelection = gallery_snapshot_subject('picture_manager.php', 'picture_manager_owned_images_for_selection', ['find_image']);
gallery_snapshot_reset(['images' => [13 => ['id' => 13, 'gallery_id' => 99, 'relative_path' => 'moved.jpg']]]);
$failures = [];
gallery_writer_check($copySelection(7, [13], $failures) === [] && count($failures) === 1, 'Copy used stale image ownership.');

$bounds = gallery_snapshot_subject('thumbnail_bounds.php', 'save_gallery_thumbnail_bounds_owned',
    ['thumbnail_bounds_schema_ready', 'find_gallery', 'thumbnail_bound_gallery_branch_ids',
        'gallery_model_set_thumbnail_bounds', 'now_sql', 'function_exists', 'write_gallery_sidecar']);
gallery_snapshot_reset(['rows' => [7 => $current, 8 => ['id' => 8, 'folder_path' => 'current/branch/child']]]);
gallery_writer_check($bounds($old, 300, 1200, true) === 2, 'Recursive bounds did not use the current branch.');
gallery_writer_check(count($GLOBALS['gallery_snapshot_fixture']['sidecars']) === 2, 'Bounds did not publish current rows.');
gallery_snapshot_reset();
gallery_writer_check($bounds($old, 300, 1200, true) === 0 && $GLOBALS['gallery_snapshot_fixture']['sidecars'] === [],
    'Vanished recursive target reused the obsolete branch.');

$refreshPlan = gallery_snapshot_subject('media_renamer.php', 'media_renamer_refresh_execution_plan',
    ['find_gallery', 'media_renamer_plan_for_gallery_selection']);
$plan = ['gallery' => $old, 'selected_image_ids' => [13], 'pattern' => '{gallery}-{sequence}',
    'items' => [['image_id' => 13, 'old_relative_path' => 'source.jpg', 'new_relative_path' => 'target.jpg']]];
gallery_snapshot_reset(['rows' => [7 => $current]]);
gallery_snapshot_expect_conflict(
    /**
     * Revalidate a rename plan whose gallery row changed since planning.
     * @return array<string,mixed> Would-be fresh plan; row mismatch must throw instead.
     * @author Rudolf Klusal
     */
    static fn () => $refreshPlan($plan));
gallery_writer_check(count($GLOBALS['gallery_snapshot_fixture']['events']) === 1, 'Changed gallery reached rename planning.');
$plan['gallery'] = $current;
gallery_snapshot_reset(['rows' => [7 => $current], 'rebuilt' => $plan]);
gallery_writer_check($refreshPlan($plan) === $plan, 'An unchanged plan did not survive revalidation.');
gallery_writer_check($GLOBALS['gallery_snapshot_fixture']['events'][1][1][1] === [13], 'Rename selection widened.');
$changedPlan = $plan;
$changedPlan['items'][0]['old_relative_path'] = 'moved.jpg';
gallery_snapshot_reset(['rows' => [7 => $current], 'rebuilt' => $changedPlan]);
gallery_snapshot_expect_conflict(
    /**
     * Revalidate a plan after one selected image moved to a different path.
     * @return array<string,mixed> Would-be fresh plan; item mismatch must throw instead.
     * @author Rudolf Klusal
     */
    static fn () => $refreshPlan($plan));
$emptyPlan = array_replace($plan, ['selected_image_ids' => [], 'items' => []]);
gallery_snapshot_reset(['rows' => [7 => $current], 'rebuilt' => $changedPlan]);
gallery_writer_check($refreshPlan($emptyPlan) === $emptyPlan && count($GLOBALS['gallery_snapshot_fixture']['events']) === 1,
    'An old empty selection expanded to newly added images.');
$unknownSelection = $plan;
unset($unknownSelection['selected_image_ids']);
gallery_snapshot_expect_conflict(
    /**
     * Refuse an old plan lacking its exact semantic selection.
     * @return array<string,mixed> Would-be fresh plan; missing selection must throw instead.
     * @author Rudolf Klusal
     */
    static fn () => $refreshPlan($unknownSelection));

$sync = gallery_snapshot_subject('gallery_migration.php', 'gallery_migration_sync_received_assets_owned',
    ['gallery_migration_load_job', 'gallery_migration_manifest_asset_refs', 'gallery_migration_asset_key',
        'gallery_migration_target_gallery_id', 'gallery_migration_recover_existing_asset',
        'gallery_migration_save_job', 'gallery_migration_t', 'now_sql']);
$oldJob = ['job_id' => 'fixture-job', 'target_gallery_id' => 7, 'manifest' => ['obsolete' => true], 'assets_received' => []];
$currentJob = ['job_id' => 'fixture-job', 'target_gallery_id' => 7,
    'manifest' => ['fixture_assets' => [['fixture_key' => 'received-newer', 'source_gallery_id' => 1],
        ['fixture_key' => 'recover-current', 'source_gallery_id' => 1]]],
    'gallery_map' => ['1' => 8], 'image_map' => ['11' => 13],
    'assets_received' => ['received-newer' => ['result' => ['image_id' => 13]]]];
gallery_snapshot_reset(['job' => $currentJob]);
$job = $sync($oldJob);
gallery_writer_check($job['assets_received']['received-newer'] === $currentJob['assets_received']['received-newer']
    && isset($job['assets_received']['recover-current']) && $job['image_map']['11'] === 13
    && $GLOBALS['gallery_snapshot_fixture']['saved_job'] === $job, 'Recovery overwrote durable job progress.');
$currentJob['target_gallery_id'] = 99;
gallery_snapshot_reset(['job' => $currentJob]);
try {
    $sync($oldJob);
    throw new LogicException('Retargeted migration job was accepted.');
} catch (RuntimeException) {
    gallery_writer_check(count($GLOBALS['gallery_snapshot_fixture']['events']) === 2
        && $GLOBALS['gallery_snapshot_fixture']['saved_job'] === null, 'Retargeted job reached recovery writes.');
}

echo "PASS gallery writer snapshots: current paths, cover/copy ownership, bounded plans and durable job reloads\n";
