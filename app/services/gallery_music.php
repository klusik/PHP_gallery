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
require_once __DIR__ . '/../models/galleries.php';
require_once __DIR__ . '/gallery_pdf_settings.php';
require_once __DIR__ . '/music_catalog.php';
require_once __DIR__ . '/music_repository.php';
require_once __DIR__ . '/gallery_music/projection.php';
require_once __DIR__ . '/gallery_music/repository.php';

/**
 * Bound one original audio upload without transcoding or decoding on the
 *     server.
 * Type: int. Units: bytes. Scope: one track.
 * Consumers: gallery_music_validate_source.
 * Rationale: 64 MiB accommodates ordinary music while bounding shared-host
 *     work.
 * Value: 64 MiB per original.
 */
const GALLERY_MUSIC_MAX_BYTES = 64 * 1024 * 1024;

/**
 * Bound playlist metadata and traversal independently of individual audio
 *     sizes.
 * Type: int. Units: tracks. Scope: one physical gallery.
 * Consumers: gallery_music_mutate.
 * Rationale: 100 tracks support a soundtrack without an unbounded public list.
 * Value: 100 projected tracks per physical gallery.
 */
const GALLERY_MUSIC_MAX_TRACKS = 100;

/**
 * Bound retained indexed originals per gallery.
 * Type: int. Units: bytes. Scope: one physical gallery playlist.
 * Consumers: gallery_music_mutate.
 * Rationale: 512 MiB limits shared-host storage even for many large originals.
 * Value: 512 MiB of local-playlist originals per gallery.
 */
const GALLERY_MUSIC_TOTAL_BYTES = 512 * 1024 * 1024;

/**
 * Bound user-facing metadata independently of filesystem paths.
 * Type: int. Units: UTF-8 bytes. Scope: one filename or title.
 * Consumers: gallery_music_validate_source.
 * Rationale: 240 bytes fit compact tables and bounded playlist JSON records.
 * Value: 240 UTF-8 bytes per filename or title.
 */
const GALLERY_MUSIC_LABEL_BYTES = 240;

/**
 * Bound normalized catalog lookups for one merged Smart Gallery playlist.
 * Type: int. Units: tracks. Scope: one SQL catalog-title lookup batch.
 * Consumers: gallery_music_merge_playlists.
 * Rationale: 100 references keep the query bounded while covering one playlist.
 * Value: 100 references per SQL query.
 */
const GALLERY_MUSIC_CATALOG_BATCH = 100;

/** Bound live album members read while preparing one gallery projection.
 * Type: int. Units: songs. Scope: one live gallery album assignment.
 * Consumers: gallery_music_playlist, gallery_music_mutate.
 * Rationale: 2,000 matches the catalog node ceiling and bounds joined reads.
 * Value: 2,000 songs per assignment.
 */
const GALLERY_MUSIC_ALBUM_MEMBER_LIMIT = 2000;

/** Bound album membership page size for live gallery projection.
 * Type: int. Units: songs. Scope: one catalog query page.
 * Consumers: gallery_music_playlist, gallery_music_mutate.
 * Rationale: 100 rows keep each joined query within the existing catalog page.
 * Value: 100 songs per query.
 */
const GALLERY_MUSIC_ALBUM_MEMBER_BATCH = 100;

/** Begin an optional music usage snapshot generation before a playlist write.
 * @param int $galleryId Persisted physical gallery identifier.
 * @return string|null Fresh CAS token when schema is available; null for a confirmed legacy schema without the optional tables.
 * @throws \RuntimeException When schema readiness is unknown or dirty marking fails.
 */
function gallery_music_attachment_usage_begin(int $galleryId): ?string
{
    if (
        !function_exists(__NAMESPACE__ . '\\presentation_gallery_attachment_usage_schema_status')
        || !function_exists(__NAMESPACE__ . '\\presentation_schema_assert_known')
        || !function_exists(__NAMESPACE__ . '\\schema_inspection_is_available')
    ) {
        throw new \RuntimeException(
            'Attachment usage schema could not be verified.',
        );
    }
    $status = presentation_gallery_attachment_usage_schema_status();
    presentation_schema_assert_known(
        $status,
        'gallery.music_usage_snapshot',
    );
    if (!schema_inspection_is_available($status)) {
        return null;
    }
    return \Gallery\Models\gallery_model_attachment_usage_mark_dirty(
        $galleryId,
        'music',
    );
}

/** Convert the complete authoritative music state to usage projection rows.
 * @param array{tracks:list<array{id:string,sha256:string,hidden:bool,asset_id?:string}>,albums?:list<array{id:string,album_id:int,hidden:bool}>} $state Raw validated playlist state.
 * @return list<array{source_id:string,sha256:?string,asset_id:?string,album_id:?int,hidden:bool}> Direct digests, canonical assets, and live album assignments.
 */
function gallery_music_attachment_usage_items(array $state): array
{
    $items = [];
    foreach ($state['tracks'] as $track) {
        $assetId = $track['asset_id'] ?? null;
        $items[] = [
            'source_id' => 'track:' . $track['id'],
            'sha256' => is_string($assetId) ? null : $track['sha256'],
            'asset_id' => is_string($assetId) ? $assetId : null,
            'album_id' => null,
            'hidden' => $track['hidden'],
        ];
    }
    foreach ($state['albums'] ?? [] as $album) {
        $items[] = [
            'source_id' => 'album:' . $album['id'],
            'sha256' => null,
            'asset_id' => null,
            'album_id' => $album['album_id'],
            'hidden' => $album['hidden'],
        ];
    }
    return $items;
}

/** Publish a playlist snapshot only while its source generation is current.
 * @param int $galleryId Persisted physical gallery identifier.
 * @param array{tracks:list<array{id:string,sha256:string,hidden:bool,asset_id?:string}>,albums?:list<array{id:string,album_id:int,hidden:bool}>} $state Complete authoritative raw playlist state.
 * @param string|null $revision CAS token from gallery_music_attachment_usage_begin, null for confirmed missing legacy schema.
 * @return bool True when the current generation was published or optional schema is confirmed missing.
 */
function gallery_music_attachment_usage_publish(
    int $galleryId,
    array $state,
    ?string $revision,
): bool {
    if ($revision === null) {
        return true;
    }
    return \Gallery\Models\gallery_model_attachment_usage_replace(
        $galleryId,
        'music',
        gallery_music_attachment_usage_items($state),
        $revision,
    );
}

/** Bound galleries inspected to find one migration source per request.
 * Type: int. Units: galleries. Scope: one explicit repository migration call.
 * Consumers: gallery_music_migrate_repository.
 * Rationale: 20 limits keyset database reads when galleries have no legacy audio.
 * Value: 20 galleries per call.
 */
const GALLERY_MUSIC_MIGRATION_GALLERY_SCAN = 20;

/**
 * Keep gallery audio sidecars private to the hosting account.
 * Type: int. Units: POSIX permission bits. Scope: one gallery music directory.
 * Consumers: gallery_music_mutate sidecar creation.
 * Rationale: Mode 0700 prevents other host accounts traversing retained audio.
 * Value: POSIX mode 0700.
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

/** Acquire the per-gallery playlist lock for a stable external snapshot.
 * PHP has no native resource type; the documented stream handle is the contract.
 * @param array{id:int,folder_path:string} $gallery Physical gallery identity/path.
 * @param bool $nonBlocking Return immediately when another writer owns the lock.
 * @return resource|false Open lock handle; false only when nonblocking and busy.
 * @throws \RuntimeException When the gallery lock is unsafe or unavailable.
 */
function gallery_music_acquire_lock(array $gallery, bool $nonBlocking = false): mixed
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
        $mode = LOCK_EX | ($nonBlocking ? LOCK_NB : 0);
        $wouldBlock = 0;
        if (!flock($lock, $mode, $wouldBlock)) {
            if ($nonBlocking && $wouldBlock !== 0) {
                fclose($lock);
                return false;
            }
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
 * @return array{path:string,track:array{id:string,filename:string,title:string,
 *     mime:string,size:int,sha256:string,hidden:bool,downloadable:bool,
 *     asset_id?:string,source_id?:string,source_type?:string,
 *     source_title?:string,source_track_id?:string}}|null Verified local or
 *     central original and its authorized gallery-scoped delivery metadata.
 */
function gallery_music_track(array $gallery, string $id): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1)
        return null;
    $directory = gallery_music_directory($gallery);
    foreach (gallery_music_playlist($gallery)['tracks'] as $track) {
        if ($track['id'] !== $id)
            continue;
        if (isset($track['asset_id'])) {
            $asset = \Gallery\Models\music_repository_model_asset(
                $track['asset_id'],
            );
            if ($asset === null) {
                return null;
            }
            $path = music_repository_asset_path($asset);
            if ($path === null) {
                return null;
            }
            return ['path' => $path, 'track' => $track];
        }
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
 * @param string $action Upload, assignment, ordering, settings or permission
 *     operation for one source group.
 * @param string $trackId Selected identity, unused for
 *     upload/settings/download_all.
 * @param string $source Uploaded temporary source, required only for upload.
 * @param string $filename Original audio filename, required only for upload.
 * @param bool $enabled Explicit gallery slideshow preference for settings.
 * @param bool $downloadable Explicit download permission for
 *     download/download_all.
 * @param int $selectionId Catalog song or album identity for add_song/add_album.
 * @param int|null $position Optional zero-based source position for reorder_source.
 * @param string $catalogRevision Catalog revision displayed with assignment choices.
 * @return array{revision:string,track_id:?string,catalog_sync_pending:bool,usage_sync_pending:bool} Exact committed revision
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
    int $selectionId = 0,
    ?int $position = null,
    string $catalogRevision = '',
): array {
    if (!in_array(
        $action,
        [
            'upload',
            'add_song',
            'add_album',
            'reorder_source',
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
    $preparedAsset = null;
    $preparedAlbumAssets = [];
    $preparedAlbumBytes = 0;
    $preparedAlbumIds = [];
    $validatedUpload = null;
    if (in_array($action, ['upload', 'add_song', 'add_album'], true)) {
        music_repository_assert_schema_ready('gallery_music.mutate');
    }
    if ($action === 'upload') {
        $validatedUpload = gallery_music_validate_source($source, $filename);
    }
    if (in_array($action, ['add_song', 'add_album'], true)) {
        if (
            $selectionId < 1
            || $catalogRevision === ''
            || !hash_equals(music_catalog_revision(), $catalogRevision)
        ) {
            throw new \RuntimeException(t('music.conflict'));
        }
        $preflightState = gallery_music_state($gallery);
        if (!hash_equals($preflightState['write_revision'], $revision)) {
            throw new \RuntimeException(t('music.conflict'));
        }
        if ($action === 'add_song') {
            $preparedAsset = music_repository_prepare_song($selectionId);
        } else {
            $currentProjection = gallery_music_playlist($gallery);
            $albumMetadata = music_catalog_album_paths([$selectionId])[$selectionId] ?? null;
            if (
                !is_array($albumMetadata)
                || !$albumMetadata['available']
                || !empty($currentProjection['partial'])
                || !empty($currentProjection['over_limit'])
                || (int) ($currentProjection['unavailable'] ?? 0) > 0
                || count($currentProjection['tracks']) + (int) $albumMetadata['song_count']
                    > GALLERY_MUSIC_MAX_TRACKS
                || array_sum(array_column($currentProjection['tracks'], 'size'))
                    >= GALLERY_MUSIC_TOTAL_BYTES
            ) {
                throw new \RuntimeException(t('music.quota'));
            }
            $remainingBytes = GALLERY_MUSIC_TOTAL_BYTES
                - array_sum(array_column($currentProjection['tracks'], 'size'));
            if ((int) $albumMetadata['song_count'] > GALLERY_MUSIC_ALBUM_MEMBER_LIMIT) {
                throw new \RuntimeException(t('music.quota'));
            }
            for ($offset = 0; $offset <= GALLERY_MUSIC_ALBUM_MEMBER_LIMIT;
                $offset += GALLERY_MUSIC_ALBUM_MEMBER_BATCH) {
                $page = music_catalog_album_members(
                    $selectionId,
                    GALLERY_MUSIC_ALBUM_MEMBER_BATCH,
                    $offset,
                );
                foreach ($page as $member) {
                    $songId = (int) $member['id'];
                    $preparedAlbumIds[] = $songId;
                    $preparedAlbumAssets[$songId] = music_repository_prepare_song(
                        $songId,
                    );
                    $preparedAlbumBytes += (int) $preparedAlbumAssets[$songId]['byte_size'];
                    if ($preparedAlbumBytes > $remainingBytes) {
                        throw new \RuntimeException(t('music.quota'));
                    }
                }
                if (count($page) < GALLERY_MUSIC_ALBUM_MEMBER_BATCH) {
                    break;
                }
            }
            if (count($preparedAlbumIds) > GALLERY_MUSIC_ALBUM_MEMBER_LIMIT) {
                throw new \RuntimeException(t('music.quota'));
            }
            $currentMembers = [];
            for ($offset = 0; $offset < count($preparedAlbumIds);
                $offset += GALLERY_MUSIC_ALBUM_MEMBER_BATCH) {
                $currentMembers = array_merge(
                    $currentMembers,
                    music_catalog_album_members(
                        $selectionId,
                        min(
                            GALLERY_MUSIC_ALBUM_MEMBER_BATCH,
                            count($preparedAlbumIds) - $offset,
                        ),
                        $offset,
                    ),
                );
            }
            if (array_map(
                static fn(array $member): int => (int) $member['id'],
                $currentMembers,
            ) !== $preparedAlbumIds) {
                throw new \RuntimeException(t('music.conflict'));
            }
        }
        if (!hash_equals(music_catalog_revision(), $catalogRevision)) {
            throw new \RuntimeException(t('music.conflict'));
        }
    }
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
        $state = gallery_music_state($gallery);
        if (!hash_equals($state['write_revision'], $revision)) {
            throw new \RuntimeException(t('music.conflict'));
        }
        if (in_array($action, ['add_song', 'add_album'], true)
            && !hash_equals(
                music_catalog_revision(),
                $catalogRevision,
            )) {
            throw new \RuntimeException(t('music.conflict'));
        }
        if ($action === 'upload' || $action === 'add_song') {
            $projected = gallery_music_playlist($gallery);
            $asset = $action === 'add_song'
                ? $preparedAsset
                : null;
            $incomingBytes = $action === 'add_song'
                ? (int) ($asset['byte_size'] ?? 0)
                : (int) ($validatedUpload['size'] ?? 0);
            if (
                !empty($projected['partial'])
                || !empty($projected['over_limit'])
                || (int) ($projected['unavailable'] ?? 0) > 0
                || count($projected['tracks']) >= GALLERY_MUSIC_MAX_TRACKS
                || array_sum(array_column($projected['tracks'], 'size'))
                    + $incomingBytes > GALLERY_MUSIC_TOTAL_BYTES
            ) {
                throw new \RuntimeException(t('music.quota'));
            }
            if ($action === 'upload') {
                $asset = music_repository_store_source($source, $filename, null);
            }
            if (!is_array($asset)) {
                throw new \RuntimeException(t('music.failed'));
            }
            if (music_repository_asset_path($asset) === null) {
                throw new \RuntimeException(t('music.failed'));
            }
            if (
                array_sum(array_column($projected['tracks'], 'size'))
                    + (int) $asset['byte_size'] > GALLERY_MUSIC_TOTAL_BYTES
            ) {
                throw new \RuntimeException(t('music.quota'));
            }
            $id = bin2hex(random_bytes(16));
            $state['version'] = 2;
            $state['tracks'][] = [
                    'id' => $id,
                    'filename' => (string) $asset['filename'],
                    'title' => (string) $asset['indexed_title'],
                    'mime' => (string) $asset['mime'],
                    'size' => (int) $asset['byte_size'],
                    'sha256' => (string) $asset['sha256'],
                    'hidden' => false,
                    'downloadable' => false,
                    'asset_id' => (string) $asset['id'],
                ];
            $state['order'][] = $id;
        } elseif ($action === 'add_album') {
            $projected = gallery_music_playlist($gallery);
            if (
                count($projected['tracks']) + count($preparedAlbumIds)
                    > GALLERY_MUSIC_MAX_TRACKS
                || array_sum(array_column($projected['tracks'], 'size'))
                    + $preparedAlbumBytes > GALLERY_MUSIC_TOTAL_BYTES
            ) {
                throw new \RuntimeException(t('music.quota'));
            }
            $state['version'] = 2;
            $id = bin2hex(random_bytes(16));
            $state['albums'][] = [
                'id' => $id,
                'album_id' => $selectionId,
                'hidden' => false,
                'downloadable' => false,
            ];
            $state['order'][] = $id;
        } elseif ($action === 'settings') {
            $state['enabled'] = $enabled;
        } elseif ($action === 'download_all') {
            foreach ($state['tracks'] as &$track) {
                $track['downloadable'] = $downloadable;
            }
            unset($track);
            foreach ($state['albums'] as &$album) {
                $album['downloadable'] = $downloadable;
            }
            unset($album);
        } else {
            $sourceId = $trackId;
            $sourcePosition = array_search($sourceId, $state['order'], true);
            if ($sourcePosition === false)
                throw new \RuntimeException(t('music.conflict'));
            $directPosition = array_search($sourceId, array_column($state['tracks'], 'id'), true);
            $albumPosition = array_search($sourceId, array_column($state['albums'], 'id'), true);
            if ($action === 'delete') {
                if ($directPosition !== false) {
                    foreach ($state['legacy_cleanup'] as $entry) {
                        if ($entry['track_id'] === $sourceId) {
                            throw new \RuntimeException(t('music.conflict'));
                        }
                    }
                    array_splice($state['tracks'], $directPosition, 1);
                } elseif ($albumPosition !== false) {
                    array_splice($state['albums'], $albumPosition, 1);
                }
                array_splice($state['order'], $sourcePosition, 1);
            } elseif ($action === 'download') {
                if ($directPosition !== false) {
                    $state['tracks'][$directPosition]['downloadable'] = $downloadable;
                } elseif ($albumPosition !== false) {
                    $state['albums'][$albumPosition]['downloadable'] = $downloadable;
                }
            } elseif ($action === 'reorder_source') {
                if ($position === null || $position < 0 || $position >= count($state['order'])) {
                    throw new \RuntimeException(t('music.conflict'));
                }
                array_splice($state['order'], $sourcePosition, 1);
                array_splice($state['order'], $position, 0, [$sourceId]);
            } else {
                $other = $sourcePosition + ($action === 'previous' ? -1 : 1);
                if (isset($state['order'][$other])) {
                    [$state['order'][$sourcePosition], $state['order'][$other]] = [
                        $state['order'][$other],
                        $state['order'][$sourcePosition],
                    ];
                }
            }
        }
        $state['revision'] = bin2hex(random_bytes(16));
        if ($state['version'] === 1) {
            // Legacy indexes store order in the track list itself. Keep both
            // their wire format and their mutation revision contract until a
            // central source or live album explicitly upgrades the index.
            $tracksById = [];
            foreach ($state['tracks'] as $track) {
                $tracksById[$track['id']] = $track;
            }
            $state['tracks'] = array_map(
                static fn(string $id): array => $tracksById[$id],
                $state['order'],
            );
        }
        $catalogReady = $catalogMode === 'available';
        $usageRevision = null;
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
        try {
            $usageRevision = gallery_music_attachment_usage_begin(
                (int) $gallery['id'],
            );
        } catch (\Throwable $error) {
            error_log('Music attachment usage dirty marker failed before playlist write.');
            throw new \RuntimeException(t('music.failed'), 0, $error);
        }
        \Gallery\Models\gallery_music_model_write($directory, $state);
        $committed = true;
        $usageSyncPending = false;
        try {
            $usageSyncPending = !gallery_music_attachment_usage_publish(
                (int) $gallery['id'],
                $state,
                $usageRevision,
            );
        } catch (\Throwable) {
            $usageSyncPending = $usageRevision !== null;
        }
        gallery_attachment_storage_record(
            (int) $gallery['id'],
            'music',
            gallery_music_accounting_entries($state),
        );
        $catalogSyncPending = false;
        if ($catalogReady) {
            try {
                music_catalog_sync_gallery_snapshot(
                    (int) $gallery['id'],
                    $state['tracks'],
                    $catalogRevision,
                    array_map(
                        static fn(array $album): array => [
                            'id' => $album['id'],
                            'album_id' => $album['album_id'],
                            'hidden' => $album['hidden'],
                            'downloadable' => $album['downloadable'],
                        ],
                        $state['albums'],
                    ),
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
            'track_id' => in_array($action, ['upload', 'add_song', 'add_album'], true)
                ? $id
                : null,
            'catalog_sync_pending' => $catalogSyncPending,
            'usage_sync_pending' => $usageSyncPending,
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
