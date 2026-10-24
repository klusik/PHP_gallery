<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/music_repository.php
 * Module Type: Service
 * Purpose: Store and resolve immutable central music originals.
 * Responsibilities: Confine repository paths, verify bounded copies and
 *     coordinate durable asset metadata.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/../models/music_catalog.php';
require_once __DIR__ . '/schema_inspection.php';
require_once __DIR__ . '/presentation_schema_policy.php';
require_once __DIR__ . '/gallery_pdf_settings.php';
require_once __DIR__ . '/../runtime/storage_observer.php';

use function Gallery\Core\storage_observer_call;

/** Bound one centrally retained audio original.
 * Type: int. Units: bytes. Scope: one immutable repository asset.
 * Consumers: music_repository_store_source.
 * Rationale: 64 MiB matches existing upload limits and bounds copy work.
 * Value: 64 MiB per source asset.
 */
const MUSIC_REPOSITORY_MAX_BYTES = 64 * 1024 * 1024;

/** Keep centrally stored originals private to the hosting account.
 * Type: int. Units: POSIX permission bits. Scope: repository files.
 * Consumers: music_repository_store_source.
 * Rationale: Mode 0600 prevents other host accounts reading originals.
 * Value: POSIX mode 0600.
 */
const MUSIC_REPOSITORY_FILE_MODE = 0600;

/** Create the central repository with private directory permissions.
 * Type: int. Units: POSIX permission bits. Scope: central music directory.
 * Consumers: music_repository_directory.
 * Rationale: Mode 0700 restricts traversal to the hosting account.
 * Value: POSIX mode 0700.
 */
const MUSIC_REPOSITORY_DIRECTORY_MODE = 0700;

/** Resolve and validate the private central music directory.
 * @param bool $create Whether a trusted writer may create a missing directory.
 * @return string Existing absolute directory confined below project data.
 * @throws \RuntimeException When project data or repository paths are unsafe.
 */
function music_repository_directory(bool $create = false): string
{
    $projectRoot = dirname(__DIR__, 2);
    $dataPath = $projectRoot . DIRECTORY_SEPARATOR . 'data';
    $repositoryPath = $dataPath . DIRECTORY_SEPARATOR . 'music';
    $root = storage_observer_call(
        'fs.realpath',
        static fn(): string|false => realpath($projectRoot),
    );
    $data = storage_observer_call(
        'fs.realpath',
        static fn(): string|false => realpath($dataPath),
    );
    if (
        $root === false
        || $data === false
        || storage_observer_call(
            'fs.is_link',
            static fn(): bool => is_link($dataPath),
        )
        || !is_dir($data)
        || !\Gallery\Core\path_inside($root, $data)
        || is_link($repositoryPath)
    ) {
        throw new \RuntimeException('Music repository is unavailable.');
    }
    if (!is_dir($repositoryPath)) {
        if (!$create || (!@mkdir($repositoryPath, MUSIC_REPOSITORY_DIRECTORY_MODE)
            && !is_dir($repositoryPath))) {
            throw new \RuntimeException('Music repository is unavailable.');
        }
    }
    $resolved = storage_observer_call(
        'fs.realpath',
        static fn(): string|false => realpath($repositoryPath),
    );
    if (
        $resolved === false
        || storage_observer_call(
            'fs.is_link',
            static fn(): bool => is_link($repositoryPath),
        )
        || !\Gallery\Core\path_inside($data, $resolved)
    ) {
        throw new \RuntimeException('Music repository is unsafe.');
    }
    return $resolved;
}

/** Store and register one verified immutable original.
 * @param string $source Existing bounded source file.
 * @param string $filename Original display filename, never a storage path.
 * @param int|null $songId Existing logical song identity, or null to allocate one.
 * @param array{gallery_id:int,track_id:string}|null $origin Stable legacy source identity for idempotent promotion.
 * @param string $indexedTitle Saved source title; empty uses the original filename.
 * @return array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null} Registered asset metadata.
 * @throws \RuntimeException When validation, copy verification or schema persistence fails.
 */
function music_repository_store_source(
    string $source,
    string $filename,
    ?int $songId = null,
    ?array $origin = null,
    string $indexedTitle = '',
): array {
    if (!function_exists(__NAMESPACE__ . '\\gallery_music_validate_source')) {
        require_once __DIR__ . '/gallery_music.php';
    }
    $validated = gallery_music_validate_source($source, $filename);
    $indexedTitle = $indexedTitle === ''
        ? $validated['filename']
        : $indexedTitle;
    if (
        strlen($indexedTitle) > GALLERY_MUSIC_LABEL_BYTES
        || preg_match('//u', $indexedTitle) !== 1
        || preg_match('/[\x00-\x1f\x7f]/u', $indexedTitle) === 1
    ) {
        throw new \RuntimeException('Invalid indexed music title.');
    }
    music_repository_assert_schema_ready('music_repository.store_asset');
    $directory = music_repository_directory(true);
    $assetId = bin2hex(random_bytes(16));
    $target = $directory . DIRECTORY_SEPARATOR . $assetId . '.bin';
    $stage = $directory . DIRECTORY_SEPARATOR . '.' . $assetId . '.tmp';
    // PHP cannot express resource|false natively; retain each original stream.
    $input = storage_observer_call(
        'fs.open.music_asset_source',
        static fn(): mixed => @fopen($source, 'rb'),
    );
    $output = storage_observer_call(
        'fs.open.music_asset_stage',
        static fn(): mixed => @fopen($stage, 'xb'),
    );
    if ($input === false || $output === false) {
        if (is_resource($input)) {
            fclose($input);
        }
        if (is_resource($output)) {
            fclose($output);
        }
        if (is_file($stage)) {
            @unlink($stage);
        }
        throw new \RuntimeException('Music repository staging failed.');
    }
    try {
        @chmod($stage, MUSIC_REPOSITORY_FILE_MODE);
        $copied = stream_copy_to_stream(
            $input,
            $output,
            MUSIC_REPOSITORY_MAX_BYTES + 1,
        );
        if ($copied !== $validated['size'] || !fflush($output)) {
            throw new \RuntimeException('Music repository copy was incomplete.');
        }
    } catch (\Throwable $error) {
        @unlink($stage);
        throw $error;
    } finally {
        fclose($input);
        fclose($output);
    }
    $digest = storage_observer_call(
        'fs.hash.music_asset_stage',
        static fn(): string|false => @hash_file('sha256', $stage),
    );
    if (!is_string($digest) || !hash_equals($validated['sha256'], $digest)) {
        @unlink($stage);
        throw new \RuntimeException('Music repository copy verification failed.');
    }
    if (file_exists($target) || is_link($target) || !@rename($stage, $target)) {
        @unlink($stage);
        throw new \RuntimeException('Music repository publication failed.');
    }
    @chmod($target, MUSIC_REPOSITORY_FILE_MODE);
    $metadata = [
        'id' => $assetId,
        'filename' => $validated['filename'],
        'indexed_title' => $indexedTitle,
        'mime' => $validated['mime'],
        'byte_size' => $validated['size'],
        'sha256' => $validated['sha256'],
    ];
    try {
        $registered = \Gallery\Models\music_repository_model_register_asset(
            $metadata,
            $songId,
            $origin,
        );
    } catch (\Throwable $error) {
        // A commit error can be ambiguous; preserve bytes if a row may exist.
        $registrationStateKnown = false;
        $registeredAsset = null;
        try {
            $registeredAsset = \Gallery\Models\music_repository_model_asset(
                $assetId,
            );
            $registrationStateKnown = true;
        } catch (\Throwable) {
            // An unreadable database cannot prove this file is unregistered.
        }
        if (
            $registrationStateKnown
            && $registeredAsset === null
            && !is_link($target)
            && is_file($target)
            && @filesize($target) === $validated['size']
        ) {
            $targetDigest = storage_observer_call(
                'fs.hash.music_asset_unregistered',
                static fn(): string|false => @hash_file('sha256', $target),
            );
            if (
                is_string($targetDigest)
                && hash_equals($validated['sha256'], $targetDigest)
            ) {
                storage_observer_call(
                    'fs.unlink.music_asset_unregistered',
                    static fn(): bool => @unlink($target),
                );
            }
        }
        throw $error;
    }
    gallery_attachment_storage_changed();
    if ($registered['id'] !== $assetId && is_file($target) && !is_link($target)) {
        @unlink($target);
    }
    return $registered;
}

/** Resolve one registered asset to its verified confined original path.
 * @param array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string} $asset Persisted asset row.
 * @return string|null Verified absolute path, or null when missing or altered.
 */
function music_repository_asset_path(array $asset): ?string
{
    $assetId = (string) ($asset['id'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/D', $assetId) !== 1) {
        return null;
    }
    try {
        $directory = music_repository_directory();
    } catch (\Throwable) {
        return null;
    }
    $path = $directory . DIRECTORY_SEPARATOR . $assetId . '.bin';
    if (
        storage_observer_call(
            'fs.is_link',
            static fn(): bool => is_link($path),
        )
        || !storage_observer_call(
            'fs.is_file',
            static fn(): bool => is_file($path),
        )
        || storage_observer_call(
            'fs.size.music_asset',
            static fn(): int|false => @filesize($path),
        ) !== (int) ($asset['byte_size'] ?? 0)
    ) {
        return null;
    }
    $digest = storage_observer_call(
        'fs.hash.music_asset',
        static fn(): string|false => @hash_file('sha256', $path),
    );
    if (
        !is_string($digest)
        || !hash_equals((string) ($asset['sha256'] ?? ''), $digest)
    ) {
        return null;
    }
    return $path;
}

/** Resolve a song for an already-authorized administrator's read-only preview.
 * No promotion, copy, assignment or catalog reconciliation occurs here.
 * @param int $songId Positive canonical catalog song identity.
 * @return array{path:string,track:array{filename:string,mime:string,size:int}}|null
 *     Verified original metadata, or null when no readable original exists.
 * @throws \RuntimeException When the repository schema cannot be verified.
 */
function music_repository_preview_song(int $songId): ?array
{
    if ($songId < 1) {
        return null;
    }
    music_repository_assert_schema_ready('music_repository.preview_song');
    foreach (\Gallery\Models\music_repository_model_assets_for_song($songId) as $asset) {
        $path = music_repository_asset_path($asset);
        if ($path !== null) {
            return [
                'path' => $path,
                'track' => [
                    'filename' => $asset['filename'],
                    'mime' => $asset['mime'],
                    'size' => (int) $asset['byte_size'],
                ],
            ];
        }
    }
    foreach (\Gallery\Models\music_repository_model_song_sources($songId) as $source) {
        $document = gallery_music_track([
            'id' => (int) $source['gallery_id'],
            'folder_path' => (string) $source['folder_path'],
        ], (string) $source['track_id']);
        if ($document !== null) {
            return $document;
        }
    }
    return null;
}

/** Promote a song from a verified existing repository asset or legacy source.
 * @param int $songId Existing logical song identity.
 * @return array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null} Usable central asset metadata.
 * @throws \RuntimeException With music.source_reconcile_required when a dirty
 *     legacy snapshot withholds a candidate, or music.original_unavailable
 *     when no verified central or physical original is readable.
 */
function music_repository_prepare_song(int $songId): array
{
    require_once __DIR__ . '/gallery_music.php';
    if ($songId < 1) {
        throw new \InvalidArgumentException('Invalid music song identity.');
    }
    music_repository_assert_schema_ready('music_repository.prepare_song');
    $assets = \Gallery\Models\music_repository_model_assets_for_song($songId);
    foreach ($assets as $asset) {
        if (music_repository_asset_path($asset) !== null) {
            return $asset;
        }
    }
    foreach (\Gallery\Models\music_repository_model_song_sources($songId) as $source) {
        $gallery = [
            'id' => (int) $source['gallery_id'],
            'folder_path' => (string) $source['folder_path'],
        ];
        try {
            $lock = gallery_music_acquire_lock($gallery, true);
        } catch (\RuntimeException) {
            continue;
        }
        try {
            $state = gallery_music_playlist($gallery);
            foreach ($state['tracks'] as $track) {
                if ($track['id'] !== $source['track_id']) {
                    continue;
                }
                $path = gallery_music_directory($gallery)
                    . DIRECTORY_SEPARATOR . $track['id'] . '.bin';
                if (
                    isset($track['asset_id'])
                    || is_link($path)
                    || !is_file($path)
                    || @filesize($path) !== $track['size']
                    || !hash_equals(
                        $track['sha256'],
                        (string) @hash_file('sha256', $path),
                    )
                ) {
                    continue 2;
                }
                $origin = [
                    'gallery_id' => (int) $source['gallery_id'],
                    'track_id' => (string) $source['track_id'],
                ];
                $asset = music_repository_store_source(
                    $path,
                    (string) $track['filename'],
                    $songId,
                    $origin,
                    (string) $track['title'],
                );
                if (music_repository_asset_path($asset) !== null) {
                    return $asset;
                }
                break;
            }
        } finally {
            gallery_music_release_lock($lock);
        }
    }
    if (\Gallery\Models\music_repository_model_song_has_dirty_sources($songId)) {
        throw new \RuntimeException('music.source_reconcile_required');
    }
    throw new \RuntimeException('music.original_unavailable');
}
