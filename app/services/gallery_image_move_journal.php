<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Coordinate journal stages, exclusive movement and conservative reconciliation.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_image_move_journal.php
 * Module Type: Service
 * Purpose: Recover image moves using durable intent and verified, non-overwriting file operations.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Models\gallery_image_move_model_find;
use function Gallery\Models\gallery_image_move_model_lock;
use function Gallery\Models\gallery_image_move_model_pending;
use function Gallery\Models\gallery_image_move_model_prepare;
use function Gallery\Models\gallery_image_move_model_state;
use function Gallery\Models\gallery_image_move_model_unlock;
use const Gallery\Core\GALLERY_DIRECTORY_PERMISSIONS;
use const Gallery\Core\IMAGE_MOVE_OPERATION_RANDOM_BYTES;
use const Gallery\Core\IMAGE_MOVE_MANIFEST_VERSION;
use const Gallery\Core\IMAGE_MOVE_MOVING;
use const Gallery\Core\IMAGE_MOVE_FINALIZED;
use const Gallery\Core\IMAGE_MOVE_ROLLED_BACK;
use const Gallery\Core\IMAGE_MOVE_NEEDS_RECONCILIATION;

require_once dirname(__DIR__) . '/policy_constants.php';
require_once __DIR__ . '/gallery_edit_concurrency.php';

/**
 * Return a bounded pending queue for authenticated diagnostics or the local CLI.
 *
 * @return array<int,array<string,mixed>> Safe identifiers/statuses, without paths or fingerprints.
 */
function gallery_image_move_pending(): array
{
    mutation_schema_assert_available(gallery_image_move_journal_schema_status(), 'gallery.move_inspect');
    try {
        return gallery_image_move_model_pending();
    } catch (Throwable) {
        throw new RuntimeException('Image move journal inspection is unavailable; existing records are retained.');
    }
}

/**
 * Release journal locks without exposing database diagnostics to HTTP or CLI callers.
 * @param list<string> $locks Exact connection-owned names returned by the model.
 * @return void
 * @throws RuntimeException When release acknowledgement is unavailable.
 */
function gallery_image_move_release_locks(array $locks): void
{
    try {
        gallery_image_move_model_unlock($locks);
    } catch (Throwable) {
        throw new RuntimeException('Image move lock release could not be verified. Inspect the retained journal before retrying.');
    }
}

/**
 * Observe an original/derivative identity before recording or reconciling a move.
 *
 * Symlinks and files that change during hashing are refused. Hashes remain private
 * journal data and must never be copied into public health/log response contexts.
 *
 * @param string $path Already confined absolute file path.
 * @return array{size:int,sha256:string,dev:int,ino:int} Verified file fingerprint.
 */
function gallery_image_move_fingerprint(string $path): array
{
    clearstatcache(true, $path);
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('Image move file identity could not be verified.');
    }
    $before = @stat($path);
    $hash = @hash_file('sha256', $path);
    clearstatcache(true, $path);
    $after = @stat($path);
    if ($before === false || $after === false || !is_string($hash)
        || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino']
        || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']) {
        throw new RuntimeException('Image move file changed during observation.');
    }
    return ['size' => (int) $after['size'], 'sha256' => $hash, 'dev' => (int) $after['dev'], 'ino' => (int) $after['ino']];
}

/**
 * Check content identity independently of the inode created by recovery linking.
 *
 * @param string $path Confined existing file path.
 * @param array{size:int,sha256:string} $identity Expected size and SHA-256.
 * @return bool Whether the current bytes match the persisted intent.
 */
function gallery_image_move_file_matches(string $path, array $identity): bool
{
    try {
        $observed = gallery_image_move_fingerprint($path);
        return $observed['size'] === $identity['size'] && hash_equals($identity['sha256'], $observed['sha256']);
    } catch (Throwable) {
        return false;
    }
}

/**
 * Move without rename's platform-dependent overwrite behavior.
 *
 * Hard-link creation is exclusive. Cross-filesystem or unsupported links refuse
 * safely, leaving the source recoverable. A crash may leave both names; recovery
 * removes a duplicate name only when positive inode identity proves the same file.
 *
 * @param string $from Confined existing source path.
 * @param string $to Confined, absent target path.
 * @param array{size:int,sha256:string} $identity Expected content identity.
 * @return void
 */
function gallery_image_move_file_exclusive(string $from, string $to, array $identity): void
{
    if (!gallery_image_move_file_matches($from, $identity) || file_exists($to) || is_link($to)) {
        throw new RuntimeException('Image move source changed or destination is occupied.');
    }
    $parent = dirname($to);
    gallery_image_move_assert_parent_boundary($parent);
    if (!is_dir($parent) && !@mkdir($parent, GALLERY_DIRECTORY_PERMISSIONS, true) && !is_dir($parent)) {
        throw new RuntimeException('Image move destination directory is unavailable.');
    }
    if (!gallery_filesystem_path_inside_root($parent) || !@link($from, $to)) {
        throw new RuntimeException('Image move requires an available same-filesystem destination supporting exclusive links.');
    }
    if (!gallery_image_move_file_matches($from, $identity) || !gallery_image_move_file_matches($to, $identity) || !@unlink($from)) {
        throw new RuntimeException('Image move needs reconciliation; verified source or destination remains recoverable.');
    }
}

/**
 * Verify the nearest existing ancestor before creating any destination directory.
 *
 * A textual descendant check cannot detect an existing nested symlink. Resolve
 * the existing ancestor through the canonical gallery-root policy, then recheck
 * the created directory before linking. Concurrent external storage replacement
 * remains an operator coordination boundary, not an application lock guarantee.
 *
 * @param string $parent Parent of an already normalized intended file path.
 * @param ?string $galleryRoot Optional original gallery boundary for persisted intent.
 * @return void
 * @throws RuntimeException When existing storage cannot prove confinement.
 */
function gallery_image_move_assert_parent_boundary(string $parent, ?string $galleryRoot = null): void
{
    $existing = $parent;
    while (!file_exists($existing) && !is_link($existing)) {
        $ancestor = dirname($existing);
        if ($ancestor === $existing) {
            throw new RuntimeException('Image move destination ancestry is unavailable.');
        }
        $existing = $ancestor;
    }
    if (!is_dir($existing) || !gallery_filesystem_path_inside_root($existing)) {
        throw new RuntimeException('Image move destination ancestry is outside trusted storage.');
    }
    if ($galleryRoot !== null) {
        $rootReal = realpath($galleryRoot);
        $ancestorReal = realpath($existing);
        if ($rootReal === false || $ancestorReal === false
            || ($ancestorReal !== $rootReal && !str_starts_with($ancestorReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Image move ancestry changed gallery ownership.');
        }
    }
}

/**
 * Persist a versioned relative-path manifest before the first file change.
 *
 * @param array<string,mixed> $source Source gallery row with id and folder_path.
 * @param array<string,mixed> $destination Destination gallery row with id and folder_path.
 * @param array<int,int> $imageIds Validated image identifiers.
 * @param array<int,array{from:string,to:string,kind:string}> $entries Confined absolute file move entries.
 * @return string Durable random operation identifier.
 */
function gallery_image_move_prepare(array $source, array $destination, array $imageIds, array $entries): string
{
    $root = rtrim(str_replace('\\', '/', galleries_root()), '/') . '/';
    $files = [];
    foreach ($entries as $entry) {
        $from = str_replace('\\', '/', $entry['from']);
        $to = str_replace('\\', '/', $entry['to']);
        if (!str_starts_with($from, $root) || !str_starts_with($to, $root)
            || !gallery_filesystem_path_inside_root(dirname($from))) {
            throw new RuntimeException('Image move manifest is outside trusted storage.');
        }
        $files[] = ['from' => normalize_relative_path(substr($from, strlen($root))),
            'to' => normalize_relative_path(substr($to, strlen($root))), 'kind' => $entry['kind'],
            'identity' => gallery_image_move_fingerprint($entry['from'])];
    }
    $manifest = ['version' => IMAGE_MOVE_MANIFEST_VERSION, 'source_path' => (string) $source['folder_path'],
        'destination_path' => (string) $destination['folder_path'], 'image_ids' => $imageIds, 'files' => $files];
    $id = bin2hex(random_bytes(IMAGE_MOVE_OPERATION_RANDOM_BYTES));
    gallery_image_move_model_prepare($id, (int) $source['id'], (int) $destination['id'],
        json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), now_sql());
    return $id;
}

/**
 * Resolve a persisted path under its originally recorded gallery, rejecting changed roots.
 *
 * @param string $relativePath Normalized gallery-root-relative file path.
 * @param string $galleryPath Manifest's original gallery folder path.
 * @return string Confined absolute path, including a not-yet-created target.
 */
function gallery_image_move_manifest_path(string $relativePath, string $galleryPath): string
{
    if ($relativePath !== normalize_relative_path($relativePath)
        || !str_starts_with($relativePath, rtrim($galleryPath, '/') . '/')) {
        throw new RuntimeException('Image move manifest path is invalid.');
    }
    $root = gallery_abs_path($galleryPath);
    $path = galleries_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_dir($root) || !thumbnail_path_inside_existing_gallery($root, $path) || is_link($path)) {
        throw new RuntimeException('Image move storage boundary changed.');
    }
    gallery_image_move_assert_parent_boundary(dirname($path), $root);
    return $path;
}

/**
 * Execute persisted file intent; callers retain both gallery locks until DB completion.
 *
 * @param string $operationId Prepared durable operation identifier.
 * @param ?callable(string,string,int):void $checkpoint Optional internal fault-injection observer; never sourced from HTTP.
 * @return array<int,array{from:string,to:string,kind:string}> Successfully moved files for existing response counts.
 */
function gallery_image_move_execute_files(string $operationId, ?callable $checkpoint = null): array
{
    $job = gallery_image_move_model_find($operationId);
    if (!$job) {
        throw new RuntimeException('Prepared image move journal was not found.');
    }
    $manifest = json_decode($job['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
    gallery_image_move_model_state($operationId, IMAGE_MOVE_MOVING, now_sql());
    $moved = [];
    foreach ($manifest['files'] as $entry) {
        $from = gallery_image_move_manifest_path($entry['from'], $manifest['source_path']);
        $to = gallery_image_move_manifest_path($entry['to'], $manifest['destination_path']);
        gallery_image_move_file_exclusive($from, $to, $entry['identity']);
        $moved[] = ['from' => $from, 'to' => $to, 'kind' => $entry['kind']];
        if ($checkpoint !== null) {
            $checkpoint('file_moved', $operationId, count($moved));
        }
    }
    return $moved;
}

/**
 * Restore one intended name without overwriting another file or deleting uncertain copies.
 *
 * @param string $wanted Path required by the authoritative database commit state.
 * @param string $other Opposite path in the same manifest entry.
 * @param array{size:int,sha256:string} $identity Persisted content fingerprint.
 * @return void
 */
function gallery_image_move_reconcile_file(string $wanted, string $other, array $identity): void
{
    $wantedExists = file_exists($wanted) || is_link($wanted);
    $otherExists = file_exists($other) || is_link($other);
    if (!$wantedExists && $otherExists) {
        gallery_image_move_file_exclusive($other, $wanted, $identity);
        return;
    }
    if (!$wantedExists || !gallery_image_move_file_matches($wanted, $identity)) {
        throw new RuntimeException('Image move recovery found a missing or changed file.');
    }
    if (!$otherExists) {
        return;
    }
    $wantedStat = gallery_image_move_fingerprint($wanted);
    $otherStat = gallery_image_move_fingerprint($other);
    if ($wantedStat['ino'] <= 0 || $wantedStat['ino'] !== $otherStat['ino']
        || $wantedStat['dev'] !== $otherStat['dev'] || !gallery_image_move_file_matches($other, $identity)) {
        throw new RuntimeException('Image move recovery found two names without proven shared file identity.');
    }
    if (!@unlink($other)) {
        throw new RuntimeException('Image move recovery could not remove a verified duplicate link.');
    }
}

/**
 * Reconcile one operation while the caller owns both gallery connection locks.
 *
 * The durable database_committed flag selects finish versus rollback even after
 * state becomes needs_reconciliation. Unexpected identities preserve every file.
 *
 * @param string $operationId Exact durable operation identifier.
 * @return array{operation_id:string,state:string} Safe bounded recovery result.
 */
function gallery_image_move_recover_locked(string $operationId): array
{
    $job = gallery_image_move_model_find($operationId);
    if (!$job) {
        throw new RuntimeException('Image move journal was not found.');
    }
    if (in_array($job['state'], [IMAGE_MOVE_FINALIZED, IMAGE_MOVE_ROLLED_BACK], true)) {
        return ['operation_id' => $operationId, 'state' => $job['state']];
    }
    try {
        $manifest = json_decode($job['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['version'] ?? null) !== IMAGE_MOVE_MANIFEST_VERSION || !is_array($manifest['files'] ?? null)
            || !is_array($manifest['image_ids'] ?? null)) {
            throw new RuntimeException('Unsupported image move manifest.');
        }
        $source = find_gallery((int) $job['source_gallery_id'], true);
        $destination = find_gallery((int) $job['destination_gallery_id'], true);
        if (!$source || !$destination || $source['folder_path'] !== $manifest['source_path']
            || $destination['folder_path'] !== $manifest['destination_path']) {
            throw new RuntimeException('Image move gallery ownership or storage changed.');
        }
        $committed = (bool) $job['database_committed'];
        $expectedOwner = (int) ($committed ? $job['destination_gallery_id'] : $job['source_gallery_id']);
        foreach ($manifest['image_ids'] as $imageId) {
            $image = find_image((int) $imageId, true);
            if (!$image || (int) $image['gallery_id'] !== $expectedOwner) {
                throw new RuntimeException('Image move image ownership changed.');
            }
        }
        $files = $committed ? $manifest['files'] : array_reverse($manifest['files']);
        // Preflight every entry before reconciling any entry.
        $paths = [];
        foreach ($files as $entry) {
            $from = gallery_image_move_manifest_path($entry['from'], $manifest['source_path']);
            $to = gallery_image_move_manifest_path($entry['to'], $manifest['destination_path']);
            $paths[] = [$committed ? $to : $from, $committed ? $from : $to, $entry['identity']];
        }
        foreach ($paths as [$wanted, $other, $identity]) {
            gallery_image_move_reconcile_file($wanted, $other, $identity);
        }
        if ($committed) {
            thumbnail_maintenance_summary_cache_clear();
            if (public_path_schema_ready()) {
                regenerate_gallery_image_public_slugs((int) $source['id']);
                regenerate_gallery_image_public_slugs((int) $destination['id']);
            }
            if (!write_gallery_sidecar(find_gallery((int) $source['id'], true) ?: $source)
                || !write_gallery_sidecar(find_gallery((int) $destination['id'], true) ?: $destination)) {
                throw new RuntimeException('Image move metadata refresh requires retry.');
            }
        }
        $state = $committed ? IMAGE_MOVE_FINALIZED : IMAGE_MOVE_ROLLED_BACK;
        gallery_image_move_model_state($operationId, $state, now_sql());
        return ['operation_id' => $operationId, 'state' => $state];
    } catch (Throwable $exception) {
        try {
            gallery_image_move_model_state($operationId, IMAGE_MOVE_NEEDS_RECONCILIATION, now_sql(), 'identity_or_storage_unverified');
        } catch (Throwable) {
            // The original durable intent/commit marker still remains; never erase it.
        }
        throw new RuntimeException('Image move requires reconciliation. Existing files and journal are retained.');
    }
}

/**
 * Explicitly recover one journal entry, serialized against competing image moves.
 *
 * @param string $operationId Exact durable operation identifier selected by the operator.
 * @return array{operation_id:string,state:string} Safe bounded recovery result.
 */
function gallery_image_move_recover(string $operationId): array
{
    mutation_schema_assert_available(gallery_image_move_journal_schema_status(), 'gallery.move_recover');
    $job = gallery_image_move_model_find($operationId);
    if (!$job) {
        throw new RuntimeException('Image move journal was not found.');
    }
    $writerLock = gallery_edit_writer_begin();
    $locks = [];
    try {
        $locks = gallery_image_move_model_lock([(int) $job['source_gallery_id'], (int) $job['destination_gallery_id']]);
        return gallery_image_move_recover_locked($operationId);
    } finally {
        try {
            gallery_image_move_release_locks($locks);
        } finally {
            gallery_edit_writer_end($writerLock);
        }
    }
}
