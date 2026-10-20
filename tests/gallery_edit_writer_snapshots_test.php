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
            gallery_writer_check(
                ($arguments[1] ?? false) === true,
                'A writer reused cached ownership.',
            );
            $collection = $call === 'find_gallery' ? 'rows' : 'images';
            return $state[$collection][(int) $arguments[0]] ?? null;
        case 'gallery_edit_writer_begin':
            return 'fixture-cover-writer';
        case 'gallery_edit_writer_end':
            gallery_writer_check(
                ($arguments[0] ?? '') === 'fixture-cover-writer',
                'Multi-cover writer lease was not released.',
            );
            return null;
        case 'gallery_editor_set_cover_image':
            $state['single_delegate'] = (int) ($arguments[1] ?? 0);
            return $state['rows'][(int) ($arguments[0] ?? 0)] ?? [];
        case 'gallery_editor_set_cover_images_owned':
            $state['multi_delegate'] = $arguments;
            return $state['rows'][(int) ($arguments[0] ?? 0)] ?? [];
        case 'gallery_editor_set_cover_image_owned':
            $state['single_owned_delegate'] = $arguments;
            return $state['rows'][(int) ($arguments[0] ?? 0)] ?? [];
        case 'gallery_editor_assert_cover_selection_schema':
            if (($state['cover_schema_ready'] ?? true) !== true) {
                throw new \Gallery\Services\GalleryEditUnavailable(
                    'Cover schema could not be verified.',
                );
            }
            return null;
        case 'gallery_editor_assert_cover_revision':
            $expectedRevision = $arguments[1] ?? null;
            if (
                $expectedRevision !== null
                && $expectedRevision !== ($state['cover_revision'] ?? null)
            ) {
                throw new GalleryEditConflict([]);
            }
            return null;
        case 'gallery_cover_selection_ids':
            $decoded = json_decode(
                (string) ($arguments[0]['cover_image_ids'] ?? ''),
                true,
            );
            return (
                is_array($decoded) && array_is_list($decoded) ? $decoded : []
            );
        case 'gallery_cover_selection_storage_valid':
            $raw = $arguments[0]['cover_image_ids'] ?? null;
            if ($raw === null) {
                return true;
            }
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            return (
                is_array($decoded)
                    && array_is_list($decoded)
                    && count($decoded) <= 4
                    && count(array_unique($decoded, SORT_REGULAR)) === count(
                        $decoded,
                    )
                    && count(array_filter(
                        $decoded,
                        static fn(mixed $id): bool => is_int($id) && $id > 0,
                    )) === count($decoded)
            );
        case 'gallery_model_update_fields':
            $id = (int) $arguments[0];
            gallery_writer_check(
                isset($state['rows'][$id]),
                'Updated a vanished gallery.',
            );
            $state['rows'][$id] = array_replace(
                $state['rows'][$id],
                $arguments[1],
            );
            return null;
        case 'now_sql':
            return '2026-09-20 12:00:00';
        case 'write_gallery_sidecar':
            $state['sidecars'][] = $arguments[0];
            return !($state['sidecar_failure'] ?? false);
        case 'media_renamer_plan_for_gallery_selection':
            gallery_writer_check(
                $arguments[1] !== [],
                'An empty snapshot expanded to all current images.',
            );
            return $state['rebuilt'];
        case 'gallery_migration_load_job':
            gallery_writer_check(
                $arguments[0] === $state['job']['job_id'],
                'Migration ignored its semantic job identity.',
            );
            return $state['job'];
        case 'gallery_migration_manifest_asset_refs':
            gallery_writer_check(
                $arguments[0] === $state['job']['manifest'],
                'Migration reused its stale manifest.',
            );
            return $arguments[0]['fixture_assets'] ?? [];
        case 'gallery_migration_asset_key':
            return $arguments[0]['fixture_key'];
        case 'gallery_migration_target_gallery_id':
            return (int) $arguments[0]['gallery_map'][(string) $arguments[1]];
        case 'gallery_migration_recover_existing_asset':
            gallery_writer_check(
                isset($arguments[3]['assets_received']['received-newer']),
                'Recovery discarded durable progress.',
            );
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
            gallery_writer_check(
                $arguments[0]['folder_path'] === 'current/branch',
                'Recursive bounds followed the obsolete branch.',
            );
            return [7, 8];
        case 'gallery_model_set_thumbnail_bounds':
            gallery_writer_check(
                $arguments[0] === [7, 8],
                'Bounds targeted the wrong descendants.',
            );
            return 2;
    }
    throw new RuntimeException(
        'Unexpected snapshot fixture adapter: ' . $call . '.',
    );
}

/**
 * Bind one actual implementation to only the listed inert adapters.
 *
 * @param string $module Service module entry filename.
 * @param string $name Function whose source is exercised.
 * @param list<string> $calls Explicit substituted calls; all other pure behavior stays real.
 * @return Closure(mixed...):mixed Bound implementation retaining the selected service signature and return type; only listed storage calls are substituted.
 */
function gallery_snapshot_subject(
    string $module,
    string $name,
    array $calls,
): Closure {
    $adapters = [];
    foreach ($calls as $call) {
        $adapters[$call] =
            /**
             * Route this selected dependency exclusively to the named in-memory adapter.
             * @param int|string|bool|array<array-key,mixed>|null ...$arguments Original semantic arguments passed by the extracted service body.
             * @return int|string|bool|array<array-key,mixed>|null Fixture row/job/selection data, counters or write acknowledgement.
             * @author Rudolf Klusal
             */
            static fn(mixed ...$arguments): mixed => gallery_snapshot_fixture_call(
                $call,
                ...$arguments,
            );
    }
    return gallery_writer_fixture_function(
        module_source(dirname(__DIR__) . '/app/services/' . $module),
        $name,
        $adapters,
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
        'rows' => [],
        'images' => [],
        'events' => [],
        'sidecars' => [],
        'saved_job' => null,
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
        gallery_writer_check(
            !isset(
                $exception->latest['access_password_hash'],
                $exception->latest['access_token_hash'],
            ),
            'Snapshot conflict exposed a credential hash.',
        );
    }
}

$old = [
    'id' => 7,
    'folder_path' => 'obsolete/branch',
    'title' => 'Before',
    'cover_image_id' => 11,
    'edit_revision' => '4',
    'access_password_hash' => 'fixture-private-hash',
];
$current = array_replace($old, [
    'folder_path' => 'current/branch',
    'title' => 'After',
    'edit_revision' => '9',
]);
$cover = gallery_snapshot_subject(
    'gallery_editor_mutations.php',
    'gallery_editor_set_cover_image_owned',
    [
        'find_gallery',
        'find_image',
        'gallery_editor_assert_cover_selection_schema',
        'gallery_model_update_fields',
        'now_sql',
        'write_gallery_sidecar',
    ],
);
gallery_snapshot_reset([
    'rows' => [7 => $current],
    'images' => [13 => ['id' => 13, 'gallery_id' => 7]],
]);
$result = $cover(7, 13, $old);
gallery_writer_check(
    $result['folder_path'] === 'current/branch'
        && $result['cover_image_id'] === 13,
    'Cover setter returned obsolete gallery data.',
);
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['sidecars'] === [$result],
    'Cover wrote an obsolete sidecar.',
);
gallery_snapshot_reset(['images' => [13 => ['id' => 13, 'gallery_id' => 7]]]);
gallery_snapshot_expect_conflict(
    /**
     * Attempt cover persistence after the target gallery vanished.
     * @return array<string,mixed> Would-be gallery row; this case must throw instead.
     * @author Rudolf Klusal
     */
    static fn() => $cover(7, 13, $old),
);
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['sidecars'] === [],
    'Vanished gallery reused its fallback path.',
);
gallery_snapshot_reset([
    'rows' => [7 => $current],
    'images' => [13 => ['id' => 13, 'gallery_id' => 99]],
]);
gallery_snapshot_expect_conflict(
    /**
     * Attempt to select an image that now belongs to another gallery.
     * @return array<string,mixed> Would-be gallery row; changed ownership must throw instead.
     * @author Rudolf Klusal
     */
    static fn() => $cover(7, 13, $old),
);
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['rows'][7] === $current,
    'Moved image became a foreign gallery cover.',
);

$multiCover = gallery_snapshot_subject(
    'gallery_editor_mutations.php',
    'gallery_editor_set_cover_images_owned',
    [
        'find_gallery',
        'find_image',
        'gallery_editor_set_cover_image_owned',
        'gallery_editor_assert_cover_selection_schema',
        'gallery_editor_assert_cover_revision',
        'gallery_model_update_fields',
        'now_sql',
        'write_gallery_sidecar',
    ],
);
$multiGallery = array_replace($current, [
    'cover_image_id' => 13,
    'cover_image_ids' => null,
    'cover_image_path' => 'uploaded/custom.png',
]);
gallery_snapshot_reset([
    'rows' => [7 => $multiGallery],
    'images' => [
        13 => ['id' => 13, 'gallery_id' => 7, 'relative_path' => 'first.jpg'],
        14 => ['id' => 14, 'gallery_id' => 7, 'relative_path' => 'second.jpg'],
        15 => ['id' => 15, 'gallery_id' => 7, 'relative_path' => 'third.jpg'],
    ],
]);
$bulkResult = $multiCover(7, [13, 14, 15], $old);
gallery_writer_check(
    $bulkResult['cover_image_id'] === 13
        && $bulkResult['cover_image_ids'] === '[13,14,15]'
        && $bulkResult['cover_image_path'] === null,
    'Selected multi-cover photos were not persisted in order.',
);
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['sidecars'] === [$bulkResult],
    'Bulk cover did not refresh its canonical sidecar.',
);
$leaseEvents = array_column($GLOBALS['gallery_snapshot_fixture']['events'], 0);
gallery_writer_check(
    count(array_filter(
        $leaseEvents,
        static fn(string $event): bool => (
            $event === 'gallery_edit_writer_begin'
        ),
    )) === 0,
    'The owned multi-cover implementation acquired a nested writer lease.',
);

$GLOBALS['gallery_snapshot_fixture']['sidecar_failure'] = true;
try {
    $multiCover(7, [14, 13], $old);
    throw new RuntimeException(
        'Sidecar failure was reported as a complete cover save.',
    );
} catch (RuntimeException $exception) {
    gallery_writer_check(
        str_contains(
            $exception->getMessage(),
            'sidecar metadata could not be written.',
        ),
        'The actual post-persistence sidecar error was not retained.',
    );
}
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['rows'][7]['cover_image_ids']
        === '[14,13]',
    'A post-write sidecar error must retain the authoritative database state for reconciliation.',
);
gallery_snapshot_reset([
    'rows' => [7 => $multiGallery],
    'images' => [
        13 => ['id' => 13, 'gallery_id' => 7, 'relative_path' => 'first.jpg'],
        14 => ['id' => 14, 'gallery_id' => 7, 'relative_path' => 'second.jpg'],
    ],
    'cover_revision' => 'current',
]);
gallery_snapshot_expect_conflict(
    /** Refuse an old cover intent after another writer advanced its selection state. @return array<string,mixed> The stale mutation must throw before persistence. */
    static fn(): array => $multiCover(7, [13, 14], $old, 'stale'),
);
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['sidecars'] === [],
    'Stale cover revision caused a sidecar write.',
);
gallery_snapshot_reset([
    'rows' => [7 => $multiGallery],
    'images' => [
        13 => ['id' => 13, 'gallery_id' => 7, 'relative_path' => 'first.jpg'],
        14 => [
            'id' => 14,
            'gallery_id' => 7,
            'relative_path' => 'nested/foreign.jpg',
        ],
    ],
]);
gallery_snapshot_expect_conflict(
    /** Refuse nested cover photos rather than writing an invalid collage. @return array<string,mixed> The operation must throw instead. */
    static fn(): array => $multiCover(7, [13, 14], $old),
);
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['rows'][7] === $multiGallery
        && $GLOBALS['gallery_snapshot_fixture']['sidecars'] === [],
    'Invalid cover photo caused partial persistence.',
);
gallery_snapshot_reset([
    'rows' => [7 => array_diff_key($multiGallery, ['cover_image_ids' => true])],
    'images' => [
        13 => ['id' => 13, 'gallery_id' => 7, 'relative_path' => 'first.jpg'],
        14 => ['id' => 14, 'gallery_id' => 7, 'relative_path' => 'second.jpg'],
    ],
    'cover_schema_ready' => false,
]);
try {
    $multiCover(7, [13, 14], $old);
    throw new RuntimeException(
        'Unmigrated multi-cover selection was not refused.',
    );
} catch (\Gallery\Services\GalleryEditUnavailable $expectedMigrationFailure) {
    gallery_writer_check(
        $GLOBALS['gallery_snapshot_fixture']['sidecars'] === [],
        'Unmigrated multi-cover selection mutated metadata.',
    );
}
gallery_snapshot_reset(['rows' => [7 => $multiGallery]]);
try {
    $multiCover(7, [13, 13], $old);
    throw new RuntimeException(
        'Duplicate title-picture IDs were silently normalized.',
    );
} catch (\DomainException $expectedDuplicateFailure) {
    gallery_writer_check(
        $GLOBALS['gallery_snapshot_fixture']['events'] === [],
        'Duplicate selection acquired a writer lease.',
    );
}
gallery_snapshot_reset(['rows' => [7 => $multiGallery]]);
$multiCoverWriter = gallery_snapshot_subject(
    'gallery_editor_mutations.php',
    'gallery_editor_set_cover_images',
    [
        'gallery_edit_writer_begin',
        'gallery_edit_writer_end',
        'gallery_editor_set_cover_images_owned',
    ],
);
$multiCoverWriter(7, [13, 14], $old, 'snapshot-revision');
$writerCalls = array_column($GLOBALS['gallery_snapshot_fixture']['events'], 0);
gallery_writer_check(
    $writerCalls === [
        'gallery_edit_writer_begin',
        'gallery_editor_set_cover_images_owned',
        'gallery_edit_writer_end',
    ],
    'The public bulk writer did not acquire its lease before delegating and release it afterward.',
);
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['multi_delegate'] === [
        7,
        [13, 14],
        $old,
        'snapshot-revision',
    ],
    'The public bulk writer changed its ordered selection, compatibility row or expected cover revision.',
);
gallery_snapshot_reset(['rows' => [7 => $multiGallery]]);
try {
    $multiCover(7, [11, 12, 13, 14, 15], $old);
    throw new RuntimeException('Five-title-photo selection was accepted.');
} catch (\DomainException $expectedSelectionFailure) {
    gallery_writer_check(
        $GLOBALS['gallery_snapshot_fixture']['events'] === [],
        'Overlimit selection acquired a writer lease.',
    );
}
gallery_snapshot_reset(['rows' => [7 => $multiGallery]]);
$multiCover(7, [13], $old);
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['single_owned_delegate'] === [
        7,
        13,
        $old,
    ],
    'One selected photo did not reuse the exclusive cover writer with the original arguments.',
);

$coverSelectionAfterChange = gallery_snapshot_subject(
    'gallery_mutations.php',
    'gallery_mutation_cover_selection_after_change',
    [
        'gallery_cover_selection_storage_valid',
        'gallery_cover_selection_ids',
        'find_image',
    ],
);
gallery_snapshot_reset([
    'images' => [
        13 => ['id' => 13, 'gallery_id' => 7, 'relative_path' => 'first.jpg'],
        14 => ['id' => 14, 'gallery_id' => 99, 'relative_path' => 'moved.jpg'],
        15 => [
            'id' => 15,
            'gallery_id' => 7,
            'relative_path' => 'nested/child.jpg',
        ],
    ],
]);
$retainedCovers = $coverSelectionAfterChange(
    ['id' => 7, 'cover_image_ids' => '[13,14,15]'],
    [13],
    'available',
);
gallery_writer_check(
    $retainedCovers === [],
    'Move/delete cover reconciliation retained removed or non-direct IDs.',
);
gallery_writer_check(
    $coverSelectionAfterChange(
        ['id' => 7, 'cover_image_ids' => '[]'],
        [],
        'available',
    ) === [],
    'Automatic cover mode was not preserved as an explicit empty selection.',
);
gallery_writer_check(
    $coverSelectionAfterChange(
        ['id' => 7, 'cover_image_ids' => null],
        [13],
        'available',
    ) === null,
    'Legacy single-cover mode was rewritten as explicit automatic mode.',
);

$copySelection = gallery_snapshot_subject(
    'picture_manager.php',
    'picture_manager_owned_images_for_selection',
    ['find_image'],
);
gallery_snapshot_reset([
    'images' => [13 => [
        'id' => 13,
        'gallery_id' => 99,
        'relative_path' => 'moved.jpg',
    ]],
]);
$failures = [];
gallery_writer_check(
    $copySelection(7, [13], $failures) === [] && count($failures) === 1,
    'Copy used stale image ownership.',
);

$bounds = gallery_snapshot_subject(
    'thumbnail_bounds.php',
    'save_gallery_thumbnail_bounds_owned',
    [
        'thumbnail_bounds_schema_ready',
        'find_gallery',
        'thumbnail_bound_gallery_branch_ids',
        'gallery_model_set_thumbnail_bounds',
        'now_sql',
        'function_exists',
        'write_gallery_sidecar',
    ],
);
gallery_snapshot_reset([
    'rows' => [
        7 => $current,
        8 => ['id' => 8, 'folder_path' => 'current/branch/child'],
    ],
]);
gallery_writer_check(
    $bounds($old, 300, 1200, true) === 2,
    'Recursive bounds did not use the current branch.',
);
gallery_writer_check(
    count($GLOBALS['gallery_snapshot_fixture']['sidecars']) === 2,
    'Bounds did not publish current rows.',
);
gallery_snapshot_reset();
gallery_writer_check(
    $bounds($old, 300, 1200, true) === 0
        && $GLOBALS['gallery_snapshot_fixture']['sidecars'] === [],
    'Vanished recursive target reused the obsolete branch.',
);

$refreshPlan = gallery_snapshot_subject(
    'media_renamer.php',
    'media_renamer_refresh_execution_plan',
    ['find_gallery', 'media_renamer_plan_for_gallery_selection'],
);
$plan = [
    'gallery' => $old,
    'selected_image_ids' => [13],
    'pattern' => '{gallery}-{sequence}',
    'items' => [[
        'image_id' => 13,
        'old_relative_path' => 'source.jpg',
        'new_relative_path' => 'target.jpg',
    ]],
];
gallery_snapshot_reset(['rows' => [7 => $current]]);
gallery_snapshot_expect_conflict(
    /**
     * Revalidate a rename plan whose gallery row changed since planning.
     * @return array<string,mixed> Would-be fresh plan; row mismatch must throw instead.
     * @author Rudolf Klusal
     */
    static fn() => $refreshPlan($plan),
);
gallery_writer_check(
    count($GLOBALS['gallery_snapshot_fixture']['events']) === 1,
    'Changed gallery reached rename planning.',
);
$plan['gallery'] = $current;
gallery_snapshot_reset(['rows' => [7 => $current], 'rebuilt' => $plan]);
gallery_writer_check(
    $refreshPlan($plan) === $plan,
    'An unchanged plan did not survive revalidation.',
);
gallery_writer_check(
    $GLOBALS['gallery_snapshot_fixture']['events'][1][1][1] === [13],
    'Rename selection widened.',
);
$changedPlan = $plan;
$changedPlan['items'][0]['old_relative_path'] = 'moved.jpg';
gallery_snapshot_reset(['rows' => [7 => $current], 'rebuilt' => $changedPlan]);
gallery_snapshot_expect_conflict(
    /**
     * Revalidate a plan after one selected image moved to a different path.
     * @return array<string,mixed> Would-be fresh plan; item mismatch must throw instead.
     * @author Rudolf Klusal
     */
    static fn() => $refreshPlan($plan),
);
$emptyPlan = array_replace($plan, ['selected_image_ids' => [], 'items' => []]);
gallery_snapshot_reset(['rows' => [7 => $current], 'rebuilt' => $changedPlan]);
gallery_writer_check(
    $refreshPlan($emptyPlan) === $emptyPlan
        && count($GLOBALS['gallery_snapshot_fixture']['events']) === 1,
    'An old empty selection expanded to newly added images.',
);
$unknownSelection = $plan;
unset($unknownSelection['selected_image_ids']);
gallery_snapshot_expect_conflict(
    /**
     * Refuse an old plan lacking its exact semantic selection.
     * @return array<string,mixed> Would-be fresh plan; missing selection must throw instead.
     * @author Rudolf Klusal
     */
    static fn() => $refreshPlan($unknownSelection),
);

$sync = gallery_snapshot_subject(
    'gallery_migration.php',
    'gallery_migration_sync_received_assets_owned',
    [
        'gallery_migration_load_job',
        'gallery_migration_manifest_asset_refs',
        'gallery_migration_asset_key',
        'gallery_migration_target_gallery_id',
        'gallery_migration_recover_existing_asset',
        'gallery_migration_save_job',
        'gallery_migration_t',
        'now_sql',
    ],
);
$oldJob = [
    'job_id' => 'fixture-job',
    'target_gallery_id' => 7,
    'manifest' => ['obsolete' => true],
    'assets_received' => [],
];
$currentJob = [
    'job_id' => 'fixture-job',
    'target_gallery_id' => 7,
    'manifest' => [
        'fixture_assets' => [
            ['fixture_key' => 'received-newer', 'source_gallery_id' => 1],
            ['fixture_key' => 'recover-current', 'source_gallery_id' => 1],
        ],
    ],
    'gallery_map' => ['1' => 8],
    'image_map' => ['11' => 13],
    'assets_received' => ['received-newer' => ['result' => ['image_id' => 13]]],
];
gallery_snapshot_reset(['job' => $currentJob]);
$job = $sync($oldJob);
gallery_writer_check(
    $job['assets_received']['received-newer']
        === $currentJob['assets_received']['received-newer']
        && isset($job['assets_received']['recover-current'])
        && $job['image_map']['11'] === 13
        && $GLOBALS['gallery_snapshot_fixture']['saved_job'] === $job,
    'Recovery overwrote durable job progress.',
);
$currentJob['target_gallery_id'] = 99;
gallery_snapshot_reset(['job' => $currentJob]);
try {
    $sync($oldJob);
    throw new LogicException('Retargeted migration job was accepted.');
} catch (RuntimeException) {
    gallery_writer_check(
        count($GLOBALS['gallery_snapshot_fixture']['events']) === 2
            && $GLOBALS['gallery_snapshot_fixture']['saved_job'] === null,
        'Retargeted job reached recovery writes.',
    );
}

echo
    "PASS gallery writer snapshots: current paths, cover/copy ownership, bounded plans and durable job reloads\n";
