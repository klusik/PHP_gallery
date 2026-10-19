<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/gallery_music.php
 * Module Type: Model
 * Purpose: Persist the versioned gallery music playlist index.
 * Responsibilities: Validate complete records and atomically replace the index.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

require_once __DIR__ . '/../runtime/storage_observer.php';

use function Gallery\Core\storage_observer_call;

/**
 * Bound playlist JSON allocation before parsing or publishing.
 * Type: int. Units: bytes. Scope: one gallery music index.
 * Consumers: gallery_music_model_read, gallery_music_model_write.
 * Rationale: 256 KiB accommodates the bounded playlist without unbounded reads.
 */
const GALLERY_MUSIC_INDEX_BYTES = 262144;

/**
 * Keep playlist indexes, locks and immutable originals private to the host
 *     user.
 * Type: int. Units: POSIX permission bits. Scope: one gallery music file.
 * Consumers: model staging and service upload/lock publication.
 * Rationale: Mode 0600 prevents other host accounts reading retained originals.
 */
const GALLERY_MUSIC_FILE_MODE = 0600;

/**
 * Map complete playlist records without treating damaged storage as empty.
 * @param string $directory Confined music directory selected by the service.
 * @return array{revision:string, enabled:bool, tracks:list<array{id:string,
 *     filename:string, title:string, mime:string, size:int, sha256:string,
 *     hidden:bool, downloadable:bool}>} Stored playlist, or initial disabled
 *     state when no index exists.
 * @throws \RuntimeException When existing storage cannot be safely interpreted.
 */
function gallery_music_model_read(string $directory): array
{
    $path = $directory . '/index.json';
    if (
        storage_observer_call('fs.is_link', static fn(): bool => is_link(
            $directory,
        ))
        || storage_observer_call('fs.is_link', static fn(): bool => is_link(
            $path,
        ))
    ) {
        throw new \RuntimeException('Unsafe music index.');
    }
    if (!storage_observer_call('fs.file_exists', static fn(): bool => file_exists(
        $path,
    ))) {
        // The all-zero revision is the wire identity of a never-created playlist.
        return [
            'revision' => str_repeat('0', 32),
            'enabled' => false,
            'tracks' => [],
        ];
    }
    $bytes = storage_observer_call('fs.read.music_index', static fn(): string|false => @file_get_contents(
        $path,
        false,
        null,
        0,
        GALLERY_MUSIC_INDEX_BYTES + 1,
    ));
    if (!is_string($bytes) || strlen($bytes) > GALLERY_MUSIC_INDEX_BYTES) {
        throw new \RuntimeException('Unreadable music index.');
    }
    $state = json_decode($bytes, true);
    if (
        !is_array($state)
        || ($state['format'] ?? '') !== 'gallery_music_v1'
        || !is_string($state['revision'] ?? null)
        || preg_match('/^[a-f0-9]{32}$/D', $state['revision']) !== 1
        || !is_bool($state['enabled'] ?? null)
        || !is_array($state['tracks'] ?? null)
        || !array_is_list($state['tracks'])
    ) {
        throw new \RuntimeException('Invalid music index.');
    }
    $seen = [];
    foreach ($state['tracks'] as $track) {
        if (
            !is_array($track)
            || !is_string($track['id'] ?? null)
            || preg_match('/^[a-f0-9]{32}$/D', $track['id']) !== 1
            || isset($seen[$track['id']])
            || !is_string($track['filename'] ?? null)
            || !is_string($track['title'] ?? null)
            || !is_string($track['mime'] ?? null)
            || !is_int($track['size'] ?? null)
            || $track['size'] < 1
            || (
                array_key_exists('hidden', $track)
                && !is_bool($track['hidden'])
            )
            || (
                array_key_exists('downloadable', $track)
                && !is_bool($track['downloadable'])
            )
            || !is_string($track['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $track['sha256']) !== 1
        ) {
            throw new \RuntimeException('Invalid music record.');
        }
        $seen[$track['id']] = true;
    }
    // Permanent v1 compatibility: missing visibility means visible.
    // Future hiding controls remain independent of download permission.
    // Records written before download controls existed grant no download
    // permission. This model owns that safe default.
    // Map both vintages to the same closed field shape and key order so a
    // legacy read and a newly written playlist have identical row semantics.
    $state['tracks'] = array_map(static function (array $track): array {
        return [
            'id' => $track['id'],
            'filename' => $track['filename'],
            'title' => $track['title'],
            'mime' => $track['mime'],
            'size' => $track['size'],
            'sha256' => $track['sha256'],
            'hidden' => $track['hidden'] ?? false,
            'downloadable' => $track['downloadable'] ?? false,
        ];
    }, $state['tracks']);
    return [
        'revision' => $state['revision'],
        'enabled' => $state['enabled'],
        'tracks' => $state['tracks'],
    ];
}

/**
 * Publish one playlist while the service holds its exclusive gallery lock.
 * @param string $directory Existing confined music directory.
 * @param array{revision:string, enabled:bool, tracks:list<array{id:string,
 *     filename:string, title:string, mime:string, size:int, sha256:string,
 *     hidden:bool, downloadable:bool}>} $state Complete next playlist.
 * @return void Replace the index atomically, preserving its previous bytes
 *     on failure.
 * @throws \RuntimeException When staging or publication fails.
 */
function gallery_music_model_write(string $directory, array $state): void
{
    $json = json_encode(
        ['format' => 'gallery_music_v1'] + $state,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
    if (
        strlen($json) > GALLERY_MUSIC_INDEX_BYTES
        || is_link($directory)
        || is_link($directory . '/index.json')
    ) {
        throw new \RuntimeException('Unsafe music index publication.');
    }
    // Random 128-bit names provide exclusive staging identities, not a retry policy.
    $stage = $directory . '/.' . bin2hex(random_bytes(16)) . '.tmp';
    $handle = @fopen($stage, 'xb');
    if ($handle === false) {
        throw new \RuntimeException('Music index staging failed.');
    }
    try {
        @chmod($stage, GALLERY_MUSIC_FILE_MODE);
        $written = fwrite($handle, $json);
        if ($written !== strlen($json) || !fflush($handle)) {
            throw new \RuntimeException('Incomplete music index.');
        }
        fclose($handle);
        $handle = null;
        if (!@rename($stage, $directory . '/index.json')) {
            throw new \RuntimeException('Music index publication failed.');
        }
    } finally {
        if (is_resource($handle))
            fclose($handle);
        if (is_file($stage))
            @unlink($stage);
    }
}
