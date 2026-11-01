<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_consistency.php
 * Module Type: Service
 * Purpose: Coordinate explicit read-only consistency reports and selected repairs.
 * Responsibilities: Own bounded cursors, report identities and repair admission.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * @phpstan-type ConsistencyIssue array{code:string,path:string,image_id:int,
 *     repair:string,details:string}
 * @phpstan-type ConsistencyGroup array{id:int,title:string,path:string,
 *     issues:list<ConsistencyIssue>,changes?:list<array{field:string,
 *         current:string,proposed:string}>}
 * @phpstan-type ConsistencyBrowserTarget array{image_id:int,gallery_id:int,
 *     gallery_path:string,relative_path:string,source_hash:string,
 *     variants:list<string>,force:list<string>,uploaded:list<string>,
 *     token:string,sequence:int}
 * @phpstan-type ConsistencyJob array{token:string,mode:string,phase:string,
 *     status:string,fingerprint:string,started_at:string,processed:int,
 *     total:int,images:int,last_image:int,gallery_cursor:int,
 *     galleries:list<array{id:int|string,title:string,folder_path:string,
 *         parent_id:int|string|null,...}>,
 *     scope?:string,groups:array<string,ConsistencyGroup>,
 *     expected:array<string,int|string>,
 *     paths:array<string,int>,stack:list<array{path:string,offset:int}>,
 *     disk_checked:int,source_hashes:array<int,string>,
 *     disk_hashes:array<string,string>,
 *     queue:list<array{key:string,issue:ConsistencyIssue}>,
 *     repaired:int,failed:int,results:list<array{key:string,code:string,
 *         status:string,variants?:int,failure?:array{exception:string,owner:string,line:int,
 *             reason?:string}}>,
 *     sequence:int,browser_pending?:ConsistencyBrowserTarget,
 *     remaining_ready?:bool,results_start?:int}
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use function Gallery\Models\gallery_consistency_model_galleries;
use function Gallery\Models\gallery_consistency_model_images;
use function Gallery\Models\gallery_consistency_model_snapshot;

/** Bound filesystem observations between browser progress receipts.
 * Type: int. Units: entries. Scope: one consistency request.
 * Consumers: gallery_consistency_step. Rationale: 2048 amortizes directory
 * reopening on large catalogs; the request deadline also bounds this traversal.
 */
const GALLERY_CONSISTENCY_DISK_BATCH = 2048;

/** Amortize HTTP/bootstrap overhead across inexpensive file observations.
 * Type: int. Units: source rows. Scope: one consistency request.
 * Consumers: gallery_consistency_step. Rationale: 32 avoids one HTTP request per
 * photograph; the deadline stops admission before another expensive source.
 */
const GALLERY_CONSISTENCY_IMAGE_BATCH = 32;

/** Batch cheap catalog comparisons without touching media or their directories.
 * Type: int. Units: galleries. Scope: one names-only report request.
 * Consumers: gallery_consistency_step. Rationale: 256 keeps progress observable
 * while gallery metadata does not pay per-photograph HTTP overhead.
 */
const GALLERY_CONSISTENCY_CATALOG_BATCH = 256;

/** Bound admission of additional I/O work between confirmed progress receipts.
 * Type: float. Units: seconds. Scope: one report request.
 * Consumers: gallery_consistency_step and gallery_consistency_disk_step.
 * Rationale: 0.75 permits useful batches with responsive progress; an admitted
 * single decoder/read retains its own existing safety limits.
 */
const GALLERY_CONSISTENCY_STEP_SECONDS = 0.75;

/** Bound raster comparison surfaces independently of original image dimensions.
 * Type: int. Units: pixels per side. Scope: consistency content comparison.
 * Consumers: gallery_consistency_signature. Rationale: 16 preserves coarse color
 * structure while JPEG/WebP quantization does not dominate the comparison.
 */
const GALLERY_CONSISTENCY_SIGNATURE_SIDE = 16;

/** Identify substantial visual disagreement without asserting pixel identity.
 * Type: float. Units: normalized mean RGB distance. Scope: consistency reports.
 * Consumers: gallery_consistency_check_image. Rationale: 0.12 is a conservative
 * review threshold; uncertain visual findings never authorize automatic repair.
 */
const GALLERY_CONSISTENCY_CONTENT_DISTANCE = 0.12;

/** Generate unguessable session-scoped report identifiers.
 * Type: int. Units: random bytes. Scope: one consistency report.
 * Consumers: gallery_consistency_start. Rationale: 16 bytes prevent token guessing.
 */
const GALLERY_CONSISTENCY_TOKEN_BYTES = 16;

require_once __DIR__ . '/gallery_consistency/inspection.php';
require_once __DIR__ . '/gallery_consistency/repairs.php';

/**
 * Bound ownership-map key size independently of deeply nested physical paths.
 * @param ConsistencyJob $job New scoped job or an already-running legacy job.
 * @param string $path Exact root-relative path whose ownership is observed.
 * @return string Full binary SHA-256 key for scoped jobs; old in-flight session
 *     jobs retain their exact path keys until replaced by a fresh analysis.
 */
function gallery_consistency_path_key(array $job, string $path): string
{
    return isset($job['scope']) ? hash('sha256', $path, true) : $path;
}

/**
 * Snapshot a new report without modifying catalog rows or media files.
 * @param string $scope Names-only DB comparisons, file/header observations, or
 *     opt-in content hashing and raster comparisons: names, files or content.
 * @return ConsistencyJob Session-owned job; filesystem discovery is incremental.
 */
function gallery_consistency_start(string $scope = 'files'): array
{
    if (!in_array($scope, ['names', 'files', 'content'], true)) {
        throw new RuntimeException('consistency_scope_invalid');
    }
    gallery_consistency_load_maintenance_owner();
    $snapshot = gallery_consistency_model_snapshot($scope !== 'names');
    $galleries = gallery_consistency_model_galleries();
    $paths = [];
    foreach ($galleries as $gallery) {
        $paths[(string) $gallery['folder_path']] = (int) $gallery['id'];
    }
    $job = [
        'token' => bin2hex(random_bytes(GALLERY_CONSISTENCY_TOKEN_BYTES)),
        'mode' => 'report', 'phase' => 'galleries', 'status' => 'running',
        'scope' => $scope,
        'fingerprint' => $snapshot['fingerprint'],
        'started_at' => date(DATE_ATOM), 'processed' => 0,
        'total' => count($galleries) + $snapshot['images'],
        'images' => $snapshot['images'], 'last_image' => 0,
        'gallery_cursor' => 0, 'galleries' => $galleries,
        'groups' => [], 'expected' => [], 'paths' => $paths,
        'stack' => [['path' => '', 'offset' => 0]], 'disk_checked' => 0,
        'source_hashes' => [], 'disk_hashes' => [],
        'queue' => [], 'repaired' => 0,
        'failed' => 0, 'results' => [], 'sequence' => 0,
    ];
    try {
        foreach (gallery_image_move_pending() as $pending) {
            foreach (['source_gallery_id', 'destination_gallery_id'] as $field) {
                $id = (int) ($pending[$field] ?? 0);
                $gallery = $id > 0 ? find_gallery($id, true) : null;
                gallery_consistency_add(
                    $job, $id, (string) ($gallery['folder_path'] ?? ''),
                    'pending_move', '', 0, '',
                    (string) ($pending['operation_id'] ?? ''),
                );
            }
        }
    } catch (\Throwable) {
        gallery_consistency_add($job, 0, '', 'move_state_unknown');
    }
    return $job;
}

/**
 * Load existing maintenance owners only for explicit inspection/repair work.
 *
 * Dashboard already owns journal inspection and cache invalidation. Reusing its
 * reviewed closure avoids adding duplicate owners to ordinary discovery routes.
 * @return void Supplies journal model and thumbnail/storage invalidation calls;
 *     direct test/CLI consumers may preload those existing owners themselves.
 */
function gallery_consistency_load_maintenance_owner(): void
{
    if (!function_exists('Gallery\\Models\\gallery_image_move_model_pending')
        || !function_exists(__NAMESPACE__
            . '\\thumbnail_maintenance_summary_cache_clear')
    ) {
        \Gallery\Core\cms_runtime_kernel()->load('domain-admin-dashboard');
    }
}

/**
 * Advance a time-bounded catalog/file batch without inventing progress.
 * @param ConsistencyJob $job Session-owned state, updated after confirmed work.
 * @return void Leaves failures explicit; never starts a repair from a report.
 */
function gallery_consistency_step(array &$job): void
{
    if ($job['status'] !== 'running') {
        return;
    }
    if ($job['mode'] === 'repair') {
        gallery_consistency_repair_step($job);
        return;
    }
    if ($job['phase'] === 'galleries') {
        $deadline = microtime(true) + GALLERY_CONSISTENCY_STEP_SECONDS;
        for ($count = 0; $count < GALLERY_CONSISTENCY_CATALOG_BATCH; $count++) {
            $gallery = $job['galleries'][$job['gallery_cursor']] ?? null;
            if ($gallery === null) break;
            if (($job['scope'] ?? 'content') === 'names') {
                try {
                    gallery_consistency_check_catalog_gallery($job, $gallery);
                } catch (\Throwable) {
                    gallery_consistency_add($job, (int) $gallery['id'],
                        (string) $gallery['folder_path'], 'unsafe_path');
                }
            } else {
                gallery_consistency_check_gallery($job, $gallery);
            }
            $job['gallery_cursor']++;
            $job['processed']++;
            if (microtime(true) >= $deadline) break;
        }
        if (isset($job['galleries'][$job['gallery_cursor']])) {
            return;
        }
        if (($job['scope'] ?? 'content') === 'names') {
            gallery_consistency_assert_catalog($job);
            $job['status'] = 'complete';
            $job['phase'] = 'finished';
            return;
        }
        $job['phase'] = 'images';
        return;
    }
    if ($job['phase'] === 'images') {
        $deadline = microtime(true) + GALLERY_CONSISTENCY_STEP_SECONDS;
        $rows = gallery_consistency_model_images(
            $job['last_image'], GALLERY_CONSISTENCY_IMAGE_BATCH,
        );
        if ($rows !== []) {
            foreach ($rows as $image) {
                gallery_consistency_check_image($job, $image);
                $job['last_image'] = (int) $image['id'];
                $job['processed']++;
                if (microtime(true) >= $deadline) break;
            }
            return;
        }
        $job['phase'] = 'disk';
    }
    gallery_consistency_disk_step($job);
    if ($job['stack'] === []) {
        if (schema_inspection_is_available(
            thumbnail_metadata_mutation_schema_status(),
        )) {
            $orphans = \Gallery\Models\gallery_consistency_model_orphan_variants();
            if ($orphans > 0) {
                gallery_consistency_add(
                    $job, 0, '', 'metadata_orphans', '', 0, '', (string) $orphans,
                );
            }
        }
        $snapshot = gallery_consistency_model_snapshot();
        $job['status'] = hash_equals(
            $job['fingerprint'], $snapshot['fingerprint'],
        ) ? 'complete' : 'stale';
        $job['phase'] = 'finished';
    }
}

/**
 * Add an exact finding, grouped by stable gallery identity or physical folder.
 * @param ConsistencyJob $job Report being assembled.
 * @param int $galleryId Catalog owner, or zero for an unindexed disk folder.
 * @param string $path Relative gallery directory, never an absolute private path.
 * @param string $code Stable localized finding category.
 * @param string $file Affected gallery-relative file, or empty for gallery data.
 * @param int $imageId Persisted source identity, or zero for disk-only findings.
 * @param string $repair Allowlisted repair policy, or empty for manual review.
 * @param string $details Safe relative evidence shown in the finding disclosure.
 * @return void Retains exact findings for selection, export and repair planning.
 */
function gallery_consistency_add(
    array &$job,
    int $galleryId,
    string $path,
    string $code,
    string $file = '',
    int $imageId = 0,
    string $repair = '',
    string $details = '',
): void {
    $key = $galleryId > 0 ? 'g' . $galleryId : 'd' . hash('sha256', $path);
    if (!isset($job['groups'][$key])) {
        $title = basename($path);
        foreach ($job['galleries'] as $gallery) {
            if ((int) $gallery['id'] === $galleryId) {
                $title = (string) $gallery['title'];
                break;
            }
        }
        $job['groups'][$key] = [
            'id' => $galleryId, 'title' => $title,
            'path' => $path, 'issues' => [],
        ];
    }
    $job['groups'][$key]['issues'][] = [
        'code' => $code, 'path' => $file, 'image_id' => $imageId,
        'repair' => $repair, 'details' => $details,
    ];
}
