<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/music_catalog_database_test.php
 * Module Type: Database Regression Test
 * Purpose: Exercise normalized music catalog persistence on disposable MySQL.
 * Responsibilities: Cover real index projection, revision-safe catalog mutations, typed-tag isolation and ownership boundaries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the validated runner-owned database connection to catalog owners.
     * @return \PDO Disposable workflow database connection.
     */
    function db(): \PDO
    {
        return $GLOBALS['music_catalog_fixture_db'];
    }

    /** Resolve only paths contained by this test's owned fixture gallery root.
     * @param string $root Absolute fixture root path.
     * @param string $path Candidate resolved gallery path.
     * @return bool Whether the candidate remains inside the fixture root.
     */
    function path_inside(string $root, string $path): bool
    {
        $resolvedRoot = realpath($root);
        $resolvedPath = realpath($path);
        return is_string($resolvedRoot)
            && is_string($resolvedPath)
            && str_starts_with(
                $resolvedPath,
                rtrim($resolvedRoot, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR,
            );
    }
}

namespace Gallery\Services {
    /** Provide deterministic English fallback interpolation for this isolated fixture.
     * The fixture intentionally omits language catalogs, session state and missing-key diagnostics.
     * @param string $key Translation key used when no English fallback is supplied.
     * @param string|array<string,bool|float|int|string|null>|null $fallback English fallback text, legacy parameter map, or null.
     * @param array<string,bool|float|int|string|null> $parameters Scalar or null replacement values keyed by placeholder name.
     * @return string English fallback or key with simple placeholders replaced.
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

    /** Return the gallery root owned by the disposable workflow fixture.
     * @return string Absolute path to the fixture's galleries directory.
     */
    function galleries_root(): string
    {
        return $GLOBALS['music_catalog_gallery_root'];
    }

    /** Resolve a gallery relative path beneath the owned disposable root.
     * @param string $relativePath Canonical relative gallery folder path.
     * @return string Absolute path within this fixture's gallery root.
     */
    function gallery_abs_path(string $relativePath): string
    {
        return galleries_root() . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}

namespace {
    /** Require four galleries for playlist and Trash restore scenarios.
     * Type: int. Units: gallery rows. Scope: one music catalog DB fixture.
     * Consumers: source selection, bridge and recreated-gallery assertions.
     * Rationale: three sources form the identity bridge and one separate gallery models local Trash recreation.
     */
    const MUSIC_CATALOG_FIXTURE_GALLERY_COUNT = 4;

    /** Give each sorting direction two pages over the full fixture result set.
     * Type: int. Units: songs per page. Scope: one disposable sorting query.
     * Consumers: ordered-page assertions for all four catalog columns.
     * Rationale: two equal pages expose pagination that incorrectly sorts only the current page. Value: 2.
     */
    const MUSIC_CATALOG_SORT_PAGE_SIZE = 2;

    /** Keep sorting expectations large enough to distinguish every supported key.
     * Type: int. Units: songs. Scope: isolated music catalog sorting fixture.
     * Consumers: title, file-count, location-count and first-tag order vectors.
     * Rationale: four records permit four unique ranks while still fitting two bounded pages. Value: 4.
     */
    const MUSIC_CATALOG_SORT_FIXTURE_SONG_COUNT = 4;

    /** Create the nested membership ranks used by the location sort vector.
     * Type: int. Units: albums. Scope: isolated music catalog sorting fixture.
     * Consumers: per-song album-membership counts for locations ordering.
     * Rationale: three progressively narrower memberships distinguish counts one through four. Value: 3.
     */
    const MUSIC_CATALOG_SORT_FIXTURE_ALBUM_COUNT = 3;

    /** Keep generated fixture folders private during index-reader coverage.
     * Type: int. Units: POSIX permission bits. Scope: test-owned directories.
     * Consumers: music index filesystem fixture setup.
     * Rationale: mode 0700 prevents unrelated host accounts traversing fixture data.
     */
    const MUSIC_CATALOG_FIXTURE_DIRECTORY_MODE = 0700;

    /** Keep the verified fixture audio payload small while exercising repository validation.
     * Type: int. Units: PCM sample bytes. Scope: one WAV upload source.
     * Consumers: repository-only list result and valid physical source files.
     * Rationale: Eight deterministic samples produce a valid minimal audio original. Value: 8.
     */
    const MUSIC_CATALOG_FIXTURE_WAV_SAMPLE_BYTES = 8;

    /** Bound one public image-search probe used to detect cross-media tag leakage.
     * Type: int. Units: rows. Scope: each disposable public-search query.
     * Consumers: music_catalog_database_test.php typed-tag isolation assertions.
     * Rationale: one matching fixture image is sufficient to prove inclusion or exclusion without broad result materialization. Value: 1.
     */
    const MUSIC_CATALOG_PUBLIC_TAG_SEARCH_LIMIT = 1;

    require_once __DIR__ . '/support/gallery_workflow_safety.php';
    require_once __DIR__ . '/../app/runtime/storage_observer.php';
    require_once __DIR__ . '/../app/helpers_runtime.php';
    require_once __DIR__ . '/../app/models/app_settings.php';
    require_once __DIR__ . '/../app/models/schema_inspection.php';
    require_once __DIR__ . '/../app/models/database_helpers.php';
    require_once __DIR__ . '/../app/models/public_search.php';
    require_once __DIR__ . '/../app/models/tags.php';
    require_once __DIR__ . '/../app/services/music_catalog.php';
    require_once __DIR__ . '/../app/services/gallery_music.php';
    require_once __DIR__ . '/../app/services/tag_metadata.php';

    use function GalleryWorkflow\check;
    use function GalleryWorkflow\fixtureDatabase;
    use function GalleryWorkflow\validateFixture;
    use function Gallery\Services\app_settings_reset_request_cache;
    use function Gallery\Services\music_catalog_album_create;
    use function Gallery\Services\music_catalog_album_delete;
    use function Gallery\Services\music_catalog_album_move;
    use function Gallery\Services\music_catalog_album_song_add;
    use function Gallery\Services\music_catalog_album_song_remove;
    use function Gallery\Services\music_catalog_album_tree;
    use function Gallery\Services\music_catalog_album_members;
    use function Gallery\Services\music_catalog_display_title;
    use function Gallery\Services\music_catalog_merge;
    use function Gallery\Services\music_catalog_list;
    use function Gallery\Services\music_catalog_restore_trash_snapshot;
    use function Gallery\Services\music_catalog_revision;
    use function Gallery\Services\music_catalog_set_song_title;
    use function Gallery\Services\music_catalog_split;
    use function Gallery\Services\music_catalog_set_show_file_extensions;
    use function Gallery\Services\music_catalog_sync_gallery_snapshot;
    use function Gallery\Services\music_catalog_sync_song_tags;
    use function Gallery\Services\music_catalog_usage;
    use function Gallery\Services\music_repository_directory;
    use function Gallery\Services\music_repository_store_source;
    use function Gallery\Services\normalize_existing_tags;
    use function Gallery\Services\gallery_music_merge_playlists;
    use function Gallery\Services\sync_entity_tags;
    use function Gallery\Services\tag_media_type_schema_state;
    use function Gallery\Services\find_tag_by_slug;
    use function Gallery\Services\tag_usage_counts;
    use function Gallery\Services\tags_for_entity;
    use function Gallery\Services\tags_for_entities;
    use function Gallery\Services\schema_inspection_reset_request_cache;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
    use const Gallery\Services\TAG_MEDIA_SLUG_MAX_BYTES;
    use function Gallery\Models\public_search_model_compatibility_image_rows;
    use function Gallery\Models\tag_model_all_names;
    use function Gallery\Models\tag_model_public_galleries;

    /** Fail schema inspection to exercise the tag service's unknown-state boundary.
     * @param string $objectType Table, column, or index inspection kind.
     * @param string $table Inspected owner table.
     * @param string $object Inspected column or index name.
     * @return never Throws to model unavailable schema metadata.
     */
    function music_catalog_fail_schema_inspection(
        string $objectType,
        string $table,
        string $object,
    ): never {
        throw new \PDOException('Fixture schema inspection unavailable.');
    }

    /** Run an expected catalog conflict and report only safe fixture context.
     * @param callable():mixed $operation Catalog operation expected to throw.
     * @param string $message Assertion context without database values.
     * @return void Fail when the operation unexpectedly succeeds.
     */
    function music_catalog_expect_conflict(
        callable $operation,
        string $message,
    ): void {
        try {
            $operation();
        } catch (\RuntimeException) {
            return;
        }
        check(false, $message);
    }

    /** Build a distinct valid WAV original for one physical music fixture.
     * @param string $seed Fixture-only value used to make stable sample bytes.
     * @return string Complete mono 8-bit PCM WAV payload accepted by the real validator.
     */
    function music_catalog_fixture_wav(string $seed): string
    {
        $samples = substr(
            hash('sha256', $seed, true),
            0,
            MUSIC_CATALOG_FIXTURE_WAV_SAMPLE_BYTES,
        );
        return 'RIFF'
            . pack('V', 36 + strlen($samples))
            . 'WAVEfmt '
            . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
            . 'data'
            . pack('V', strlen($samples))
            . $samples;
    }

    /** Write one owned gallery-local original under its generated track ID.
     * @param string $musicDirectory Existing sidecar directory owned by this fixture.
     * @param string $trackId Generated 32-character hexadecimal gallery track identity.
     * @param string $bytes Valid WAV data whose digest and size are recorded in the index.
     * @return void Persist the exact original consumed by repository promotion.
     * @throws \RuntimeException When the generated identity or bounded write is invalid.
     */
    function music_catalog_fixture_write_track(
        string $musicDirectory,
        string $trackId,
        string $bytes,
    ): void {
        if (preg_match('/^[a-f0-9]{32}$/D', $trackId) !== 1) {
            throw new \RuntimeException('Invalid owned catalog track identity.');
        }
        $path = $musicDirectory . DIRECTORY_SEPARATOR . $trackId . '.bin';
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Owned catalog audio original could not be written.');
        }
    }

    if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
        $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
        echo ($required ? 'BLOCKED' : 'SKIP')
            . " music catalog requires an owned disposable database\n";
        exit($required ? 1 : 0);
    }

    $pdo = null;
    $fixtureSongIds = [];
    $fixtureAlbumIds = [];
    $fixtureRevision = null;
    $originalSettingRows = [];
    $fixtureGalleryIds = [];
    $fixtureGalleryPaths = [];
    $playlistDirectories = [];
    $fixtureGalleryRoot = null;
    $tagAlphaSlug = null;
    $tagBetaSlug = null;
    $tagImageSlug = null;
    $tagPdfSlug = null;
    $sortingTagSlugs = [];
    $fixtureImageId = null;
    $trackOneId = null;
    $trackVariantId = null;
    $fixtureTrackIds = [];
    $fixtureAssetIds = [];
    $fixtureAssetPaths = [];
    $fixtureSourcePaths = [];
    $repositoryDirectoryCreated = false;
    try {
        $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
        $directory = validateFixture(
            (string) getenv('GALLERY_WORKFLOW_FIXTURE'),
            $token,
        );
        $pdo = fixtureDatabase($directory, $token);
        $GLOBALS['music_catalog_fixture_db'] = $pdo;
        $fixtureGalleryRoot = $directory . DIRECTORY_SEPARATOR . 'galleries';
        $GLOBALS['music_catalog_gallery_root'] = $fixtureGalleryRoot;
        $repositoryDirectoryCreated = !is_dir(
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data'
                . DIRECTORY_SEPARATOR . 'music',
        );
        $fixtureRevision = music_catalog_revision();
        $settingQuery = $pdo->prepare(
            'SELECT setting_key, setting_value, updated_at FROM app_settings '
            . 'WHERE setting_key IN (?, ?)',
        );
        $settingQuery->execute([
            'music_show_file_extensions',
            'theme_public_content_revision',
        ]);
        foreach ($settingQuery->fetchAll(\PDO::FETCH_ASSOC) as $setting) {
            $originalSettingRows[$setting['setting_key']] = [
                'setting_value' => $setting['setting_value'],
                'updated_at' => $setting['updated_at'],
            ];
        }
        $trackOneId = substr(hash('sha256', $token . ':track-one'), 0, 32);
        $trackVariantId = substr(hash('sha256', $token . ':track-variant'), 0, 32);
        $fixtureTrackIds = [$trackOneId, $trackVariantId];

        // The migration must classify preexisting tags as images without
        // changing their numeric identities or globally unique slugs.
        $pdo->exec(
            'CREATE TEMPORARY TABLE catalog_tag_migration_probe '
            . '(id BIGINT UNSIGNED PRIMARY KEY, name VARCHAR(100), '
            . 'slug VARCHAR(100) NOT NULL UNIQUE)',
        );
        $pdo->exec(
            "INSERT INTO catalog_tag_migration_probe VALUES "
            . "(41, 'Existing', 'existing-slug'), "
            . "(42, 'Second', 'second-slug')",
        );
        $tagMigration = require __DIR__
            . '/../database/migrations/202610200001_tag_media_types.php';
        $pdo->exec(str_replace(
            'ALTER TABLE tags',
            'ALTER TABLE catalog_tag_migration_probe',
            $tagMigration[0],
        ));
        $tagRows = $pdo->query(
            'SELECT id, slug, media_type FROM catalog_tag_migration_probe '
            . 'ORDER BY id',
        )->fetchAll(\PDO::FETCH_ASSOC);
        $tagRows = array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'slug' => $row['slug'],
                'media_type' => $row['media_type'],
            ],
            $tagRows,
        );
        check(
            $tagRows === [
                ['id' => 41, 'slug' => 'existing-slug', 'media_type' => 'image'],
                ['id' => 42, 'slug' => 'second-slug', 'media_type' => 'image'],
            ],
            'Tag media migration must preserve existing identities and slugs.',
        );

        // Exercise normalization against a PDF slug owner in a transaction;
        // rollback also protects every preexisting tag row from this global pass.
        $collisionBase = 'catalog-collision-'
            . substr(hash('sha256', $token), 0, 12);
        $collisionName = 'Catalog Collision '
            . substr(hash('sha256', $token), 0, 12);
        $pdo->beginTransaction();
        try {
            $collisionInsert = $pdo->prepare(
                'INSERT INTO tags '
                . '(name, slug, media_type, created_at, updated_at) '
                . 'VALUES (?, ?, ?, NOW(), NOW()), (?, ?, ?, NOW(), NOW())',
            );
            $collisionInsert->execute([
                $collisionName,
                $collisionBase,
                'pdf',
                $collisionName,
                '-' . $collisionBase,
                'image',
            ]);
            $collisionRows = $pdo->prepare(
                'SELECT id, slug, media_type FROM tags '
                . 'WHERE slug IN (?, ?) ORDER BY media_type',
            );
            $collisionRows->execute([
                $collisionBase,
                '-' . $collisionBase,
            ]);
            $collisionRows = $collisionRows->fetchAll(\PDO::FETCH_ASSOC);
            $collisionIds = [];
            foreach ($collisionRows as $collisionRow) {
                $collisionIds[$collisionRow['media_type']] = (int) $collisionRow['id'];
            }
            check(
                count($collisionIds) === 2
                    && isset($collisionIds['pdf'], $collisionIds['image']),
                'The normalization fixture must own two distinct media tag rows.',
            );

            normalize_existing_tags();

            $pdfCollision = $pdo->prepare(
                'SELECT slug FROM tags WHERE id = ?',
            );
            $pdfCollision->execute([$collisionIds['pdf']]);
            $imageCollision = $pdo->prepare(
                'SELECT slug FROM tags WHERE id = ?',
            );
            $imageCollision->execute([$collisionIds['image']]);
            $normalizedImageSlug = (string) $imageCollision->fetchColumn();
            check(
                (string) $pdfCollision->fetchColumn() === $collisionBase
                    && str_starts_with(
                        $normalizedImageSlug,
                        $collisionBase . '-image-'
                            . $collisionIds['image'],
                    )
                    && strlen($normalizedImageSlug)
                        <= TAG_MEDIA_SLUG_MAX_BYTES,
                'Normalization must preserve PDF ownership and allocate a bounded globally unique image slug.',
            );
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $insertGallery = $pdo->prepare(
            'INSERT INTO galleries '
            . '(parent_id, folder_path, folder_path_hash, slug, title, '
            . 'description, visibility, created_at, updated_at) '
            . 'VALUES (NULL, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
        );
        for ($index = 0; $index < MUSIC_CATALOG_FIXTURE_GALLERY_COUNT; $index++) {
            $folderPath = 'music-catalog-' . $token . '-' . $index;
            $insertGallery->execute([
                $folderPath,
                hash('sha256', $folderPath),
                'music-catalog-' . $index . '-' . $token,
                'Music catalog fixture ' . $index . ' ' . $token,
                '',
                'public',
            ]);
            $galleryId = (int) $pdo->lastInsertId();
            $fixtureGalleryIds[] = $galleryId;
            $fixtureGalleryPaths[$galleryId] = $folderPath;
        }
        check(
            count($fixtureGalleryIds) === MUSIC_CATALOG_FIXTURE_GALLERY_COUNT,
            'Could not create four owned music catalog galleries.',
        );
        $galleryA = $fixtureGalleryIds[0];
        $galleryB = $fixtureGalleryIds[1];
        $galleryC = $fixtureGalleryIds[2];
        $galleryD = $fixtureGalleryIds[3];
        foreach ([$galleryA, $galleryB, $galleryC, $galleryD] as $galleryId) {
            $musicDirectory = $fixtureGalleryRoot . DIRECTORY_SEPARATOR
                . $fixtureGalleryPaths[$galleryId] . DIRECTORY_SEPARATOR
                . '.gallery-music';
            if (!is_dir($musicDirectory)
                && !mkdir(
                    $musicDirectory,
                    MUSIC_CATALOG_FIXTURE_DIRECTORY_MODE,
                    true,
                )
                && !is_dir($musicDirectory)) {
                throw new \RuntimeException('Music fixture directory unavailable.');
            }
            $playlistDirectories[$galleryId] = $musicDirectory;
        }
        $bridgeBytesX = music_catalog_fixture_wav($token . ':bridge-x');
        $bridgeBytesY = music_catalog_fixture_wav($token . ':bridge-y');
        $variantBytes = music_catalog_fixture_wav($token . ':variant');
        $bridgeHashX = hash('sha256', $bridgeBytesX);
        $bridgeHashY = hash('sha256', $bridgeBytesY);
        music_catalog_fixture_write_track(
            $playlistDirectories[$galleryA],
            $trackOneId,
            $bridgeBytesX,
        );
        music_catalog_fixture_write_track(
            $playlistDirectories[$galleryA],
            $trackVariantId,
            $variantBytes,
        );
        music_catalog_fixture_write_track(
            $playlistDirectories[$galleryB],
            $trackOneId,
            $bridgeBytesX,
        );
        music_catalog_fixture_write_track(
            $playlistDirectories[$galleryC],
            $trackOneId,
            $bridgeBytesY,
        );

        $beforeTags = $pdo->query(
            'SELECT id, slug, media_type FROM tags ORDER BY id',
        )->fetchAll(\PDO::FETCH_ASSOC);
        $beforeImages = $pdo->query(
            'SELECT id, gallery_id FROM images ORDER BY id',
        )->fetchAll(\PDO::FETCH_ASSOC);

        // A direct snapshot models the committed JSON index boundary while
        // proving the composite identity includes its gallery owner.
        $revision = music_catalog_revision();
        $revision = music_catalog_sync_gallery_snapshot(
            $galleryA,
            [[
                'id' => $trackOneId,
                'title' => 'Indexed Song',
                'filename' => 'song-one.wav',
                'sha256' => $bridgeHashX,
                'mime' => 'audio/wav',
                'size' => strlen($bridgeBytesX),
                'hidden' => false,
                'downloadable' => true,
            ], [
                'id' => $trackVariantId,
                'title' => 'Indexed Song',
                'filename' => 'song-variant.wav',
                'sha256' => hash('sha256', $variantBytes),
                'mime' => 'audio/wav',
                'size' => strlen($variantBytes),
                'hidden' => false,
                'downloadable' => true,
            ]],
            $revision,
        );
        $songId = (int) $pdo->query(
            "SELECT song_id FROM music_track_instances "
            . "WHERE gallery_id = $galleryA "
            . "AND track_id = '$trackOneId'",
        )->fetchColumn();
        $variantId = (int) $pdo->query(
            "SELECT song_id FROM music_track_instances "
            . "WHERE gallery_id = $galleryA "
            . "AND track_id = '$trackVariantId'",
        )->fetchColumn();
        check(
            $songId > 0 && $songId !== $variantId,
            'Distinct physical variants initially receive distinct identities.',
        );
        $fixtureSongIds = [$songId, $variantId];

        check(
            preg_match(
                '/^[0-9]+$/D',
                (string) $pdo->query(
                    "SELECT setting_value FROM app_settings "
                    . "WHERE setting_key = 'theme_public_content_revision'",
                )->fetchColumn(),
            ) === 1,
            'Catalog mutations must preserve the decimal public HTML revision contract.',
        );

        // Replaying the same physical index refreshes identities in place.
        $revision = music_catalog_sync_gallery_snapshot(
            $galleryA,
            [[
                'id' => $trackOneId,
                'title' => 'Indexed Song',
                'filename' => 'song-one.wav',
                'sha256' => $bridgeHashX,
                'mime' => 'audio/wav',
                'size' => strlen($bridgeBytesX),
                'hidden' => false,
                'downloadable' => true,
            ], [
                'id' => $trackVariantId,
                'title' => 'Indexed Song',
                'filename' => 'song-variant.wav',
                'sha256' => hash('sha256', $variantBytes),
                'mime' => 'audio/wav',
                'size' => strlen($variantBytes),
                'hidden' => false,
                'downloadable' => true,
            ]],
            $revision,
        );
        check(
            (int) $pdo->query(
                "SELECT song_id FROM music_track_instances "
                . "WHERE gallery_id = $galleryA "
                . "AND track_id = '$trackOneId'",
            )->fetchColumn() === $songId,
            'Repeated index reconciliation must retain the physical identity.',
        );

        $revision = music_catalog_sync_gallery_snapshot(
            $galleryB,
            [[
                'id' => $trackOneId,
                'title' => 'Same Recording',
                'filename' => 'shared.wav',
                'sha256' => $bridgeHashX,
                'mime' => 'audio/wav',
                'size' => strlen($bridgeBytesX),
                'hidden' => false,
                'downloadable' => true,
            ]],
            $revision,
        );
        $revision = music_catalog_sync_gallery_snapshot(
            $galleryC,
            [[
                'id' => $trackOneId,
                'title' => 'Album Recording',
                'filename' => 'album.wav',
                'sha256' => $bridgeHashY,
                'mime' => 'audio/wav',
                'size' => strlen($bridgeBytesY),
                'hidden' => false,
                'downloadable' => true,
            ]],
            $revision,
        );
        $songInB = (int) $pdo->query(
            "SELECT song_id FROM music_track_instances "
            . "WHERE gallery_id = $galleryB "
            . "AND track_id = '$trackOneId'",
        )->fetchColumn();
        $songInC = (int) $pdo->query(
            "SELECT song_id FROM music_track_instances "
            . "WHERE gallery_id = $galleryC "
            . "AND track_id = '$trackOneId'",
        )->fetchColumn();

        // Seed the bridge graph A(song 1, hash X), B(song 2, hash X),
        // C(song 1, hash Y). Only B grants original-download permission.
        $pdo->prepare(
            'UPDATE music_track_instances SET song_id = ?, file_sha256 = ? '
            . 'WHERE gallery_id = ? AND track_id = ?',
        )->execute([$songId, $bridgeHashX, $galleryA, $trackOneId]);
        $pdo->prepare(
            'UPDATE music_track_instances SET file_sha256 = ? '
            . 'WHERE gallery_id = ? AND track_id = ?',
        )->execute([$bridgeHashX, $galleryB, $trackOneId]);
        $pdo->prepare(
            'UPDATE music_track_instances SET song_id = ?, file_sha256 = ? '
            . 'WHERE gallery_id = ? AND track_id = ?',
        )->execute([$songId, $bridgeHashY, $galleryC, $trackOneId]);
        $sourceRows = [
            ['id' => $galleryA, 'folder_path' => $fixtureGalleryPaths[$galleryA]],
            ['id' => $galleryB, 'folder_path' => $fixtureGalleryPaths[$galleryB]],
            ['id' => $galleryC, 'folder_path' => $fixtureGalleryPaths[$galleryC]],
        ];
        foreach ($sourceRows as $sourceIndex => $source) {
            $track = [
                'id' => $trackOneId,
                'filename' => ['song-one.wav', 'shared.wav', 'album.wav'][$sourceIndex],
                'title' => 'Bridge recording',
                'mime' => 'audio/wav',
                'size' => strlen($sourceIndex === 2 ? $bridgeBytesY : $bridgeBytesX),
                'sha256' => $sourceIndex === 2 ? $bridgeHashY : $bridgeHashX,
                'hidden' => false,
                'downloadable' => $sourceIndex === 1,
            ];
            $tracks = [$track];
            if ($sourceIndex === 0) {
                $tracks[] = [
                    'id' => $trackVariantId,
                    'filename' => 'song-variant.wav',
                    'title' => 'Bridge variant',
                    'mime' => 'audio/wav',
                    'size' => strlen($variantBytes),
                    'sha256' => hash('sha256', $variantBytes),
                    'hidden' => false,
                    'downloadable' => false,
                ];
            }
            foreach ($tracks as $sourceTrack) {
                music_catalog_fixture_write_track(
                    $playlistDirectories[$source['id']],
                    $sourceTrack['id'],
                    $sourceTrack['id'] === $trackVariantId
                        ? $variantBytes
                        : ($sourceIndex === 2 ? $bridgeBytesY : $bridgeBytesX),
                );
            }
            $indexPath = $playlistDirectories[$source['id']]
                . DIRECTORY_SEPARATOR . 'index.json';
            file_put_contents($indexPath, json_encode([
                'format' => 'gallery_music_v1',
                'revision' => substr(hash('sha256', $token . ':' . $sourceIndex), 0, 32),
                'enabled' => true,
                'tracks' => $tracks,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }
        $projection = gallery_music_merge_playlists($sourceRows);
        $bridgeRepresentatives = array_values(array_filter(
            $projection['tracks'],
            static fn(array $track): bool => $track['sha256'] === $bridgeHashX,
        ));
        check(
            count($bridgeRepresentatives) === 1
                && count($projection['tracks']) === 2
                && $bridgeRepresentatives[0]['source_gallery_id'] === $galleryB
                && $bridgeRepresentatives[0]['downloadable'] === true
                && in_array(
                    hash('sha256', $variantBytes),
                    array_column($projection['tracks'], 'sha256'),
                    true,
                ),
            'Song and digest bridge must collapse its three sources, retain the authorized downloadable copy, and preserve the unrelated variant.',
        );
        $projectionRevision = $projection['revision'];
        $revision = music_catalog_set_song_title(
            $songId,
            'Bridge title revision',
            music_catalog_revision(),
        );
        $changedProjection = gallery_music_merge_playlists($sourceRows);
        check(
            $changedProjection['revision'] !== $projectionRevision,
            'A logical title edit must change the merged playlist fingerprint.',
        );
        $revision = music_catalog_set_song_title(
            $songId,
            '',
            $revision,
        );

        $tagAlphaSlug = 'music-fixture-alpha-' . $token;
        $tagBetaSlug = 'music-fixture-beta-' . $token;
        $tagImageSlug = 'image-fixture-' . $token;
        $tagPdfSlug = 'pdf-fixture-' . $token;
        $tagInsert = $pdo->prepare(
            'INSERT INTO tags '
            . '(name, slug, media_type, description, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, NOW(), NOW()), '
            . '(?, ?, ?, ?, NOW(), NOW()), '
            . '(?, ?, ?, ?, NOW(), NOW()), '
            . '(?, ?, ?, ?, NOW(), NOW())',
        );
        $tagInsert->execute([
            'Fixture Alpha ' . $token,
            $tagAlphaSlug,
            'music',
            'music-description-marker-' . $token,
            'Fixture Beta ' . $token,
            $tagBetaSlug,
            'music',
            'music-beta-description-' . $token,
            'Image marker ' . $token,
            $tagImageSlug,
            'image',
            'image-description-marker-' . $token,
            'PDF marker ' . $token,
            $tagPdfSlug,
            'pdf',
            'pdf-description-marker-' . $token,
        ]);
        $tagLookup = $pdo->prepare(
            'SELECT id FROM tags WHERE slug IN (?, ?) ORDER BY slug',
        );
        $tagLookup->execute([$tagAlphaSlug, $tagBetaSlug]);
        $tagIds = $tagLookup->fetchAll(\PDO::FETCH_COLUMN);
        $tagIdBySlug = $pdo->prepare('SELECT id FROM tags WHERE slug = ?');
        $tagIdBySlug->execute([$tagImageSlug]);
        $imageTagId = (int) $tagIdBySlug->fetchColumn();
        $tagIdBySlug->execute([$tagPdfSlug]);
        $pdfTagId = (int) $tagIdBySlug->fetchColumn();
        $pdo->prepare(
            'INSERT INTO music_song_tags (song_id, tag_id) VALUES (?, ?), (?, ?)',
        )->execute([$songId, $tagIds[0], $songInB, $tagIds[1]]);

        // Malformed historical assignments deliberately bypass the write
        // service so public read paths must still exclude music/PDF metadata.
        $imageInsert = $pdo->prepare(
            'INSERT INTO images (gallery_id, relative_path, relative_path_hash, '
            . 'filename, title, description, mime_type, file_size, visibility, '
            . 'created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
        );
        $imageInsert->execute([
            $galleryA,
            'typed-tag-probe.jpg',
            hash('sha256', $token . ':typed-tag-probe.jpg'),
            'typed-tag-probe.jpg',
            'Typed image probe',
            '',
            'image/jpeg',
            1,
            'public',
        ]);
        $fixtureImageId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO image_tags (image_id, tag_id) VALUES (?, ?), (?, ?), (?, ?) ',
        )->execute([
            $fixtureImageId,
            $imageTagId,
            $fixtureImageId,
            $tagIds[0],
            $fixtureImageId,
            $pdfTagId,
        ]);
        $pdo->prepare(
            'INSERT INTO gallery_tags (gallery_id, tag_id) VALUES (?, ?), (?, ?), (?, ?) ',
        )->execute([
            $galleryA,
            $imageTagId,
            $galleryA,
            $tagIds[0],
            $galleryA,
            $pdfTagId,
        ]);

        check(
            tag_media_type_schema_state() === 'available',
            'The migrated production tag schema must confirm media_type availability.',
        );
        check(
            is_array($imagePublicTag = find_tag_by_slug($tagImageSlug))
                && $imagePublicTag['slug'] === $tagImageSlug
                && find_tag_by_slug($tagAlphaSlug) === null
                && find_tag_by_slug($tagPdfSlug) === null,
            'Public tag lookup must resolve image tags only, even when other media types have the same assigned usage.',
        );
        $publicTagNames = tag_model_all_names(true);
        check(
            in_array('Image marker ' . $token, $publicTagNames, true)
                && !in_array('Fixture Alpha ' . $token, $publicTagNames, true)
                && !in_array('PDF marker ' . $token, $publicTagNames, true),
            'Public tag-name aggregation must exclude music/PDF labels.',
        );
        $imageTagGalleryIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            tag_model_public_galleries($imageTagId, true, true),
        );
        check(
            $imageTagGalleryIds === [$galleryA]
                && tag_model_public_galleries($tagIds[0], true, true) === []
                && tag_model_public_galleries($pdfTagId, true, true) === [],
            'Public image-tag paths must not resolve music/PDF assignments to galleries.',
        );
        foreach ([
            'Fixture Alpha ' . $token,
            $tagAlphaSlug,
            'music-description-marker-' . $token,
            'PDF marker ' . $token,
            $tagPdfSlug,
            'pdf-description-marker-' . $token,
        ] as $nonImageTagTerm) {
            check(
                public_search_model_compatibility_image_rows(
                    $nonImageTagTerm,
                    false,
                    null,
                    false,
                    false,
                    'en',
                    'available',
                    MUSIC_CATALOG_PUBLIC_TAG_SEARCH_LIMIT,
                ) === [],
                'Public image search must not leak non-image tag names, slugs, or descriptions.',
            );
        }
        $imageSearchRows = public_search_model_compatibility_image_rows(
            'image-description-marker-' . $token,
            false,
            null,
            false,
            false,
            'en',
            'available',
            MUSIC_CATALOG_PUBLIC_TAG_SEARCH_LIMIT,
        );
        check(
            count($imageSearchRows) === 1
                && (int) $imageSearchRows[0]['id'] === $fixtureImageId
                && $imageSearchRows[0]['image_tag_names']
                    === 'Image marker ' . $token,
            'Public image search must retain the image-tag name and description while excluding other media types.',
        );
        set_error_handler(
            static function (
                int $severity,
                string $message,
                string $file,
                int $line,
            ): bool {
                if ($severity === E_WARNING) {
                    throw new \ErrorException(
                        $message,
                        0,
                        $severity,
                        $file,
                        $line,
                    );
                }
                return false;
            },
        );
        try {
            $imageUsage = tag_usage_counts([
                $imageTagId,
                (int) $tagIds[0],
                $pdfTagId,
            ]);
        } finally {
            restore_error_handler();
        }
        check(
            $imageUsage[$imageTagId] === 2
                && $imageUsage[(int) $tagIds[0]] === 0
                && $imageUsage[$pdfTagId] === 0,
            'Hero usage counts must include only direct image-category assignments without emitting PHP warnings.',
        );
        $cachedSingleImageTags = tags_for_entity('image', $fixtureImageId);
        $cachedPluralImageTags = tags_for_entities(
            'image',
            [$fixtureImageId],
        );
        check(
            count($cachedSingleImageTags) === 1
                && $cachedSingleImageTags[0]['slug'] === $tagImageSlug
                && count($cachedPluralImageTags[$fixtureImageId] ?? []) === 1
                && $cachedPluralImageTags[$fixtureImageId][0]['slug']
                    === $tagImageSlug,
            'Single and plural image-tag readers must prime their positive request caches from image assignments.',
        );
        schema_inspection_set_query_executor_for_tests(
            'music_catalog_fail_schema_inspection',
        );
        try {
            check(
                tag_media_type_schema_state() === 'unknown'
                    && find_tag_by_slug($tagImageSlug) === null
                    && tags_for_entity('image', $fixtureImageId) === []
                    && tags_for_entities(
                        'image',
                        [$fixtureImageId],
                    ) === []
                    && public_search_model_compatibility_image_rows(
                        'image-description-marker-' . $token,
                        false,
                        null,
                        false,
                        false,
                        'en',
                        'unknown',
                        MUSIC_CATALOG_PUBLIC_TAG_SEARCH_LIMIT,
                    ) === [],
                'Unknown post-migration media_type inspection must fail closed without image fallback.',
            );
        } finally {
            schema_inspection_set_query_executor_for_tests(null);
            schema_inspection_reset_request_cache();
        }
        check(
            sync_entity_tags(
                'music_song',
                $songId,
                'Fixture Alpha ' . $token,
                'image',
            ) === false
                && (int) $pdo->query(
                    "SELECT COUNT(*) FROM music_song_tags "
                    . "WHERE song_id = $songId",
                )->fetchColumn() === 1,
            'Cross-type tag assignment must refuse without replacing existing tags.',
        );
        $revision = music_catalog_merge(
            [$songId, $variantId, $songInB, $songInC],
            $songId,
            $revision,
        );
        $fixtureSongIds[] = $songInB;
        $fixtureSongIds[] = $songInC;
        $usage = music_catalog_usage($songId);
        check(
            count($usage['direct_galleries']) === 3
                && array_sum(array_map(
                    static fn(array $row): int => (int) $row['track_count'],
                    $usage['direct_galleries'],
                )) === 4,
            'Merged recording must count both variants and both other galleries.',
        );
        check(
            (int) $pdo->query(
                "SELECT COUNT(*) FROM music_song_tags "
                . "WHERE song_id = $songId",
            )->fetchColumn() === 2,
            'Merge tag union must retain tags from selected identities.',
        );
        $splitAlbum = music_catalog_album_create(
            'Split membership fixture',
            null,
            $revision,
        );
        $fixtureAlbumIds[] = $splitAlbum['album_id'];
        $revision = music_catalog_album_song_add(
            $splitAlbum['album_id'],
            $songId,
            0,
            $splitAlbum['revision'],
        );
        $split = music_catalog_split(
            $songId,
            [
                ['gallery_id' => $galleryB, 'track_id' => $trackOneId],
                ['gallery_id' => $galleryC, 'track_id' => $trackOneId],
            ],
            $revision,
        );
        $revision = $split['revision'];
        $fixtureSongIds[] = $split['song_id'];
        check(
            (int) $pdo->query(
                "SELECT COUNT(*) FROM music_track_instances "
                . "WHERE song_id = {$split['song_id']} AND active = 1",
            )->fetchColumn() === 2
                && (int) $pdo->query(
                    "SELECT COUNT(*) FROM music_song_tags "
                    . "WHERE song_id = {$split['song_id']}",
                )->fetchColumn() === 2
                && (int) $pdo->query(
                    "SELECT COUNT(*) FROM music_album_songs "
                    . "WHERE album_id = {$splitAlbum['album_id']} "
                    . "AND song_id IN ($songId, {$split['song_id']})",
                )->fetchColumn() === 2,
            'Split must move the cross-gallery pair and copy tags and album membership.',
        );
        $pdo->prepare(
            'DELETE FROM music_song_tags WHERE song_id = ? AND tag_id = ?',
        )->execute([$songId, $tagIds[1]]);
        $revision = music_catalog_merge(
            [$songId, $split['song_id']],
            $songId,
            $revision,
            'survivor',
        );
        check(
            (int) $pdo->query(
                "SELECT COUNT(*) FROM music_song_tags "
                . "WHERE song_id = $songId",
            )->fetchColumn() === 1
                && (int) $pdo->query(
                    "SELECT COUNT(*) FROM music_track_instances "
                    . "WHERE song_id = $songId AND active = 1",
                )->fetchColumn() === 4
                && (int) $pdo->query(
                    "SELECT COUNT(*) FROM music_album_songs "
                    . "WHERE album_id = {$splitAlbum['album_id']} "
                    . "AND song_id = $songId",
                )->fetchColumn() === 1,
            'Survivor tag policy must retain its tags while restoring selected track references.',
        );

        $revision = music_catalog_set_song_title(
            $songId,
            'Český hlas 🎵',
            $revision,
        );
        check(
            $pdo->query(
                "SELECT title FROM music_songs WHERE id = $songId",
            )->fetchColumn() === 'Český hlas 🎵',
            'Custom titles must preserve exact Unicode input.',
        );
        $pdo->prepare(
            'DELETE FROM app_settings WHERE setting_key = ?',
        )->execute(['music_show_file_extensions']);
        app_settings_reset_request_cache();
        check(
            music_catalog_display_title('Audio.MP3', 'Audio.MP3') === 'Audio',
            'The default title presentation hides supported file extensions.',
        );
        $revision = music_catalog_set_show_file_extensions('on', $revision);
        check(
            music_catalog_display_title('Audio.MP3', 'Audio.MP3') === 'Audio.MP3',
            'The extension preference must expose the source suffix.',
        );
        $revision = music_catalog_set_show_file_extensions('off', $revision);

        $rootAlbum = music_catalog_album_create(
            'Root fixture album',
            null,
            $revision,
        );
        $fixtureAlbumIds[] = $rootAlbum['album_id'];
        $revision = $rootAlbum['revision'];
        $childAlbum = music_catalog_album_create(
            'Nested fixture album',
            $rootAlbum['album_id'],
            $revision,
        );
        $fixtureAlbumIds[] = $childAlbum['album_id'];
        $revision = $childAlbum['revision'];
        $secondAlbum = music_catalog_album_create(
            'Second fixture album',
            null,
            $revision,
        );
        $fixtureAlbumIds[] = $secondAlbum['album_id'];
        $revision = $secondAlbum['revision'];
        $revision = music_catalog_album_song_add(
            $childAlbum['album_id'],
            $songId,
            0,
            $revision,
        );
        $revision = music_catalog_album_move(
            $childAlbum['album_id'],
            $secondAlbum['album_id'],
            0,
            $revision,
        );
        $tree = music_catalog_album_tree();
        $child = array_values(array_filter(
            $tree,
            static fn(array $album): bool => $album['id']
                === $childAlbum['album_id'],
        ))[0];
        check(
            $child['parent_id'] === $secondAlbum['album_id'],
            'Album move must update its parent relation.',
        );
        music_catalog_expect_conflict(
            static fn(): string => music_catalog_album_move(
                $secondAlbum['album_id'],
                $childAlbum['album_id'],
                0,
                music_catalog_revision(),
            ),
            'Album parent cycle must be rejected.',
        );
        $revision = music_catalog_album_move(
            $childAlbum['album_id'],
            null,
            1,
            $revision,
        );
        $tree = music_catalog_album_tree();
        $child = array_values(array_filter(
            $tree,
            static fn(array $album): bool => $album['id']
                === $childAlbum['album_id'],
        ))[0];
        check(
            $child['parent_id'] === null,
            'Moving an album from nested to root level must update parentage.',
        );
        $revision = music_catalog_album_move(
            $childAlbum['album_id'],
            $secondAlbum['album_id'],
            0,
            $revision,
        );

        $restoredKnownTrackId = substr(
            hash('sha256', $token . ':trash-known'),
            0,
            32,
        );
        $restoredUnknownTrackId = substr(
            hash('sha256', $token . ':trash-unknown'),
            0,
            32,
        );
        $fixtureTrackIds[] = $restoredKnownTrackId;
        $fixtureTrackIds[] = $restoredUnknownTrackId;
        $songIdHighWater = (int) $pdo->query(
            'SELECT COALESCE(MAX(id), 0) FROM music_songs',
        )->fetchColumn();
        music_catalog_restore_trash_snapshot(
            $galleryD,
            [
                'revision' => str_repeat('0', 32),
                'enabled' => true,
                'tracks' => [[
                    'id' => $restoredKnownTrackId,
                    'filename' => 'restored-known.mp3',
                    'title' => 'Restored known recording',
                    'mime' => 'audio/mpeg',
                    'size' => 160,
                    'sha256' => str_repeat('a', 64),
                    'hidden' => false,
                    'downloadable' => true,
                ], [
                    'id' => $restoredUnknownTrackId,
                    'filename' => 'restored-unknown.mp3',
                    'title' => 'Restored unknown recording',
                    'mime' => 'audio/mpeg',
                    'size' => 170,
                    'sha256' => str_repeat('b', 64),
                    'hidden' => false,
                    'downloadable' => true,
                ]],
            ],
            [
                [
                    'track_id' => $restoredKnownTrackId,
                    'song_id' => $songId,
                ],
                [
                    'track_id' => $restoredUnknownTrackId,
                    'song_id' => PHP_INT_MAX,
                ],
            ],
        );
        $newSongIds = $pdo->prepare(
            'SELECT id FROM music_songs WHERE id > ?',
        );
        $newSongIds->execute([$songIdHighWater]);
        $fixtureSongIds = array_merge(
            $fixtureSongIds,
            array_map('intval', $newSongIds->fetchAll(\PDO::FETCH_COLUMN)),
        );
        $restoredKnown = $pdo->prepare(
            'SELECT song_id FROM music_track_instances '
            . 'WHERE gallery_id = ? AND track_id = ?',
        );
        $restoredKnown->execute([$galleryD, $restoredKnownTrackId]);
        $restoredKnownSongId = (int) $restoredKnown->fetchColumn();
        $restoredUnknown = $pdo->prepare(
            'SELECT song_id FROM music_track_instances '
            . 'WHERE gallery_id = ? AND track_id = ?',
        );
        $restoredUnknown->execute([$galleryD, $restoredUnknownTrackId]);
        $restoredUnknownSongId = (int) $restoredUnknown->fetchColumn();
        check(
            $restoredKnownSongId === $songId
                && $pdo->query(
                    "SELECT title FROM music_songs WHERE id = $songId",
                )->fetchColumn() === 'Český hlas 🎵'
                && (int) $pdo->query(
                    "SELECT COUNT(*) FROM music_song_tags "
                    . "WHERE song_id = $songId",
                )->fetchColumn() === 1
                && (int) $pdo->query(
                    "SELECT COUNT(*) FROM music_album_songs "
                    . "WHERE song_id = $songId",
                )->fetchColumn() === 2,
            'Trusted Trash reference must restore the song identity and preserve its title, tags and album links.',
        );
        check(
            $restoredUnknownSongId > 0
                && $restoredUnknownSongId !== $songId
                && (int) $pdo->query(
                    'SELECT COUNT(*) FROM music_songs WHERE id = '
                    . PHP_INT_MAX,
                )->fetchColumn() === 0,
            'Unknown saved Trash identity must leave a fresh song and never resurrect a missing identity.',
        );
        $revision = music_catalog_revision();

        // Optimistic conflict must leave membership and title untouched.
        $stale = music_catalog_revision();
        $revision = music_catalog_set_song_title($songId, 'Current title', $stale);
        $titleBeforeStale = $pdo->query(
            "SELECT title FROM music_songs WHERE id = $songId",
        )->fetchColumn();
        music_catalog_expect_conflict(
            static fn(): string => music_catalog_set_song_title(
                $songId,
                'Stale overwrite',
                $stale,
            ),
            'A stale global catalog revision must refuse a write.',
        );
        check(
            $pdo->query(
                "SELECT title FROM music_songs WHERE id = $songId",
            )->fetchColumn() === $titleBeforeStale,
            'Stale revision refusal must happen before persistent mutation.',
        );

        // A lower-level transaction failure must roll back its revision bump.
        $revisionBeforeFailure = music_catalog_revision();
        try {
            \Gallery\Models\music_catalog_model_transaction(
                $revisionBeforeFailure,
                static function (): void {
                    throw new \RuntimeException('fixture rollback');
                },
            );
        } catch (\RuntimeException) {
        }
        check(
            music_catalog_revision() === $revisionBeforeFailure,
            'A failed catalog transaction must roll back its revision advance.',
        );

        $revision = music_catalog_album_song_remove(
            $childAlbum['album_id'],
            $songId,
            $revision,
        );
        $revision = music_catalog_album_delete(
            $secondAlbum['album_id'],
            $revision,
        );
        if (is_int($fixtureImageId)) {
            $pdo->prepare('DELETE FROM images WHERE id = ?')
                ->execute([$fixtureImageId]);
            $fixtureImageId = null;
        }
        check(
            $pdo->query(
                "SELECT COUNT(*) FROM music_album_songs "
                . "WHERE album_id = {$childAlbum['album_id']} "
                . "AND song_id = $songId",
            )->fetchColumn() === 0,
            'Album removal must delete membership without deleting the song.',
        );
        check(
            $pdo->query(
                "SELECT COUNT(*) FROM music_songs WHERE id = $songId",
            )->fetchColumn() === 1,
            'Album deletion must preserve normalized song identities.',
        );

        $afterTags = $pdo->query(
            'SELECT id, slug, media_type FROM tags ORDER BY id',
        )->fetchAll(\PDO::FETCH_ASSOC);
        $originalTagIds = array_map(
            static fn(array $row): int => (int) $row['id'],
            $beforeTags,
        );
        if ($originalTagIds !== []) {
            $marks = implode(',', array_fill(0, count($originalTagIds), '?'));
            $originalTagsAfter = $pdo->prepare(
                "SELECT id, slug, media_type FROM tags "
                . "WHERE id IN ($marks) ORDER BY id",
            );
            $originalTagsAfter->execute($originalTagIds);
            $afterTags = $originalTagsAfter->fetchAll(\PDO::FETCH_ASSOC);
        } else {
            $afterTags = [];
        }
        $afterImages = $pdo->query(
            'SELECT id, gallery_id FROM images ORDER BY id',
        )->fetchAll(\PDO::FETCH_ASSOC);
        check(
            $beforeTags === $afterTags && $beforeImages === $afterImages,
            'Catalog activity must leave existing tags and image identities intact.',
        );

        // Use four token-matched owned songs with deliberately different title,
        // active-file, distinct-location and first-tag ranks. Extra track rows
        // are real service-created references and are tracked for final cleanup.
        $sortQuery = 'catalog-sort-'
            . substr(hash('sha256', $token . ':sorting'), 0, 12);
        $sortDefinitions = [
            'alpha' => [
                'file_count' => 3,
                'album_count' => 0,
                'tag_rank' => 'delta',
                'title_kind' => 'filename',
            ],
            'bravo' => [
                'file_count' => 1,
                'album_count' => 1,
                'tag_rank' => 'alpha',
                'title_kind' => 'indexed',
            ],
            'charlie' => [
                'file_count' => 4,
                'album_count' => 2,
                'tag_rank' => 'charlie',
                'title_kind' => 'custom',
            ],
            'delta' => [
                'file_count' => 2,
                'album_count' => 3,
                'tag_rank' => 'bravo',
                'title_kind' => 'custom',
            ],
        ];
        $sortTracks = [];
        $sortTrackIdsByRank = [];
        foreach ($sortDefinitions as $rank => $definition) {
            $trackIds = [];
            for ($variant = 0; $variant < $definition['file_count']; $variant++) {
                $trackIds[] = substr(
                    hash(
                        'sha256',
                        $token . ':sorting:' . $rank . ':' . $variant,
                    ),
                    0,
                    32,
                );
            }
            sort($trackIds, SORT_STRING);
            $sortTrackIdsByRank[$rank] = $trackIds;
            foreach ($trackIds as $variant => $trackId) {
                $filename = $sortQuery . '-' . $rank
                    . ($variant === 0 ? '' : '-variant-' . $variant)
                    . '.wav';
                $audioBytes = music_catalog_fixture_wav(
                    $token . ':sorting:' . $rank . ':' . $variant,
                );
                music_catalog_fixture_write_track(
                    $playlistDirectories[$galleryD],
                    $trackId,
                    $audioBytes,
                );
                $indexedTitle = match ($definition['title_kind']) {
                    'filename' => $variant === 0
                        ? $filename
                        : $sortQuery . '-alpha',
                    'indexed' => $sortQuery . '-bravo',
                    default => $sortQuery . '-' . $rank . '-indexed',
                };
                $sortTracks[] = [
                    'id' => $trackId,
                    'title' => $indexedTitle,
                    'filename' => $filename,
                    'sha256' => hash('sha256', $audioBytes),
                    'mime' => 'audio/wav',
                    'size' => strlen($audioBytes),
                    'hidden' => false,
                    'downloadable' => true,
                ];
                $fixtureTrackIds[] = $trackId;
            }
        }
        $revision = music_catalog_sync_gallery_snapshot(
            $galleryD,
            $sortTracks,
            music_catalog_revision(),
        );
        $sortIndexPath = $playlistDirectories[$galleryD]
            . DIRECTORY_SEPARATOR . 'index.json';
        if (file_put_contents($sortIndexPath, json_encode([
            'format' => 'gallery_music_v1',
            'revision' => substr(hash('sha256', $token . ':sorting-index'), 0, 32),
            'enabled' => false,
            'tracks' => $sortTracks,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) === false) {
            throw new \RuntimeException('Owned sorting playlist index could not be written.');
        }
        $sortSongIds = [];
        $sortSongLookup = $pdo->prepare(
            'SELECT song_id FROM music_track_instances '
            . 'WHERE gallery_id = ? AND track_id = ?',
        );
        foreach ($sortDefinitions as $rank => $definition) {
            $rankSongIds = [];
            foreach ($sortTrackIdsByRank[$rank] as $trackId) {
                $sortSongLookup->execute([$galleryD, $trackId]);
                $rankSongIds[] = (int) $sortSongLookup->fetchColumn();
            }
            check(
                count($rankSongIds) === $definition['file_count']
                    && !in_array(0, $rankSongIds, true),
                'Every sorting track must resolve to its owned normalized song identity.',
            );
            $sortSongIds[$rank] = $rankSongIds[0];
            if (count($rankSongIds) > 1) {
                $revision = music_catalog_merge(
                    $rankSongIds,
                    $sortSongIds[$rank],
                    $revision,
                );
            }
        }
        $revision = music_catalog_set_song_title(
            $sortSongIds['charlie'],
            $sortQuery . '-charlie',
            $revision,
        );
        $revision = music_catalog_set_song_title(
            $sortSongIds['delta'],
            $sortQuery . '-delta',
            $revision,
        );
        foreach ($sortDefinitions as $rank => $definition) {
            $sortingTagSlug = $sortQuery . '-tag-' . $definition['tag_rank'];
            $sortingTagSlugs[] = $sortingTagSlug;
            $revision = music_catalog_sync_song_tags(
                $sortSongIds[$rank],
                $sortingTagSlug,
                $revision,
            );
        }

        // Nested membership ranks make locations distinct from file counts.
        $sortAlbumIds = [];
        for (
            $albumIndex = 0;
            $albumIndex < MUSIC_CATALOG_SORT_FIXTURE_ALBUM_COUNT;
            $albumIndex++
        ) {
            $album = music_catalog_album_create(
                $sortQuery . ' album ' . $albumIndex,
                null,
                $revision,
            );
            $sortAlbumIds[] = (int) $album['album_id'];
            $fixtureAlbumIds[] = (int) $album['album_id'];
            $revision = $album['revision'];
        }
        $sortAlbumMembers = [
            ['bravo', 'charlie', 'delta'],
            ['charlie', 'delta'],
            ['delta'],
        ];
        foreach ($sortAlbumMembers as $albumIndex => $memberRanks) {
            foreach ($memberRanks as $position => $rank) {
                $revision = music_catalog_album_song_add(
                    $sortAlbumIds[$albumIndex],
                    $sortSongIds[$rank],
                    $position,
                    $revision,
                );
            }
        }

        $sortOrders = [
            'title' => [
                $sortSongIds['alpha'],
                $sortSongIds['bravo'],
                $sortSongIds['charlie'],
                $sortSongIds['delta'],
            ],
            'files' => [
                $sortSongIds['bravo'],
                $sortSongIds['delta'],
                $sortSongIds['alpha'],
                $sortSongIds['charlie'],
            ],
            'locations' => [
                $sortSongIds['alpha'],
                $sortSongIds['bravo'],
                $sortSongIds['charlie'],
                $sortSongIds['delta'],
            ],
            'tags' => [
                $sortSongIds['bravo'],
                $sortSongIds['delta'],
                $sortSongIds['charlie'],
                $sortSongIds['alpha'],
            ],
        ];
        foreach ($sortOrders as $sortKey => $expectedAscending) {
            foreach (['asc', 'desc'] as $direction) {
                $expected = $direction === 'asc'
                    ? $expectedAscending
                    : array_reverse($expectedAscending);
                $firstPage = music_catalog_list(
                    $sortQuery,
                    MUSIC_CATALOG_SORT_PAGE_SIZE,
                    0,
                    $sortKey,
                    $direction,
                );
                $secondPage = music_catalog_list(
                    $sortQuery,
                    MUSIC_CATALOG_SORT_PAGE_SIZE,
                    MUSIC_CATALOG_SORT_PAGE_SIZE,
                    $sortKey,
                    $direction,
                );
                $actual = array_map(
                    static fn(array $song): int => (int) $song['id'],
                    array_merge($firstPage['songs'], $secondPage['songs']),
                );
                check(
                    $firstPage['total'] === MUSIC_CATALOG_SORT_FIXTURE_SONG_COUNT
                        && $secondPage['total'] === MUSIC_CATALOG_SORT_FIXTURE_SONG_COUNT
                        && $actual === $expected,
                    'Catalog ' . $sortKey . ' ' . $direction
                        . ' order must cover the full matched set before pagination.',
                );
            }
        }
        $titleRows = music_catalog_list(
            $sortQuery,
            MUSIC_CATALOG_SORT_FIXTURE_SONG_COUNT,
            0,
            'title',
            'asc',
        )['songs'];
        check(
            array_column($titleRows, 'title') === [
                $sortQuery . '-alpha',
                $sortQuery . '-bravo',
                $sortQuery . '-charlie',
                $sortQuery . '-delta',
            ],
            'Title sorting must share extension-stripped filename, meaningful indexed and exact custom title fallbacks.',
        );
        $repositoryOnlyTitle = 'Central upload '
            . substr(hash('sha256', $token . ':repository-only'), 0, 12);
        $repositoryOnlyFilename = $repositoryOnlyTitle . '.wav';
        $repositoryOnlySource = $directory . DIRECTORY_SEPARATOR
            . 'owned-repository-only.wav';
        $fixtureSourcePaths[] = $repositoryOnlySource;
        $repositoryOnlyBytes = music_catalog_fixture_wav(
            $token . ':repository-only',
        );
        if (file_put_contents($repositoryOnlySource, $repositoryOnlyBytes)
            !== strlen($repositoryOnlyBytes)) {
            throw new \RuntimeException('Owned repository-only WAV could not be written.');
        }
        $repositoryOnlyAsset = music_repository_store_source(
            $repositoryOnlySource,
            $repositoryOnlyFilename,
            null,
            null,
            $repositoryOnlyTitle,
        );
        $fixtureSongIds[] = (int) $repositoryOnlyAsset['song_id'];
        $fixtureAssetIds[] = (string) $repositoryOnlyAsset['id'];
        $fixtureAssetPaths[] = music_repository_directory()
            . DIRECTORY_SEPARATOR . $repositoryOnlyAsset['id'] . '.bin';
        $repositoryOnlyResult = music_catalog_list(
            $repositoryOnlyTitle,
            MUSIC_CATALOG_SORT_PAGE_SIZE,
            0,
            'title',
            'asc',
        );
        check(
            $repositoryOnlyResult['total'] === 1
                && count($repositoryOnlyResult['songs']) === 1
                && (int) $repositoryOnlyResult['songs'][0]['id']
                    === (int) $repositoryOnlyAsset['song_id']
                && $repositoryOnlyResult['songs'][0]['title']
                    === $repositoryOnlyTitle
                && (int) $repositoryOnlyResult['songs'][0]['track_count'] === 1,
            'Repository-ready title search and ordering must include the matching asset-only song with its canonical title.',
        );
        $revisionBeforeInvalidOrder = music_catalog_revision();
        foreach ([['unknown', 'asc'], ['title', 'sideways']] as $invalidOrder) {
            $refused = false;
            try {
                music_catalog_list(
                    $sortQuery,
                    MUSIC_CATALOG_SORT_PAGE_SIZE,
                    0,
                    $invalidOrder[0],
                    $invalidOrder[1],
                );
            } catch (\InvalidArgumentException) {
                $refused = true;
            }
            check(
                $refused,
                'Unknown sorting columns and directions must be rejected.',
            );
        }
        check(
            music_catalog_revision() === $revisionBeforeInvalidOrder,
            'Invalid sorting requests must leave the global revision unchanged.',
        );

        // Reuse the owned sorting album after all count/order assertions.
        // Its existing members are bravo, charlie, delta; context Add appends
        // alpha and must never move an already-present member to the end.
        $beforeAppendRevision = music_catalog_revision();
        $revision = music_catalog_album_song_add(
            $sortAlbumIds[0],
            $sortSongIds['alpha'],
            null,
            $beforeAppendRevision,
        );
        $expectedAppendedIds = [
            $sortSongIds['bravo'],
            $sortSongIds['charlie'],
            $sortSongIds['delta'],
            $sortSongIds['alpha'],
        ];
        $appendedIds = array_map(
            static fn(array $song): int => (int) $song['id'],
            music_catalog_album_members($sortAlbumIds[0]),
        );
        check(
            $appendedIds === $expectedAppendedIds,
            'Contextual album Add must append after all existing members.',
        );
        $staleAppendRefused = false;
        try {
            music_catalog_album_song_add(
                $sortAlbumIds[1],
                $sortSongIds['alpha'],
                null,
                $beforeAppendRevision,
            );
        } catch (\RuntimeException) {
            $staleAppendRefused = true;
        }
        $membersAfterStaleAppend = array_map(
            static fn(array $song): int => (int) $song['id'],
            music_catalog_album_members($sortAlbumIds[1]),
        );
        check(
            $staleAppendRefused
                && music_catalog_revision() === $revision
                && $membersAfterStaleAppend === [
                    $sortSongIds['charlie'],
                    $sortSongIds['delta'],
                ],
            'Contextual Add must reject a stale revision without changing state.',
        );
        $revision = music_catalog_album_song_add(
            $sortAlbumIds[0],
            $sortSongIds['charlie'],
            null,
            $revision,
        );
        $repeatedAppendIds = array_map(
            static fn(array $song): int => (int) $song['id'],
            music_catalog_album_members($sortAlbumIds[0]),
        );
        check(
            $repeatedAppendIds === $expectedAppendedIds,
            'Adding an existing member again must preserve the complete album order.',
        );

        echo "Music catalog database contracts passed.\n";
    } finally {
        if ($pdo instanceof \PDO) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($fixtureGalleryIds !== [] && $fixtureTrackIds !== []) {
                $galleryMarks = implode(
                    ',',
                    array_fill(0, count($fixtureGalleryIds), '?'),
                );
                $trackMarks = implode(
                    ',',
                    array_fill(0, count($fixtureTrackIds), '?'),
                );
                $ownedAssets = $pdo->prepare(
                    'SELECT DISTINCT a.id FROM music_repository_assets a '
                    . 'JOIN music_track_instances i '
                    . 'ON i.gallery_id = a.origin_gallery_id '
                    . 'AND i.track_id = a.origin_track_id '
                    . "WHERE i.gallery_id IN ($galleryMarks) "
                    . "AND i.track_id IN ($trackMarks)",
                );
                $ownedAssets->execute(array_merge(
                    $fixtureGalleryIds,
                    $fixtureTrackIds,
                ));
                $fixtureAssetIds = array_merge(
                    $fixtureAssetIds,
                    array_map('strval', $ownedAssets->fetchAll(\PDO::FETCH_COLUMN)),
                );
                foreach (array_unique($fixtureAssetIds) as $assetId) {
                    $fixtureAssetPaths[] = dirname(__DIR__)
                        . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
                        . 'music' . DIRECTORY_SEPARATOR . $assetId . '.bin';
                }
            }
            if ($fixtureTrackIds !== []) {
                $trackMarks = implode(
                    ',',
                    array_fill(0, count($fixtureTrackIds), '?'),
                );
                $trackSongs = $pdo->prepare(
                    'SELECT DISTINCT song_id FROM music_track_instances '
                    . "WHERE track_id IN ($trackMarks)",
                );
                $trackSongs->execute($fixtureTrackIds);
                $fixtureSongIds = array_merge(
                    $fixtureSongIds,
                    array_map('intval', $trackSongs->fetchAll(\PDO::FETCH_COLUMN)),
                );
                $pdo->prepare(
                    'DELETE FROM music_track_instances '
                    . "WHERE track_id IN ($trackMarks)",
                )->execute($fixtureTrackIds);
            }
            if ($fixtureAlbumIds !== []) {
                $marks = implode(',', array_fill(0, count($fixtureAlbumIds), '?'));
                $pdo->prepare(
                    "DELETE FROM music_albums WHERE id IN ($marks)",
                )->execute($fixtureAlbumIds);
            }
            if ($fixtureAssetIds !== []) {
                $assetIds = array_values(array_unique($fixtureAssetIds));
                $assetMarks = implode(',', array_fill(0, count($assetIds), '?'));
                $pdo->prepare(
                    "DELETE FROM music_repository_assets WHERE id IN ($assetMarks)",
                )->execute($assetIds);
            }
            foreach ($playlistDirectories as $musicDirectory) {
                foreach (array_unique($fixtureTrackIds) as $trackId) {
                    $trackPath = $musicDirectory . DIRECTORY_SEPARATOR
                        . $trackId . '.bin';
                    if (is_file($trackPath) && !is_link($trackPath)) {
                        unlink($trackPath);
                    }
                }
                $indexPath = $musicDirectory . DIRECTORY_SEPARATOR
                    . 'index.json';
                if (is_file($indexPath) && !is_link($indexPath)) {
                    unlink($indexPath);
                }
                $lockPath = $musicDirectory . DIRECTORY_SEPARATOR . '.lock';
                if (is_file($lockPath) && !is_link($lockPath)) {
                    unlink($lockPath);
                }
                if (is_dir($musicDirectory) && !is_link($musicDirectory)) {
                    rmdir($musicDirectory);
                    rmdir(dirname($musicDirectory));
                }
            }
            if ($fixtureSongIds !== []) {
                $songIds = array_values(array_unique($fixtureSongIds));
                $marks = implode(',', array_fill(0, count($songIds), '?'));
                $pdo->prepare(
                    "DELETE FROM music_songs WHERE id IN ($marks)",
                )->execute($songIds);
            }
            if ($fixtureGalleryIds !== []) {
                $galleryMarks = implode(
                    ',',
                    array_fill(0, count($fixtureGalleryIds), '?'),
                );
                $pdo->prepare(
                    "DELETE FROM galleries WHERE id IN ($galleryMarks)",
                )->execute($fixtureGalleryIds);
            }
            if (is_string($fixtureRevision)) {
                $pdo->prepare(
                    "UPDATE music_catalog_state SET revision = ? "
                    . "WHERE state_key = 'catalog'",
                )->execute([(int) $fixtureRevision]);
            }
            foreach ([
                'music_show_file_extensions',
                'theme_public_content_revision',
            ] as $settingKey) {
                if (array_key_exists($settingKey, $originalSettingRows)) {
                    $pdo->prepare(
                        'UPDATE app_settings SET setting_value = ?, updated_at = ? '
                        . 'WHERE setting_key = ?',
                    )->execute([
                        $originalSettingRows[$settingKey]['setting_value'],
                        $originalSettingRows[$settingKey]['updated_at'],
                        $settingKey,
                    ]);
                } else {
                    $pdo->prepare(
                        'DELETE FROM app_settings WHERE setting_key = ?',
                    )->execute([$settingKey]);
                }
            }
            if (is_string($tagAlphaSlug)
                && is_string($tagBetaSlug)
                && is_string($tagImageSlug)
                && is_string($tagPdfSlug)) {
                $pdo->prepare(
                    'DELETE FROM tags WHERE slug IN (?, ?, ?, ?)',
                )->execute([
                    $tagAlphaSlug,
                    $tagBetaSlug,
                    $tagImageSlug,
                    $tagPdfSlug,
                ]);
            }
            if ($sortingTagSlugs !== []) {
                $sortTagMarks = implode(
                    ',',
                    array_fill(0, count($sortingTagSlugs), '?'),
                );
                $pdo->prepare(
                    "DELETE FROM tags WHERE slug IN ($sortTagMarks)",
                )->execute($sortingTagSlugs);
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
