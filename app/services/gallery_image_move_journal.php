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

/** Safe, structured diagnostics for an image move; the previous exception stays private. */
final class ImageMoveDiagnosticFailure extends RuntimeException
{
    /**
     * Preserve a private cause while exposing only fixed move diagnostics.
     * @param string $phase Safe workflow phase.
     * @param string $reason Allowlisted reason code.
     * @param ?string $operationId Random journal identifier when one exists.
     * @param ?int $fileNumber One-based manifest entry number when relevant.
     * @param ?string $fileKind Original or derivative kind when relevant.
     * @param ?Throwable $previous Private underlying failure.
     * @param array<string,string> $fileContext Private Admin log file paths and failed step.
     * @return void
     */
    public function __construct(
        public readonly string $phase,
        public readonly string $reason,
        public readonly ?string $operationId = null,
        public readonly ?int $fileNumber = null,
        public readonly ?string $fileKind = null,
        ?Throwable $previous = null,
        public readonly array $fileContext = []
    ) {
        $details = str_replace('_', ' ', $phase) . ': ' . gallery_image_move_reason_description($reason);
        if ($fileNumber !== null && in_array($fileKind, ['original', 'derivative'], true)) {
            $details .= ' (' . $fileKind . ' file ' . $fileNumber . ')';
        }
        if ($operationId !== null && preg_match('/^[a-f0-9]{32}$/D', $operationId) === 1) {
            $details .= '. Operation ' . $operationId;
        }
        parent::__construct('Image move failed at ' . $details . '. Inspect pending image-move operations before retrying.', 0, $previous);
    }
}

/**
 * Build a bounded Admin-only diagnostic with the exact file and exception chain.
 * Database exception messages are excluded because they can contain SQL or credentials.
 * @param Throwable $exception Move failure, with nested causes when available.
 * @return array{file_context:array<string,string>,exceptions:list<array<string,mixed>>,origin_trace:list<array<string,mixed>>} Private diagnostic fields.
 */
function gallery_image_move_exception_context(Throwable $exception): array
{
    $fileContext = $exception instanceof ImageMoveDiagnosticFailure ? $exception->fileContext : [];
    $chain = [];
    $current = $exception;
    $origin = $exception;
    for ($depth = 0; $current !== null && $depth < 6; $depth++, $current = $current->getPrevious()) {
        $origin = $current;
        $databaseException = $current instanceof \PDOException;
        $chain[] = [
            'class' => get_class($current),
            'code' => $current->getCode(),
            'message' => $databaseException ? '[database exception message withheld]' : $current->getMessage(),
            'php_file' => $current->getFile(),
            'php_line' => $current->getLine(),
        ];
    }
    $trace = [];
    foreach (array_slice($origin->getTrace(), 0, 8) as $frame) {
        $trace[] = [
            'php_file' => (string) ($frame['file'] ?? ''),
            'php_line' => (int) ($frame['line'] ?? 0),
            'call' => (string) ($frame['class'] ?? '') . (string) ($frame['type'] ?? '') . (string) ($frame['function'] ?? ''),
        ];
    }
    return ['file_context' => $fileContext, 'exceptions' => $chain, 'origin_trace' => $trace];
}

/**
 * Preserve the exact suppressed PHP filesystem warning as an exception cause.
 * Call only after error_clear_last() and a failed filesystem operation.
 * @return ?Throwable Native warning with its original message, file and line.
 */
function gallery_image_move_last_warning(): ?Throwable
{
    $warning = error_get_last();
    if (!is_array($warning) || !is_string($warning['message'] ?? null)) {
        return null;
    }
    return new \ErrorException($warning['message'], 0, (int) ($warning['type'] ?? E_WARNING),
        (string) ($warning['file'] ?? __FILE__), (int) ($warning['line'] ?? 0));
}

/**
 * Give administrators useful next checks using only fixed, non-sensitive text.
 * @param string $reason Allowlisted reason code.
 * @return string Safe explanation without paths or native exceptions.
 */
function gallery_image_move_reason_description(string $reason): string
{
    return match ($reason) {
        'exclusive_link_unavailable' => 'an earlier hard-link move could not create its destination',
        'file_rename_failed' => 'the original file could not be moved to its destination; check filesystem permissions and storage boundaries',
        'destination_directory_unavailable' => 'the destination directory cannot be created; check storage write permissions',
        'destination_boundary_unverified', 'storage_boundary_unverified' => 'gallery storage confinement could not be verified',
        'manifest_path_invalid' => 'the journal file path does not belong to its recorded gallery',
        'gallery_directory_missing' => 'the recorded gallery directory is missing',
        'path_outside_gallery' => 'the file path resolves outside its gallery',
        'file_symlink' => 'the file path is a symbolic link',
        'gallery_ancestry_changed' => 'the file parent resolves outside its recorded gallery',
        'storage_ancestry_untrusted' => 'the file parent is outside trusted gallery storage',
        'storage_ancestry_missing' => 'the file parent has no verifiable existing ancestor',
        'source_unlink_failed' => 'an earlier move could not remove its source file',
        'destination_occupied' => 'the destination file is already occupied',
        'destination_identity_unverified' => 'the new destination file could not be verified',
        'source_identity_changed', 'source_identity_unverified' => 'the source file changed or could not be verified',
        'journal_write_failed', 'journal_update_failed', 'journal_read_failed' => 'the move journal could not be accessed; check database availability and migrations',
        'journal_missing' => 'the prepared move journal is missing',
        'ownership_update_failed' => 'the image ownership transaction failed; check database availability and schema',
        'metadata_refresh_failed' => 'gallery metadata refresh failed; check gallery write permissions',
        'file_missing_or_changed' => 'a recovery file is missing or changed',
        'duplicate_identity_unverified' => 'both file names exist but their expected content could not be verified',
        'duplicate_unlink_failed' => 'the verified extra file could not be removed',
        'image_ownership_changed' => 'image ownership no longer matches the journal',
        'gallery_ownership_changed' => 'gallery ownership or storage no longer matches the journal',
        'manifest_invalid' => 'the move journal manifest is invalid',
        'recovery_verification_failed' => 'recovery could not verify the stored move',
        'pending_operation' => 'an earlier move still needs reconciliation',
        'gallery_busy' => 'another move or recovery is using this gallery',
        'gallery_missing' => 'the source or destination gallery is missing',
        'gallery_folder_missing' => 'the source or destination gallery folder is missing',
        'same_gallery' => 'the source and destination are the same gallery',
        'operation_unavailable' => 'the operation could not be verified',
        default => 'unclassified failure',
    };
}

/**
 * Return only structured reason codes; never serialize a native exception message.
 * @param Throwable $exception Failure to classify.
 * @param string $fallback Safe fallback reason.
 * @return string Safe reason code.
 */
function gallery_image_move_failure_reason(Throwable $exception, string $fallback): string
{
    return $exception instanceof ImageMoveDiagnosticFailure ? $exception->reason : $fallback;
}

/**
 * Classify recovery failures without exposing paths, SQL, or native errors.
 * @param Throwable $exception Recovery failure to classify.
 * @return string Safe reason code.
 */
function gallery_image_move_recovery_reason(Throwable $exception): string
{
    if ($exception instanceof ImageMoveDiagnosticFailure) {
        return $exception->reason;
    }
    return match ($exception->getMessage()) {
        'Image move recovery found a missing or changed file.' => 'file_missing_or_changed',
        'Image move recovery found two names without verified expected content.' => 'duplicate_identity_unverified',
        'Image move recovery could not remove a verified extra file.' => 'duplicate_unlink_failed',
        'Image move image ownership changed.' => 'image_ownership_changed',
        'Image move gallery ownership or storage changed.' => 'gallery_ownership_changed',
        'Image move metadata refresh requires retry.' => 'metadata_refresh_failed',
        'Unsupported image move manifest.' => 'manifest_invalid',
        default => 'recovery_verification_failed',
    };
}

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
 * Check content identity independently of the physical move method.
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
 * Move the physical original to an unoccupied destination with rename().
 * Verify the source before and the destination after the move. Gallery locks
 * serialize application writers; external filesystem writers must be stopped
 * during a move because PHP has no portable no-replace rename primitive.
 *
 * @param string $from Confined existing source path.
 * @param string $to Confined, absent target path.
 * @param array{size:int,sha256:string} $identity Expected content identity.
 * @return void
 */
function gallery_image_move_file_exclusive(string $from, string $to, array $identity): void
{
    if (!gallery_image_move_file_matches($from, $identity)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'source_identity_changed');
    }
    if (file_exists($to) || is_link($to)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'destination_occupied');
    }
    $parent = dirname($to);
    try {
        gallery_image_move_assert_parent_boundary($parent);
    } catch (Throwable $exception) {
        throw new ImageMoveDiagnosticFailure('file_transfer', gallery_image_move_failure_reason($exception, 'destination_boundary_unverified'), previous: $exception);
    }
    error_clear_last();
    if (!is_dir($parent) && !@mkdir($parent, GALLERY_DIRECTORY_PERMISSIONS, true) && !is_dir($parent)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'destination_directory_unavailable', previous: gallery_image_move_last_warning());
    }
    if (!gallery_filesystem_path_inside_root($parent)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'destination_boundary_unverified');
    }
    if (file_exists($to) || is_link($to)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'destination_occupied');
    }
    error_clear_last();
    if (!@rename($from, $to)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'file_rename_failed', previous: gallery_image_move_last_warning());
    }
    if (!gallery_image_move_file_matches($to, $identity)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'destination_identity_unverified');
    }
}

/**
 * Verify the nearest existing ancestor before creating any destination directory.
 *
 * A textual descendant check cannot detect an existing nested symlink. Resolve
 * the existing ancestor through the canonical gallery-root policy, then recheck
 * the created directory before moving. Concurrent external storage replacement
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
            throw new ImageMoveDiagnosticFailure('file_transfer', 'storage_ancestry_missing');
        }
        $existing = $ancestor;
    }
    if (!is_dir($existing) || !gallery_filesystem_path_inside_root($existing)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'storage_ancestry_untrusted');
    }
    if ($galleryRoot !== null) {
        $rootReal = realpath($galleryRoot);
        $ancestorReal = realpath($existing);
        if ($rootReal === false || $ancestorReal === false
            || ($ancestorReal !== $rootReal && !str_starts_with($ancestorReal, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new ImageMoveDiagnosticFailure('file_transfer', 'gallery_ancestry_changed');
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
    foreach ($entries as $index => $entry) {
        $from = str_replace('\\', '/', $entry['from']);
        $to = str_replace('\\', '/', $entry['to']);
        if (!str_starts_with($from, $root) || !str_starts_with($to, $root)
            || !gallery_filesystem_path_inside_root(dirname($from))) {
            throw new ImageMoveDiagnosticFailure('prepare', 'storage_boundary_unverified', fileNumber: $index + 1, fileKind: $entry['kind']);
        }
        try {
            $identity = gallery_image_move_fingerprint($entry['from']);
        } catch (Throwable $exception) {
            throw new ImageMoveDiagnosticFailure('prepare', 'source_identity_unverified', fileNumber: $index + 1, fileKind: $entry['kind'], previous: $exception);
        }
        $files[] = ['from' => normalize_relative_path(substr($from, strlen($root))),
            'to' => normalize_relative_path(substr($to, strlen($root))), 'kind' => $entry['kind'],
            'identity' => $identity];
    }
    $manifest = ['version' => IMAGE_MOVE_MANIFEST_VERSION, 'source_path' => (string) $source['folder_path'],
        'destination_path' => (string) $destination['folder_path'], 'image_ids' => $imageIds, 'files' => $files];
    $id = bin2hex(random_bytes(IMAGE_MOVE_OPERATION_RANDOM_BYTES));
    try {
        gallery_image_move_model_prepare($id, (int) $source['id'], (int) $destination['id'],
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), now_sql());
    } catch (Throwable $exception) {
        throw new ImageMoveDiagnosticFailure('prepare', 'journal_write_failed', previous: $exception);
    }
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
    // Persisted gallery paths can retain legacy separators or dot segments even
    // though file paths in the journal are canonical forward-slash paths.
    try {
        $normalizedGalleryPath = normalize_relative_path($galleryPath);
        $normalizedRelativePath = normalize_relative_path($relativePath);
    } catch (Throwable $exception) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'manifest_path_invalid', previous: $exception);
    }
    if ($normalizedGalleryPath === '' || $relativePath !== $normalizedRelativePath
        || !str_starts_with($relativePath, $normalizedGalleryPath . '/')) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'manifest_path_invalid');
    }
    $root = gallery_abs_path($galleryPath);
    $path = galleries_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_dir($root)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'gallery_directory_missing');
    }
    if (!thumbnail_path_inside_existing_gallery($root, $path)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'path_outside_gallery');
    }
    if (is_link($path)) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'file_symlink');
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
    try {
        $job = gallery_image_move_model_find($operationId);
    } catch (Throwable $exception) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'journal_read_failed', $operationId, previous: $exception);
    }
    if (!$job) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'journal_missing', $operationId);
    }
    try {
        $manifest = json_decode($job['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        gallery_image_move_model_state($operationId, IMAGE_MOVE_MOVING, now_sql());
    } catch (Throwable $exception) {
        throw new ImageMoveDiagnosticFailure('file_transfer', 'journal_update_failed', $operationId, previous: $exception);
    }
    $moved = [];
    foreach ($manifest['files'] as $index => $entry) {
        $step = 'resolve_source';
        try {
            $from = gallery_image_move_manifest_path($entry['from'], $manifest['source_path']);
            $step = 'resolve_destination';
            $to = gallery_image_move_manifest_path($entry['to'], $manifest['destination_path']);
            $step = 'exclusive_transfer';
            gallery_image_move_file_exclusive($from, $to, $entry['identity']);
        } catch (Throwable $exception) {
            $storageRoot = galleries_root();
            $sourcePath = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) ($entry['from'] ?? ''));
            $destinationPath = $storageRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) ($entry['to'] ?? ''));
            throw new ImageMoveDiagnosticFailure('file_transfer', gallery_image_move_failure_reason($exception, 'storage_boundary_unverified'),
                $operationId, $index + 1, $entry['kind'] ?? null, $exception, [
                    'failed_step' => $step,
                    'source_relative_path' => (string) ($entry['from'] ?? ''),
                    'destination_relative_path' => (string) ($entry['to'] ?? ''),
                    'source_absolute_path' => $sourcePath,
                    'destination_absolute_path' => $destinationPath,
                    'storage_root_realpath' => realpath($storageRoot) ?: '',
                    'source_parent_realpath' => realpath(dirname($sourcePath)) ?: '',
                    'destination_parent_realpath' => realpath(dirname($destinationPath)) ?: '',
                    'manifest_source_gallery_path' => (string) ($manifest['source_path'] ?? ''),
                    'manifest_destination_gallery_path' => (string) ($manifest['destination_path'] ?? ''),
                    'source_exists' => file_exists($sourcePath) ? 'yes' : 'no',
                    'destination_exists' => file_exists($destinationPath) ? 'yes' : 'no',
                    'source_is_link' => is_link($sourcePath) ? 'yes' : 'no',
                    'destination_is_link' => is_link($destinationPath) ? 'yes' : 'no',
                ]);
        }
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
    if (!gallery_image_move_file_matches($other, $identity)
        || !gallery_image_move_file_matches($wanted, $identity)) {
        throw new RuntimeException('Image move recovery found two names without verified expected content.');
    }
    if (!@unlink($other)) {
        throw new RuntimeException('Image move recovery could not remove a verified extra file.');
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
        throw new ImageMoveDiagnosticFailure('recovery', 'journal_missing', $operationId);
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
        $reason = gallery_image_move_recovery_reason($exception);
        try {
            gallery_image_move_model_state($operationId, IMAGE_MOVE_NEEDS_RECONCILIATION, now_sql(), $reason);
        } catch (Throwable) {
            // The original durable intent/commit marker still remains; never erase it.
        }
        throw new ImageMoveDiagnosticFailure('recovery', $reason, $operationId, previous: $exception);
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
