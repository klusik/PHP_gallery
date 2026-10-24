<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_music/repository.php
 * Module Type: Service implementation part
 * Purpose: Account for and migrate legacy gallery-local music originals.
 * Responsibilities: Publish durable source snapshots and safely retire verified copies.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/** Preserve a catalogued local original before removing its gallery reference.
 * The caller holds the gallery writer lock and has verified its revision.
 * @param array{id:int,folder_path:string} $gallery Locked physical source.
 * @param array{id:string,filename:string,title:string,size:int,sha256:string}
 *     $track Authoritative local track selected for removal.
 * @return void Returns only after the same original is verified centrally;
 *     failures preserve the old playlist and local file.
 * @throws \RuntimeException When schema, source or retained copy is unavailable.
 */
function gallery_music_preserve_removed_original(
    array $gallery,
    array $track,
): void {
    music_repository_assert_schema_ready('gallery_music.detach_legacy');
    $songId = music_catalog_track_song_id((int) $gallery['id'], $track['id']);
    if ($songId !== null) {
        foreach (\Gallery\Models\music_repository_model_assets_for_song(
            $songId,
        ) as $retained) {
            if ($retained['byte_size'] === $track['size']
                && hash_equals($track['sha256'], $retained['sha256'])
                && music_repository_asset_path($retained) !== null) {
                return;
            }
        }
    }
    $path = gallery_music_directory($gallery) . DIRECTORY_SEPARATOR
        . $track['id'] . '.bin';
    if (
        is_link($path)
        || !is_file($path)
        || @filesize($path) !== $track['size']
        || !hash_equals($track['sha256'], (string) @hash_file('sha256', $path))
    ) {
        throw new \RuntimeException('music.original_unavailable');
    }
    $asset = music_repository_store_source(
        $path,
        $track['filename'],
        $songId,
        ['gallery_id' => (int) $gallery['id'], 'track_id' => $track['id']],
        $track['title'],
    );
    if (
        $asset['byte_size'] !== $track['size']
        || !hash_equals($track['sha256'], $asset['sha256'])
        || music_repository_asset_path($asset) === null
    ) {
        throw new \RuntimeException('music.original_unavailable');
    }
}

/** Build local attachment accounting rows without charging central assets.
 * @param array{tracks:list<array{id:string,size:int,asset_id?:string}> ,legacy_cleanup:list<array{track_id:string,size:int,sha256:string}>} $state Raw authoritative source state.
 * @return list<array{id:string,size:int}> Local originals and promoted copies awaiting verified cleanup.
 */
function gallery_music_accounting_entries(array $state): array
{
    $entries = [];
    foreach ($state['tracks'] as $track) {
        if (!isset($track['asset_id'])) {
            $entries[] = [
                'id' => $track['id'],
                'size' => $track['size'],
            ];
        }
    }
    foreach ($state['legacy_cleanup'] as $entry) {
        $entries[] = [
            'id' => $entry['track_id'],
            'size' => $entry['size'],
        ];
    }
    return $entries;
}

/** Publish migration state and its catalog snapshot while holding the gallery lock.
 * @param array{id:int,folder_path:string} $gallery Physical source gallery.
 * @param array{revision:string,enabled:bool,tracks:list<array{id:string,filename:string,title:string,mime:string,size:int,sha256:string,hidden:bool,downloadable:bool,asset_id?:string}>,albums:list<array{id:string,album_id:int,hidden:bool,downloadable:bool}>,order:list<string>,legacy_cleanup:list<array{track_id:string,size:int,sha256:string}>} $state Complete raw v2 state.
 * @return void Persist authoritative JSON before replacing catalog projections.
 * @throws \RuntimeException When durable marker, index, or snapshot publication fails.
 */
function gallery_music_publish_migration_state(array $gallery, array $state): void
{
    $galleryId = (int) $gallery['id'];
    \Gallery\Models\music_catalog_model_mark_gallery_dirty($galleryId);
    $catalogRevision = music_catalog_revision();
    $usageRevision = gallery_music_attachment_usage_begin($galleryId);
    \Gallery\Models\gallery_music_model_write(
        gallery_music_directory($gallery),
        $state,
    );
    try {
        gallery_music_attachment_usage_publish(
            $galleryId,
            $state,
            $usageRevision,
        );
    } catch (\Throwable) {
        // A committed playlist remains usable; the dirty projection is retried by maintenance.
    }
    music_catalog_sync_gallery_snapshot(
        $galleryId,
        $state['tracks'],
        $catalogRevision,
        $state['albums'],
    );
    gallery_attachment_storage_record(
        $galleryId,
        'music',
        gallery_music_accounting_entries($state),
    );
}

/** Promote at most one physical original and safely retire its local duplicate.
 * @param array{gallery_id:int,track_id:string}|null $cursor Last scanned source key, exclusive; empty track_id resumes after a gallery with no work.
 * @return array{cursor:array{gallery_id:int,track_id:string}|null,moved:int,bytes:int,complete:bool,error:array{gallery_id:int,track_id:string,code:string}|null} Restart-safe bounded migration progress without filesystem paths.
 */
function gallery_music_migrate_repository(?array $cursor = null): array
{
    music_repository_assert_schema_ready('gallery_music.repository_migration');
    $empty = [
        'cursor' => $cursor,
        'moved' => 0,
        'bytes' => 0,
        'complete' => false,
        'error' => null,
    ];
    $cursorGalleryId = max(0, (int) ($cursor['gallery_id'] ?? 0));
    $cursorTrackId = (string) ($cursor['track_id'] ?? '');
    $afterId = $cursorTrackId !== ''
        ? max(0, $cursorGalleryId - 1)
        : $cursorGalleryId;
    $picker = \Gallery\Models\gallery_model_source_picker_page(
        $afterId,
        GALLERY_MUSIC_MIGRATION_GALLERY_SCAN + 1,
    );
    $scanRows = array_slice($picker, 0, GALLERY_MUSIC_MIGRATION_GALLERY_SCAN);
    if ($scanRows === []) {
        $empty['complete'] = true;
        return $empty;
    }
    foreach ($scanRows as $galleryRow) {
        $galleryId = (int) $galleryRow['id'];
        $resumeTrack = $galleryId === $cursorGalleryId
            ? $cursorTrackId
            : '';
        $gallery = \Gallery\Models\gallery_model_find_by_id($galleryId);
        if ($gallery === null) {
            $empty['cursor'] = ['gallery_id' => $galleryId, 'track_id' => ''];
            $resumeTrack = '';
            continue;
        }
        $lock = null;
        try {
            $musicDirectory = gallery_music_directory($gallery);
            if (!is_dir($musicDirectory)) {
                $empty['cursor'] = ['gallery_id' => $galleryId, 'track_id' => ''];
                $resumeTrack = '';
                continue;
            }
            $lock = gallery_music_acquire_lock($gallery);
            $state = gallery_music_state($gallery);
            $tracks = $state['tracks'];
            usort($tracks, static fn(array $left, array $right): int => strcmp($left['id'], $right['id']));
            $resumed = $resumeTrack === '';
            foreach ($tracks as $track) {
                if (!$resumed) {
                    if (strcmp($track['id'], $resumeTrack) <= 0) {
                        continue;
                    }
                    $resumed = true;
                }
                $sourceKey = ['gallery_id' => $galleryId, 'track_id' => $track['id']];
                if (isset($track['asset_id'])) {
                    $pending = false;
                    foreach ($state['legacy_cleanup'] as $entry) {
                        $pending = $pending || $entry['track_id'] === $track['id'];
                    }
                    if (!$pending) {
                        $empty['cursor'] = $sourceKey;
                        continue;
                    }
                }
                $path = gallery_music_directory($gallery) . DIRECTORY_SEPARATOR
                    . $track['id'] . '.bin';
                if (!isset($track['asset_id'])) {
                    if (!is_file($path) || is_link($path)
                        || @filesize($path) !== $track['size']
                        || !hash_equals($track['sha256'], (string) @hash_file('sha256', $path))) {
                        $empty['error'] = $sourceKey + ['code' => 'source_unverified'];
                        $empty['cursor'] = $cursor;
                        return $empty;
                    }
                    $songId = \Gallery\Models\music_catalog_model_track_song_id(
                        $galleryId,
                        $track['id'],
                    );
                    $asset = music_repository_store_source(
                        $path,
                        $track['filename'],
                        $songId,
                        ['gallery_id' => $galleryId, 'track_id' => $track['id']],
                        $track['title'],
                    );
                    if (music_repository_asset_path($asset) === null) {
                        $empty['error'] = $sourceKey + ['code' => 'asset_unverified'];
                        $empty['cursor'] = $cursor;
                        return $empty;
                    }
                    $track['asset_id'] = (string) $asset['id'];
                    foreach ($state['tracks'] as &$rawTrack) {
                        if ($rawTrack['id'] === $track['id']) {
                            $rawTrack['asset_id'] = (string) $asset['id'];
                            break;
                        }
                    }
                    unset($rawTrack);
                    $state['version'] = 2;
                    $state['legacy_cleanup'][] = [
                        'track_id' => $track['id'],
                        'size' => $track['size'],
                        'sha256' => $track['sha256'],
                    ];
                    $state['revision'] = bin2hex(random_bytes(16));
                    gallery_music_publish_migration_state($gallery, $state);
                }
                $asset = \Gallery\Models\music_repository_model_asset(
                    $track['asset_id'],
                );
                $assetUsable = $asset !== null
                    && (int) $asset['byte_size'] === $track['size']
                    && hash_equals($track['sha256'], (string) $asset['sha256'])
                    && music_repository_asset_path($asset) !== null;
                if (!$assetUsable) {
                    $empty['error'] = $sourceKey + ['code' => 'asset_unverified'];
                    $empty['cursor'] = $cursor;
                    return $empty;
                }
                $localAbsent = !file_exists($path) && !is_link($path);
                $localRemoved = $localAbsent;
                if (!$localAbsent && !is_link($path) && is_file($path)
                    && @filesize($path) === $track['size']
                    && hash_equals($track['sha256'], (string) @hash_file('sha256', $path))) {
                    $localRemoved = @unlink($path);
                }
                if ($localRemoved) {
                    $state = gallery_music_state($gallery);
                    $state['legacy_cleanup'] = array_values(array_filter(
                        $state['legacy_cleanup'],
                        static fn(array $entry): bool => $entry['track_id'] !== $track['id'],
                    ));
                    $state['revision'] = bin2hex(random_bytes(16));
                    gallery_music_publish_migration_state($gallery, $state);
                    gallery_attachment_storage_changed();
                    $empty['cursor'] = $sourceKey;
                    $empty['moved'] = 1;
                    $empty['bytes'] = $track['size'];
                    return $empty;
                }
                $empty['error'] = $sourceKey + ['code' => 'cleanup_unverified'];
                $empty['cursor'] = $cursor;
                return $empty;
            }
            $empty['cursor'] = ['gallery_id' => $galleryId, 'track_id' => ''];
            $resumeTrack = '';
        } catch (\Throwable) {
            $empty['error'] = [
                'gallery_id' => $galleryId,
                'track_id' => '',
                'code' => 'migration_failed',
            ];
            $empty['cursor'] = $cursor;
            return $empty;
        } finally {
            if ($lock !== null) {
                gallery_music_release_lock($lock);
            }
        }
    }
    $empty['complete'] = count($picker) <= GALLERY_MUSIC_MIGRATION_GALLERY_SCAN;
    return $empty;
}

