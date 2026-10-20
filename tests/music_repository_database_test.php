<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/music_repository_database_test.php
 * Module Type: Disposable Database Regression Test
 * Purpose: Exercise central music assets and gallery usage counts with real persistence.
 * Responsibilities: Cover v1 promotion, live album projection, digest-unique branch reads, and attachment snapshot CAS.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the explicitly owned workflow database connection.
     * @return \PDO Dedicated disposable MySQL connection.
     */
    function db(): \PDO
    {
        return $GLOBALS['music_repository_fixture_db'];
    }

    /** Check strict containment for test-owned gallery filesystem roots.
     * @param string $root Canonical owned fixture root.
     * @param string $path Canonical candidate path.
     * @return bool Whether the candidate is a strict descendant of the root.
     */
    function path_inside(string $root, string $path): bool
    {
        return str_starts_with(
            $path,
            rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
        );
    }
}

namespace Gallery\Services {
    /** Resolve the workflow fixture's gallery root.
     * @return string Absolute path to the owned fixture galleries directory.
     */
    function galleries_root(): string
    {
        return $GLOBALS['music_repository_gallery_root'];
    }

    /** Resolve one gallery beneath the owned workflow fixture.
     * @param string $relative Relative gallery directory stored in SQL.
     * @return string Absolute confined gallery path.
     */
    function gallery_abs_path(string $relative): string
    {
        return galleries_root() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /** Supply deterministic English fallback text to real services.
     * @param string $key Translation identity used without a fallback.
     * @param string|array<string,bool|float|int|string|null>|null $fallback English fallback or legacy placeholder map.
     * @param array<string,bool|float|int|string|null> $parameters Scalar or null placeholder values.
     * @return string Fallback or key with fixture placeholder interpolation.
     */
    function t(
        string $key,
        string|array|null $fallback = null,
        array $parameters = [],
    ): string {
        if (is_array($fallback)) {
            $parameters = $fallback;
            $fallback = null;
        }
        $text = is_string($fallback) ? $fallback : $key;
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{' . (string) $name . '}'] = (string) $value;
        }
        return strtr($text, $replacements);
    }

    /** Confirm public access schema for the gallery lookup boundary in this fixture.
     * @return void The migrated disposable schema is controlled by this test.
     */
    function gallery_access_assert_public_policy_available(): void
    {
    }

    /** Confirm visibility schema for the gallery lookup boundary in this fixture.
     * @return void The migrated disposable schema is controlled by this test.
     */
    function gallery_visibility_assert_public_policy_available(): void
    {
    }

    /** Report the test-owned gallery access fields as available.
     * @return bool The disposable database includes the access migration.
     */
    function gallery_access_schema_ready(): bool
    {
        return true;
    }

    /** Apply fixture-owned ordinary visitor access to one gallery row.
     * @param array{id?:int|string,access_mode?:string} $gallery Full gallery row from the real descendant query.
     * @return bool Whether the fixture marks this gallery visitor-accessible, regardless of the simulated Admin session.
     */
    function visitor_can_access_gallery_without_admin_bypass(array $gallery): bool
    {
        return in_array(
            (int) ($gallery['id'] ?? 0),
            $GLOBALS['music_repository_usage_visitor_gallery_ids'] ?? [],
            true,
        );
    }

    /** Read one test-owned feature toggle with fail-closed defaults for unknown keys.
     * @param string $key Canonical feature capability key.
     * @param array<string,bool> $resolving Recursion guard accepted by the production signature.
     * @return bool Whether this fixture explicitly enables the requested count capability.
     */
    function feature_capability_effective_enabled(
        string $key,
        array $resolving = [],
    ): bool {
        return ($GLOBALS['music_repository_usage_capabilities'][$key] ?? false)
            === true;
    }
}

namespace {
    /** Bound synthetic gallery rows used by the real bounded migration worker.
     * Type: int. Units: owned galleries. Scope: one disposable repository test.
     * Consumers: migration source, destination playback, and quota fixtures.
     * Rationale: distinct owners isolate source migration from independent destination state. Value: 3.
     */
    const MUSIC_REPOSITORY_FIXTURE_GALLERY_COUNT = 3;

    /** Bound original tracks promoted by the repeated-gallery migration assertions.
     * Type: int. Units: source tracks. Scope: one owned v1 playlist.
     * Consumers: migration cursor and metadata-preservation assertions.
     * Rationale: two ordered tracks prove continuation remains within one gallery. Value: 2.
     */
    const MUSIC_REPOSITORY_MIGRATION_TRACK_COUNT = 2;

    /** Number of equal-sized references needed to hit the gallery byte ceiling.
     * Type: int. Units: repeated playlist references. Scope: one quota boundary.
     * Consumers: exact 512 MiB aggregate admission fixture.
     * Rationale: Eight 64 MiB references reach the configured total exactly. Value: 8.
     */
    const MUSIC_REPOSITORY_BYTE_BOUNDARY_TRACK_COUNT = 8;

    /** Amount by which the negative source fixture exceeds the source ceiling.
     * Type: int. Units: bytes. Scope: one rejected upload source.
     * Consumers: pre-publication oversize admission regression.
     * Rationale: One byte proves the strict upper bound at its adjacent value. Value: 1.
     */
    const MUSIC_REPOSITORY_OVERSIZE_SOURCE_DELTA_BYTES = 1;

    /** Give the projection fixture an overlapping branch and an independent root.
     * Type: int. Units: physical galleries. Scope: digest-unique usage assertions.
     * Consumers: root, descendant, and unrelated card branch setup.
     * Rationale: a root plus one child proves recursive deduplication; one sibling proves branch isolation. Value: 3.
     */
    const MUSIC_REPOSITORY_USAGE_GALLERY_COUNT = 3;

    /** Keep synthetic repository metadata structurally valid without writing originals.
     * Type: int. Units: bytes. Scope: database-only digest projection assets.
     * Consumers: canonical live-album reader fixtures.
     * Rationale: the projection must read metadata without opening or hashing media files. Value: 1 byte per synthetic asset.
     */
    const MUSIC_REPOSITORY_USAGE_ASSET_BYTES = 1;

    require_once __DIR__ . '/support/gallery_workflow_safety.php';
    require_once __DIR__ . '/../app/runtime/storage_observer.php';
    require_once __DIR__ . '/../app/helpers_runtime.php';
    require_once __DIR__ . '/../app/models/app_settings.php';
    require_once __DIR__ . '/../app/models/schema_inspection.php';
    require_once __DIR__ . '/../app/services/schema_inspection.php';
    require_once __DIR__ . '/../app/services/presentation_schema_policy.php';
    require_once __DIR__ . '/../app/models/galleries.php';
    require_once __DIR__ . '/../app/models/music_catalog.php';
    require_once __DIR__ . '/../app/models/gallery_music.php';
    require_once __DIR__ . '/../app/services/music_catalog.php';
    require_once __DIR__ . '/../app/services/gallery_music.php';
    require_once __DIR__ . '/../app/services/gallery_lookup.php';

    use function GalleryWorkflow\check;
    use function GalleryWorkflow\fixtureDatabase;
    use function GalleryWorkflow\validateFixture;
    use function Gallery\Services\app_settings_reset_request_cache;
    use function Gallery\Services\gallery_music_directory;
    use function Gallery\Services\gallery_music_migrate_repository;
    use function Gallery\Services\gallery_music_mutate;
    use function Gallery\Services\gallery_music_playlist;
    use function Gallery\Services\gallery_music_state;
    use function Gallery\Services\music_catalog_album_create;
    use function Gallery\Services\music_catalog_album_members;
    use function Gallery\Services\music_catalog_album_song_add;
    use function Gallery\Services\music_catalog_album_song_remove;
    use function Gallery\Services\music_catalog_album_update;
    use function Gallery\Services\music_catalog_list;
    use function Gallery\Services\music_catalog_revision;
    use function Gallery\Services\music_catalog_song_details;
    use function Gallery\Services\music_catalog_sync_gallery_snapshot;
    use function Gallery\Services\music_catalog_usage;
    use function Gallery\Services\music_repository_asset_path;
    use function Gallery\Services\music_repository_directory;
    use function Gallery\Services\music_repository_store_source;
    use function Gallery\Services\gallery_branch_attachment_usage_counts;
    use function Gallery\Services\schema_inspection_reset_request_cache;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
    use function Gallery\Models\gallery_model_attachment_usage_mark_dirty;
    use function Gallery\Models\gallery_model_attachment_usage_replace;
    use function Gallery\Models\gallery_model_attachment_usage_rows;
    use function Gallery\Models\gallery_model_attachment_usage_seed_empty;
    use function Gallery\Services\presentation_gallery_attachment_usage_schema_status;
    use function Gallery\Services\presentation_schema_render_available;
    use function Gallery\Models\music_repository_model_asset;
    use function Gallery\Models\music_repository_model_register_asset;
    use function Gallery\Models\gallery_music_model_write;
    use const Gallery\Services\GALLERY_MUSIC_MAX_BYTES;
    use const Gallery\Services\GALLERY_MUSIC_MAX_TRACKS;
    use const Gallery\Services\GALLERY_MUSIC_TOTAL_BYTES;

    /** Create a small valid PCM WAV payload for fileinfo-backed service tests.
     * @return string Mono 8-bit PCM WAV bytes used only inside the owned fixture.
     */
    function music_repository_fixture_wav(): string
    {
        $samples = str_repeat("\x80", 8);
        return 'RIFF'
            . pack('V', 36 + strlen($samples))
            . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
            . 'data'
            . pack('V', strlen($samples))
            . $samples;
    }

    /** Publish one complete derived snapshot through the real model generation checks.
     * @param int $galleryId Owned fixture gallery identity.
     * @param string $kind Projection category, either PDF or music.
     * @param list<array{source_id:string,sha256:?string,asset_id:?string,album_id:?int,hidden:bool}> $items Complete source projection for this gallery and category.
     * @return string The current published generation token.
     */
    function music_repository_usage_publish(
        int $galleryId,
        string $kind,
        array $items,
    ): string {
        $revision = gallery_model_attachment_usage_mark_dirty(
            $galleryId,
            $kind,
        );
        check(
            gallery_model_attachment_usage_replace(
                $galleryId,
                $kind,
                $items,
                $revision,
            ),
            'Current attachment usage generation must publish its complete snapshot.',
        );
        return $revision;
    }

    if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
        $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
        echo ($required ? 'BLOCKED' : 'SKIP')
            . " music repository requires an owned disposable database\n";
        exit($required ? 1 : 0);
    }

    $pdo = null;
    $fixtureGalleryIds = [];
    $fixtureGalleryPaths = [];
    $fixtureSongIds = [];
    $fixtureAlbumIds = [];
    $fixtureTrackIds = [];
    $fixtureAssetIds = [];
    $fixtureAssetPaths = [];
    $fixtureSourcePaths = [];
    $fixtureDirectories = [];
    $repositoryDirectoryCreated = false;
    $themeRevisionSettingCaptured = false;
    $originalThemeRevisionSetting = null;
    try {
        $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
        $directory = validateFixture(
            (string) getenv('GALLERY_WORKFLOW_FIXTURE'),
            $token,
        );
        $pdo = fixtureDatabase($directory, $token);
        $GLOBALS['music_repository_fixture_db'] = $pdo;
        $themeRevisionQuery = $pdo->prepare(
            'SELECT setting_value, updated_at FROM app_settings '
                . 'WHERE setting_key = ?',
        );
        $themeRevisionQuery->execute(['theme_public_content_revision']);
        $themeRevisionRow = $themeRevisionQuery->fetch(\PDO::FETCH_ASSOC);
        $themeRevisionSettingCaptured = true;
        if (is_array($themeRevisionRow)) {
            $originalThemeRevisionSetting = [
                'setting_value' => (string) $themeRevisionRow['setting_value'],
                'updated_at' => (string) $themeRevisionRow['updated_at'],
            ];
        }
        $galleryRoot = $directory . DIRECTORY_SEPARATOR . 'galleries';
        $GLOBALS['music_repository_gallery_root'] = $galleryRoot;

        $repositoryExisted = is_dir(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data'
                . DIRECTORY_SEPARATOR . 'music',
        );
        $repositoryDirectoryCreated = !$repositoryExisted;

        $migration = require __DIR__
            . '/../database/migrations/202610200003_music_repository.php';
        foreach ($migration['statements'] as $statement) {
            $pdo->exec($statement);
        }
        $migration['after']($pdo);
        $usageMigration = require __DIR__
            . '/../database/migrations/202610200004_gallery_attachment_usage.php';
        foreach ($usageMigration['statements'] as $statement) {
            $pdo->exec($statement);
        }

        $insertGallery = $pdo->prepare(
            'INSERT INTO galleries '
                . '(parent_id, folder_path, folder_path_hash, slug, title, '
                . 'description, visibility, created_at, updated_at) '
                . 'VALUES (NULL, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
        );
        for ($index = 0; $index < MUSIC_REPOSITORY_FIXTURE_GALLERY_COUNT; $index++) {
            $folder = 'music-repository-' . $token . '-' . $index;
            $insertGallery->execute([
                $folder,
                hash('sha256', $folder),
                'music-repository-' . $token . '-' . $index,
                'Music repository fixture ' . $index . ' ' . $token,
                '',
                'public',
            ]);
            $galleryId = (int) $pdo->lastInsertId();
            $fixtureGalleryIds[] = $galleryId;
            $fixtureGalleryPaths[$galleryId] = $folder;
            $absolute = $galleryRoot . DIRECTORY_SEPARATOR . $folder;
            if (!mkdir($absolute, 0700, true) && !is_dir($absolute)) {
                throw new \RuntimeException('Owned gallery fixture could not be created.');
            }
            $fixtureDirectories[$galleryId] = $absolute;
        }
        $sourceId = $fixtureGalleryIds[0];
        $destinationId = $fixtureGalleryIds[1];
        $secondDestinationId = $fixtureGalleryIds[2];
        $source = [
            'id' => $sourceId,
            'folder_path' => $fixtureGalleryPaths[$sourceId],
        ];
        $destination = [
            'id' => $destinationId,
            'folder_path' => $fixtureGalleryPaths[$destinationId],
        ];
        $secondDestination = [
            'id' => $secondDestinationId,
            'folder_path' => $fixtureGalleryPaths[$secondDestinationId],
        ];
        foreach ([$source, $destination, $secondDestination] as $gallery) {
            $musicDirectory = gallery_music_directory($gallery);
            if (!mkdir($musicDirectory, 0700) && !is_dir($musicDirectory)) {
                throw new \RuntimeException('Owned music sidecar could not be created.');
            }
        }

        $sourceDirectory = gallery_music_directory($source);
        $trackIds = [
            '00000000000000000000000000000001',
            '00000000000000000000000000000002',
        ];
        $fixtureTrackIds = $trackIds;
        $wav = music_repository_fixture_wav();
        $sha = hash('sha256', $wav);
        $legacyTracks = [];
        $snapshotTracks = [];
        foreach ($trackIds as $position => $trackId) {
            $filename = 'Repository Fixture ' . $position . '.wav';
            $localPath = $sourceDirectory . DIRECTORY_SEPARATOR . $trackId . '.bin';
            file_put_contents($localPath, $wav);
            $legacyTracks[] = [
                'id' => $trackId,
                'filename' => $filename,
                'title' => 'Indexed ' . $position . ' ' . $token,
                'mime' => 'audio/wav',
                'size' => strlen($wav),
                'sha256' => $sha,
                'hidden' => $position === 1,
                'downloadable' => $position === 0,
            ];
            $snapshotTracks[] = [
                'id' => $trackId,
                'title' => 'Indexed ' . $position . ' ' . $token,
                'filename' => $filename,
                'sha256' => $sha,
                'mime' => 'audio/wav',
                'size' => strlen($wav),
                'hidden' => $position === 1,
                'downloadable' => $position === 0,
            ];
        }
        $v1Revision = substr(hash('sha256', $token . ':repository-v1'), 0, 32);
        file_put_contents($sourceDirectory . DIRECTORY_SEPARATOR . 'index.json', json_encode([
            'format' => 'gallery_music_v1',
            'revision' => $v1Revision,
            'enabled' => true,
            'tracks' => $legacyTracks,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        music_catalog_sync_gallery_snapshot(
            $sourceId,
            $snapshotTracks,
            music_catalog_revision(),
        );
        $songRows = $pdo->prepare(
            'SELECT track_id, song_id FROM music_track_instances '
                . 'WHERE gallery_id = ? ORDER BY track_id',
        );
        $songRows->execute([$sourceId]);
        $initialSongs = $songRows->fetchAll(\PDO::FETCH_KEY_PAIR);
        check(
            count($initialSongs) === MUSIC_REPOSITORY_MIGRATION_TRACK_COUNT,
            'Both v1 source tracks must have real pre-migration catalog identities.',
        );
        foreach ($initialSongs as $songId) {
            $fixtureSongIds[] = (int) $songId;
        }

        $firstMigration = gallery_music_migrate_repository([
            'gallery_id' => $sourceId - 1,
            'track_id' => '',
        ]);
        $afterFirst = gallery_music_state($source);
        check(
            $firstMigration['moved'] === 1
                && $firstMigration['cursor']['gallery_id'] === $sourceId
                && $firstMigration['cursor']['track_id'] === $trackIds[0]
                && $afterFirst['version'] === 2
                && isset($afterFirst['tracks'][0]['asset_id'])
                && is_file($sourceDirectory . DIRECTORY_SEPARATOR . $trackIds[1] . '.bin'),
            'First bounded promotion must switch the same gallery to v2 and retain the next legacy original.',
        );
        $fixtureAssetIds[] = (string) $afterFirst['tracks'][0]['asset_id'];
        $fixtureAssetPaths[] = music_repository_directory() . DIRECTORY_SEPARATOR
            . $afterFirst['tracks'][0]['asset_id'] . '.bin';
        $secondMigration = gallery_music_migrate_repository($firstMigration['cursor']);
        $afterSecond = gallery_music_state($source);
        check(
            $secondMigration['moved'] === 1
                && $secondMigration['cursor']['gallery_id'] === $sourceId
                && $secondMigration['cursor']['track_id'] === $trackIds[1]
                && count(array_filter(
                    $afterSecond['tracks'],
                    static fn(array $track): bool => isset($track['asset_id']),
                )) === MUSIC_REPOSITORY_MIGRATION_TRACK_COUNT
                && !is_file($sourceDirectory . DIRECTORY_SEPARATOR . $trackIds[0] . '.bin')
                && !is_file($sourceDirectory . DIRECTORY_SEPARATOR . $trackIds[1] . '.bin'),
            'Second bounded promotion must resume within the same gallery and remove only verified originals.',
        );
        $fixtureAssetIds[] = (string) $afterSecond['tracks'][1]['asset_id'];
        $fixtureAssetPaths[] = music_repository_directory() . DIRECTORY_SEPARATOR
            . $afterSecond['tracks'][1]['asset_id'] . '.bin';
        check(
            array_column($afterSecond['tracks'], 'id') === $trackIds
                && array_column($afterSecond['tracks'], 'title') === array_column($legacyTracks, 'title')
                && array_column($afterSecond['tracks'], 'downloadable') === [true, false]
                && array_column($afterSecond['tracks'], 'hidden') === [false, true],
            'Repository promotion must preserve track identities, titles, order, visibility and download flags.',
        );

        // A deterministic origin-metadata conflict fails after the new central
        // file is published but before its asset row can be registered.
        $conflictSource = $directory . DIRECTORY_SEPARATOR
            . 'owned-origin-conflict.wav';
        $fixtureSourcePaths[] = $conflictSource;
        file_put_contents($conflictSource, $wav);
        $repositoryDirectory = music_repository_directory();
        $centralFilesBefore = glob(
            $repositoryDirectory . DIRECTORY_SEPARATOR . '*.bin',
        ) ?: [];
        sort($centralFilesBefore, SORT_STRING);
        $assetsBeforeConflict = (int) $pdo->query(
            'SELECT COUNT(*) FROM music_repository_assets',
        )->fetchColumn();
        $originAssetId = (string) $afterSecond['tracks'][0]['asset_id'];
        $conflictRefused = false;
        try {
            music_repository_store_source(
                $conflictSource,
                'Conflicting origin ' . $token . '.wav',
                (int) $initialSongs[$trackIds[0]],
                ['gallery_id' => $sourceId, 'track_id' => $trackIds[0]],
                'Conflicting origin ' . $token,
            );
        } catch (\RuntimeException) {
            $conflictRefused = true;
        }
        $centralFilesAfter = glob(
            $repositoryDirectory . DIRECTORY_SEPARATOR . '*.bin',
        ) ?: [];
        sort($centralFilesAfter, SORT_STRING);
        $originAsset = music_repository_model_asset($originAssetId);
        check(
            $conflictRefused
                && $centralFilesAfter === $centralFilesBefore
                && (int) $pdo->query(
                    'SELECT COUNT(*) FROM music_repository_assets',
                )->fetchColumn() === $assetsBeforeConflict
                && is_file($conflictSource)
                && $originAsset !== null
                && music_repository_asset_path($originAsset) !== null,
            'A failed pre-registration conflict must remove only its new central file and preserve the source and registered origin.',
        );

        // Four additional source rows model interruption states without
        // depending on process-kill timing: two unsafe central copies and two
        // durable cleanup markers from the index-switched phase.
        $pendingIds = [
            '00000000000000000000000000000003',
            '00000000000000000000000000000004',
            '00000000000000000000000000000005',
            '00000000000000000000000000000006',
        ];
        array_push($fixtureTrackIds, ...$pendingIds);
        $pendingSnapshotRows = [];
        $pendingAssets = [];
        foreach ($pendingIds as $position => $trackId) {
            $filename = 'Pending repository ' . $position . ' ' . $token . '.wav';
            $localPath = $sourceDirectory . DIRECTORY_SEPARATOR . $trackId . '.bin';
            file_put_contents($localPath, $wav);
            $pendingSnapshotRows[] = [
                'id' => $trackId,
                'title' => 'Pending ' . $position . ' ' . $token,
                'filename' => $filename,
                'sha256' => $sha,
                'mime' => 'audio/wav',
                'size' => strlen($wav),
                'hidden' => false,
                'downloadable' => false,
            ];
        }
        music_catalog_sync_gallery_snapshot(
            $sourceId,
            array_merge($snapshotTracks, $pendingSnapshotRows),
            music_catalog_revision(),
        );
        $pendingSongLookup = $pdo->prepare(
            'SELECT song_id FROM music_track_instances '
                . 'WHERE gallery_id = ? AND track_id = ?',
        );
        foreach ($pendingIds as $position => $trackId) {
            $pendingSongLookup->execute([$sourceId, $trackId]);
            $songId = (int) $pendingSongLookup->fetchColumn();
            $fixtureSongIds[] = $songId;
            $asset = music_repository_store_source(
                $sourceDirectory . DIRECTORY_SEPARATOR . $trackId . '.bin',
                'Pending repository ' . $position . ' ' . $token . '.wav',
                $songId,
                ['gallery_id' => $sourceId, 'track_id' => $trackId],
                'Pending ' . $position . ' ' . $token,
            );
            $pendingAssets[$trackId] = $asset;
            $fixtureAssetIds[] = $asset['id'];
            $fixtureAssetPaths[] = music_repository_directory() . DIRECTORY_SEPARATOR
                . $asset['id'] . '.bin';
        }
        $v2Tracks = $afterSecond['tracks'];
        foreach ($pendingSnapshotRows as $row) {
            $trackId = $row['id'];
            $v2Tracks[] = $row + [
                'asset_id' => $pendingAssets[$trackId]['id'],
            ];
        }
        music_catalog_sync_gallery_snapshot(
            $sourceId,
            $v2Tracks,
            music_catalog_revision(),
        );
        $cleanupMarkers = [];
        foreach ($pendingIds as $trackId) {
            $cleanupMarkers[] = [
                'track_id' => $trackId,
                'size' => strlen($wav),
                'sha256' => $sha,
            ];
        }
        gallery_music_model_write($sourceDirectory, [
            'revision' => substr(hash('sha256', $token . ':pending-v2'), 0, 32),
            'enabled' => true,
            'tracks' => $v2Tracks,
            'albums' => [],
            'order' => array_column($v2Tracks, 'id'),
            'legacy_cleanup' => $cleanupMarkers,
        ]);
        $missingAssetPath = music_repository_directory() . DIRECTORY_SEPARATOR
            . $pendingAssets[$pendingIds[0]]['id'] . '.bin';
        $corruptAssetPath = music_repository_directory() . DIRECTORY_SEPARATOR
            . $pendingAssets[$pendingIds[1]]['id'] . '.bin';
        unlink($missingAssetPath);
        file_put_contents($corruptAssetPath, str_repeat('x', strlen($wav)));
        $unsafeIndexBytes = file_get_contents(
            $sourceDirectory . DIRECTORY_SEPARATOR . 'index.json',
        );
        $missingCentral = gallery_music_migrate_repository([
            'gallery_id' => $sourceId,
            'track_id' => $trackIds[1],
        ]);
        check(
            ($missingCentral['error']['code'] ?? '') === 'asset_unverified'
                && is_file($sourceDirectory . DIRECTORY_SEPARATOR . $pendingIds[0] . '.bin')
                && file_get_contents($sourceDirectory . DIRECTORY_SEPARATOR . 'index.json')
                    === $unsafeIndexBytes,
            'A missing registered central asset must preserve its local source and switched index.',
        );
        $corruptCentral = gallery_music_migrate_repository([
            'gallery_id' => $sourceId,
            'track_id' => $pendingIds[0],
        ]);
        check(
            ($corruptCentral['error']['code'] ?? '') === 'asset_unverified'
                && is_file($sourceDirectory . DIRECTORY_SEPARATOR . $pendingIds[1] . '.bin')
                && file_get_contents($sourceDirectory . DIRECTORY_SEPARATOR . 'index.json')
                    === $unsafeIndexBytes,
            'A corrupt registered central asset must block unlink and index mutation.',
        );
        $afterIndexSwitch = gallery_music_migrate_repository([
            'gallery_id' => $sourceId,
            'track_id' => $pendingIds[1],
        ]);
        $afterIndexState = gallery_music_state($source);
        check(
            $afterIndexSwitch['moved'] === 1
                && !is_file($sourceDirectory . DIRECTORY_SEPARATOR . $pendingIds[2] . '.bin')
                && !in_array(
                    $pendingIds[2],
                    array_column($afterIndexState['legacy_cleanup'], 'track_id'),
                    true,
                ),
            'Replay after the v2 index switch must verify central bytes, remove the owned local copy and clear its marker.',
        );
        unlink($sourceDirectory . DIRECTORY_SEPARATOR . $pendingIds[3] . '.bin');
        $afterUnlink = gallery_music_migrate_repository([
            'gallery_id' => $sourceId,
            'track_id' => $pendingIds[2],
        ]);
        $afterUnlinkState = gallery_music_state($source);
        check(
            $afterUnlink['moved'] === 1
                && !in_array(
                    $pendingIds[3],
                    array_column($afterUnlinkState['legacy_cleanup'], 'track_id'),
                    true,
                ),
            'Replay after local unlink must clear the durable cleanup marker without requiring the missing source.',
        );

        $assetForSong = music_repository_model_asset(
            (string) $afterSecond['tracks'][0]['asset_id'],
        );
        check(
            $assetForSong !== null
                && music_repository_asset_path($assetForSong) !== null,
            'A promoted asset must resolve to its verified central bytes.',
        );

        $uploadSource = $directory . DIRECTORY_SEPARATOR . 'owned-upload.wav';
        $fixtureSourcePaths[] = $uploadSource;
        file_put_contents($uploadSource, $wav);
        $upload = gallery_music_mutate(
            $destination,
            str_repeat('0', 32),
            'upload',
            '',
            $uploadSource,
            'Central upload ' . $token . '.wav',
        );
        $destinationState = gallery_music_state($destination);
        $uploadedTrack = $destinationState['tracks'][0];
        $fixtureTrackIds[] = $uploadedTrack['id'];
        $fixtureAssetIds[] = $uploadedTrack['asset_id'];
        $fixtureAssetPaths[] = music_repository_directory() . DIRECTORY_SEPARATOR
            . $uploadedTrack['asset_id'] . '.bin';
        $uploadSongId = (int) $pdo->query(
            'SELECT song_id FROM music_track_instances WHERE gallery_id = '
                . $destinationId . ' AND track_id = ' . $pdo->quote($uploadedTrack['id']),
        )->fetchColumn();
        $fixtureSongIds[] = $uploadSongId;
        check(
            $upload['track_id'] === $uploadedTrack['id']
                && isset($uploadedTrack['asset_id'])
                && !is_file(gallery_music_directory($destination) . DIRECTORY_SEPARATOR
                    . $uploadedTrack['id'] . '.bin')
                && is_file(music_repository_directory() . DIRECTORY_SEPARATOR
                    . $uploadedTrack['asset_id'] . '.bin'),
            'New upload must create a central original and no gallery-local bin.',
        );
        $beforeStaleBytes = file_get_contents(
            gallery_music_directory($destination) . DIRECTORY_SEPARATOR . 'index.json',
        );
        $staleRefused = false;
        try {
            gallery_music_mutate(
                $destination,
                str_repeat('0', 32),
                'settings',
                '',
                '',
                '',
                true,
            );
        } catch (\RuntimeException) {
            $staleRefused = true;
        }
        check(
            $staleRefused
                && file_get_contents(
                    gallery_music_directory($destination) . DIRECTORY_SEPARATOR . 'index.json',
                ) === $beforeStaleBytes,
            'Stale gallery CAS must refuse without changing the committed playlist bytes.',
        );

        $firstSongId = (int) $initialSongs[$trackIds[0]];
        $secondSongId = (int) $initialSongs[$trackIds[1]];
        $nowCatalogRevision = music_catalog_revision();
        $assign = gallery_music_mutate(
            $destination,
            gallery_music_state($destination)['write_revision'],
            'add_song',
            '',
            '',
            '',
            false,
            false,
            $firstSongId,
            null,
            $nowCatalogRevision,
        );
        check(
            count(gallery_music_state($destination)['tracks']) === 2
                && count(gallery_music_state($destination)['albums']) === 0
                && count(gallery_music_playlist($destination)['tracks']) === 2,
            'Selected-song assignment must remain a direct independent playlist item.',
        );

        $album = music_catalog_album_create(
            'Live repository album ' . $token,
            null,
            music_catalog_revision(),
        );
        $fixtureAlbumIds[] = (int) $album['album_id'];
        $catalogRevision = music_catalog_album_song_add(
            (int) $album['album_id'],
            $firstSongId,
            null,
            $album['revision'],
        );
        $catalogRevision = music_catalog_album_song_add(
            (int) $album['album_id'],
            $secondSongId,
            null,
            $catalogRevision,
        );
        $secondDirectAssignment = gallery_music_mutate(
            $secondDestination,
            gallery_music_state($secondDestination)['write_revision'],
            'add_song',
            '',
            '',
            '',
            false,
            false,
            $firstSongId,
            null,
            music_catalog_revision(),
        );
        $secondDirectTrackId = (string) $secondDirectAssignment['track_id'];
        $fixtureTrackIds[] = $secondDirectTrackId;
        $catalogRevision = music_catalog_revision();
        $albumAssignment = gallery_music_mutate(
            $secondDestination,
            gallery_music_state($secondDestination)['write_revision'],
            'add_album',
            '',
            '',
            '',
            false,
            false,
            (int) $album['album_id'],
            null,
            $catalogRevision,
        );
        $secondState = gallery_music_state($secondDestination);
        $secondPlaylist = gallery_music_playlist($secondDestination);
        check(
            $albumAssignment['track_id'] !== null
                && count($secondState['tracks']) === 1
                && count($secondState['albums']) === 1
                && count($secondPlaylist['tracks']) === 3,
            'A live album assignment must expand current catalog members without serializing them as direct tracks.',
        );
        $indexAfterAssignment = file_get_contents(
            gallery_music_directory($secondDestination) . DIRECTORY_SEPARATOR . 'index.json',
        );
        // Adding the live source commits a catalog snapshot and advances CAS.
        $catalogRevision = music_catalog_revision();
        $catalogRevision = music_catalog_album_update(
            (int) $album['album_id'],
            'Renamed live album ' . $token,
            $catalogRevision,
        );
        $renamedAlbumSources = array_values(array_filter(
            gallery_music_playlist($secondDestination, true)['tracks'],
            static fn(array $track): bool => $track['source_type'] === 'album',
        ));
        check(
            count($renamedAlbumSources) === 2
                && array_unique(array_column($renamedAlbumSources, 'source_title'))
                    === ['Renamed live album ' . $token]
                && file_get_contents(
                    gallery_music_directory($secondDestination) . DIRECTORY_SEPARATOR . 'index.json',
                ) === $indexAfterAssignment,
            'Album rename must update live playback metadata without rewriting gallery JSON.',
        );
        $catalogRevision = music_catalog_album_song_remove(
            (int) $album['album_id'],
            $secondSongId,
            $catalogRevision,
        );
        check(
            count(gallery_music_playlist($secondDestination)['tracks']) === 2
                && file_get_contents(
                    gallery_music_directory($secondDestination) . DIRECTORY_SEPARATOR . 'index.json',
                ) === $indexAfterAssignment,
            'Live album membership removal must update projection without rewriting the assignment index.',
        );
        $catalogRevision = music_catalog_album_song_add(
            (int) $album['album_id'],
            $secondSongId,
            0,
            $catalogRevision,
        );
        $liveAlbumTrackIds = array_values(array_map(
            static fn(array $track): string => (string) $track['source_track_id'],
            array_values(array_filter(
                gallery_music_playlist($secondDestination, true)['tracks'],
                static fn(array $track): bool => $track['source_type'] === 'album',
            )),
        ));
        check(
            $liveAlbumTrackIds === [(string) $secondSongId, (string) $firstSongId]
                && file_get_contents(
                    gallery_music_directory($secondDestination) . DIRECTORY_SEPARATOR . 'index.json',
                ) === $indexAfterAssignment,
            'Album reorder must immediately change live playback order while retaining the unchanged gallery assignment JSON.',
        );

        gallery_music_mutate(
            $secondDestination,
            gallery_music_state($secondDestination)['write_revision'],
            'download_all',
            '',
            '',
            '',
            false,
            true,
        );
        $destinationBeforeDownload = gallery_music_state($destination);
        check(
            $destinationBeforeDownload['tracks'][0]['downloadable'] === false
                && gallery_music_state($secondDestination)['tracks'][0]['downloadable'] === true
                && gallery_music_state($secondDestination)['albums'][0]['downloadable'] === true,
            'Download permission must remain scoped to each gallery assignment.',
        );

        $firstSongDetails = music_catalog_song_details([$firstSongId])[$firstSongId];
        $firstSongUsage = music_catalog_usage($firstSongId);
        check(
            count($firstSongDetails['repository_assets']) === 1
                && count($firstSongDetails['tracks']) === 3
                && count($firstSongUsage['live_albums']) === 1,
            'A shared immutable asset counts once while retaining its physical references and live-album usage.',
        );

        $quotaAlbum = music_catalog_album_create(
            'Quota fixture album ' . $token,
            null,
            music_catalog_revision(),
        );
        $fixtureAlbumIds[] = (int) $quotaAlbum['album_id'];
        $quotaAssignmentId = substr(
            hash('sha256', $token . ':quota-assignment'),
            0,
            32,
        );
        // Keep one physical slot for the live album member below. The
        // gallery's projected source count includes both direct tracks and
        // album members, so 99 direct tracks plus one album song is the exact
        // accepted boundary.
        $quotaTrackRows = [];
        $quotaTracks = [];
        for ($index = 0; $index < GALLERY_MUSIC_MAX_TRACKS - 1; $index++) {
            $trackId = substr(
                hash('sha256', $token . ':quota-track:' . $index),
                0,
                32,
            );
            $fixtureTrackIds[] = $trackId;
            $row = [
                'id' => $trackId,
                'title' => 'Quota source ' . $index . ' ' . $token,
                'filename' => 'Quota source ' . $index . ' ' . $token . '.wav',
                'sha256' => (string) $assetForSong['sha256'],
                'mime' => (string) $assetForSong['mime'],
                'size' => (int) $assetForSong['byte_size'],
                'hidden' => false,
                'downloadable' => false,
                'asset_id' => (string) $assetForSong['id'],
            ];
            $quotaTracks[] = $row;
            $quotaTrackRows[] = [
                'id' => $trackId,
                'title' => $row['title'],
                'filename' => $row['filename'],
                'sha256' => $row['sha256'],
                'mime' => $row['mime'],
                'size' => $row['size'],
                'hidden' => false,
                'downloadable' => false,
                'asset_id' => $row['asset_id'],
            ];
        }
        music_catalog_album_song_add(
            (int) $quotaAlbum['album_id'],
            $firstSongId,
            null,
            music_catalog_revision(),
        );
        music_catalog_sync_gallery_snapshot(
            $secondDestinationId,
            $quotaTrackRows,
            music_catalog_revision(),
            [[
                'id' => $quotaAssignmentId,
                'album_id' => (int) $quotaAlbum['album_id'],
                'hidden' => false,
                'downloadable' => false,
            ]],
        );
        gallery_music_model_write(
            gallery_music_directory($secondDestination),
            [
                'revision' => substr(hash('sha256', $token . ':quota-v2'), 0, 32),
                'enabled' => false,
                'tracks' => $quotaTracks,
                'albums' => [[
                    'id' => $quotaAssignmentId,
                    'album_id' => (int) $quotaAlbum['album_id'],
                    'hidden' => false,
                    'downloadable' => false,
                ]],
                'order' => array_merge(
                    array_column($quotaTracks, 'id'),
                    [$quotaAssignmentId],
                ),
                'legacy_cleanup' => [],
            ],
        );
        check(
            count(gallery_music_playlist($secondDestination)['tracks'])
                === GALLERY_MUSIC_MAX_TRACKS,
            'The quota fixture must seed exactly 100 projected sources including its live album member.',
        );
        $quotaIndexBefore = file_get_contents(
            gallery_music_directory($secondDestination) . DIRECTORY_SEPARATOR . 'index.json',
        );
        $quotaRevisionBefore = music_catalog_revision();
        $quotaRefused = false;
        try {
            music_catalog_album_song_add(
                (int) $quotaAlbum['album_id'],
                $secondSongId,
                null,
                $quotaRevisionBefore,
            );
        } catch (\RuntimeException) {
            $quotaRefused = true;
        }
        check(
            $quotaRefused
                && music_catalog_revision() === $quotaRevisionBefore
                && array_map(
                    'intval',
                    array_column(
                        music_catalog_album_members(
                            (int) $quotaAlbum['album_id'],
                        ),
                        'id',
                    ),
                ) === [$firstSongId]
                && file_get_contents(
                    gallery_music_directory($secondDestination) . DIRECTORY_SEPARATOR . 'index.json',
                ) === $quotaIndexBefore,
            'Adding live album growth beyond the exact per-gallery track limit must refuse before catalog or playlist mutation.',
        );

        $quotaAssetCountBefore = (int) $pdo->query(
            'SELECT COUNT(*) FROM music_repository_assets',
        )->fetchColumn();
        $quotaUploadIndexBefore = file_get_contents(
            gallery_music_directory($secondDestination)
                . DIRECTORY_SEPARATOR . 'index.json',
        );
        $trackLimitUploadRefused = false;
        try {
            gallery_music_mutate(
                $secondDestination,
                gallery_music_state($secondDestination)['write_revision'],
                'upload',
                '',
                $uploadSource,
                'Rejected at track limit ' . $token . '.wav',
            );
        } catch (\RuntimeException) {
            $trackLimitUploadRefused = true;
        }
        check(
            $trackLimitUploadRefused
                && (int) $pdo->query(
                    'SELECT COUNT(*) FROM music_repository_assets',
                )->fetchColumn() === $quotaAssetCountBefore
                && file_get_contents(
                    gallery_music_directory($secondDestination)
                        . DIRECTORY_SEPARATOR . 'index.json',
                ) === $quotaUploadIndexBefore,
            'The 101st upload must be rejected before central publication or playlist replacement.',
        );

        $boundaryBytes = GALLERY_MUSIC_MAX_BYTES;
        $sparseAssetId = substr(
            hash('sha256', $token . ':exact-byte-quota-asset'),
            0,
            32,
        );
        $sparseAssetPath = music_repository_directory()
            . DIRECTORY_SEPARATOR . $sparseAssetId . '.bin';
        $fixtureAssetIds[] = $sparseAssetId;
        $fixtureAssetPaths[] = $sparseAssetPath;
        $sparseAsset = fopen($sparseAssetPath, 'xb');
        if ($sparseAsset === false) {
            throw new \RuntimeException('Owned sparse quota asset could not be created.');
        }
        $sparseWavHeader = 'RIFF'
            . pack('V', $boundaryBytes - 8)
            . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
            . 'data'
            . pack('V', $boundaryBytes - 44);
        fwrite($sparseAsset, $sparseWavHeader);
        if (!ftruncate($sparseAsset, $boundaryBytes)) {
            fclose($sparseAsset);
            throw new \RuntimeException('Owned sparse quota asset could not be sized.');
        }
        fclose($sparseAsset);
        $sparseAssetRow = music_repository_model_register_asset([
            'id' => $sparseAssetId,
            'filename' => 'quota-boundary-' . $token . '.wav',
            'indexed_title' => 'Quota boundary ' . $token,
            'mime' => 'audio/wav',
            'byte_size' => $boundaryBytes,
            'sha256' => hash_file('sha256', $sparseAssetPath),
        ], null, null);
        $fixtureSongIds[] = (int) $sparseAssetRow['song_id'];
        $boundaryTracks = [];
        $boundaryStateTracks = [];
        for ($index = 0; $index < MUSIC_REPOSITORY_BYTE_BOUNDARY_TRACK_COUNT; $index++) {
            $trackId = substr(
                hash('sha256', $token . ':byte-boundary-track:' . $index),
                0,
                32,
            );
            $fixtureTrackIds[] = $trackId;
            $track = [
                'id' => $trackId,
                'title' => 'Boundary reference ' . $index . ' ' . $token,
                'filename' => 'Boundary reference ' . $index . ' ' . $token . '.wav',
                'sha256' => (string) $sparseAssetRow['sha256'],
                'mime' => 'audio/wav',
                'size' => $boundaryBytes,
                'hidden' => false,
                'downloadable' => false,
                'asset_id' => $sparseAssetId,
            ];
            $boundaryTracks[] = $track;
            $boundaryStateTracks[] = $track;
        }
        music_catalog_sync_gallery_snapshot(
            $secondDestinationId,
            $boundaryTracks,
            music_catalog_revision(),
            [],
        );
        gallery_music_model_write(
            gallery_music_directory($secondDestination),
            [
                'revision' => substr(hash('sha256', $token . ':byte-boundary-v2'), 0, 32),
                'enabled' => false,
                'tracks' => $boundaryStateTracks,
                'albums' => [],
                'order' => array_column($boundaryStateTracks, 'id'),
                'legacy_cleanup' => [],
            ],
        );
        $boundaryProjection = gallery_music_playlist($secondDestination);
        check(
            count($boundaryProjection['tracks'])
                === MUSIC_REPOSITORY_BYTE_BOUNDARY_TRACK_COUNT
                && array_sum(array_column($boundaryProjection['tracks'], 'size'))
                    === GALLERY_MUSIC_TOTAL_BYTES,
            'Sparse fixture references must represent exactly 512 MiB without allocating eight copied originals.',
        );
        $byteQuotaIndexBefore = file_get_contents(
            gallery_music_directory($secondDestination)
                . DIRECTORY_SEPARATOR . 'index.json',
        );
        $byteQuotaAssetsBefore = (int) $pdo->query(
            'SELECT COUNT(*) FROM music_repository_assets',
        )->fetchColumn();
        $byteQuotaRefused = false;
        try {
            gallery_music_mutate(
                $secondDestination,
                gallery_music_state($secondDestination)['write_revision'],
                'upload',
                '',
                $uploadSource,
                'Rejected at byte limit ' . $token . '.wav',
            );
        } catch (\RuntimeException) {
            $byteQuotaRefused = true;
        }
        check(
            $byteQuotaRefused
                && (int) $pdo->query(
                    'SELECT COUNT(*) FROM music_repository_assets',
                )->fetchColumn() === $byteQuotaAssetsBefore
                && file_get_contents(
                    gallery_music_directory($secondDestination)
                        . DIRECTORY_SEPARATOR . 'index.json',
                ) === $byteQuotaIndexBefore,
            'An upload above the exact 512 MiB gallery aggregate must be refused before asset or index publication.',
        );

        music_catalog_sync_gallery_snapshot(
            $secondDestinationId,
            [],
            music_catalog_revision(),
            [],
        );
        gallery_music_model_write(
            gallery_music_directory($secondDestination),
            [
                'revision' => substr(hash('sha256', $token . ':source-limit-v2'), 0, 32),
                'enabled' => false,
                'tracks' => [],
                'albums' => [],
                'order' => [],
                'legacy_cleanup' => [],
            ],
        );
        $oversizedSource = $directory . DIRECTORY_SEPARATOR . 'owned-oversize.wav';
        $fixtureSourcePaths[] = $oversizedSource;
        $oversizedHandle = fopen($oversizedSource, 'xb');
        if ($oversizedHandle === false) {
            throw new \RuntimeException('Owned oversized source could not be created.');
        }
        fwrite($oversizedHandle, $wav);
        if (!ftruncate(
            $oversizedHandle,
            GALLERY_MUSIC_MAX_BYTES
                + MUSIC_REPOSITORY_OVERSIZE_SOURCE_DELTA_BYTES,
        )) {
            fclose($oversizedHandle);
            throw new \RuntimeException('Owned oversized source could not be sized.');
        }
        fclose($oversizedHandle);
        $oversizedIndexBefore = file_get_contents(
            gallery_music_directory($secondDestination)
                . DIRECTORY_SEPARATOR . 'index.json',
        );
        $oversizedAssetCountBefore = (int) $pdo->query(
            'SELECT COUNT(*) FROM music_repository_assets',
        )->fetchColumn();
        $oversizedSourceRefused = false;
        try {
            gallery_music_mutate(
                $secondDestination,
                gallery_music_state($secondDestination)['write_revision'],
                'upload',
                '',
                $oversizedSource,
                'Rejected oversized source ' . $token . '.wav',
            );
        } catch (\RuntimeException) {
            $oversizedSourceRefused = true;
        }
        check(
            $oversizedSourceRefused
                && (int) $pdo->query(
                    'SELECT COUNT(*) FROM music_repository_assets',
                )->fetchColumn() === $oversizedAssetCountBefore
                && file_get_contents(
                    gallery_music_directory($secondDestination)
                        . DIRECTORY_SEPARATOR . 'index.json',
                ) === $oversizedIndexBefore,
            'A source one byte above 64 MiB must be rejected before central publication or index mutation.',
        );

        // Build real overlapping gallery rows for the actual public branch reader.
        $usageInsertGallery = $pdo->prepare(
            'INSERT INTO galleries '
                . '(parent_id, folder_path, folder_path_hash, slug, title, '
                . 'description, visibility, access_mode, access_listing, '
                . 'created_at, updated_at) '
                . "VALUES (?, ?, ?, ?, ?, '', 'public', ?, 'listed', NOW(), NOW())",
        );
        $usageRootPath = 'music-usage-' . $token . '-root';
        $usageChildPath = $usageRootPath . '/child';
        $usageUnrelatedPath = 'music-usage-' . $token . '-unrelated';
        $usageGalleryIds = [];
        $usageGalleryRows = [
            [null, $usageRootPath, 'root', 'normal'],
            [null, $usageUnrelatedPath, 'unrelated', 'normal'],
        ];
        foreach ($usageGalleryRows as [$parentId, $path, $label, $accessMode]) {
            $usageInsertGallery->execute([
                $parentId,
                $path,
                hash('sha256', $path),
                'music-usage-' . $label . '-' . $token,
                'Music usage ' . $label . ' ' . $token,
                $accessMode,
            ]);
            $usageGalleryIds[] = (int) $pdo->lastInsertId();
            $fixtureGalleryIds[] = (int) $pdo->lastInsertId();
        }
        $usageRootId = $usageGalleryIds[0];
        $usageUnrelatedId = $usageGalleryIds[1];
        $usageInsertGallery->execute([
            $usageRootId,
            $usageChildPath,
            hash('sha256', $usageChildPath),
            'music-usage-child-' . $token,
            'Music usage child ' . $token,
            'password',
        ]);
        $usageChildId = (int) $pdo->lastInsertId();
        $fixtureGalleryIds[] = $usageChildId;
        check(
            count($usageGalleryIds) + 1 === MUSIC_REPOSITORY_USAGE_GALLERY_COUNT,
            'Usage fixture must contain one root, one descendant, and one unrelated gallery.',
        );

        // Two distinct original digests belong to one song; a third song is
        // added to a live album later to prove membership is resolved at read time.
        $usageSongInsert = $pdo->prepare(
            'INSERT INTO music_songs (title, created_at, updated_at) '
                . 'VALUES (?, NOW(), NOW())',
        );
        $usageSongIds = [];
        foreach (['same-song', 'live-album-song'] as $label) {
            $usageSongInsert->execute([
                'Usage ' . $label . ' ' . $token,
            ]);
            $usageSongIds[] = (int) $pdo->lastInsertId();
            $fixtureSongIds[] = (int) $pdo->lastInsertId();
        }
        $usageDigestA = hash('sha256', 'usage file A ' . $token);
        $usageDigestB = hash('sha256', 'usage file B ' . $token);
        $usageDigestC = hash('sha256', 'usage file C ' . $token);
        $usageAssetInsert = $pdo->prepare(
            'INSERT INTO music_repository_assets '
                . '(id, song_id, filename, indexed_title, mime, byte_size, '
                . 'sha256, origin_gallery_id, origin_track_id, created_at, '
                . 'updated_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, NULL, NULL, ?, ?)',
        );
        $usageAssetIds = [
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(16)),
        ];
        $usageAssets = [
            [$usageAssetIds[0], $usageSongIds[0], $usageDigestA, '2000-01-01 00:00:00'],
            [$usageAssetIds[1], $usageSongIds[0], $usageDigestB, '2000-01-02 00:00:00'],
            [$usageAssetIds[2], $usageSongIds[1], $usageDigestC, '2000-01-03 00:00:00'],
        ];
        foreach ($usageAssets as $index => [$assetId, $songId, $digest, $createdAt]) {
            $usageAssetInsert->execute([
                $assetId,
                $songId,
                'usage-' . $index . '-' . $token . '.wav',
                'Usage asset ' . $index . ' ' . $token,
                'audio/wav',
                MUSIC_REPOSITORY_USAGE_ASSET_BYTES,
                $digest,
                $createdAt,
                $createdAt,
            ]);
            $fixtureAssetIds[] = $assetId;
        }
        $usageAlbumInsert = $pdo->prepare(
            'INSERT INTO music_albums '
                . '(parent_id, title, description, sort_order, revision, '
                . 'created_at, updated_at) '
                . 'VALUES (NULL, ?, NULL, 0, 1, NOW(), NOW())',
        );
        $usageAlbumInsert->execute(['Usage album ' . $token]);
        $usageAlbumId = (int) $pdo->lastInsertId();
        $fixtureAlbumIds[] = $usageAlbumId;
        $usageMembershipInsert = $pdo->prepare(
            'INSERT INTO music_album_songs (album_id, song_id, sort_order) '
                . 'VALUES (?, ?, 0)',
        );
        $usageMembershipDelete = $pdo->prepare(
            'DELETE FROM music_album_songs WHERE album_id = ? AND song_id = ?',
        );
        $usageMembershipInsert->execute([$usageAlbumId, $usageSongIds[0]]);

        $usagePdfRootItems = [
            [
                'source_id' => 'document:shared-' . $token,
                'sha256' => $usageDigestA,
                'asset_id' => null,
                'album_id' => null,
                'hidden' => false,
            ],
            [
                'source_id' => 'ofp',
                'sha256' => $usageDigestA,
                'asset_id' => null,
                'album_id' => null,
                'hidden' => true,
            ],
        ];
        $usagePdfChildItems = [[
            'source_id' => 'document:child-' . $token,
            'sha256' => $usageDigestB,
            'asset_id' => null,
            'album_id' => null,
            'hidden' => false,
        ]];
        $usageMusicRootItems = [
            [
                'source_id' => 'track:direct-a-' . $token,
                'sha256' => null,
                'asset_id' => $usageAssetIds[0],
                'album_id' => null,
                'hidden' => false,
            ],
            [
                'source_id' => 'track:direct-b-' . $token,
                'sha256' => null,
                'asset_id' => $usageAssetIds[1],
                'album_id' => null,
                'hidden' => false,
            ],
            [
                'source_id' => 'track:repeat-a-' . $token,
                'sha256' => null,
                'asset_id' => $usageAssetIds[0],
                'album_id' => null,
                'hidden' => false,
            ],
            [
                'source_id' => 'album:live-' . $token,
                'sha256' => null,
                'asset_id' => null,
                'album_id' => $usageAlbumId,
                'hidden' => false,
            ],
        ];
        $usageMusicChildItems = [[
            'source_id' => 'track:shared-descendant-' . $token,
            'sha256' => null,
            'asset_id' => $usageAssetIds[0],
            'album_id' => null,
            'hidden' => false,
        ]];
        music_repository_usage_publish(
            $usageRootId,
            'pdf',
            $usagePdfRootItems,
        );
        music_repository_usage_publish(
            $usageChildId,
            'pdf',
            $usagePdfChildItems,
        );
        music_repository_usage_publish(
            $usageRootId,
            'music',
            $usageMusicRootItems,
        );
        music_repository_usage_publish(
            $usageChildId,
            'music',
            $usageMusicChildItems,
        );
        check(
            gallery_model_attachment_usage_seed_empty($usageUnrelatedId, 'pdf')
                && gallery_model_attachment_usage_seed_empty($usageUnrelatedId, 'music'),
            'Known-empty usage seed must create ready state for a new gallery.',
        );
        $GLOBALS['music_repository_usage_capabilities'] = [
            'gallery_pdf_documents' => true,
            'gallery_music' => true,
        ];
        $GLOBALS['music_repository_usage_visitor_gallery_ids'] = [
            $usageRootId,
            $usageChildId,
            $usageUnrelatedId,
        ];
        $GLOBALS['music_repository_usage_admin_session'] = true;
        check(
            gallery_branch_attachment_usage_counts([$usageRootId], 'pdf')[$usageRootId]
                === 2
                && gallery_branch_attachment_usage_counts([$usageRootId], 'music')[$usageRootId]
                    === 2
                && gallery_branch_attachment_usage_counts([$usageUnrelatedId], 'pdf')[$usageUnrelatedId]
                    === 0,
            'Public branch counts must dedupe PDF/OFP identity, repeated music assets and descendants while counting a known-empty branch as zero.',
        );

        // Live album membership changes the canonical digest expansion without
        // rewriting the gallery's assignment snapshot.
        $usageMembershipInsert->execute([$usageAlbumId, $usageSongIds[1]]);
        check(
            gallery_branch_attachment_usage_counts([$usageRootId], 'music')[$usageRootId]
                === 3,
            'Adding a current album member must expose its distinct canonical digest in the existing branch projection.',
        );
        $usageMembershipDelete->execute([$usageAlbumId, $usageSongIds[1]]);
        check(
            gallery_branch_attachment_usage_counts([$usageRootId], 'music')[$usageRootId]
                === 2,
            'Removing an album member must immediately remove its digest from the public count.',
        );

        // A simultaneous Admin session cannot make a visitor-denied descendant countable.
        $GLOBALS['music_repository_usage_visitor_gallery_ids'] = [$usageRootId];
        check(
            gallery_branch_attachment_usage_counts([$usageRootId], 'pdf')[$usageRootId]
                === 1
                && gallery_branch_attachment_usage_counts([$usageRootId], 'music')[$usageRootId]
                    === 2,
            'Public attachment counts must exclude an inaccessible child even when the fixture marks an Admin session active.',
        );
        $GLOBALS['music_repository_usage_visitor_gallery_ids'] = [
            $usageRootId,
            $usageChildId,
            $usageUnrelatedId,
        ];
        $usagePdfChildItems[0]['hidden'] = true;
        music_repository_usage_publish(
            $usageChildId,
            'pdf',
            $usagePdfChildItems,
        );
        check(
            gallery_branch_attachment_usage_counts([$usageRootId], 'pdf')[$usageRootId]
                === 1,
            'Hidden descendants must not contribute to the digest-unique PDF branch count.',
        );
        $usagePdfChildItems[0]['hidden'] = false;

        $staleUsageRevision = gallery_model_attachment_usage_mark_dirty(
            $usageRootId,
            'pdf',
        );
        $currentUsageRevision = gallery_model_attachment_usage_mark_dirty(
            $usageRootId,
            'pdf',
        );
        check(
            !gallery_model_attachment_usage_replace(
                $usageRootId,
                'pdf',
                $usagePdfRootItems,
                $staleUsageRevision,
            )
                && gallery_model_attachment_usage_rows([$usageRootId], 'pdf')['states'][$usageRootId]
                    === 'dirty',
            'A stale CAS publisher must not replace the current dirty generation.',
        );
        check(
            gallery_model_attachment_usage_replace(
                $usageRootId,
                'pdf',
                $usagePdfRootItems,
                $currentUsageRevision,
            ),
            'The current attachment usage generation must publish after a stale token is refused.',
        );

        $seedRaceRevision = gallery_model_attachment_usage_mark_dirty(
            $usageRootId,
            'music',
        );
        $stateAfterSeedRace = $pdo->prepare(
            'SELECT state, revision FROM gallery_attachment_usage_state '
                . 'WHERE gallery_id = ? AND kind = ?',
        );
        check(
            !gallery_model_attachment_usage_seed_empty($usageRootId, 'music'),
            'Insert-only empty seeding must not replace a writer-owned generation.',
        );
        $stateAfterSeedRace->execute([$usageRootId, 'music']);
        $retainedUsageState = $stateAfterSeedRace->fetch(\PDO::FETCH_ASSOC);
        check(
            is_array($retainedUsageState)
                && $retainedUsageState['state'] === 'dirty'
                && $retainedUsageState['revision'] === $seedRaceRevision,
            'An existing dirty source token must survive an empty-seed race.',
        );
        check(
            gallery_model_attachment_usage_replace(
                $usageRootId,
                'music',
                $usageMusicRootItems,
                $seedRaceRevision,
            ),
            'The original source writer must still publish after an empty seed loses the insert race.',
        );

        $dirtyChildRevision = gallery_model_attachment_usage_mark_dirty(
            $usageChildId,
            'pdf',
        );
        check(
            !array_key_exists(
                $usageRootId,
                gallery_branch_attachment_usage_counts([$usageRootId], 'pdf'),
            ),
            'Any dirty descendant must omit the incomplete branch count.',
        );
        $pdo->prepare(
            'DELETE FROM gallery_attachment_usage_state WHERE gallery_id = ? AND kind = ?',
        )->execute([$usageChildId, 'pdf']);
        check(
            !array_key_exists(
                $usageRootId,
                gallery_branch_attachment_usage_counts([$usageRootId], 'pdf'),
            ),
            'An absent descendant snapshot must omit the incomplete branch count.',
        );
        music_repository_usage_publish(
            $usageChildId,
            'pdf',
            $usagePdfChildItems,
        );

        schema_inspection_set_query_executor_for_tests(
            static fn(string $objectType, string $table, string $object): bool => false,
        );
        check(
            presentation_gallery_attachment_usage_schema_status()['state'] === 'missing'
                && gallery_branch_attachment_usage_counts([$usageRootId], 'pdf') === [],
            'Confirmed missing projection schema must safely omit optional card counts.',
        );
        schema_inspection_set_query_executor_for_tests(
            static function (
                string $objectType,
                string $table,
                string $object,
            ): bool {
                throw new \PDOException('Fixture schema metadata is unavailable.');
            },
        );
        check(
            presentation_gallery_attachment_usage_schema_status()['state'] === 'unknown'
                && gallery_branch_attachment_usage_counts([$usageRootId], 'pdf') === [],
            'Unknown projection schema must safely omit optional card counts.',
        );
        schema_inspection_set_query_executor_for_tests(null);
        schema_inspection_reset_request_cache();
        schema_inspection_set_query_executor_for_tests(
            static fn(string $objectType, string $table, string $object): bool =>
                !in_array($table, ['music_album_songs', 'music_repository_assets'], true),
        );
        check(
            gallery_branch_attachment_usage_counts([$usageRootId], 'pdf')[$usageRootId]
                === 2
                && gallery_branch_attachment_usage_counts([$usageRootId], 'music') === [],
            'An unavailable current music schema must hide only music counts while PDF counts remain available.',
        );
        schema_inspection_set_query_executor_for_tests(null);
        schema_inspection_reset_request_cache();

        $sourceAssets = array_column($afterSecond['tracks'], 'asset_id');
        $pdo->prepare('DELETE FROM galleries WHERE id = ?')->execute([$sourceId]);
        $deletedOriginDetails = music_catalog_song_details(
            [$firstSongId],
        )[$firstSongId] ?? [];
        $deletedOriginGalleryRows = is_array(
            $deletedOriginDetails['galleries'] ?? null,
        ) ? $deletedOriginDetails['galleries'] : [];
        $survivingSourceAsset = music_repository_model_asset(
            (string) $sourceAssets[0],
        );
        check(
            count(gallery_music_playlist($destination)['tracks']) === 2
                && is_array($survivingSourceAsset)
                && music_repository_asset_path($survivingSourceAsset) !== null
                && !isset($deletedOriginGalleryRows[$sourceId]),
            'Deleting a source gallery must preserve destination playback and its central asset without returning a URL row for the deleted origin.',
        );

        echo "Music repository database contracts passed.\n";
    } finally {
        if ($pdo instanceof \PDO) {
            $ownedAssetQuery = $pdo->prepare(
                'SELECT id FROM music_repository_assets '
                    . 'WHERE (origin_gallery_id = ? AND origin_track_id IN (?, ?)) '
                    . 'OR filename = ?',
            );
            if ($fixtureGalleryIds !== [] && count($fixtureTrackIds) >= 2) {
                $ownedAssetQuery->execute([
                    $fixtureGalleryIds[0],
                    '00000000000000000000000000000001',
                    '00000000000000000000000000000002',
                    'Central upload ' . $token . '.wav',
                ]);
                $fixtureAssetIds = array_merge(
                    $fixtureAssetIds,
                    array_map('strval', $ownedAssetQuery->fetchAll(\PDO::FETCH_COLUMN)),
                );
            }
            if ($fixtureGalleryIds !== []) {
                $galleryMarks = implode(',', array_fill(0, count($fixtureGalleryIds), '?'));
                $pdo->prepare("DELETE FROM galleries WHERE id IN ($galleryMarks)")
                    ->execute($fixtureGalleryIds);
            }
            if ($fixtureAlbumIds !== []) {
                $albumMarks = implode(',', array_fill(0, count($fixtureAlbumIds), '?'));
                $pdo->prepare("DELETE FROM music_albums WHERE id IN ($albumMarks)")
                    ->execute($fixtureAlbumIds);
            }
            if ($fixtureTrackIds !== []) {
                $galleryMarks = implode(',', array_fill(0, count($fixtureGalleryIds), '?'));
                $trackMarks = implode(',', array_fill(0, count($fixtureTrackIds), '?'));
                $pdo->prepare(
                    "DELETE FROM music_track_instances WHERE gallery_id IN ($galleryMarks) AND track_id IN ($trackMarks)",
                )->execute(array_merge($fixtureGalleryIds, $fixtureTrackIds));
            }
            if ($fixtureAssetIds !== []) {
                $uniqueAssetIds = array_values(array_unique($fixtureAssetIds));
                $assetMarks = implode(',', array_fill(0, count($uniqueAssetIds), '?'));
                $pdo->prepare("DELETE FROM music_repository_assets WHERE id IN ($assetMarks)")
                    ->execute($uniqueAssetIds);
            }
            if ($fixtureSongIds !== []) {
                $songIds = array_values(array_unique(array_map('intval', $fixtureSongIds)));
                $songMarks = implode(',', array_fill(0, count($songIds), '?'));
                $pdo->prepare("DELETE FROM music_songs WHERE id IN ($songMarks)")
                    ->execute($songIds);
            }
            if ($themeRevisionSettingCaptured) {
                if (is_array($originalThemeRevisionSetting)) {
                    $pdo->prepare(
                        'INSERT INTO app_settings '
                            . '(setting_key, setting_value, updated_at) '
                            . 'VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE '
                            . 'setting_value = VALUES(setting_value), '
                            . 'updated_at = VALUES(updated_at)',
                    )->execute([
                        'theme_public_content_revision',
                        $originalThemeRevisionSetting['setting_value'],
                        $originalThemeRevisionSetting['updated_at'],
                    ]);
                } else {
                    $pdo->prepare(
                        'DELETE FROM app_settings WHERE setting_key = ?',
                    )->execute(['theme_public_content_revision']);
                }
                app_settings_reset_request_cache();
            }
        }
        foreach (array_unique($fixtureAssetPaths) as $assetPath) {
            if (is_file($assetPath) && !is_link($assetPath)) {
                unlink($assetPath);
            }
        }
        foreach (array_unique($fixtureSourcePaths) as $sourcePath) {
            if (is_file($sourcePath) && !is_link($sourcePath)) {
                unlink($sourcePath);
            }
        }
        foreach ($fixtureDirectories as $absolute) {
            $musicDirectory = $absolute . DIRECTORY_SEPARATOR . '.gallery-music';
            if (is_dir($musicDirectory) && !is_link($musicDirectory)) {
                foreach (['index.json', '.lock'] as $knownFile) {
                    $path = $musicDirectory . DIRECTORY_SEPARATOR . $knownFile;
                    if (is_file($path) && !is_link($path)) {
                        unlink($path);
                    }
                }
                foreach ($fixtureTrackIds as $trackId) {
                    $path = $musicDirectory . DIRECTORY_SEPARATOR . $trackId . '.bin';
                    if (is_file($path) && !is_link($path)) {
                        unlink($path);
                    }
                }
                rmdir($musicDirectory);
            }
            if (is_dir($absolute) && !is_link($absolute)) {
                rmdir($absolute);
            }
        }
        if ($repositoryDirectoryCreated) {
            $repositoryDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR
                . 'data' . DIRECTORY_SEPARATOR . 'music';
            if (is_dir($repositoryDirectory) && !is_link($repositoryDirectory)
                && count(scandir($repositoryDirectory) ?: []) === 2) {
                @rmdir($repositoryDirectory);
            }
        }
    }
}
