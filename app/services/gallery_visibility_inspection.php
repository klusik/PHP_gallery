<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_visibility_inspection.php
 * Module Type: Service
 * Purpose: Inspect and reconcile retained visibility evidence.
 * Responsibilities: Validate current row and sidecar evidence and close only an
 *   explicitly reviewed matching marker without replaying a database mutation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Core\normalize_relative_path;

/**
 * Bound the complete fixed-format visibility marker read by operator inspection.
 * Type: int. Units: bytes. Scope: one marker. Consumers: gallery_visibility_inspect.
 * Rationale: 2048 accommodates the fixed marker fields and refuses larger input.
 */
const LINKED_VISIBILITY_MARKER_MAX_BYTES = 2048;

/**
 * Read one bounded regular file while checking its handle and directory entry.
 *
 * @param string $path Path under an already verified gallery directory.
 * @param int $limit Maximum permitted content length in bytes.
 * @return array{content:string,dev:int,ino:int} Exact bytes and observed identity.
 * @throws RuntimeException When the file is redirected, replaced or unavailable.
 */
function gallery_visibility_inspect_file(string $path, int $limit): array
{
    clearstatcache(true, $path);
    $before = @lstat($path);
    if (!is_array($before) || is_link($path) || !is_file($path)
        || ($before['nlink'] ?? 0) !== 1 || ($before['size'] ?? -1) < 0
        || $before['size'] > $limit) {
        throw new RuntimeException('Visibility inspection file is unavailable.');
    }
    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Visibility inspection file is unavailable.');
    }
    try {
        $opened = fstat($handle);
        $content = stream_get_contents($handle, $limit + 1);
        $afterHandle = fstat($handle);
    } finally {
        fclose($handle);
    }
    clearstatcache(true, $path);
    $after = @lstat($path);
    if (!is_array($opened) || !is_array($afterHandle) || !is_array($after)
        || is_link($path) || ($opened['nlink'] ?? 0) !== 1
        || ($afterHandle['nlink'] ?? 0) !== 1 || ($after['nlink'] ?? 0) !== 1
        || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino']
        || $before['dev'] !== $afterHandle['dev'] || $before['ino'] !== $afterHandle['ino']
        || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino']
        || !is_string($content) || strlen($content) !== $before['size']
        || $afterHandle['size'] !== $before['size'] || $after['size'] !== $before['size']) {
        throw new RuntimeException('Visibility inspection file changed.');
    }
    return ['content' => $content, 'dev' => $before['dev'], 'ino' => $before['ino']];
}

/**
 * Retire an owned marker only after durable matching sidecar readback.
 *
 * @param array{path:string,dev:int,ino:int,folder_dev:int,folder_ino:int,content:string} $marker
 *     Exact marker and directory evidence under the shared writer.
 * @param string $visibility Confirmed current database visibility.
 * @param string $expectedHash Exact SHA-256 of the matching sidecar bytes.
 * @param string $writer Held gallery writer lock name.
 * @return void Returns after verified marker removal and directory sync.
 * @throws RuntimeException On changed evidence, unowned or uncertain removal.
 */
function gallery_visibility_marker_finish(
    array $marker,
    string $visibility,
    string $expectedHash,
    string $writer,
): void {
    gallery_visibility_marker_assert_owned($marker);
    $directory = dirname($marker['path']);
    $path = $directory . DIRECTORY_SEPARATOR . 'gallery.json';
    $sidecar = gallery_visibility_inspect_file($path, LINKED_VISIBILITY_SIDECAR_MAX_BYTES);
    if (!hash_equals($expectedHash, hash('sha256', $sidecar['content']))) {
        throw new RuntimeException('Published visibility sidecar differs.');
    }
    gallery_sidecar_visibility_replacement($sidecar['content'], $visibility, $visibility);
    gallery_visibility_sync_file($path, $sidecar['dev'], $sidecar['ino']);
    gallery_visibility_marker_assert_owned($marker);
    if (!\Gallery\Models\admin_operation_model_lock_owned($writer)
        || gallery_visibility_inspect_file($path, LINKED_VISIBILITY_SIDECAR_MAX_BYTES) !== $sidecar
        || !@unlink($marker['path'])) {
        throw new RuntimeException('Visibility marker retirement is uncertain.');
    }
    gallery_visibility_sync_directory($directory, $marker['folder_dev'], $marker['folder_ino']);
    clearstatcache(true, $marker['path']);
    if (@lstat($marker['path']) !== false) {
        throw new RuntimeException('Visibility marker retirement is uncertain.');
    }
}

/**
 * Inspect a retained marker or explicitly synchronize its sidecar to the DB.
 *
 * @param int $galleryId Positive gallery identifier supplied by an operator.
 * @param string|null $reviewed Exact fingerprint from the reviewed inspection;
 *     null only inspects without writes.
 * @return array{gallery_id:int,database_visibility:string,sidecar_visibility:string,sidecar_matches_database:bool,marker_retained:bool,revision:string,review_fingerprint:?string}
 *     Current observations, with a fingerprint only for eligible reconciliation.
 * @throws Throwable When schema, ownership, filesystem or provenance is uncertain.
 */
function gallery_visibility_inspect(int $galleryId, ?string $reviewed = null): array
{
    if ($galleryId <= 0 || ($reviewed !== null
        && preg_match('/\A[a-f0-9]{64}\z/D', $reviewed) !== 1)) {
        throw new RuntimeException('Visibility inspection is unavailable.');
    }
    app_linking_require_schema(app_linking_schema_status(
        'gallery.visibility_inspection',
        ['galleries' => ['id', 'folder_path', 'visibility', 'edit_revision']],
        ['galleries' => ['PRIMARY' => ['id']]],
        ['galleries'],
    ));
    $writer = gallery_edit_writer_begin();
    try {
        return \Gallery\Models\gallery_model_visibility_inspect(
            $galleryId,
            static function (array $row) use ($galleryId, $writer, $reviewed): array {
                $relative = $row['folder_path'] ?? null;
                $visibility = $row['visibility'] ?? null;
                $revision = gallery_edit_revision($row);
                if ((int) ($row['id'] ?? 0) !== $galleryId || !is_string($relative)
                    || $relative === '' || normalize_relative_path($relative) !== $relative
                    || !is_string($visibility)
                    || !in_array($visibility, ['public', 'private', 'unpublished', 'draft'], true)
                    || $revision === '') {
                    throw new RuntimeException('Visibility inspection row is unavailable.');
                }
                $root = realpath(galleries_root());
                if (!is_string($root) || !is_dir($root)) {
                    throw new RuntimeException('Visibility inspection root is unavailable.');
                }
                $folder = $root;
                $directories = [];
                foreach (explode('/', $relative) as $part) {
                    $folder .= DIRECTORY_SEPARATOR . $part;
                    clearstatcache(true, $folder);
                    $entry = @lstat($folder);
                    if (!is_array($entry) || is_link($folder) || !is_dir($folder)
                        || realpath($folder) !== $folder) {
                        throw new RuntimeException('Visibility inspection folder is unavailable.');
                    }
                    $directories[] = ['path' => $folder, 'dev' => $entry['dev'], 'ino' => $entry['ino']];
                }
                $folderStat = @lstat($folder);
                if (!is_array($folderStat) || !gallery_filesystem_path_inside_root($folder)) {
                    throw new RuntimeException('Visibility inspection folder is unavailable.');
                }
                $markerPath = $folder . DIRECTORY_SEPARATOR . LINKED_VISIBILITY_PENDING_MARKER;
                $marker = gallery_visibility_inspect_file($markerPath, LINKED_VISIBILITY_MARKER_MAX_BYTES);
                $data = json_decode($marker['content'], true, 512, JSON_THROW_ON_ERROR);
                $fields = [
                    'format', 'gallery_id', 'revision', 'folder_hash',
                    'original_hash', 'private_hash', 'target_hash',
                ];
                $versionTwo = ($data['format'] ?? null) === 'gallery_visibility_pending_v2';
                if ($versionTwo) {
                    $fields[] = 'row_hash';
                }
                if (!is_array($data) || array_keys($data) !== $fields
                    || (!$versionTwo && $data['format'] !== 'gallery_visibility_pending_v1')
                    || json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                        !== $marker['content']
                    || $data['gallery_id'] !== $galleryId || !is_string($data['revision'])
                    || gallery_edit_revision(['edit_revision' => $data['revision']]) === '') {
                    throw new RuntimeException('Visibility inspection marker is invalid.');
                }
                $hashFields = ['folder_hash', 'original_hash', 'private_hash', 'target_hash'];
                if ($versionTwo) {
                    $hashFields[] = 'row_hash';
                }
                foreach ($hashFields as $key) {
                    if (!is_string($data[$key]) || preg_match('/^[a-f0-9]{64}$/D', $data[$key]) !== 1) {
                        throw new RuntimeException('Visibility inspection marker is invalid.');
                    }
                }
                if (!hash_equals($data['folder_hash'], hash('sha256', $relative))
                    || ($revision !== $data['revision']
                        && $revision !== app_linking_visibility_next_revision($data['revision']))) {
                    throw new RuntimeException('Visibility inspection marker is stale.');
                }
                $sidecarPath = $folder . DIRECTORY_SEPARATOR . 'gallery.json';
                $sidecar = gallery_visibility_inspect_file($sidecarPath, LINKED_VISIBILITY_SIDECAR_MAX_BYTES);
                if (!in_array(hash('sha256', $sidecar['content']), [
                    $data['original_hash'], $data['private_hash'], $data['target_hash'],
                ], true)) {
                    throw new RuntimeException('Visibility inspection sidecar is unrecognized.');
                }
                $object = json_decode($sidecar['content'], false, 512, JSON_THROW_ON_ERROR);
                $sideVisibility = $object instanceof \stdClass ? ($object->visibility ?? null) : null;
                if (!is_string($sideVisibility)
                    || !in_array($sideVisibility, ['public', 'private', 'unpublished', 'draft'], true)) {
                    throw new RuntimeException('Visibility inspection sidecar is invalid.');
                }
                gallery_sidecar_visibility_replacement($sidecar['content'], $sideVisibility, $sideVisibility);
                $proof = [
                    'path' => $markerPath, 'dev' => $marker['dev'], 'ino' => $marker['ino'],
                    'folder_dev' => $folderStat['dev'], 'folder_ino' => $folderStat['ino'],
                    'content' => $marker['content'],
                ];
                gallery_visibility_marker_assert_owned($proof);
                foreach ($directories as $directory) {
                    clearstatcache(true, $directory['path']);
                    $entry = @lstat($directory['path']);
                    if (!is_array($entry) || is_link($directory['path'])
                        || realpath($directory['path']) !== $directory['path']
                        || $entry['dev'] !== $directory['dev'] || $entry['ino'] !== $directory['ino']) {
                        throw new RuntimeException('Visibility inspection folder changed.');
                    }
                }
                if (gallery_visibility_inspect_file($sidecarPath, LINKED_VISIBILITY_SIDECAR_MAX_BYTES) !== $sidecar
                    || !\Gallery\Models\admin_operation_model_lock_owned($writer)) {
                    throw new RuntimeException('Visibility inspection ownership was lost.');
                }
                $expectedHash = $data[$revision === $data['revision'] ? 'original_hash' : 'target_hash'];
                $replacement = gallery_sidecar_visibility_replacement(
                    $sidecar['content'], $sideVisibility, $visibility,
                );
                // Historical v1 markers lack the unchanged-row witness and
                // remain inspectable without permitting automated repair.
                $fingerprint = $versionTwo
                    && hash_equals($data['row_hash'], gallery_visibility_row_hash($row))
                    && hash_equals($expectedHash, hash('sha256', $replacement))
                    ? hash('sha256', serialize([$marker, $sidecar, $revision, $visibility]))
                    : null;
                if ($reviewed !== null) {
                    if ($fingerprint === null || !hash_equals($fingerprint, $reviewed)) {
                        throw new RuntimeException('Reviewed visibility evidence changed.');
                    }
                    if ($sidecar['content'] !== $replacement) {
                        $sidecarRow = ['folder_path' => $relative, 'visibility' => $sideVisibility];
                        $prepared = gallery_sidecar_visibility_prepare($sidecarRow, $visibility);
                        if ($prepared['original'] !== $sidecar['content']
                            || $prepared['replacement'] !== $replacement
                            || !\Gallery\Models\admin_operation_model_lock_owned($writer)) {
                            throw new RuntimeException('Visibility reconciliation lost ownership.');
                        }
                        gallery_visibility_marker_assert_owned($proof);
                        gallery_sidecar_visibility_publish($prepared);
                    }
                    gallery_visibility_marker_finish($proof, $visibility, $expectedHash, $writer);
                    $sideVisibility = $visibility;
                    $fingerprint = null;
                }
                return ['gallery_id' => $galleryId, 'database_visibility' => $visibility,
                    'sidecar_visibility' => $sideVisibility,
                    'sidecar_matches_database' => $visibility === $sideVisibility,
                    'marker_retained' => $reviewed === null,
                    'revision' => $revision, 'review_fingerprint' => $fingerprint];
            },
        );
    } finally {
        gallery_edit_writer_end($writer);
    }
}
