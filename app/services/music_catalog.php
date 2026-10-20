<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/music_catalog.php
 * Module Type: Service
 * Purpose: Orchestrate the normalized music catalog and album hierarchy.
 * Responsibilities: Apply bounded input policy, revisions and presentation titles.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/../models/music_catalog.php';
require_once __DIR__ . '/music_repository.php';
require_once __DIR__ . '/app_settings.php';
require_once __DIR__ . '/schema_inspection.php';
require_once __DIR__ . '/presentation_schema_policy.php';
require_once __DIR__ . '/mutation_schema_policy.php';

/**
 * Bound catalog page size to keep administrator queries responsive.
 * Type: int. Units: songs. Scope: one catalog page.
 * Consumers: music_catalog_list.
 * Rationale: 100 rows allow useful browsing without unbounded result sets.
 */
const MUSIC_CATALOG_PAGE_SIZE = 100;

/** Bound songs and albums returned by one live assignment chooser.
 * Type: int. Units: results per type. Scope: one authenticated chooser query.
 * Consumers: music_catalog_assignment_choices. Rationale: 25 rows are selectable without bloating the manager payload.
 */
const MUSIC_CATALOG_CHOOSER_LIMIT = 25;

/** Bound direct tracks and live album groups in one physical playlist snapshot.
 * Type: int. Units: sources. Scope: one gallery music index.
 * Consumers: music_catalog_sync_gallery_snapshot. Rationale: the established 100-track playlist cap bounds links and writes.
 */
const MUSIC_CATALOG_GALLERY_SOURCE_LIMIT = 100;

/** Bound metadata IDs for one effective public playlist projection.
 * Type: int. Units: IDs per lookup category. Scope: one gallery request.
 * Consumers: music_catalog_repository_projection_assets. Rationale: the existing 100-source playlist limit keeps query placeholders bounded.
 */
const MUSIC_CATALOG_REPOSITORY_BATCH_LIMIT = 100;

/**
 * Bound galleries observed during one resumable filesystem reconciliation call.
 * Type: int. Units: galleries. Scope: one administrator reconcile request.
 * Consumers: music_catalog_reconcile.
 * Rationale: 20 galleries permit continuation without crawling the public tree.
 */
const MUSIC_CATALOG_RECONCILE_BATCH = 20;

/**
 * Bound nested album ancestry so browser and database work stay finite.
 * Type: int. Units: levels. Scope: the music album tree.
 * Consumers: music_catalog_album_move.
 * Rationale: eight levels support collections while keeping path validation bounded.
 */
const MUSIC_CATALOG_ALBUM_MAX_DEPTH = 8;

/** Bound hierarchy nodes so validation and manager rendering stay finite.
 * Type: int. Units: albums. Scope: one global music catalog.
 * Consumers: music_catalog_album_tree, music_catalog_album_create.
 * Rationale: 2,000 nested nodes permit large catalogs without unbounded reads.
 */
const MUSIC_CATALOG_ALBUM_MAX_NODES = 2000;

/**
 * Bound a per-song custom public title before SQL persistence.
 * Type: int. Units: UTF-8 bytes. Scope: one canonical song title override.
 * Consumers: music_catalog_set_song_title.
 * Rationale: 240 bytes fit catalog records and compact public playlists.
 */
const MUSIC_CATALOG_TITLE_BYTES = 240;

/** Bound catalog search text before constructing SQL patterns.
 * Type: int. Units: UTF-8 bytes. Scope: one administrator catalog query.
 * Consumers: music_catalog_list.
 * Rationale: 240 bytes keep search parameters finite and match title limits.
 */
const MUSIC_CATALOG_QUERY_BYTES = 240;

/** Bound tag editor input before parsing names.
 * Type: int. Units: UTF-8 bytes. Scope: one song tag update.
 * Consumers: music_catalog_sync_song_tags.
 * Rationale: 2,000 bytes support practical tag lists with bounded parsing.
 */
const MUSIC_CATALOG_TAG_TEXT_BYTES = 2000;

/** Bound plain-text album descriptions stored with one album.
 * Type: int. Units: UTF-8 bytes. Scope: one album description.
 * Consumers: music_catalog_album_create, music_catalog_album_update.
 * Rationale: 4,000 bytes accommodate useful notes without large row payloads.
 */
const MUSIC_CATALOG_DESCRIPTION_BYTES = 4000;

/** Validate a prospective parent chain for loops and bounded nesting.
 * @param int $albumId Album being moved, or zero while creating a new album.
 * @param int|null $parentId Proposed parent album, or null for a root album.
 * @return void Reject missing parents, cycles and resulting depth above eight.
 * @throws \RuntimeException When an album is missing, cyclic or too deep.
 */
function music_catalog_assert_album_move_safe(int $albumId, ?int $parentId): void
{
    $albums = music_catalog_album_tree();
    $parents = [];
    foreach ($albums as $album) {
        $parents[(int) $album['id']] = $album['parent_id'] === null
            ? null
            : (int) $album['parent_id'];
    }
    if ($albumId > 0 && !array_key_exists($albumId, $parents)) {
        throw new \RuntimeException('music.album_missing');
    }
    $depth = 1;
    $seen = [];
    $cursor = $parentId;
    while ($cursor !== null) {
        if ($cursor === $albumId || isset($seen[$cursor])) {
            throw new \RuntimeException('music.album_cycle');
        }
        if (!array_key_exists($cursor, $parents)) {
            throw new \RuntimeException('music.album_missing');
        }
        $seen[$cursor] = true;
        $depth++;
        $cursor = $parents[$cursor];
    }
    if ($depth > MUSIC_CATALOG_ALBUM_MAX_DEPTH) {
        throw new \RuntimeException('music.album_depth');
    }
    if ($albumId > 0) {
        $children = [];
        foreach ($parents as $childId => $ancestorId) {
            if ($ancestorId !== null) {
                $children[$ancestorId][] = $childId;
            }
        }
        $pending = [[$albumId, 1]];
        $visited = [];
        while ($pending !== []) {
            [$currentId, $relativeDepth] = array_pop($pending);
            if (isset($visited[$currentId])) {
                throw new \RuntimeException('music.album_cycle');
            }
            $visited[$currentId] = true;
            if ($depth + $relativeDepth - 1 > MUSIC_CATALOG_ALBUM_MAX_DEPTH) {
                throw new \RuntimeException('music.album_depth');
            }
            foreach ($children[$currentId] ?? [] as $childId) {
                $pending[] = [$childId, $relativeDepth + 1];
            }
        }
    }
}

/** Observe every catalog table and column required by catalog operations.
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state schema report.
 */
function music_catalog_schema_status(): array
{
    return presentation_schema_tables_status('presentation.music_catalog', [
        'music_songs' => ['id', 'title', 'created_at', 'updated_at'],
        'music_track_instances' => ['gallery_id', 'track_id', 'song_id', 'filename', 'indexed_title', 'file_sha256', 'active', 'updated_at'],
        'music_albums' => ['id', 'parent_id', 'title', 'description', 'sort_order', 'revision'],
        'music_album_songs' => ['album_id', 'song_id', 'sort_order'],
        'music_song_tags' => ['song_id', 'tag_id'],
        'music_catalog_state' => ['state_key', 'revision', 'updated_at'],
        'music_catalog_dirty_galleries' => ['gallery_id', 'changed_at'],
        'tags' => ['id', 'name', 'media_type'],
        'app_settings' => ['setting_key', 'setting_value', 'updated_at'],
        'galleries' => ['id', 'folder_path', 'title'],
        'gallery_trash_entries' => ['status', 'original_folder_path'],
    ]);
}

/** Inspect central repository schema separately from legacy catalog readiness.
 * @return array{state:string,feature:string,requirements:list<array{state:string,object_type:string,table:string,object:string,error_code:string,diagnostic:string}>} Three-state repository schema report.
 */
function music_repository_schema_status(): array
{
    return presentation_schema_tables_status('presentation.music_repository', [
        'music_repository_assets' => ['id', 'song_id', 'filename', 'indexed_title', 'mime', 'byte_size', 'sha256', 'origin_gallery_id', 'origin_track_id', 'created_at', 'updated_at'],
        'music_track_instances' => ['gallery_id', 'track_id', 'asset_id'],
        'music_gallery_album_links' => ['gallery_id', 'assignment_id', 'album_id', 'updated_at'],
    ]);
}

/** Refuse repository writes unless every repository table is verified.
 * @param string $operation Stable operation name for readiness diagnostics.
 * @return void Continue only when repository schema is fully available.
 * @throws \RuntimeException When schema is missing, partial or unknown.
 */
function music_repository_assert_schema_ready(string $operation): void
{
    $safeOperation = preg_match('/^[a-z][a-z0-9_.-]{0,79}$/D', $operation) === 1
        ? $operation
        : 'music_repository.mutation';
    mutation_schema_assert_available(
        music_repository_schema_status(),
        $safeOperation,
        t('music.failed'),
        t('music.failed'),
    );
}

/** Resolve repository readiness for catalog SQL that has a legacy branch.
 * @param string $operation Stable operation name for diagnostics.
 * @return bool True for the installed repository schema, false for proven absence.
 * @throws \RuntimeException When inspection is partial or unavailable.
 */
function music_repository_is_ready_or_absent(string $operation): bool
{
    $status = music_repository_schema_status();
    if ($status['state'] === 'available') {
        return true;
    }
    if ($status['state'] === 'missing') {
        return false;
    }
    music_repository_assert_schema_ready($operation);
    return false;
}

/** Refuse catalog writes unless every required schema object is verified.
 * @param string $operation Stable operation name for readiness diagnostics.
 * @return void Continue only when schema inspection is complete and ready.
 * @throws \RuntimeException When schema state is unavailable or incomplete.
 */
function music_catalog_assert_schema_ready(string $operation): void
{
    presentation_schema_assert_write_available(
        music_catalog_schema_status(),
        $operation,
    );
}

/**
 * Select complete catalog writes or the confirmed pre-catalog playlist path.
 *
 * @return string Either available or legacy; partial and unknown schema refuse
 *     physical writes before originals or playlist state can change.
 * @throws \RuntimeException When inspection is unknown or catalog DDL is partial.
 */
function music_catalog_physical_write_mode(): string
{
    $status = music_catalog_schema_status();
    if ($status['state'] === 'available') {
        return 'available';
    }
    $catalogTables = [
        'music_songs',
        'music_track_instances',
        'music_albums',
        'music_album_songs',
        'music_song_tags',
        'music_catalog_state',
        'music_catalog_dirty_galleries',
    ];
    $missingTables = [];
    foreach ($status['requirements'] as $requirement) {
        if (
            $requirement['object_type'] === 'table'
            && in_array($requirement['table'], $catalogTables, true)
            && $requirement['state'] === 'missing'
        ) {
            $missingTables[] = $requirement['table'];
        }
    }
    // Original-only support is permanent for a positively observed old schema.
    // A partially created catalog must not leave live SQL usage unmaintained.
    if (
        $status['state'] === 'missing'
        && count(array_unique($missingTables)) === count($catalogTables)
    ) {
        return 'legacy';
    }
    presentation_schema_assert_write_available(
        $status,
        'music_catalog.playlist_mutation',
    );
    throw new \RuntimeException('Music catalog schema is incomplete.');
}

/** Synchronize one committed physical playlist without scanning other galleries.
 * @param int $galleryId Physical gallery whose JSON index was committed.
 * @param list<array{id:string,title:string,filename:string,sha256:string,mime:string,size:int,hidden:bool,downloadable:bool,asset_id?:string}> $tracks Raw direct current index rows only.
 * @param string $revision Expected catalog revision for atomic snapshot update.
 * @param list<array{id:string,album_id:int,hidden:bool,downloadable:bool}> $albumLinks Raw live album references from the same sidecar.
 * @return string New catalog revision after the snapshot is linked.
 * @throws \RuntimeException When persistence fails or the revision is stale.
 */
function music_catalog_sync_gallery_snapshot(int $galleryId, array $tracks, string $revision, array $albumLinks = []): string
{
    if (count($tracks) + count($albumLinks) > MUSIC_CATALOG_GALLERY_SOURCE_LIMIT) {
        throw new \InvalidArgumentException('music.playlist_limit');
    }
    $repositoryStatus = music_repository_schema_status();
    $repositoryFields = $albumLinks !== [];
    foreach ($tracks as $track) {
        $repositoryFields = $repositoryFields || !empty($track['asset_id']);
    }
    if ($repositoryFields || !in_array($repositoryStatus['state'], ['available', 'missing'], true)) {
        music_repository_assert_schema_ready('catalog.gallery_snapshot');
    }
    $ids = [];
    $rows = [];
    foreach ($tracks as $track) {
        $ids[] = (string) $track['id'];
        $rows[] = [
            'gallery_id' => $galleryId,
            'track_id' => (string) $track['id'],
            'title' => (string) $track['title'],
            'filename' => (string) $track['filename'],
            'sha256' => (string) $track['sha256'],
            'asset_id' => isset($track['asset_id']) ? (string) $track['asset_id'] : null,
        ];
    }
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.gallery_snapshot',
        static function () use ($galleryId, $ids, $rows, $albumLinks, $repositoryStatus): void {
            foreach ($rows as $row) {
                \Gallery\Models\music_catalog_model_attach_track($row);
                if ($row['asset_id'] !== null) {
                    music_repository_assert_schema_ready('catalog.gallery_asset_link');
                    \Gallery\Models\music_repository_model_link_track(
                        $galleryId,
                        $row['track_id'],
                        $row['asset_id'],
                    );
                }
            }
            if ($repositoryStatus['state'] === 'available') {
                \Gallery\Models\music_repository_model_sync_album_links(
                    $galleryId,
                    $albumLinks,
                );
            }
            \Gallery\Models\music_catalog_model_prune_gallery($galleryId, $ids);
            \Gallery\Models\music_catalog_model_clear_gallery_dirty($galleryId);
        },
    );
    return $result['revision'];
}

/** Find a logical song by its stable physical gallery-track reference.
 * @param int $galleryId Stable gallery identity.
 * @param string $trackId Stable track identity within that gallery.
 * @return int|null Logical song identity, or null when not indexed.
 */
function music_catalog_track_song_id(int $galleryId, string $trackId): ?int
{
    return \Gallery\Models\music_catalog_model_track_song_id($galleryId, $trackId);
}

/** Batch central metadata for direct and live-album sources in one query.
 * @param list<string> $assetIds Direct central asset IDs from raw track entries.
 * @param list<int> $songIds Logical songs expanded from assigned albums.
 * @return array{assets_by_id:array<string,array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}>,assets_by_song:array<int,list<array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}>>} Existing metadata indexed by direct asset and song identity.
 * @throws \InvalidArgumentException When IDs are malformed or exceed batch bounds.
 * @throws \RuntimeException When repository schema is unavailable.
 */
function music_catalog_repository_projection_assets(array $assetIds, array $songIds): array
{
    if (count($assetIds) > MUSIC_CATALOG_REPOSITORY_BATCH_LIMIT || count($songIds) > MUSIC_CATALOG_REPOSITORY_BATCH_LIMIT) {
        throw new \InvalidArgumentException('Music projection batch is too large.');
    }
    foreach ($assetIds as $assetId) {
        if (!is_string($assetId) || preg_match('/^[a-f0-9]{32}$/D', $assetId) !== 1) {
            throw new \InvalidArgumentException('Invalid repository asset identity.');
        }
    }
    foreach ($songIds as $songId) {
        if (!is_int($songId) || $songId < 1) {
            throw new \InvalidArgumentException('Invalid music song identity.');
        }
    }
    $assetIds = array_values(array_unique($assetIds));
    $songIds = array_values(array_unique($songIds));
    music_repository_assert_schema_ready('catalog.public_projection');
    return \Gallery\Models\music_repository_model_projection_assets(
        $assetIds,
        $songIds,
    );
}

/** Restore this local Trash snapshot's surviving song links after playlist indexing.
 * @param int $galleryId Newly recreated physical gallery identity.
 * @param array{revision:string,enabled:bool,tracks:list<array{id:string,filename:string,title:string,mime:string,size:int,sha256:string,hidden:bool,downloadable:bool,asset_id?:string}>,albums?:list<array{id:string,album_id:int,hidden:bool,downloadable:bool}>} $playlist Authoritative raw v1/v2 physical playlist.
 * @param list<array{track_id:string,song_id:int}> $savedRefs Trusted same-site Trash references.
 * @return void Reuse existing song identities where still present; indexing keeps fresh identities otherwise.
 * @throws \RuntimeException When the catalog schema or revision is unavailable.
 */
function music_catalog_restore_trash_snapshot(
    int $galleryId,
    array $playlist,
    array $savedRefs,
): void {
    $tracks = [];
    $trackIds = [];
    foreach ($playlist['tracks'] as $track) {
        $trackIds[(string) $track['id']] = true;
        $tracks[] = [
            'gallery_id' => $galleryId,
            'track_id' => (string) $track['id'],
            'title' => (string) $track['title'],
            'filename' => (string) $track['filename'],
            'sha256' => (string) $track['sha256'],
            'asset_id' => isset($track['asset_id']) ? (string) $track['asset_id'] : null,
        ];
    }
    $refs = [];
    foreach ($savedRefs as $reference) {
        $trackId = (string) ($reference['track_id'] ?? '');
        $songId = filter_var(
            $reference['song_id'] ?? null,
            FILTER_VALIDATE_INT,
        );
        if (
            $trackId !== ''
            && isset($trackIds[$trackId])
            && $songId !== false
            && $songId > 0
        ) {
            $refs[$trackId] = (int) $songId;
        }
    }
    $revision = music_catalog_revision();
    $albumLinks = array_values($playlist['albums'] ?? []);
    $repositoryStatus = music_repository_schema_status();
    if ($albumLinks !== [] || array_filter($tracks, static fn(array $track): bool => $track['asset_id'] !== null) !== []) {
        music_repository_assert_schema_ready('catalog.trash_restore');
    }
    music_catalog_commit_mutation(
        $revision,
        'catalog.trash_restore',
        static function () use ($galleryId, $tracks, $refs, $albumLinks, $repositoryStatus): void {
            foreach ($tracks as $track) {
                \Gallery\Models\music_catalog_model_attach_track($track);
                if ($track['asset_id'] !== null) {
                    \Gallery\Models\music_repository_model_link_track(
                        $galleryId,
                        $track['track_id'],
                        $track['asset_id'],
                    );
                }
            }
            \Gallery\Models\music_catalog_model_restore_trash_track_refs(
                $galleryId,
                $refs,
            );
            \Gallery\Models\music_catalog_model_prune_gallery(
                $galleryId,
                array_column($tracks, 'track_id'),
            );
            \Gallery\Models\music_catalog_model_clear_gallery_dirty($galleryId);
            if ($repositoryStatus['state'] === 'available') {
                \Gallery\Models\music_repository_model_sync_album_links(
                    $galleryId,
                    $albumLinks,
                );
            }
        },
    );
}

/** Commit one validated mutation together with its expected catalog revision.
 * @param string $revision Expected decimal revision from the submitted form.
 * @param string $operation Stable operation name for schema diagnostics.
 * @param \Closure():int|void $mutation Persistence callback executed inside the transaction.
 * @return array{revision:string,value:int|null} New revision and callback result.
 * @throws \RuntimeException When schema is unavailable or the revision is stale.
 */
function music_catalog_commit_mutation(string $revision, string $operation, \Closure $mutation): array
{
    music_catalog_assert_schema_ready($operation);
    return \Gallery\Models\music_catalog_model_transaction(
        $revision,
        static function () use ($mutation): ?int {
            $value = $mutation();
            // Public HTML exposes this shared setting as a decimal timestamp,
            // consistently with Theme and thumbnail preference mutations.
            set_app_setting(
                'theme_public_content_revision',
                (string) time(),
            );
            return is_int($value) ? $value : null;
        },
    );
}

/** Return the current global optimistic revision.
 * @return string Decimal revision token used by catalog mutations.
 */
function music_catalog_revision(): string
{
    return \Gallery\Models\music_catalog_model_revision();
}

/** Report whether committed physical snapshots await catalog reconciliation.
 * @return array{state:string,count:int|null} Verified state and pending count.
 */
function music_catalog_dirty_status(): array
{
    $status = music_catalog_schema_status();
    return [
        'state' => $status['state'],
        'count' => $status['state'] === 'available'
            ? \Gallery\Models\music_catalog_model_dirty_gallery_count()
            : null,
    ];
}

/** Read a bounded page of normalized music identities.
 * @param string $query Search text for titles, filenames and music tags.
 * @param int $limit Maximum rows, no greater than the named page-size policy.
 * @param int $offset Zero-based page offset.
 * @param string $sort Semantic ordering: title, files, locations, or tags.
 * @param string $direction Requested ascending or descending order.
 * @return array{revision:string,songs:list<array{id:int,title:string,custom_title:?string,indexed_title:?string,filename:?string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int} Canonically titled songs and count.
 * @throws \InvalidArgumentException When search, page bounds, or ordering are invalid.
 */
function music_catalog_list(
    string $query = '',
    int $limit = MUSIC_CATALOG_PAGE_SIZE,
    int $offset = 0,
    string $sort = 'title',
    string $direction = 'asc',
): array {
    if (strlen($query) > MUSIC_CATALOG_QUERY_BYTES || $limit < 1 || $limit > MUSIC_CATALOG_PAGE_SIZE || $offset < 0) {
        throw new \InvalidArgumentException('Invalid catalog query.');
    }
    if (
        !in_array($sort, ['title', 'files', 'locations', 'tags'], true)
        || !in_array($direction, ['asc', 'desc'], true)
    ) {
        throw new \InvalidArgumentException('Invalid music catalog ordering.');
    }
    $repositoryStatus = music_repository_schema_status();
    if (!in_array($repositoryStatus['state'], ['available', 'missing'], true)) {
        music_repository_assert_schema_ready('catalog.list');
    }
    $result = \Gallery\Models\music_catalog_model_list(
        $query,
        $limit,
        $offset,
        $sort,
        $direction,
        app_setting('music_show_file_extensions', '0') === '1',
        array_keys(gallery_music_formats()),
        t('admin.music.untitled_song', 'Song #{id}'),
        $repositoryStatus['state'] === 'available',
    );
    foreach ($result['songs'] as &$song) {
        $song['title'] = music_catalog_display_title(
            (string) ($song['filename'] ?? ''),
            (string) ($song['indexed_title'] ?? ''),
            isset($song['custom_title']) ? (string) $song['custom_title'] : null,
        );
        if ($song['title'] === '') {
            $song['title'] = t(
                'admin.music.untitled_song',
                'Song #{id}',
                ['id' => (int) $song['id']],
            );
        }
    }
    unset($song);
    return $result;
}

/** Reconcile a bounded physical gallery page from its existing JSON indexes.
 * @param int $limit Number of galleries, capped by the reconcile policy.
 * @param int $afterGalleryId Exclusive continuation cursor.
 * @param string $revision Expected catalog revision for atomic persistence.
 * @return array{revision:string,processed:int,next_gallery_id:int|null,complete:bool,blocked_gallery_id?:int} Progress or blocked source.
 * @throws \InvalidArgumentException When the cursor or batch size is invalid.
 * @throws \RuntimeException When schema is unavailable or revision is stale.
 */
function music_catalog_reconcile(int $limit, int $afterGalleryId, string $revision): array
{
    if ($limit < 1 || $limit > MUSIC_CATALOG_RECONCILE_BATCH || $afterGalleryId < 0) {
        throw new \InvalidArgumentException('Invalid reconciliation cursor.');
    }
    require_once __DIR__ . '/gallery_music.php';
    music_catalog_assert_schema_ready('catalog.reconcile');
    if (music_catalog_revision() !== $revision) {
        throw new \RuntimeException('music.conflict');
    }
    $galleries = \Gallery\Models\music_catalog_model_galleries(
        $afterGalleryId,
        $limit + 1,
        true,
    );
    $hasMore = count($galleries) > $limit;
    if ($hasMore) {
        array_pop($galleries);
    }
    $processed = 0;
    $nextId = null;
    $galleryBatch = [];
    $locks = [];
    foreach ($galleries as $gallery) {
        $gallery['id'] = (int) $gallery['id'];
        try {
            $locks[] = gallery_music_acquire_lock($gallery);
            $playlist = gallery_music_playlist($gallery);
        } catch (\Throwable) {
            foreach ($locks as $lock) {
                gallery_music_release_lock($lock);
            }
            return [
                'revision' => $revision,
                'processed' => 0,
                'next_gallery_id' => $afterGalleryId,
                'complete' => false,
                'blocked_gallery_id' => $gallery['id'],
            ];
        }
        $ids = [];
        foreach ($playlist['tracks'] as $track) {
            $ids[] = $track['id'];
            $galleryBatch[$gallery['id']]['tracks'][] = [
                'gallery_id' => $gallery['id'],
                'track_id' => $track['id'],
                'title' => $track['title'],
                'filename' => $track['filename'],
                'sha256' => $track['sha256'],
            ];
        }
        $galleryBatch[$gallery['id']]['ids'] = $ids;
        $nextId = $gallery['id'];
        $processed++;
    }
    if ($galleryBatch === []) {
        foreach ($locks as $lock) {
            gallery_music_release_lock($lock);
        }
        return [
            'revision' => $revision,
            'processed' => 0,
            'next_gallery_id' => null,
            'complete' => !$hasMore,
        ];
    }
    try {
        $result = music_catalog_commit_mutation(
            $revision,
            'catalog.reconcile',
            static function () use ($galleryBatch): void {
            foreach ($galleryBatch as $galleryId => $entry) {
                foreach ($entry['tracks'] ?? [] as $track) {
                    \Gallery\Models\music_catalog_model_attach_track($track);
                }
                \Gallery\Models\music_catalog_model_prune_gallery(
                    (int) $galleryId,
                    $entry['ids'],
                );
                \Gallery\Models\music_catalog_model_clear_gallery_dirty(
                    (int) $galleryId,
                );
            }
            },
        );
    } finally {
        foreach ($locks as $lock) {
            gallery_music_release_lock($lock);
        }
    }
    return [
        'revision' => $result['revision'],
        'processed' => $processed,
        'next_gallery_id' => $hasMore ? $nextId : null,
        'complete' => !$hasMore,
    ];
}

/** Validate and store the global title presentation mode.
 * @param string $mode Accepted boolean form: 0/off or 1/on.
 * @param string $revision Expected catalog revision.
 * @return string New decimal revision after the setting is saved.
 * @throws \InvalidArgumentException When mode is not a supported boolean form.
 * @throws \RuntimeException When schema is unavailable or revision is stale.
 */
function music_catalog_set_show_file_extensions(string $mode, string $revision): string
{
    if (!in_array($mode, ['0', '1', 'off', 'on'], true)) {
        throw new \InvalidArgumentException('Invalid extension setting.');
    }
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.update_title_display',
        static function () use ($mode): void {
            set_app_setting(
                'music_show_file_extensions',
                in_array($mode, ['1', 'on'], true) ? '1' : '0',
            );
        },
    );
    return $result['revision'];
}

/** Choose the public title according to the global title policy.
 * @param string $filename Indexed physical filename used as final fallback.
 * @param string|null $songTitle Meaningful title from the existing index.
 * @param string|null $custom Exact canonical per-song override, if set.
 * @return string Custom, indexed or filename-derived public title.
 */
function music_catalog_display_title(string $filename, ?string $songTitle = null, ?string $custom = null): string
{
    if ($custom !== null && $custom !== '') {
        return $custom;
    }
    $filenameBase = basename(str_replace('\\', '/', $filename));
    if (
        $songTitle !== null
        && $songTitle !== ''
        && $songTitle !== $filename
        && $songTitle !== $filenameBase
    ) {
        return $songTitle;
    }
    $display = $filenameBase;
    $extensionsVisible = app_setting('music_show_file_extensions', '0') === '1';
    if (!$extensionsVisible) {
        $extension = strtolower(pathinfo($display, PATHINFO_EXTENSION));
        if (isset(\Gallery\Services\gallery_music_formats()[$extension])) {
            $display = substr($display, 0, -strlen($extension) - 1);
        }
    }
    return $display;
}

/** Resolve a physical reference's canonical song title with its file fallback.
 * @param int $galleryId Physical gallery owning the reference.
 * @param string $trackId Stable track ID within that gallery.
 * @param string $filename Physical filename fallback.
 * @param string $indexedTitle Existing indexed title fallback.
 * @return string Canonical public title for the track instance.
 */
function music_catalog_track_title(int $galleryId, string $trackId, string $filename, string $indexedTitle): string
{
    $titles = \Gallery\Models\music_catalog_model_titles([['gallery_id' => $galleryId, 'track_id' => $trackId]]);
    $entry = $titles[$galleryId . ':' . $trackId] ?? [];
    return music_catalog_display_title(
        $filename,
        $entry['indexed'] ?? $indexedTitle,
        $entry['custom'] ?? null,
    );
}

/** Resolve gallery-scoped references in named-size SQL batches.
 * @param list<array{gallery_id:int,track_id:string}> $references Track references.
 * @return array<string,array{song_id:int,custom:string,indexed:string}> Rows keyed by gallery and track ID.
 */
function music_catalog_titles_for_references(array $references): array
{
    $status = music_catalog_schema_status();
    if ($status['state'] === 'unknown') {
        presentation_schema_log_degraded($status, 'music_catalog.public_titles');
        return [];
    }
    if ($status['state'] === 'missing') {
        return [];
    }
    $titles = [];
    foreach (array_chunk($references, MUSIC_CATALOG_PAGE_SIZE) as $batch) {
        $titles += \Gallery\Models\music_catalog_model_titles($batch);
    }
    return $titles;
}

/** Apply canonical song overrides to a playlist with one bounded SQL lookup.
 * @param list<array{id:string,title:string,filename:string,sha256:string,mime:string,size:int,hidden:bool,downloadable:bool,source_gallery_id?:int}> $tracks Authorized playlist track rows.
 * @param int $defaultGalleryId Gallery ID for rows without explicit source ID.
 * @return list<array{id:string,title:string,filename:string,sha256:string,mime:string,size:int,hidden:bool,downloadable:bool,source_gallery_id?:int}> Tracks with canonical title fields applied.
 */
function music_catalog_present_tracks(array $tracks, int $defaultGalleryId): array
{
    $references = [];
    foreach ($tracks as $track) {
        $references[] = [
            'gallery_id' => (int) ($track['source_gallery_id'] ?? $defaultGalleryId),
            'track_id' => $track['id'],
        ];
    }
    try {
        $titles = music_catalog_titles_for_references($references);
    } catch (\Throwable) {
        $titles = [];
    }
    foreach ($tracks as &$track) {
        $galleryId = (int) ($track['source_gallery_id'] ?? $defaultGalleryId);
        $key = $galleryId . ':' . $track['id'];
        $track['title'] = music_catalog_display_title(
            $track['filename'],
            $titles[$key]['indexed'] ?? $track['title'],
            $titles[$key]['custom'] ?? null,
        );
    }
    unset($track);
    return $tracks;
}

/** Return direct gallery and album usage for a song identity.
 * @param int $songId Stable normalized song identity.
 * @return array{direct_galleries:list<array{gallery_id:int,title:string,track_count:int|string}>,albums:list<array{album_id:int,title:string,track_count:int|string}>,live_albums:list<array{gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>} Physical, global album and live gallery album usage.
 * @throws \InvalidArgumentException When the identity is invalid.
 */
function music_catalog_usage(int $songId): array
{
    if ($songId < 1) {
        throw new \InvalidArgumentException('Invalid song identity.');
    }
    return \Gallery\Models\music_catalog_model_usage(
        $songId,
        music_repository_is_ready_or_absent('catalog.usage'),
    );
}

/** Batch song references and distinct usage data for an administrator page.
 * @param list<int> $songIds Positive song IDs, capped at the page-size policy.
 * @return array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:string|null}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}>,direct_galleries:list<array{song_id:int,gallery_id:int,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>,galleries:array<int,array{id:int|string,url_path:string|null,slug:string|null,folder_path:string|null,title:string|null}>}> Details keyed by song ID with extant physical gallery URL inputs.
 * @throws \InvalidArgumentException When more than one bounded page is requested.
 */
function music_catalog_song_details(array $songIds): array
{
    $ids = array_values(array_unique(array_filter($songIds, static fn(int $id): bool => $id > 0)));
    if (count($ids) > MUSIC_CATALOG_PAGE_SIZE) {
        throw new \InvalidArgumentException('Too many song identities.');
    }
    return \Gallery\Models\music_catalog_model_song_details(
        $ids,
        music_repository_is_ready_or_absent('catalog.song_details'),
    );
}

/** Return a merge preview without changing catalog identities.
 * @param list<int> $songIds Selected normalized song IDs.
 * @param int $survivorId Selected identity retaining its custom title.
 * @return array{survivor_id:int,songs:list<array{id:int,title:?string,indexed_title:?string,filename:?string,display_title:string,tags:string,track_count:int|string,album_count:int|string,tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:string|null}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}>,direct_galleries:list<array{song_id:int,gallery_id:int,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>}>} Merge preview with tags, locations and distinct repository assets.
 * @throws \InvalidArgumentException When fewer than two IDs or invalid survivor.
 */
function music_catalog_merge_preview(array $songIds, int $survivorId): array
{
    $ids = array_values(array_unique(array_filter($songIds, static fn(int $id): bool => $id > 0)));
    if (
        count($ids) < 2
        || count($ids) > MUSIC_CATALOG_PAGE_SIZE
        || !in_array($survivorId, $ids, true)
    ) {
        throw new \InvalidArgumentException('Invalid merge selection.');
    }
    $preview = \Gallery\Models\music_catalog_model_merge_preview(
        $ids,
        $survivorId,
        music_repository_is_ready_or_absent('catalog.merge_preview'),
    );
    require_once __DIR__ . '/tag_metadata.php';
    $tagNames = tag_names_for_entities('music_song', $ids, 'music');
    $details = music_catalog_song_details($ids);
    foreach ($preview['songs'] as &$song) {
        $songId = (int) $song['id'];
        $song['display_title'] = music_catalog_display_title(
            (string) ($song['filename'] ?? ''),
            (string) ($song['indexed_title'] ?? ''),
            isset($song['title']) ? (string) $song['title'] : null,
        );
        if ($song['display_title'] === '') {
            $song['display_title'] = t(
                'admin.music.untitled_song',
                'Song #{id}',
                ['id' => $songId],
            );
        }
        $song['tags'] = $tagNames[$songId] ?? '';
        $song['tracks'] = $details[$songId]['tracks'] ?? [];
        $song['repository_assets'] = $details[$songId]['repository_assets'] ?? [];
        $song['direct_galleries'] = $details[$songId]['direct_galleries'] ?? [];
        $song['albums'] = $details[$songId]['albums'] ?? [];
        $song['live_albums'] = $details[$songId]['live_albums'] ?? [];
    }
    unset($song);
    return $preview;
}

/** Merge selected identities after checking the global revision.
 * @param list<int> $songIds Selected normalized song IDs.
 * @param int $survivorId Identity retaining its custom title.
 * @param string $revision Expected catalog revision.
 * @param string $tagPolicy Either union all selected tags or keep survivor tags.
 * @return string New decimal revision.
 * @throws \InvalidArgumentException When selection is invalid.
 * @throws \RuntimeException When schema is unavailable or revision is stale.
 */
function music_catalog_merge(array $songIds, int $survivorId, string $revision, string $tagPolicy = 'union'): string
{
    if (!in_array($tagPolicy, ['union', 'survivor'], true)) {
        throw new \InvalidArgumentException('Invalid merge tag policy.');
    }
    $preview = music_catalog_merge_preview($songIds, $survivorId);
    if (count($preview['songs']) !== count(array_unique($songIds))) {
        throw new \InvalidArgumentException('Unknown song identity.');
    }
    $ids = array_map('intval', array_column($preview['songs'], 'id'));
    $result = music_catalog_commit_mutation($revision, 'catalog.merge', static function () use ($ids, $survivorId, $tagPolicy): void {
            \Gallery\Models\music_catalog_model_merge(
                $ids,
                $survivorId,
                $tagPolicy,
                music_repository_is_ready_or_absent('catalog.merge'),
            );
    });
    return $result['revision'];
}

/** Set or clear one canonical song title override.
 * @param int $songId Stable normalized song identity.
 * @param string $title Exact override; empty clears it to restore fallback title.
 * @param string $revision Expected catalog revision.
 * @return string New decimal revision.
 * @throws \RuntimeException When input is invalid, schema unavailable or stale.
 */
function music_catalog_set_song_title(int $songId, string $title, string $revision): string
{
    if (
        $songId < 1
        || strlen($title) > MUSIC_CATALOG_TITLE_BYTES
        || preg_match('//u', $title) !== 1
        || preg_match('/[\x00-\x1f\x7f]/u', $title) === 1
    ) {
        throw new \RuntimeException('music.conflict');
    }
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.set_song_title',
        static function () use ($songId, $title): void {
            \Gallery\Models\music_catalog_model_set_title(
                $songId,
                $title,
            );
        },
    );
    return $result['revision'];
}

/** Replace the tags of one song within the catalog revision transaction.
 * @param int $songId Stable normalized song identity.
 * @param string $tagText Delimited music tag names supplied by the editor.
 * @param string $revision Expected catalog revision.
 * @return string New decimal revision.
 * @throws \InvalidArgumentException When identity or tag text is invalid.
 * @throws \RuntimeException When tag schema is unavailable or revision is stale.
 */
function music_catalog_sync_song_tags(int $songId, string $tagText, string $revision): string
{
    if ($songId < 1 || strlen($tagText) > MUSIC_CATALOG_TAG_TEXT_BYTES) {
        throw new \InvalidArgumentException('Invalid song tags.');
    }
    require_once __DIR__ . '/tag_metadata.php';
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.assign_tags',
        static function () use ($songId, $tagText): void {
            if (!sync_entity_tags('music_song', $songId, $tagText, 'music')) {
                throw new \RuntimeException('music.conflict');
            }
        },
    );
    return $result['revision'];
}

/** Return physical track references attached to one normalized identity.
 * @param int $songId Stable normalized song identity.
 * @return list<array{gallery_id:int,track_id:string,filename:string,asset_id:string|null,title:?string}> Active physical references and optional independent repository IDs.
 */
function music_catalog_song_tracks(int $songId): array
{
    return \Gallery\Models\music_catalog_model_song_tracks(
        $songId,
        music_repository_is_ready_or_absent('catalog.song_tracks'),
    );
}

/** Return the ordered flat album hierarchy with its song memberships.
 * @return list<array{id:int,parent_id:int|null,title:string,description:string|null,sort_order:int,revision:int,song_count:int}> Bounded album hierarchy.
 * @throws \RuntimeException When the hierarchy exceeds its documented node policy.
 */
function music_catalog_album_tree(): array
{
    $albums = [];
    $rows = \Gallery\Models\music_catalog_model_albums(
        MUSIC_CATALOG_ALBUM_MAX_NODES + 1,
    );
    if (count($rows) > MUSIC_CATALOG_ALBUM_MAX_NODES) {
        throw new \RuntimeException('music.album_limit');
    }
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        if (!isset($albums[$id])) {
            $albums[$id] = [
                'id' => $id,
                'parent_id' => $row['parent_id'] === null
                    ? null
                    : (int) $row['parent_id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'sort_order' => (int) $row['sort_order'],
                'revision' => (int) $row['revision'],
                'song_count' => (int) $row['song_count'],
            ];
        }
    }
    return array_values($albums);
}

/** Resolve paths for assigned albums without loading the full global tree.
 * @param list<int> $albumIds Distinct assigned album IDs from one raw playlist.
 * @return array<int,array{id:int,title:string,path:string,available:bool,song_count:int}> Paths and current member counts keyed by requested album ID; missing/cyclic nodes are unavailable.
 * @throws \InvalidArgumentException When the ID list exceeds the bounded source policy or contains invalid values.
 */
function music_catalog_album_paths(array $albumIds): array
{
    foreach ($albumIds as $id) {
        if (!is_int($id) || $id < 1) {
            throw new \InvalidArgumentException('Invalid assigned album identity.');
        }
    }
    $ids = array_values(array_unique($albumIds));
    if (count($ids) > MUSIC_CATALOG_GALLERY_SOURCE_LIMIT) {
        throw new \InvalidArgumentException('Too many assigned albums.');
    }
    $nodes = [];
    $pending = $ids;
    for ($level = 0; $level <= MUSIC_CATALOG_ALBUM_MAX_DEPTH && $pending !== []; $level++) {
        $batch = array_values(array_diff($pending, array_keys($nodes)));
        if ($batch === []) {
            break;
        }
        foreach (\Gallery\Models\music_catalog_model_album_nodes($batch) as $node) {
            $nodes[$node['id']] = $node;
        }
        $pending = [];
        foreach ($nodes as $node) {
            if ($node['parent_id'] !== null && !isset($nodes[$node['parent_id']])) {
                $pending[] = $node['parent_id'];
            }
        }
        $pending = array_values(array_unique($pending));
    }
    $paths = [];
    foreach ($ids as $id) {
        $cursor = $id;
        $seen = [];
        $parts = [];
        $available = true;
        while (true) {
            if (!isset($nodes[$cursor]) || isset($seen[$cursor]) || count($parts) >= MUSIC_CATALOG_ALBUM_MAX_DEPTH) {
                $available = false;
                break;
            }
            $seen[$cursor] = true;
            $node = $nodes[$cursor];
            array_unshift($parts, $node['title']);
            if ($node['parent_id'] === null) {
                break;
            }
            $cursor = $node['parent_id'];
        }
        $paths[$id] = [
            'id' => $id,
            'title' => isset($nodes[$id]) ? $nodes[$id]['title'] : '',
            'path' => $available ? implode(' / ', $parts) : '',
            'available' => $available,
            'song_count' => isset($nodes[$id]) ? (int) $nodes[$id]['song_count'] : 0,
        ];
    }
    return $paths;
}

/** Return a paged album member list, including identities outside catalog pages.
 * @param int $albumId Stable album identity.
 * @param int $limit Maximum members, bounded to one catalog page.
 * @param int $offset Zero-based membership offset.
 * @return list<array{id:int,membership_order:int,title:string,custom_title:?string,indexed_title:?string,filename:?string}> Canonically titled members in stored order.
 * @throws \InvalidArgumentException When album or pagination values are invalid.
 */
function music_catalog_album_members(int $albumId, int $limit = MUSIC_CATALOG_PAGE_SIZE, int $offset = 0): array
{
    if ($albumId < 1 || $limit < 1 || $limit > MUSIC_CATALOG_PAGE_SIZE || $offset < 0) {
        throw new \InvalidArgumentException('Invalid album page.');
    }
    $members = \Gallery\Models\music_catalog_model_album_members(
        $albumId,
        $limit,
        $offset,
        music_repository_is_ready_or_absent('catalog.album_members'),
    );
    foreach ($members as &$member) {
        $member['title'] = music_catalog_display_title(
            (string) ($member['filename'] ?? ''),
            (string) ($member['indexed_title'] ?? ''),
            isset($member['custom_title']) ? (string) $member['custom_title'] : null,
        );
        if ($member['title'] === '') {
            $member['title'] = t(
                'admin.music.untitled_song',
                'Song #{id}',
                ['id' => (int) $member['id']],
            );
        }
    }
    unset($member);
    return $members;
}

/** Search bounded songs and live albums for an authenticated gallery chooser.
 * @param string $query Search text for canonical song and full album titles.
 * @param int $limit Maximum rows per result type, capped at the chooser policy.
 * @return array{revision:string,songs:list<array{id:int,title:string,track_count:int}>,albums:list<array{id:int,title:string,song_count:int,path:string}>} Bounded choice lists with album breadcrumb paths.
 * @throws \InvalidArgumentException When query or result limit exceeds policy.
 * @throws \RuntimeException When repository schema is unavailable.
 */
function music_catalog_assignment_choices(string $query, int $limit = MUSIC_CATALOG_CHOOSER_LIMIT): array
{
    if (strlen($query) > MUSIC_CATALOG_QUERY_BYTES || $limit < 1 || $limit > MUSIC_CATALOG_CHOOSER_LIMIT) {
        throw new \InvalidArgumentException('Invalid assignment chooser query.');
    }
    music_repository_assert_schema_ready('catalog.assignment_choices');
    $catalog = music_catalog_list($query, $limit, 0, 'title', 'asc');
    $songs = [];
    foreach ($catalog['songs'] as $song) {
        $songs[] = [
            'id' => (int) $song['id'],
            'title' => (string) $song['title'],
            'track_count' => (int) $song['track_count'],
        ];
    }
    $rows = music_catalog_album_tree();
    $byId = [];
    foreach ($rows as $album) {
        $byId[(int) $album['id']] = $album;
    }
    $albums = [];
    foreach ($rows as $album) {
        $parts = [];
        $cursor = $album;
        while (true) {
            array_unshift($parts, (string) $cursor['title']);
            $parentId = $cursor['parent_id'];
            if ($parentId === null || !isset($byId[(int) $parentId])) {
                break;
            }
            $cursor = $byId[(int) $parentId];
        }
        $path = implode(' / ', $parts);
        if ($query !== '' && stripos($path, $query) === false) {
            continue;
        }
        $albums[] = [
            'id' => (int) $album['id'],
            'title' => (string) $album['title'],
            'song_count' => (int) $album['song_count'],
            'path' => $path,
        ];
        if (count($albums) >= $limit) {
            break;
        }
    }
    return ['revision' => music_catalog_revision(), 'songs' => $songs, 'albums' => $albums];
}

/** Create a nested album under a validated parent.
 * @param string $title Non-empty album label.
 * @param int|null $parentId Parent album, or null for a root album.
 * @param string $revision Expected catalog revision.
 * @param string $description Optional plain-text description.
 * @return array{revision:string,album_id:int} Created identity and new revision.
 * @throws \RuntimeException When input is invalid, parent unsafe, schema unavailable or stale.
 */
function music_catalog_album_create(string $title, ?int $parentId, string $revision, string $description = ''): array
{
    if (trim($title) === '' || strlen($title) > MUSIC_CATALOG_TITLE_BYTES || strlen($description) > MUSIC_CATALOG_DESCRIPTION_BYTES) {
        throw new \RuntimeException('music.conflict');
    }
    if ($parentId !== null) {
        music_catalog_assert_album_move_safe(0, $parentId);
    }
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.album_create',
        static fn(): int => \Gallery\Models\music_catalog_model_album_create(
            trim($title),
            $parentId,
            $description,
            MUSIC_CATALOG_ALBUM_MAX_NODES,
        ),
    );
    return ['revision' => $result['revision'], 'album_id' => $result['value']];
}

/** Update one album title and description under optimistic revision control.
 * @param int $albumId Stable album identity.
 * @param string $title Non-empty album label.
 * @param string $revision Expected catalog revision.
 * @param string $description Optional plain-text description.
 * @return string New decimal revision.
 * @throws \RuntimeException When input is invalid, schema unavailable or stale.
 */
function music_catalog_album_update(int $albumId, string $title, string $revision, string $description = ''): string
{
    if ($albumId < 1 || trim($title) === '' || strlen($title) > MUSIC_CATALOG_TITLE_BYTES || strlen($description) > MUSIC_CATALOG_DESCRIPTION_BYTES) {
        throw new \RuntimeException('music.conflict');
    }
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.album_update',
        static function () use ($albumId, $title, $description): void {
            \Gallery\Models\music_catalog_model_album_mutate(
                'update', $albumId, null, 0, trim($title), $description,
            );
        },
    );
    return $result['revision'];
}

/** Move an album while preventing cycles and excessive nested depth.
 * @param int $albumId Stable album identity being moved.
 * @param int|null $parentId Proposed parent album, or null for a root.
 * @param int $position Non-negative sibling order position.
 * @param string $revision Expected catalog revision.
 * @return string New decimal revision.
 * @throws \RuntimeException When hierarchy is invalid, schema unavailable or stale.
 */
function music_catalog_album_move(int $albumId, ?int $parentId, int $position, string $revision): string
{
    if ($albumId < 1 || $position < 0) {
        throw new \RuntimeException('music.conflict');
    }
    music_catalog_assert_album_move_safe($albumId, $parentId);
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.album_move',
        static function () use ($albumId, $parentId, $position): void {
            \Gallery\Models\music_catalog_model_album_mutate(
                'move', $albumId, $parentId, $position, '',
            );
        },
    );
    return $result['revision'];
}

/** Remove an album and its owned descendants and memberships.
 * @param int $albumId Stable album identity to remove.
 * @param string $revision Expected catalog revision.
 * @return string New decimal revision.
 * @throws \RuntimeException When identity is invalid, schema unavailable or stale.
 */
function music_catalog_album_delete(int $albumId, string $revision): string
{
    if ($albumId < 1) {
        throw new \RuntimeException('music.conflict');
    }
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.album_delete',
        static function () use ($albumId): void {
            \Gallery\Models\music_catalog_model_album_mutate(
                'delete', $albumId, null, 0, '',
            );
        },
    );
    return $result['revision'];
}

/** Add one song to an album at an explicit position or append a new member.
 * @param int $albumId Stable target album identity.
 * @param int $songId Stable normalized song identity.
 * @param int|null $position Non-negative ordering position, or null to append
 *   without moving a song that already belongs to the album.
 * @param string $revision Expected catalog revision.
 * @return string New decimal revision.
 * @throws \RuntimeException When inputs are invalid, schema unavailable or stale.
 */
function music_catalog_album_song_add(
    int $albumId,
    int $songId,
    ?int $position,
    string $revision,
): string
{
    if ($albumId < 1 || $songId < 1 || ($position !== null && $position < 0)) {
        throw new \RuntimeException('music.conflict');
    }
    music_catalog_assert_schema_ready('catalog.album_song_add');
    music_repository_assert_schema_ready('catalog.album_song_add');
    if (!hash_equals(music_catalog_revision(), $revision)) {
        throw new \RuntimeException('music.conflict');
    }
    if (!\Gallery\Models\music_catalog_model_album_exists($albumId)) {
        throw new \RuntimeException('music.album_missing');
    }
    $alreadyMember = \Gallery\Models\music_catalog_model_album_has_song(
        $albumId,
        $songId,
    );
    // Source promotion holds a gallery lock. Finish it before acquiring the
    // global catalog revision lock to preserve the established lock order.
    $asset = music_repository_prepare_song($songId);
    if (!$alreadyMember) {
        music_catalog_assert_album_growth_quota(
            $albumId,
            $songId,
            (int) $asset['byte_size'],
        );
    }
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.album_song_add',
        static function () use ($albumId, $songId, $position): void {
            \Gallery\Models\music_catalog_model_album_mutate(
                'song_add', $albumId, $songId, $position, '',
            );
        },
    );
    return $result['revision'];
}

/** Remove one song from an album.
 * @param int $albumId Stable album identity.
 * @param int $songId Stable normalized song identity.
 * @param string $revision Expected catalog revision.
 * @return string New decimal revision.
 * @throws \RuntimeException When inputs are invalid, schema unavailable or stale.
 */
function music_catalog_album_song_remove(int $albumId, int $songId, string $revision): string
{
    if ($albumId < 1 || $songId < 1) {
        throw new \RuntimeException('music.conflict');
    }
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.album_song_remove',
        static function () use ($albumId, $songId): void {
            \Gallery\Models\music_catalog_model_album_mutate(
                'song_remove', $albumId, $songId, 0, '',
            );
        },
    );
    return $result['revision'];
}

/** Refuse album growth that would overflow any currently linked gallery.
 * @param int $albumId Stable globally shared album identity.
 * @param int $songId Song proposed for membership.
 * @param int $assetBytes Verified central original size in bytes.
 * @return void Continue only when every live gallery remains within quota.
 * @throws \RuntimeException When effective state is incomplete or any playlist would exceed quota.
 */
function music_catalog_assert_album_growth_quota(int $albumId, int $songId, int $assetBytes): void
{
    if ($assetBytes < 0) {
        throw new \RuntimeException('music.linked_gallery_limit');
    }
    if (\Gallery\Models\music_catalog_model_album_has_song($albumId, $songId)) {
        return;
    }
    music_repository_assert_schema_ready('catalog.album_growth_quota');
    require_once __DIR__ . '/gallery_music.php';
    $assignments = \Gallery\Models\music_catalog_model_album_assignment_galleries($albumId);
    foreach ($assignments as $assignment) {
        $gallery = [
            'id' => $assignment['gallery_id'],
            'folder_path' => $assignment['folder_path'],
        ];
        $lock = gallery_music_acquire_lock($gallery);
        try {
            $playlist = gallery_music_playlist($gallery);
        } catch (\Throwable) {
            throw new \RuntimeException('music.linked_gallery_limit');
        } finally {
            gallery_music_release_lock($lock);
        }
        if (
            !empty($playlist['partial'])
            || !empty($playlist['over_limit'])
            || (int) ($playlist['unavailable'] ?? 0) > 0
        ) {
            throw new \RuntimeException('music.linked_gallery_limit');
        }
        $occurrences = (int) $assignment['occurrences'];
        $currentCount = count($playlist['tracks']);
        $currentBytes = array_sum(array_column($playlist['tracks'], 'size'));
        if (
            $occurrences < 1
            || $occurrences > GALLERY_MUSIC_MAX_TRACKS - $currentCount
            || $assetBytes > intdiv(PHP_INT_MAX, $occurrences)
            || $assetBytes * $occurrences > GALLERY_MUSIC_TOTAL_BYTES - $currentBytes
        ) {
            throw new \RuntimeException('music.linked_gallery_limit');
        }
    }
}

/** Split selected gallery-scoped physical references into a new song identity.
 * @param int $songId Existing normalized identity to split from.
 * @param list<array{gallery_id:int,track_id:string}> $trackIds Exact selected references.
 * @param string $revision Expected catalog revision.
 * @return array{revision:string,song_id:int} New identity and revision.
 * @throws \InvalidArgumentException When references are malformed or unknown.
 * @throws \RuntimeException When selection is invalid, schema unavailable or stale.
 */
function music_catalog_split(int $songId, array $trackIds, string $revision): array
{
    if ($songId < 1 || $trackIds === []) {
        throw new \RuntimeException('music.conflict');
    }
    $requested = [];
    foreach ($trackIds as $reference) {
        if (!is_array($reference) || !is_int($reference['gallery_id'] ?? null) || !is_string($reference['track_id'] ?? null)) {
            throw new \InvalidArgumentException('Invalid track reference.');
        }
        $requested[$reference['gallery_id'] . ':' . $reference['track_id']] = true;
    }
    $tracks = music_catalog_song_tracks($songId);
    $selected = array_values(array_filter($tracks, static fn(array $row): bool => isset(
        $requested[(int) $row['gallery_id'] . ':' . $row['track_id']],
    )));
    if (count($selected) !== count($requested)) {
        throw new \InvalidArgumentException('Unknown track reference.');
    }
    $result = music_catalog_commit_mutation(
        $revision,
        'catalog.split',
        static fn(): int => \Gallery\Models\music_catalog_model_split(
            $songId,
            $selected,
            music_repository_is_ready_or_absent('catalog.split'),
        ),
    );
    return ['revision' => $result['revision'], 'song_id' => $result['value']];
}
