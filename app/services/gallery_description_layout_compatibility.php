<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_description_layout_compatibility.php
 * Module Type: Service
 * Purpose: Preserve legacy gallery-card appearance while correcting orientation semantics.
 * Responsibilities:
 *   - Convert only legacy persisted/imported orientation values and preserve inheritance.
 *   - Migrate existing sidecars with per-document replay markers and atomic replacement.
 *   - Coordinate the canonical migration model without owning SQL.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

use PDO;
use RuntimeException;
use Throwable;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RecursiveCallbackFilterIterator;
use FilesystemIterator;
use SplFileInfo;
use function Gallery\Models\gallery_layout_migration_model_state;
use function Gallery\Models\gallery_layout_migration_model_begin;
use function Gallery\Models\gallery_layout_migration_model_apply;
use function Gallery\Models\gallery_layout_migration_model_finish;
use function Gallery\Models\gallery_edit_model_lock;
use function Gallery\Models\gallery_edit_model_release;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_has_config;

require_once dirname(__DIR__) . '/models/gallery_edit_concurrency.php';

/**
 * Purpose: Identify canonical gallery-card orientation semantics independently of document structure versions.
 * @var int
 * Type: int. Units: document semantics version. Scope: persisted orientation compatibility.
 * Consumers: orientation migration, gallery sidecars, Trash snapshots and gallery migration metadata.
 * Rationale: version 2 means horizontal places the photo beside text and vertical above it;
 * older unmarked documents require one appearance-preserving swap, while marked documents must not swap again.
 */
const GALLERY_DESCRIPTION_LAYOUT_SEMANTICS_VERSION = 2;

/**
 * Bound Windows commits of a preflighted layout sidecar.
 * @var int
 * Units: rename attempts, including the first. Scope: one sidecar replacement.
 * Consumers: gallery_description_layout_apply_sidecar_plan().
 * Rationale: tolerate short sharing locks without removing the existing metadata.
 */
const GALLERY_LAYOUT_SIDECAR_RENAME_ATTEMPTS = 10;

/**
 * Bound pauses between Windows sidecar commit attempts.
 * @var int
 * Units: microseconds. Scope: one replacement, at most nine pauses.
 * Consumers: gallery_description_layout_apply_sidecar_plan().
 * Rationale: give readers up to 450 ms of requested pauses to release shared handles.
 */
const GALLERY_LAYOUT_SIDECAR_RENAME_DELAY_US = 50_000;

/**
 * Convert one external legacy metadata document without changing any other field.
 * Old vertical meant photo beside text; current horizontal preserves that appearance.
 * Null, missing, empty and inherit values remain exactly as stored.
 * @param array<string,mixed> $document Sidecar, exported gallery metadata or Smart presentation.
 * @param string $field Orientation field owned by this document format.
 * @return array<string,mixed> Current-semantic document with a durable per-document marker.
 */
function gallery_description_layout_upgrade_document(array $document, string $field = 'description_layout'): array
{
    if ((int) ($document['description_layout_semantics_version'] ?? 0) >= GALLERY_DESCRIPTION_LAYOUT_SEMANTICS_VERSION) return $document;
    if (isset($document[$field]) && is_string($document[$field])) {
        $document[$field] = match (strtolower(trim($document[$field]))) {
            'vertical' => 'horizontal', 'horizontal' => 'vertical', default => $document[$field],
        };
    }
    $document['description_layout_semantics_version'] = GALLERY_DESCRIPTION_LAYOUT_SEMANTICS_VERSION;
    return $document;
}

/**
 * Upgrade legacy Trash orientation fields while preserving the restore document.
 * @param array<string,mixed> $snapshot Stored Trash snapshot containing gallery records.
 * @return array<string,mixed> Snapshot with converted explicit orientations and a replay marker.
 */
function gallery_description_layout_upgrade_snapshot(array $snapshot): array
{
    if ((int) ($snapshot['description_layout_semantics_version'] ?? 0) >= GALLERY_DESCRIPTION_LAYOUT_SEMANTICS_VERSION) return $snapshot;
    if (isset($snapshot['galleries']) && is_array($snapshot['galleries'])) {
        foreach ($snapshot['galleries'] as &$gallery) {
            if ($gallery instanceof \stdClass) $gallery = get_object_vars($gallery);
            if (is_array($gallery)) $gallery = gallery_description_layout_upgrade_document($gallery);
        }
        unset($gallery);
    }
    $snapshot['description_layout_semantics_version'] = GALLERY_DESCRIPTION_LAYOUT_SEMANTICS_VERSION;
    return $snapshot;
}

/**
 * Convert existing sidecars under one established gallery or Trash root.
 * Per-document markers make a interrupted database migration safe to replay;
 * normal sidecar reads deliberately do not invert future hand-authored files.
 * @param string $root Existing canonical filesystem root; absent roots are harmless.
 * @return int Number of sidecar documents converted.
 */
function gallery_description_layout_upgrade_sidecars(string $root): int
{
    $plan = gallery_description_layout_sidecar_plan([$root]);
    gallery_description_layout_apply_sidecar_plan($plan['documents']);
    return count($plan['documents']);
}

/**
 * Preflight every existing sidecar before replacing any document.
 * @param array<int,string> $roots Established gallery/Trash roots or isolated test roots.
 * @return array{documents:array<int,array{path:string,original:string,encoded:string}>,has_sidecars:bool} Prepared writes and durable filesystem installation evidence.
 */
function gallery_description_layout_sidecar_plan(array $roots): array
{
    $documents = [];
    $hasSidecars = false;
    foreach (array_unique($roots) as $root) {
        $prepared = gallery_description_layout_sidecar_root_plan($root);
        $documents = array_merge($documents, $prepared['documents']);
        $hasSidecars = $hasSidecars || $prepared['has_sidecars'];
    }
    return ['documents' => $documents, 'has_sidecars' => $hasSidecars];
}

/**
 * Read and prepare one physical sidecar root without writing files.
 * @param string $root Established physical root; absent roots remain absent.
 * @return array{documents:array<int,array{path:string,original:string,encoded:string}>,has_sidecars:bool} Fully validated replacement inputs.
 */
function gallery_description_layout_sidecar_root_plan(string $root): array
{
    if (!is_dir($root)) return ['documents' => [], 'has_sidecars' => false];
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false || is_link($root)) throw new RuntimeException('Gallery layout migration root could not be verified.');
    $documents = [];
    $hasSidecars = false;
    $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($resolvedRoot, FilesystemIterator::SKIP_DOTS),
        /**
         * Traverse only physical source directories, excluding derivative/internal stores.
         * @param SplFileInfo $file Candidate below the verified root.
         * @return bool Whether the iterator may yield or descend into the candidate.
         */ static function (SplFileInfo $file): bool {
            if ($file->isLink()) return false;
            return !$file->isDir() || (!str_starts_with($file->getFilename(), '.')
                && !in_array(strtolower($file->getFilename()), ['cache', 'thumbs', 'thumbnail', 'thumbnails', 'preview', 'previews', '_php-gallery-internal'], true));
        }
    ));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getFilename() !== 'gallery.json') continue;
        $hasSidecars = true;
        $path = $file->getRealPath();
        if ($path === false || !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/')) {
            throw new RuntimeException('Gallery layout sidecar escaped its storage root.');
        }
        $original = file_get_contents($path);
        if ($original === false) throw new RuntimeException('Gallery layout sidecar could not be read.');
        $decoded = json_decode($original);
        if (json_last_error() !== JSON_ERROR_NONE && preg_match('/"description_layout"\s*:/', $original) === 1) {
            throw new RuntimeException('Gallery layout sidecar metadata is invalid; migration was not applied.');
        }
        if (!$decoded instanceof \stdClass) continue;
        $document = get_object_vars($decoded);
        if (!array_key_exists('description_layout', $document)) continue;
        $converted = gallery_description_layout_upgrade_document($document);
        if ($converted === $document) continue;
        $encoded = json_encode($converted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $documents[] = ['path' => $path, 'original' => $original, 'encoded' => $encoded];
    }
    return ['documents' => $documents, 'has_sidecars' => $hasSidecars];
}

/**
 * Atomically replace only the previously preflighted sidecars.
 * @param array<int,array{path:string,original:string,encoded:string}> $documents Verified replacement plan.
 * @return void Writes per-document markers or refuses stale/unwritable replacements.
 */
function gallery_description_layout_apply_sidecar_plan(array $documents): void
{
    foreach ($documents as $document) {
        $path = $document['path'];
        $encoded = $document['encoded'];
        if (file_get_contents($path) !== $document['original']) throw new RuntimeException('Gallery layout sidecar changed during migration.');
        $temporary = tempnam(dirname($path), '.layout-');
        if ($temporary === false) throw new RuntimeException('Gallery layout sidecar replacement could not be prepared.');
        try {
            if (realpath(dirname($temporary)) !== realpath(dirname($path))) {
                throw new RuntimeException('Gallery layout sidecar replacement left its storage directory.');
            }
            $permissions = fileperms($path);
            if ($permissions !== false && !chmod($temporary, $permissions & 0777)) {
                throw new RuntimeException('Gallery layout sidecar permissions could not be preserved.');
            }
            if (@file_put_contents($temporary, $encoded) !== strlen($encoded)) {
                throw new RuntimeException('Gallery layout sidecar replacement failed.');
            }
            // Retry only the same complete staging bytes on Windows. A concurrent
            // edit during a pause invalidates the original preflight; never delete it.
            $attempts = PHP_OS_FAMILY === 'Windows' ? GALLERY_LAYOUT_SIDECAR_RENAME_ATTEMPTS : 1;
            $committed = false;
            for ($attempt = 0; $attempt < $attempts; $attempt++) {
                if ($attempt > 0 && file_get_contents($path) !== $document['original']) {
                    throw new RuntimeException('Gallery layout sidecar changed during migration.');
                }
                if (@rename($temporary, $path)) {
                    $committed = true;
                    break;
                }
                if ($attempt + 1 < $attempts) usleep(GALLERY_LAYOUT_SIDECAR_RENAME_DELAY_US);
            }
            if (!$committed) throw new RuntimeException('Gallery layout sidecar replacement failed.');
        } finally {
            if (is_file($temporary)) {
                // The owned staging file may have inherited a read-only target mode.
                // Restore owner read/write only for staging cleanup, never the target.
                @chmod($temporary, 0600);
                @unlink($temporary);
            }
        }
    }
}

/**
 * Prepare changed JSON persistence documents before the first filesystem mutation.
 * @param array<int,array<string,mixed>> $rows Smart Gallery or Trash model projections.
 * @param string $column Canonical JSON column in the supplied projections.
 * @return array<int,array{id:int,json:string}> Only changed, valid serialized documents.
 */
function gallery_description_layout_upgrade_json_rows(array $rows, string $column): array
{
    $changes = [];
    foreach ($rows as $row) {
        $raw = $row[$column] ?? null;
        if ($raw === null || $raw === '') continue;
        $decoded = json_decode((string) $raw);
        if (($decoded === null && json_last_error() === JSON_ERROR_NONE) || $decoded === []) continue;
        if (!$decoded instanceof \stdClass) throw new RuntimeException('Stored gallery layout metadata is invalid; migration was not applied.');
        $document = get_object_vars($decoded);
        $converted = $column === 'snapshot_json' ? gallery_description_layout_upgrade_snapshot($document)
            : gallery_description_layout_upgrade_document($document, 'card_layout');
        if ($converted !== $document) $changes[] = ['id' => (int) $row['id'], 'json' => json_encode($converted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
    }
    return $changes;
}

/**
 * Resolve layout-repair storage before or after the installation configuration exists.
 * The installer owns the default galleries directory before writing config.php;
 * upgrades use the configured gallery root. Persistent and legacy Trash locations
 * remain project-owned, and resolving these paths creates no directories.
 * @return array<int,string> Gallery, persistent Trash and legacy Trash roots.
 */
function gallery_description_layout_migration_roots(): array
{
    require_once dirname(__DIR__) . '/bootstrap/configuration.php';
    $projectRoot = dirname(__DIR__, 2);
    $galleryRoot = cms_has_config() ? (string) cms_config()['galleries_root'] : $projectRoot . '/galleries';
    return [$galleryRoot, $projectRoot . '/data/gallery-trash', $projectRoot . '/cache/gallery-trash'];
}

/**
 * Preserve established installations' card appearance through the canonical migration runner.
 * Fresh setup executes before its first administrator exists and keeps the new vertical default.
 * The database transaction and completion marker prevent repeated scalar swaps; filesystem
 * documents carry their own marker in case PHP stops before the database commit.
 * The shared writer lock is acquired and released on this explicit connection,
 * including first installation before application database/configuration bootstrap.
 * @param PDO $pdo Canonical migration connection with the required storage migrations applied.
 * @param array<int,string>|null $sidecarRoots Explicit isolated storage roots for migration recovery/tests; null uses canonical live roots.
 * @return void Completes the conversion or leaves the migration retryable.
 */
function gallery_description_layout_migrate_legacy(PDO $pdo, ?array $sidecarRoots = null): void
{
    try {
        $lease = gallery_edit_model_lock($pdo);
    } catch (Throwable) {
        throw new RuntimeException('Gallery layout migration edit protection could not be verified.');
    }
    if ($lease === null) {
        throw new RuntimeException('Another gallery operation is still running. Retry the layout migration after it finishes.');
    }
    try {
        gallery_layout_migration_model_begin($pdo);
        try {
            $state = gallery_layout_migration_model_state($pdo);
            if ((int) ($state['settings']['gallery_description_layout_semantics_version'] ?? 0) >= GALLERY_DESCRIPTION_LAYOUT_SEMANTICS_VERSION) {
                gallery_layout_migration_model_finish($pdo, true);
                return;
            }
            $smart = gallery_description_layout_upgrade_json_rows($state['smart'], 'presentation_json');
            $trash = gallery_description_layout_upgrade_json_rows($state['trash'], 'snapshot_json');
            $roots = $sidecarRoots ?? gallery_description_layout_migration_roots();
            $sidecars = gallery_description_layout_sidecar_plan($roots);
            $upgrade = $state['upgrade'] || $sidecars['has_sidecars'];
            $settings = ['gallery_description_layout_semantics_version' => '2'];
            if ($upgrade) {
                foreach (['theme_gallery_description_layout', 'tag_page_gallery_description_layout'] as $key) {
                    if (array_key_exists($key, $state['settings'])) {
                        $value = $state['settings'][$key];
                        $settings[$key] = match (strtolower(trim($value))) {
                            'vertical' => 'horizontal', 'horizontal' => 'vertical',
                            default => $key === 'theme_gallery_description_layout' ? 'horizontal' : $value,
                        };
                    }
                }
                if (!isset($settings['theme_gallery_description_layout'])) $settings['theme_gallery_description_layout'] = 'horizontal';
                gallery_description_layout_apply_sidecar_plan($sidecars['documents']);
            }
            $settings['theme_public_content_revision'] = (string) time();
            gallery_layout_migration_model_apply($pdo, $settings, $smart, $trash, $upgrade);
            gallery_layout_migration_model_finish($pdo, true);
        } catch (Throwable $error) {
            gallery_layout_migration_model_finish($pdo, false);
            throw $error;
        }
    } finally {
        try {
            gallery_edit_model_release($lease, $pdo);
        } catch (Throwable) {
            throw new RuntimeException('Gallery layout migration connection was interrupted. Verify migration state before retrying.');
        }
    }
}
