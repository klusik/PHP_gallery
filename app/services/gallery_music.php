<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_music.php
 * Module Type: Service
 * Purpose: Own bounded gallery audio attachments and ordered playlist
 *     mutations.
 * Responsibilities: Validate audio, confine files and serialize revisioned
 *     writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/../models/gallery_music.php';
require_once __DIR__ . '/../models/music_catalog.php';
require_once __DIR__ . '/gallery_pdf_settings.php';
require_once __DIR__ . '/music_catalog.php';

/**
 * Bound one original audio upload without transcoding or decoding on the
 *     server.
 * Type: int. Units: bytes. Scope: one track.
 * Consumers: gallery_music_validate_source.
 * Rationale: 64 MiB accommodates ordinary music while bounding shared-host
 *     work.
 */
const GALLERY_MUSIC_MAX_BYTES = 64 * 1024 * 1024;

/**
 * Bound playlist metadata and traversal independently of individual audio
 *     sizes.
 * Type: int. Units: tracks. Scope: one physical gallery.
 * Consumers: gallery_music_mutate.
 * Rationale: 100 tracks support a soundtrack without an unbounded public list.
 */
const GALLERY_MUSIC_MAX_TRACKS = 100;

/**
 * Bound retained indexed originals per gallery.
 * Type: int. Units: bytes. Scope: one physical gallery playlist.
 * Consumers: gallery_music_mutate.
 * Rationale: 512 MiB limits shared-host storage even for many large originals.
 */
const GALLERY_MUSIC_TOTAL_BYTES = 512 * 1024 * 1024;

/**
 * Bound user-facing metadata independently of filesystem paths.
 * Type: int. Units: UTF-8 bytes. Scope: one filename or title.
 * Consumers: gallery_music_validate_source.
 * Rationale: 240 bytes fit compact tables and bounded playlist JSON records.
 */
const GALLERY_MUSIC_LABEL_BYTES = 240;

/**
 * Bound normalized catalog lookups for one merged Smart Gallery playlist.
 * Type: int. Units: tracks. Scope: one SQL catalog-title lookup batch.
 * Consumers: gallery_music_merge_playlists.
 * Rationale: 100 references keep the query bounded while covering one playlist.
 */
const GALLERY_MUSIC_CATALOG_BATCH = 100;

/**
 * Keep gallery audio sidecars private to the hosting account.
 * Type: int. Units: POSIX permission bits. Scope: one gallery music directory.
 * Consumers: gallery_music_mutate sidecar creation.
 * Rationale: Mode 0700 prevents other host accounts traversing retained audio.
 */
const GALLERY_MUSIC_DIRECTORY_MODE = 0700;

/**
 * Resolve music storage through the canonical confined gallery path owner.
 * @param array{id:int, folder_path:string} $gallery Physical gallery
 *     identity/path.
 * @return string Confined music directory, which may not yet exist.
 * @throws \RuntimeException When the gallery or sidecar is unsafe/unavailable.
 */
function gallery_music_directory(array $gallery): string
{
    $candidate = gallery_abs_path($gallery['folder_path']);
    $root = realpath($candidate);
    $base = realpath(galleries_root());
    if (
        $gallery['id'] < 1
        || $gallery['folder_path'] === ''
        || $root === false
        || $base === false
        || !is_dir($root)
        || is_link($candidate)
        || !\Gallery\Core\path_inside($base, $root)
    ) {
        throw new \RuntimeException('Unavailable gallery.');
    }
    $directory = $root . '/.gallery-music';
    if (
        is_link($directory)
        || (
            file_exists($directory)
            && !is_dir($directory)
        )
    ) {
        throw new \RuntimeException('Unsafe music storage.');
    }
    return $directory;
}

/**
 * Describe accepted extensions and their content-detected audio MIME variants.
 * @return array<string, array{mime:string, detected:list<string>}> Closed
 *     browser audio container policy; codecs remain browser-dependent.
 */
function gallery_music_formats(): array
{
    return [
        'mp3' => [
            'mime' => 'audio/mpeg',
            'detected' => ['audio/mpeg', 'audio/mp3'],
        ],
        'm4a' => [
            'mime' => 'audio/mp4',
            'detected' => ['audio/mp4', 'audio/x-m4a', 'video/mp4'],
        ],
        'aac' => [
            'mime' => 'audio/aac',
            'detected' => ['audio/aac', 'audio/x-hx-aac-adts', 'audio/x-aac'],
        ],
        'ogg' => [
            'mime' => 'audio/ogg',
            'detected' => ['audio/ogg', 'application/ogg'],
        ],
        'oga' => [
            'mime' => 'audio/ogg',
            'detected' => ['audio/ogg', 'application/ogg'],
        ],
        'opus' => [
            'mime' => 'audio/ogg',
            'detected' => ['audio/ogg', 'application/ogg'],
        ],
        'wav' => [
            'mime' => 'audio/wav',
            'detected' => ['audio/wav', 'audio/x-wav', 'audio/vnd.wave'],
        ],
        'flac' => [
            'mime' => 'audio/flac',
            'detected' => ['audio/flac', 'audio/x-flac'],
        ],
        'webm' => [
            'mime' => 'audio/webm',
            'detected' => ['audio/webm', 'video/webm'],
        ],
        'weba' => [
            'mime' => 'audio/webm',
            'detected' => ['audio/webm', 'video/webm'],
        ],
    ];
}

/**
 * Validate original audio using server-detected content and a matching
 *     extension.
 * @param string $source Local upload temporary path supplied by the controller.
 * @param string $filename Original display filename, never used as a storage
 *     path.
 * @return array{filename:string, mime:string, size:int, sha256:string}
 *     Bounded validated source metadata; upload admission assigns permission.
 * @throws \RuntimeException When content, size, filename or detection is
 *     invalid.
 */
function gallery_music_validate_source(string $source, string $filename): array
{
    $filename = basename(str_replace('\\', '/', $filename));
    $format =
        gallery_music_formats()[strtolower(pathinfo(
            $filename,
            PATHINFO_EXTENSION,
        ))] ?? null;
    $size = @filesize($source);
    if (
        !is_file($source)
        || is_link($source)
        || $size === false
        || $size < 1
        || $size > GALLERY_MUSIC_MAX_BYTES
        || $filename === ''
        || strlen($filename) > GALLERY_MUSIC_LABEL_BYTES
        || preg_match('/[\x00-\x1f\x7f]/u', $filename) !== 0
        || $format === null
        || !class_exists(\finfo::class)
    ) {
        throw new \RuntimeException(t('music.invalid_file'));
    }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
    if (!in_array($mime, $format['detected'], true)) {
        throw new \RuntimeException(t('music.invalid_file'));
    }
    $digest = hash_file('sha256', $source);
    if (!is_string($digest))
        throw new \RuntimeException(t('music.failed'));
    return [
        'filename' => $filename,
        'mime' => $format['mime'],
        'size' => $size,
        'sha256' => $digest,
    ];
}

/**
 * Read the persisted ordered playlist without silently repairing damaged state.
 * @param array{id:int, folder_path:string} $gallery Physical gallery
 *     identity/path.
 * @return array{revision:string, enabled:bool, tracks:list<array{id:string,
 *     filename:string, title:string, mime:string, size:int, sha256:string,
 *     hidden:bool, downloadable:bool}>} Complete playlist and slideshow default.
 */
function gallery_music_playlist(array $gallery): array
{
    return \Gallery\Models\gallery_music_model_read(gallery_music_directory(
        $gallery,
    ));
}

/** Acquire the per-gallery playlist lock for a stable external snapshot.
 * PHP has no native resource type; the documented stream handle is the contract.
 * @param array{id:int,folder_path:string} $gallery Physical gallery identity/path.
 * @return resource Open exclusive lock handle; caller must release it.
 * @throws \RuntimeException When the gallery lock is unsafe or unavailable.
 */
function gallery_music_acquire_lock(array $gallery): mixed
{
    $directory = gallery_music_directory($gallery);
    if (!is_dir($directory) && !@mkdir($directory, GALLERY_MUSIC_DIRECTORY_MODE)
        && !is_dir($directory)) {
        throw new \RuntimeException(t('music.failed'));
    }
    if (is_link($directory) || is_link($directory . '/.lock')) {
        throw new \RuntimeException(t('music.failed'));
    }
    $lock = @fopen($directory . '/.lock', 'c');
    if ($lock === false) {
        throw new \RuntimeException(t('music.failed'));
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new \RuntimeException(t('music.failed'));
        }
        return $lock;
    } catch (\Throwable $error) {
        fclose($lock);
        throw $error;
    }
}

/** Release a playlist lock acquired for snapshot reconciliation.
 * PHP has no native resource parameter type; the handle is checked before use.
 * @param resource $lock Open locked playlist handle.
 * @return void Unlock and close the handle.
 */
function gallery_music_release_lock(mixed $lock): void
{
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Keep track visibility independent of permission to download the original.
 * @param array{hidden?:bool} $track Normalized visibility or an older record.
 * @return bool Whether visitors may see/play this track after source access.
 */
function gallery_music_track_visible(array $track): bool
{
    return empty($track['hidden']);
}

/**
 * Merge visible tracks from an already authorized physical-source union.
 * @param list<array{id:int,folder_path:string}> $sources Complete canonical
 *     Smart Gallery sources, independent of photo pagination/view filtering.
 * @return array{revision:string,enabled:bool,unavailable:int,
 *     tracks:list<array{source_gallery_id:int,id:string,filename:string,
 *     title:string,mime:string,size:int,sha256:string,hidden:bool,
 *     downloadable:bool}>} Stable source/track order with each connected
 *     song/digest represented once, and unreadable indexes counted without
 *     modifying storage.
 */
function gallery_music_merge_playlists(array $sources): array
{
    usort(
        $sources,
        static fn(array $left, array $right): int => (
            $left['id'] <=> $right['id']
        ),
    );
    $tracks = [];
    $versions = [];
    $visibleTracks = [];
    $unavailable = 0;
    foreach ($sources as $source) {
        $galleryId = $source['id'];
        if ($galleryId <= 0 || isset($versions[$galleryId]))
            continue;
        try {
            $playlist = gallery_music_playlist($source);
        } catch (\RuntimeException) {
            // Independent optional sources remain usable when one index is
            // damaged. Preserve its bytes; this service owns this permanent
            // omission rather than inventing an empty writable playlist.
            $unavailable += 1;
            $versions[$galleryId] = 'unavailable';
            continue;
        }
        $versions[$galleryId] = $playlist['revision'];
        foreach ($playlist['tracks'] as $track) {
            if (!gallery_music_track_visible($track))
                continue;
            $visibleTracks[] = ['source_gallery_id' => $galleryId] + $track;
        }
    }
    $catalogRows = [];
    foreach (array_chunk($visibleTracks, GALLERY_MUSIC_CATALOG_BATCH) as $batch) {
        $references = [];
        foreach ($batch as $track) {
            $references[] = [
                'gallery_id' => (int) $track['source_gallery_id'],
                'track_id' => $track['id'],
            ];
        }
        try {
            $catalogRows += music_catalog_titles_for_references($references);
        } catch (\Throwable) {
            // Catalog identity is optional for playback; digest grouping below
            // still removes byte-identical copies without inventing song IDs.
        }
    }

    // Union both independent keys before choosing representatives. This keeps
    // a shared digest from hiding a song-ID bridge to a later visible copy.
    $parents = array_keys($visibleTracks);
    $rank = array_fill(0, count($visibleTracks), 0);
    $keyOwners = [];
    foreach ($visibleTracks as $position => $track) {
        $reference = (int) $track['source_gallery_id'] . ':' . $track['id'];
        $songId = (int) ($catalogRows[$reference]['song_id'] ?? 0);
        $keys = ['sha:' . $track['sha256']];
        if ($songId > 0) {
            $keys[] = 'song:' . $songId;
        }
        foreach ($keys as $key) {
            if (isset($keyOwners[$key])) {
                $leftRoot = $position;
                while ($parents[$leftRoot] !== $leftRoot) {
                    $parents[$leftRoot] = $parents[$parents[$leftRoot]];
                    $leftRoot = $parents[$leftRoot];
                }
                $rightRoot = $keyOwners[$key];
                while ($parents[$rightRoot] !== $rightRoot) {
                    $parents[$rightRoot] = $parents[$parents[$rightRoot]];
                    $rightRoot = $parents[$rightRoot];
                }
                if ($leftRoot !== $rightRoot) {
                    if ($rank[$leftRoot] < $rank[$rightRoot]) {
                        [$leftRoot, $rightRoot] = [$rightRoot, $leftRoot];
                    }
                    $parents[$rightRoot] = $leftRoot;
                    if ($rank[$leftRoot] === $rank[$rightRoot]) {
                        $rank[$leftRoot] += 1;
                    }
                }
            } else {
                $keyOwners[$key] = $position;
            }
        }
    }

    $identityTracks = [];
    $componentPositions = [];
    $identityFingerprint = [];
    foreach ($visibleTracks as $position => $track) {
        $root = $position;
        while ($parents[$root] !== $root) {
            $parents[$root] = $parents[$parents[$root]];
            $root = $parents[$root];
        }
        $reference = (int) $track['source_gallery_id'] . ':' . $track['id'];
        $catalogRow = $catalogRows[$reference] ?? [];
        $identityFingerprint[] = [
            $reference,
            (int) ($catalogRow['song_id'] ?? 0),
            (string) ($catalogRow['custom'] ?? ''),
            (string) ($catalogRow['indexed'] ?? ''),
        ];
        if (!isset($componentPositions[$root])) {
            $componentPositions[$root] = count($identityTracks);
            $identityTracks[] = $track;
            continue;
        }
        $representative = $componentPositions[$root];
        // Keep the first source-order item unless an authorized visible copy
        // grants download access, in which case its own URL remains protected.
        if (
            $track['downloadable']
            && !$identityTracks[$representative]['downloadable']
        ) {
            $identityTracks[$representative] = $track;
        }
    }
    try {
        $catalogRevision = music_catalog_revision();
    } catch (\Throwable) {
        $catalogRevision = 'unavailable';
    }
    return [
        'revision' => hash('sha256', json_encode(
            [
                'sources' => $versions,
                'catalog' => $catalogRevision,
                'identities' => $identityFingerprint,
            ],
            JSON_THROW_ON_ERROR,
        )),
        'enabled' => false,
        'unavailable' => $unavailable,
        'tracks' => $identityTracks,
    ];
}

/**
 * Resolve one indexed original without accepting caller-supplied paths or MIME.
 * @param array{id:int,folder_path:string} $gallery Authorized physical gallery.
 * @param string $id Opaque gallery-scoped track identity.
 * @return array{path:string, track:array{id:string, filename:string,
 *     title:string, mime:string, size:int, sha256:string,
 *     hidden:bool, downloadable:bool}}|null Verified source and metadata, or null when
 *     absent/unsafe.
 */
function gallery_music_track(array $gallery, string $id): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1)
        return null;
    $directory = gallery_music_directory($gallery);
    foreach (gallery_music_playlist($gallery)['tracks'] as $track) {
        if ($track['id'] !== $id)
            continue;
        $path = $directory . '/' . $id . '.bin';
        $extension = strtolower(pathinfo(
            $track['filename'],
            PATHINFO_EXTENSION,
        ));
        $format = gallery_music_formats()[$extension] ?? null;
        if (
            $format === null
            || $format['mime'] !== $track['mime']
            || is_link($path)
            || !is_file($path)
            || @filesize($path) !== $track['size']
            || !hash_equals(
                $track['sha256'],
                (string) @hash_file('sha256', $path),
            )
        ) {
            return null;
        }
        return ['path' => $path, 'track' => $track];
    }
    return null;
}

/**
 * Apply an optimistic, serialized playlist operation with atomic index
 *     publication.
 * @param array{id:int,folder_path:string} $gallery Authorized physical gallery.
 * @param string $revision Last observed playlist revision; stale writes are
 *     refused.
 * @param string $action Upload, delete, previous, next, settings, download
 *     or download_all.
 * @param string $trackId Selected identity, unused for
 *     upload/settings/download_all.
 * @param string $source Uploaded temporary source, required only for upload.
 * @param string $filename Original audio filename, required only for upload.
 * @param bool $enabled Explicit gallery slideshow preference for settings.
 * @param bool $downloadable Explicit download permission for
 *     download/download_all.
 * @return array{revision:string, track_id:?string,catalog_sync_pending:bool} Exact committed revision
 *     and new upload identity, or null identity for non-upload actions;
 *     refusals preserve the prior playlist; pending flags a durable snapshot
 *     marker awaiting reconciliation after the physical operation succeeded.
 * @throws \RuntimeException When validation, quota, revision or storage fails.
 */
function gallery_music_mutate(
    array $gallery,
    string $revision,
    string $action,
    string $trackId = '',
    string $source = '',
    string $filename = '',
    bool $enabled = false,
    bool $downloadable = false,
): array {
    if (!in_array(
        $action,
        [
            'upload',
            'delete',
            'previous',
            'next',
            'settings',
            'download',
            'download_all',
        ],
        true,
    )) {
        throw new \RuntimeException(t('music.failed'));
    }
    $catalogMode = music_catalog_physical_write_mode();
    $validated = $action === 'upload'
        ? gallery_music_validate_source($source, $filename)
        : null;
    $directory = gallery_music_directory($gallery);
    if (
        (!is_dir($directory) && !@mkdir($directory, GALLERY_MUSIC_DIRECTORY_MODE)
            && !is_dir($directory))
        || is_link($directory)
        || is_link($directory . '/.lock')
    ) {
        throw new \RuntimeException(t('music.failed'));
    }
    $lock = @fopen($directory . '/.lock', 'c');
    if ($lock === false)
        throw new \RuntimeException(t('music.failed'));
    @chmod($directory . '/.lock', \Gallery\Models\GALLERY_MUSIC_FILE_MODE);
    $newPath = '';
    $committed = false;
    try {
        if (!flock($lock, LOCK_EX))
            throw new \RuntimeException(t('music.failed'));
        $state = gallery_music_playlist($gallery);
        if (!hash_equals($state['revision'], $revision)) {
            throw new \RuntimeException(t('music.conflict'));
        }
        if ($validated !== null) {
            $total = array_sum(array_column($state['tracks'], 'size'));
            if (
                count($state['tracks']) >= GALLERY_MUSIC_MAX_TRACKS
                || ($total + $validated['size']) > GALLERY_MUSIC_TOTAL_BYTES
            ) {
                throw new \RuntimeException(t('music.quota'));
            }
            // A random 128-bit id names this immutable original; filenames stay metadata.
            $id = bin2hex(random_bytes(16));
            $newPath = $directory . '/' . $id . '.bin';
            $output = @fopen($newPath, 'xb');
            if ($output === false) {
                $newPath = '';
                throw new \RuntimeException(t('music.failed'));
            }
            $input = @fopen($source, 'rb');
            try {
                @chmod($newPath, \Gallery\Models\GALLERY_MUSIC_FILE_MODE);
                if (
                    $input === false
                    || stream_copy_to_stream(
                        $input,
                        $output,
                        GALLERY_MUSIC_MAX_BYTES + 1,
                    ) !== $validated['size']
                    || !fflush($output)
                ) {
                    throw new \RuntimeException(t('music.failed'));
                }
            } finally {
                if (is_resource($input))
                    fclose($input);
                fclose($output);
            }
            if (!hash_equals(
                $validated['sha256'],
                (string) @hash_file('sha256', $newPath),
            )) {
                throw new \RuntimeException(t('music.failed'));
            }
            $state['tracks'][] =
                [
                    'id' => $id,
                    'title' => $validated['filename'],
                    'hidden' => false,
                    'downloadable' => false,
                ] + $validated;
        } elseif ($action === 'settings') {
            $state['enabled'] = $enabled;
        } elseif ($action === 'download_all') {
            foreach ($state['tracks'] as &$track) {
                $track['downloadable'] = $downloadable;
            }
            unset($track);
        } else {
            $position = array_search(
                $trackId,
                array_column($state['tracks'], 'id'),
                true,
            );
            if ($position === false)
                throw new \RuntimeException(t('music.conflict'));
            if ($action === 'delete') {
                array_splice($state['tracks'], $position, 1);
            } elseif ($action === 'download') {
                $state['tracks'][$position]['downloadable'] = $downloadable;
            } else {
                $other = $position + ($action === 'previous' ? -1 : 1);
                if (isset($state['tracks'][$other])) {
                    [$state['tracks'][$position], $state['tracks'][$other]] = [
                        $state['tracks'][$other],
                        $state['tracks'][$position],
                    ];
                }
            }
        }
        $state['revision'] = bin2hex(random_bytes(16));
        $catalogReady = $catalogMode === 'available';
        if ($catalogReady) {
            try {
                \Gallery\Models\music_catalog_model_mark_gallery_dirty(
                    (int) $gallery['id'],
                );
                $catalogRevision = music_catalog_revision();
            } catch (\Throwable $error) {
                error_log('Music catalog dirty marker failed before playlist write.');
                throw new \RuntimeException(t('music.failed'), 0, $error);
            }
        }
        \Gallery\Models\gallery_music_model_write($directory, $state);
        $committed = true;
        gallery_attachment_storage_record(
            (int) $gallery['id'],
            'music',
            $state['tracks'],
        );
        $catalogSyncPending = false;
        if ($catalogReady) {
            try {
                music_catalog_sync_gallery_snapshot(
                    (int) $gallery['id'],
                    $state['tracks'],
                    $catalogRevision,
                );
            } catch (\Throwable) {
                // The durable dirty marker suppresses stale usage until a
                // bounded administrator reconciliation repairs the snapshot.
                $catalogSyncPending = true;
            }
        }
        // Metadata is authoritative: an interrupted unlink leaves an inaccessible
        // orphan rather than a playlist that points to a deleted original.
        if ($action === 'delete') {
            $path = $directory . '/' . $trackId . '.bin';
            if (!is_link($path) && is_file($path))
                @unlink($path);
        }
        return [
            'revision' => $state['revision'],
            'track_id' => $action === 'upload' ? $id : null,
            'catalog_sync_pending' => $catalogSyncPending,
        ];
    } finally {
        if (!$committed && $newPath !== '' && is_file($newPath))
            @unlink($newPath);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Interpret one bounded HTTP byte range for an authorized immutable original.
 * @param string $header Range header, or an empty string for a complete
 *     response.
 * @param int $size Positive original byte size established by the trusted
 *     index.
 * @return array{start:int, end:int, partial:bool}|null Inclusive byte
 *     bounds, or null for malformed/unsatisfiable/multiple ranges.
 */
function gallery_music_byte_range(string $header, int $size): ?array
{
    if ($size < 1)
        return null;
    if ($header === '')
        return ['start' => 0, 'end' => $size - 1, 'partial' => false];
    if (
        preg_match('/^bytes=([0-9]*)-([0-9]*)$/D', trim($header), $match) !== 1
        || (
            $match[1] === ''
            && $match[2] === ''
        )
    )
        return null;
    $start = $match[1] === ''
        ? null
        : filter_var($match[1], FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
        ]]);
    $end = $match[2] === ''
        ? null
        : filter_var($match[2], FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0,
        ]]);
    if ($start === false || $end === false)
        return null;
    if ($start === null) {
        if ($end === null || $end < 1)
            return null;
        $start = max(0, $size - $end);
        $end = $size - 1;
    } else {
        $end = $end === null ? $size - 1 : min($end, $size - 1);
    }
    if ($start >= $size || $end < $start)
        return null;
    return ['start' => $start, 'end' => $end, 'partial' => true];
}
