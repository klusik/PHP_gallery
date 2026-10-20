<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_music/projection.php
 * Module Type: Service implementation part
 * Purpose: Resolve raw gallery music state into bounded effective playlists.
 * Responsibilities: Preserve legacy reads and project ordered live album references.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;
/** Read and normalize the persisted v1/v2 source state without expansion.
 * @param array{id:int,folder_path:string} $gallery Physical gallery identity/path.
 * @return array{version:int,revision:string,write_revision:string,enabled:bool,tracks:list<array{id:string,filename:string,title:string,mime:string,size:int,sha256:string,hidden:bool,downloadable:bool,asset_id?:string}>,albums:list<array{id:string,album_id:int,hidden:bool,downloadable:bool}>,order:list<string>,legacy_cleanup:list<array{track_id:string,size:int,sha256:string}>} Raw direct tracks and live album assignments; never includes expanded members.
 * @throws \RuntimeException When the persisted index is invalid or unsafe.
 */
function gallery_music_state(array $gallery): array
{
    $stored = \Gallery\Models\gallery_music_model_read(
        gallery_music_directory($gallery),
    );
    $version = (int) ($stored['version'] ?? 1);
    $tracks = $stored['tracks'];
    if ($version === 1) {
        return [
            'version' => 1,
            'revision' => $stored['revision'],
            'write_revision' => $stored['revision'],
            'enabled' => $stored['enabled'],
            'tracks' => $tracks,
            'albums' => [],
            'order' => array_column($tracks, 'id'),
            'legacy_cleanup' => [],
        ];
    }
    return [
        'version' => 2,
        'revision' => $stored['revision'],
        'write_revision' => $stored['revision'],
        'enabled' => $stored['enabled'],
        'tracks' => $tracks,
        'albums' => $stored['albums'],
        'order' => $stored['order'],
        'legacy_cleanup' => $stored['legacy_cleanup'],
    ];
}

/** Project raw direct tracks and live album membership into a playable playlist.
 * @param array{id:int,folder_path:string} $gallery Authorized physical gallery.
 * @param bool $includeSources Whether editor source groups are required.
 * @return array{revision:string,enabled:bool,tracks:list<array{id:string,filename:string,title:string,mime:string,size:int,sha256:string,hidden:bool,downloadable:bool,asset_id?:string,source_id?:string,source_type?:string,source_title?:string,source_track_id?:string}>,sources?:list<array{id:string,type:string,song_id:int|null,album_id:int|null,title:string,path?:string,downloadable:bool,hidden:bool,track_count:int,position:int}>,write_revision?:string,unavailable?:int,over_limit?:bool,omitted?:int,partial?:bool} Current effective ordered playlist; v1-only callers retain the legacy result shape unless source groups are requested.
 * @throws \RuntimeException When a referenced catalog or repository record cannot be trusted.
 */
function gallery_music_playlist(array $gallery, bool $includeSources = false): array
{
    $state = gallery_music_state($gallery);
    if ($state['version'] === 1) {
        if (!$includeSources) {
            return [
                'revision' => $state['revision'],
                'enabled' => $state['enabled'],
                'tracks' => $state['tracks'],
            ];
        }
        $sources = [];
        $tracks = [];
        foreach ($state['tracks'] as $position => $track) {
            $sources[] = [
                'id' => $track['id'],
                'type' => 'direct',
                'song_id' => null,
                'album_id' => null,
                'title' => (string) $track['title'],
                'downloadable' => (bool) $track['downloadable'],
                'hidden' => (bool) $track['hidden'],
                'track_count' => 1,
                'position' => $position,
            ];
            $tracks[] = $track + [
                'source_id' => $track['id'],
                'source_type' => 'direct',
                'source_title' => $track['title'],
                'source_track_id' => $track['id'],
            ];
        }
        return [
            'revision' => $state['revision'],
            'write_revision' => $state['write_revision'],
            'enabled' => $state['enabled'],
            'tracks' => $tracks,
            'sources' => $sources,
            'unavailable' => 0,
            'over_limit' => false,
            'omitted' => 0,
            'partial' => false,
        ];
    }
    $needsRepository = $state['albums'] !== [];
    foreach ($state['tracks'] as $rawTrack) {
        $needsRepository = $needsRepository || isset($rawTrack['asset_id']);
    }
    if ($needsRepository) {
        music_repository_assert_schema_ready('music_repository.playlist_projection');
    }
    $directById = [];
    $sourcesById = [];
    $directAssetIds = [];
    $unavailable = 0;
    foreach ($state['tracks'] as $track) {
        if (isset($track['asset_id'])) {
            $directAssetIds[] = $track['asset_id'];
        }
        $directById[$track['id']] = $track;
        $sourcesById[$track['id']] = [
            'id' => $track['id'],
            'type' => 'direct',
            'song_id' => null,
            'album_id' => null,
            'title' => (string) ($track['title'] ?: $track['filename']),
            'downloadable' => (bool) $track['downloadable'],
            'hidden' => (bool) $track['hidden'],
            'track_count' => 1,
        ];
    }
    $albumPaths = [];
    $assignedAlbumIds = array_values(array_unique(array_map(
        static fn(array $album): int => (int) $album['album_id'],
        $state['albums'],
    )));
    foreach (array_chunk(
        $assignedAlbumIds,
        GALLERY_MUSIC_ALBUM_MEMBER_BATCH,
    ) as $albumIdBatch) {
        $albumPaths += music_catalog_album_paths($albumIdBatch);
    }
    $albumOverflow = false;
    $omitted = 0;
    $albumAssignments = [];
    foreach ($state['albums'] as $assignment) {
        $albumAssignments[$assignment['id']] = $assignment;
    }
    $remainingTrackSlots = GALLERY_MUSIC_MAX_TRACKS;
    $remainingMemberReads = GALLERY_MUSIC_MAX_TRACKS;
    foreach ($state['order'] as $orderedSourceId) {
        if (isset($directById[$orderedSourceId])) {
            $remainingTrackSlots = max(0, $remainingTrackSlots - 1);
            continue;
        }
        $assignment = $albumAssignments[$orderedSourceId] ?? null;
        if (!is_array($assignment)) {
            continue;
        }
        $albumRows = [];
        $albumInfo = $albumPaths[$assignment['album_id']] ?? null;
        $albumExists = is_array($albumInfo) && $albumInfo['available'];
        $songCount = $albumExists ? (int) $albumInfo['song_count'] : 0;
        if (!$albumExists) {
            $unavailable++;
        }
        $readLimit = min(
            $songCount,
            $remainingTrackSlots,
            $remainingMemberReads,
        );
        for ($offset = 0; $albumExists && $offset < $readLimit;) {
            $pageLimit = min(
                GALLERY_MUSIC_ALBUM_MEMBER_BATCH,
                $readLimit - $offset,
            );
            try {
                $page = music_catalog_album_members(
                    $assignment['album_id'],
                    $pageLimit,
                    $offset,
                );
            } catch (\Throwable) {
                $albumRows = [];
                $unavailable++;
                break;
            }
            array_push($albumRows, ...$page);
            $offset += count($page);
            $remainingMemberReads -= count($page);
            if (count($page) < $pageLimit) {
                break;
            }
        }
        if (count($albumRows) > $remainingTrackSlots) {
            $albumOverflow = true;
            $albumRows = array_slice($albumRows, 0, $remainingTrackSlots);
        }
        $remainingTrackSlots -= count($albumRows);
        $omitted += max(0, $songCount - count($albumRows));
        $albumOverflow = $albumOverflow || $omitted > 0;
        $groupTitle = t('admin.music.album', 'Album')
            . ' #' . (int) $assignment['album_id'];
        $albumPath = '';
        if ($albumExists) {
            $groupTitle = (string) $albumInfo['title'];
            $albumPath = (string) $albumInfo['path'];
        }
        $sourcesById[$assignment['id']] = [
            'id' => $assignment['id'],
            'type' => 'album',
            'song_id' => null,
            'album_id' => $assignment['album_id'],
            'title' => $groupTitle,
            'path' => $albumPath,
            'downloadable' => (bool) $assignment['downloadable'],
            'hidden' => (bool) $assignment['hidden'],
            'track_count' => $songCount,
        ];
        $sourcesById[$assignment['id']]['members'] = $albumRows;
    }
    $albumSongIds = [];
    foreach ($sourcesById as $source) {
        foreach ($source['members'] ?? [] as $member) {
            $albumSongIds[] = (int) $member['id'];
        }
    }
    $assetsById = [];
    $assetsBySong = [];
    $songBatches = array_chunk(
        array_values(array_unique($albumSongIds)),
        GALLERY_MUSIC_ALBUM_MEMBER_BATCH,
    );
    if ($songBatches === [] && $directAssetIds !== []) {
        $songBatches = [[]];
    }
    foreach ($songBatches as $batchPosition => $songBatch) {
        $assetBatch = music_catalog_repository_projection_assets(
            $batchPosition === 0 ? $directAssetIds : [],
            $songBatch,
        );
        $assetsById += $assetBatch['assets_by_id'];
        $assetsBySong += $assetBatch['assets_by_song'];
    }
    foreach ($directById as $trackId => &$track) {
        if (!isset($track['asset_id'])) {
            continue;
        }
        $asset = $assetsById[$track['asset_id']] ?? null;
        if (!is_array($asset)) {
            $unavailable++;
            unset($directById[$trackId]);
            continue;
        }
        $track['size'] = (int) $asset['byte_size'];
        $track['sha256'] = (string) $asset['sha256'];
        $track['filename'] = (string) $asset['filename'];
        $track['mime'] = (string) $asset['mime'];
        $sourcesById[$trackId]['song_id'] = (int) $asset['song_id'];
    }
    unset($track);
    if ($needsRepository && $directById !== []) {
        $references = [];
        foreach (array_keys($directById) as $trackId) {
            $references[] = [
                'gallery_id' => (int) $gallery['id'],
                'track_id' => $trackId,
            ];
        }
        $titles = music_catalog_titles_for_references($references);
        foreach ($directById as $trackId => &$track) {
            $key = (int) $gallery['id'] . ':' . $trackId;
            $canonical = $titles[$key] ?? null;
            if (is_array($canonical)) {
                $track['title'] = music_catalog_display_title(
                    $track['filename'],
                    $canonical['indexed'],
                    $canonical['custom'],
                );
                $sourcesById[$trackId]['title'] = $track['title'];
            }
        }
        unset($track);
    }
    $tracks = [];
    $sources = [];
    $bytes = 0;
    foreach ($state['order'] as $position => $sourceId) {
        if (!isset($sourcesById[$sourceId])) {
            continue;
        }
        $source = $sourcesById[$sourceId];
        unset($source['members']);
        $source['position'] = $position;
        $sources[] = $source;
        if ($source['type'] === 'direct') {
            if (!isset($directById[$sourceId])) {
                continue;
            }
            $track = $directById[$sourceId];
            if (
                count($tracks) >= GALLERY_MUSIC_MAX_TRACKS
                || $bytes + $track['size'] > GALLERY_MUSIC_TOTAL_BYTES
            ) {
                $omitted++;
                $albumOverflow = true;
                continue;
            }
            if ($includeSources) {
                $track['source_id'] = $sourceId;
                $track['source_type'] = 'direct';
                $track['source_title'] = $source['title'];
                $track['source_track_id'] = $sourceId;
            }
            $tracks[] = $track;
            $bytes += $track['size'];
            continue;
        }
        foreach ($sourcesById[$sourceId]['members'] as $member) {
            $songId = (int) $member['id'];
            $asset = $assetsBySong[$songId][0] ?? null;
            if ($asset === null) {
                $unavailable++;
                continue;
            }
            if (
                count($tracks) >= GALLERY_MUSIC_MAX_TRACKS
                || $bytes + (int) $asset['byte_size'] > GALLERY_MUSIC_TOTAL_BYTES
            ) {
                $omitted++;
                $albumOverflow = true;
                continue;
            }
            $deliveryId = substr(hash(
                'sha256',
                $sourceId . "\0" . $songId,
            ), 0, 32);
            $track = [
                'id' => $deliveryId,
                'filename' => (string) $asset['filename'],
                'title' => (string) $member['title'],
                'mime' => (string) $asset['mime'],
                'size' => (int) $asset['byte_size'],
                'sha256' => (string) $asset['sha256'],
                'hidden' => (bool) $source['hidden'],
                'downloadable' => (bool) $source['downloadable'],
                'source_id' => $sourceId,
                'source_type' => 'album',
                'source_title' => $source['title'],
                'source_track_id' => (string) $songId,
            ];
            $track['asset_id'] = (string) $asset['id'];
            $tracks[] = $track;
            $bytes += $track['size'];
        }
    }
    $catalogRevision = $needsRepository
        ? music_catalog_revision()
        : 'legacy';
    $overLimit = $albumOverflow
        || count($tracks) > GALLERY_MUSIC_MAX_TRACKS
        || $bytes > GALLERY_MUSIC_TOTAL_BYTES;
    return [
        'revision' => substr(hash(
            'sha256',
            $state['revision'] . ':' . $catalogRevision,
        ), 0, 32),
        'write_revision' => $state['write_revision'],
        'enabled' => $state['enabled'],
        'tracks' => $tracks,
        'sources' => $sources,
        'unavailable' => $unavailable,
        'over_limit' => $overLimit,
        'omitted' => $omitted,
        'partial' => $unavailable > 0 || $albumOverflow,
    ];
}

