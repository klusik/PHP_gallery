<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_cover_transfer_test.php
 * Module Type: Regression Test
 * Purpose: Protect ordered cover transfer across sidecars and resumable migration jobs.
 * Responsibilities:
 *   - Verify export prunes missing, moved and nested source images before ID mapping
 *   - Verify sidecar import preserves valid ordered lists and Automatic mode
 *   - Verify migration completion remaps ordered IDs and fails closed on schema or ownership errors
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Normalize fixture relative paths using the production separator contract.
     *
     * @param string $path Candidate relative path.
     * @return string Normalized slash-separated path, or throw for traversal.
     */
    function normalize_relative_path(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $parts = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== '' && $part !== '.'));
        if (in_array('..', $parts, true)) {
            throw new \RuntimeException('Path traversal is not allowed.');
        }
        return implode('/', $parts);
    }

    /**
     * Return a deterministic timestamp for transfer metadata writes.
     *
     * @return string Fixture SQL timestamp.
     */
    function now_sql(): string
    {
        return '2026-10-16 12:00:00';
    }

    /**
     * Return a fixture URL for the imported gallery.
     *
     * @param string $route Named route.
     * @param array<string,mixed> $params Route values.
     * @return string Deterministic fixture URL.
     */
    function url_for(string $route, array $params = []): string
    {
        return '/' . $route . '/' . (string) ($params['id'] ?? '');
    }
}

namespace Gallery\Models {
    /**
     * Apply semantic gallery fields to the isolated transfer fixture.
     *
     * @param int $galleryId Gallery identity.
     * @param array<string,mixed> $fields Semantic fields to persist.
     * @param string $now Fixture timestamp.
     * @return void Updates only the fixture's named gallery row.
     */
    function gallery_model_update_fields(int $galleryId, array $fields, string $now): void
    {
        $GLOBALS['cover_transfer_fixture']['galleries'][$galleryId] = array_replace(
            $GLOBALS['cover_transfer_fixture']['galleries'][$galleryId] ?? [],
            $fields,
            ['updated_at' => $now]
        );
    }

    /**
     * Report whether the fixture gallery schema includes uploaded cover assets.
     *
     * @return bool Whether cover_image_path is available.
     */
    function gallery_model_cover_image_path_column_exists(): bool
    {
        return (bool) ($GLOBALS['cover_transfer_fixture']['asset_schema'] ?? true);
    }
}

namespace Gallery\Services {
    /**
     * Purpose: Preserve the fixture's gallery description layout contract.
     * Type: int.
     * Units: Semantic schema revision.
     * Scope: Transfer fixture only; value 2.
     * Consumers: Gallery description transfer fixture.
     * Rationale: Keep fixture behavior aligned with the current layout semantics.
     */
    const GALLERY_DESCRIPTION_LAYOUT_SEMANTICS_VERSION = 2;
    /**
     * Purpose: Represent the inherited breadcrumb style in the fixture.
     * Type: string.
     * Units: Style token.
     * Scope: Transfer fixture only; literal token `inherit`.
     * Consumers: Breadcrumb transfer fixture.
     * Rationale: Exercise inheritance without introducing a fixture-only style value.
     */
    const BREADCRUMB_STYLE_INHERIT = 'inherit';

    /**
     * Read one authoritative fixture image by ID.
     *
     * @param int $imageId Image identity.
     * @param bool $fresh Request a fresh row.
     * @return array{id:int,gallery_id:int,relative_path:string,visibility:string}|null Owned fixture row or null.
     */
    function find_image(int $imageId, bool $fresh = false): ?array
    {
        return $GLOBALS['cover_transfer_fixture']['images'][$imageId] ?? null;
    }

    /**
     * Return the fixture schema inspection outcome for one column.
     *
     * @param string $table Table name.
     * @param string $column Column name.
     * @return string Fixture schema state.
     */
    function schema_inspection_column(string $table, string $column): string
    {
        return (string) ($GLOBALS['cover_transfer_fixture']['schema_state'] ?? 'available');
    }

    /**
     * Identify a confirmed available fixture column.
     *
     * @param string $result Schema inspection state.
     * @return bool Whether the state is available.
     */
    function schema_inspection_is_available(string $result): bool
    {
        return $result === 'available';
    }

    /**
     * Identify a confirmed missing fixture column.
     *
     * @param string $result Schema inspection state.
     * @return bool Whether the state is missing.
     */
    function schema_inspection_is_missing(string $result): bool
    {
        return $result === 'missing';
    }

    /**
     * Read one sidecar payload supplied by the isolated test.
     *
     * @param string $path Ignored fixture path.
     * @return array<string,mixed> Configured sidecar metadata.
     */
    function read_gallery_sidecar(string $path): array
    {
        return $GLOBALS['cover_transfer_fixture']['sidecar'] ?? [];
    }

    /**
     * Resolve one direct path in the requested gallery.
     *
     * @param int $galleryId Physical gallery identity.
     * @param string $relativePath Normalized direct relative path.
     * @return array{id:int,gallery_id:int,relative_path:string,visibility:string}|null Matching image or null.
     */
    function find_image_by_path(int $galleryId, string $relativePath): ?array
    {
        foreach ($GLOBALS['cover_transfer_fixture']['images'] as $image) {
            if ($image['gallery_id'] === $galleryId && $image['relative_path'] === $relativePath) {
                return $image;
            }
        }
        return null;
    }

    /**
     * Return the fixture content-localization schema state.
     *
     * @param string $entity Entity kind.
     * @return bool False because transfer tests isolate cover metadata only.
     */
    function content_localization_schema_ready(string $entity): bool
    {
        return false;
    }

    /**
     * Return no tags for the isolated sidecar serialization fixture.
     *
     * @param string $entity Entity kind.
     * @param int $entityId Entity identity.
     * @return list<array{name:string}> No configured tags.
     */
    function tags_for_entity(string $entity, int $entityId): array
    {
        return [];
    }

    /**
     * Return the inherited breadcrumb style for the sidecar fixture.
     *
     * @param int $galleryId Gallery identity.
     * @return string Inherited style marker.
     */
    function gallery_breadcrumb_style_override(int $galleryId): string
    {
        return BREADCRUMB_STYLE_INHERIT;
    }

    /**
     * Capture serialized sidecar data without filesystem writes.
     *
     * @param string $folderPath Gallery folder.
     * @param array<string,mixed> $data Sidecar payload.
     * @return bool Fixture write result.
     */
    function write_gallery_sidecar_for_path(string $folderPath, array $data): bool
    {
        $GLOBALS['cover_transfer_fixture']['sidecar_export'] = ['folder_path' => $folderPath, 'data' => $data];
        return true;
    }
}

namespace {
    require_once __DIR__ . '/support/module_source.php';
    require_once __DIR__ . '/support/gallery_edit_writer_source.php';
    require_once dirname(__DIR__) . '/app/services/gallery_covers.php';

    /**
     * Require a strict equality for one transfer behavior.
     *
     * @param int|string|bool|null|list<int|string>|array<string,int|string|bool|null> $expected Required scalar, ordered cover IDs/paths, or gallery fields.
     * @param int|string|bool|null|list<int|string>|array<string,int|string|bool|null> $actual Observed scalar, ordered cover IDs/paths, or gallery fields.
     * @param string $label Behavior description.
     * @return void Throws when the fixture differs.
     */
    function cover_transfer_assert(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    /**
     * Reset the independent gallery/image transfer fixture.
     *
     * @param array<string,mixed> $overrides Replacement fixture state.
     * @return void Installs a fresh in-memory transfer case.
     */
    function cover_transfer_reset(array $overrides = []): void
    {
        $GLOBALS['cover_transfer_fixture'] = array_replace([
            'schema_state' => 'available',
            'asset_schema' => true,
            'sidecar' => [],
            'galleries' => [20 => ['id' => 20, 'folder_path' => 'target', 'cover_image_id' => null,
                'cover_image_ids' => null, 'cover_image_path' => 'old-upload.png']],
            'images' => [
                201 => ['id' => 201, 'gallery_id' => 20, 'relative_path' => 'one.jpg', 'visibility' => 'public'],
                202 => ['id' => 202, 'gallery_id' => 20, 'relative_path' => 'two.jpg', 'visibility' => 'public'],
                203 => ['id' => 203, 'gallery_id' => 99, 'relative_path' => 'moved.jpg', 'visibility' => 'public'],
                204 => ['id' => 204, 'gallery_id' => 20, 'relative_path' => 'nested/child.jpg', 'visibility' => 'public'],
            ],
        ], $overrides);
    }

    /**
     * Build one explicit adapter for the extracted migration completion body.
     *
     * @param string $name Dependency name replaced in the production source.
     * @return Closure(mixed...):mixed Fixture result for that named dependency.
     */
    function cover_transfer_import_adapter(string $name): Closure
    {
        return static function (mixed ...$arguments) use ($name): mixed {
            $state = &$GLOBALS['cover_transfer_fixture'];
            $state['events'][] = $name;
            return match ($name) {
                'mutation_schema_assert_available' => null,
                'gallery_migration_schema_status' => 'available',
                'gallery_migration_load_job' => $state['job'],
                'gallery_migration_manifest_galleries' => $arguments[0]['galleries'],
                'gallery_cover_selection_schema_state' => $state['schema_state'],
                'gallery_migration_sync_received_assets' => $arguments[0],
                'gallery_migration_manifest_asset_refs' => [],
                'gallery_migration_target_gallery_id' => (int) $arguments[0]['gallery_map'][(string) $arguments[1]],
                'gallery_migration_apply_gallery_metadata' => null,
                'find_gallery' => $state['galleries'][(int) $arguments[0]] ?? null,
                'find_image' => $state['images'][(int) $arguments[0]] ?? null,
                'gallery_cover_selection_schema_ready' => $state['schema_state'] === 'available',
                'gallery_cover_asset_schema_ready' => (bool) ($state['asset_schema'] ?? true),
                'gallery_model_update_fields' => cover_transfer_apply_gallery_fields((int) $arguments[0], $arguments[1], (string) $arguments[2]),
                'now_sql' => \Gallery\Core\now_sql(),
                'write_gallery_sidecar' => cover_transfer_write_sidecar($arguments[0]),
                'gallery_migration_save_job' => cover_transfer_save_job($arguments[0]),
                'admin_log_event' => null,
                'gallery_public_url' => '/gallery/' . (int) $arguments[0]['id'],
                'url_for' => \Gallery\Core\url_for((string) $arguments[0], (array) ($arguments[1] ?? [])),
                'gallery_migration_t' => (string) ($arguments[1] ?? $arguments[0] ?? ''),
                default => throw new RuntimeException('Unexpected migration adapter: ' . $name),
            };
        };
    }

    /**
     * Apply one mapped gallery update inside the import adapter.
     *
     * @param int $galleryId Target gallery identity.
     * @param array<string,mixed> $fields Semantic cover fields.
     * @param string $now Fixture timestamp.
     * @return void No value is returned by the fixture adapter.
     */
    function cover_transfer_apply_gallery_fields(int $galleryId, array $fields, string $now): void
    {
        \Gallery\Models\gallery_model_update_fields($galleryId, $fields, $now);
    }

    /**
     * Record one completed gallery sidecar write.
     *
     * @param array<string,mixed> $gallery Updated gallery row.
     * @return bool Successful fixture write.
     */
    function cover_transfer_write_sidecar(array $gallery): bool
    {
        $GLOBALS['cover_transfer_fixture']['sidecars'][] = $gallery;
        return true;
    }

    /**
     * Record durable migration-job completion.
     *
     * @param array<string,mixed> $job Completed job state.
     * @return void No value is returned by the fixture adapter.
     */
    function cover_transfer_save_job(array $job): void
    {
        $GLOBALS['cover_transfer_fixture']['saved_job'] = $job;
    }

    $migrationSource = module_source(dirname(__DIR__) . '/app/services/gallery_migration.php');
    $exportMetadata = gallery_writer_fixture_function($migrationSource, 'gallery_migration_gallery_metadata', [
        'find_image' => static fn (int $id, bool $fresh = false): ?array => \Gallery\Services\find_image($id, $fresh),
        'content_localization_schema_ready' => static fn (string $entity): bool => false,
    ]);
    $applySidecarCover = gallery_writer_fixture_function(
        module_source(dirname(__DIR__) . '/app/services/gallery_covers.php'),
        'apply_gallery_cover_from_sidecar',
        [
            'read_gallery_sidecar' => static fn (string $path): array => \Gallery\Services\read_gallery_sidecar($path),
            'gallery_abs_path' => static fn (string $path): string => '/fixture/' . $path,
            'gallery_cover_selection_schema_ready' => static fn (): bool => \Gallery\Services\gallery_cover_selection_schema_ready(),
            'normalize_relative_path' => static fn (string $path): string => \Gallery\Core\normalize_relative_path($path),
            'find_image_by_path' => static fn (int $galleryId, string $path): ?array => \Gallery\Services\find_image_by_path($galleryId, $path),
            'gallery_cover_asset_schema_ready' => static fn (): bool => \Gallery\Services\gallery_cover_asset_schema_ready(),
            'gallery_model_update_fields' => static function (int $id, array $fields, string $now): void {
                \Gallery\Models\gallery_model_update_fields($id, $fields, $now);
            },
            'now_sql' => static fn (): string => \Gallery\Core\now_sql(),
        ]
    );
    $writeSidecarExport = gallery_writer_fixture_function(
        module_source(dirname(__DIR__) . '/app/services/gallery_sidecars.php'),
        'write_gallery_sidecar',
        [
            'tags_for_entity' => static fn (string $entity, int $id): array => [],
            'content_localization_schema_ready' => static fn (string $entity): bool => false,
            'gallery_date_schema_ready' => static fn (): bool => false,
            'gallery_date_range_schema_ready' => static fn (): bool => false,
            'gallery_description_layout_schema_ready' => static fn (): bool => false,
            'gallery_count_badge_schema_ready' => static fn (): bool => false,
            'gallery_lightbox_browsing_mode_schema_ready' => static fn (): bool => false,
            'gallery_breadcrumb_style_override' => static fn (int $id): string => 'inherit',
            'gallery_grid_schema_ready' => static fn (): bool => false,
            'thumbnail_bounds_schema_ready' => static fn (): bool => false,
            'gallery_access_assert_public_policy_available' => static fn (): mixed => null,
            'gallery_access_schema_ready' => static fn (): bool => false,
            'find_image' => static fn (int $id, bool $fresh = false): ?array => \Gallery\Services\find_image($id, $fresh),
            'gallery_cover_selection_schema_ready' => static fn (): bool => \Gallery\Services\gallery_cover_selection_schema_ready(),
            'gallery_cover_selection_storage_valid' => static fn (array $gallery): bool => \Gallery\Services\gallery_cover_selection_storage_valid($gallery),
            'gallery_cover_selection_ids' => static fn (array $gallery): array => \Gallery\Services\gallery_cover_selection_ids($gallery),
            'write_gallery_sidecar_for_path' => static fn (string $folder, array $data): bool => \Gallery\Services\write_gallery_sidecar_for_path($folder, $data),
        ]
    );
    $completeImportAdapters = [];
    foreach ([
        'mutation_schema_assert_available', 'gallery_migration_schema_status', 'gallery_migration_load_job',
        'gallery_migration_manifest_galleries', 'gallery_cover_selection_schema_state',
        'gallery_migration_sync_received_assets', 'gallery_migration_manifest_asset_refs',
        'gallery_migration_target_gallery_id', 'gallery_migration_apply_gallery_metadata', 'find_gallery',
        'find_image', 'gallery_cover_selection_schema_ready', 'gallery_cover_asset_schema_ready',
        'gallery_model_update_fields', 'now_sql', 'write_gallery_sidecar', 'gallery_migration_save_job',
        'admin_log_event', 'gallery_public_url', 'url_for', 'gallery_migration_t',
    ] as $dependency) {
        $completeImportAdapters[$dependency] = cover_transfer_import_adapter($dependency);
    }
    $completeImport = gallery_writer_fixture_function($migrationSource, 'gallery_migration_complete_job_owned', $completeImportAdapters);

    cover_transfer_reset();
    $exported = $exportMetadata(['id' => 20, 'title' => 'Source', 'cover_image_id' => 203,
        'cover_image_ids' => '[201,203,204,202]']);
    cover_transfer_assert([201, 202], $exported['cover_selection_source_ids'], 'ZIP metadata prunes moved and nested cover IDs while retaining order');
    cover_transfer_assert(201, $exported['cover_source_id'], 'ZIP scalar primary follows the first surviving selection');

    cover_transfer_reset();
    $automaticExport = $exportMetadata(['id' => 20, 'title' => 'Source', 'cover_image_id' => null, 'cover_image_ids' => '[]']);
    cover_transfer_assert([], $automaticExport['cover_selection_source_ids'], 'ZIP metadata preserves explicit Automatic as an empty list');
    cover_transfer_assert(0, $automaticExport['cover_source_id'], 'Automatic ZIP metadata has no scalar primary');

    cover_transfer_reset();
    $writeSidecarExport(['id' => 20, 'folder_path' => 'source', 'title' => 'Source', 'description' => '',
        'visibility' => 'public', 'sort_order' => 0, 'cover_image_id' => 203, 'cover_image_ids' => '[201,203,204,202]']);
    cover_transfer_assert(['one.jpg', 'two.jpg'], $GLOBALS['cover_transfer_fixture']['sidecar_export']['data']['cover_images'],
        'Sidecar export writes only surviving direct images in persisted order');
    cover_transfer_assert(false, array_key_exists('cover', $GLOBALS['cover_transfer_fixture']['sidecar_export']['data']),
        'Sidecar export omits a foreign scalar cover path');
    cover_transfer_reset();
    $writeSidecarExport(['id' => 20, 'folder_path' => 'source', 'title' => 'Source', 'description' => '',
        'visibility' => 'public', 'sort_order' => 0, 'cover_image_id' => null, 'cover_image_ids' => '[]']);
    cover_transfer_assert([], $GLOBALS['cover_transfer_fixture']['sidecar_export']['data']['cover_images'],
        'Sidecar export preserves explicit Automatic as an empty path list');
    cover_transfer_reset(['schema_state' => 'unknown']);
    cover_transfer_assert(false, $writeSidecarExport(['id' => 20, 'folder_path' => 'source', 'title' => 'Source', 'description' => '',
        'visibility' => 'public', 'sort_order' => 0, 'cover_image_id' => 201, 'cover_image_ids' => '[201]']),
        'Sidecar export refuses unverified selection schema');
    cover_transfer_assert(false, isset($GLOBALS['cover_transfer_fixture']['sidecar_export']), 'Unknown-schema sidecar export performs no write');

    cover_transfer_reset();
    $legacyExport = $exportMetadata(['id' => 20, 'title' => 'Legacy', 'cover_image_id' => 201, 'cover_image_ids' => null]);
    cover_transfer_assert(false, array_key_exists('cover_selection_source_ids', $legacyExport), 'Legacy NULL mode stays distinct from Automatic');
    cover_transfer_assert(201, $legacyExport['cover_source_id'], 'Legacy scalar export remains compatible');
    $movedLegacyExport = $exportMetadata(['id' => 20, 'title' => 'Legacy', 'cover_image_id' => 203, 'cover_image_ids' => null]);
    cover_transfer_assert(0, $movedLegacyExport['cover_source_id'], 'Legacy ZIP export prunes moved scalar cover IDs before mapping');

    cover_transfer_reset(['schema_state' => 'missing', 'sidecar' => ['cover_images' => ['one.jpg']]]);
    $before = $GLOBALS['cover_transfer_fixture']['galleries'][20];
    $applySidecarCover(['id' => 20, 'folder_path' => 'target', 'cover_image_ids' => null, 'cover_image_id' => null]);
    cover_transfer_assert($before, $GLOBALS['cover_transfer_fixture']['galleries'][20], 'Sidecar multi-selection refuses a missing schema without partial writes');
    cover_transfer_reset(['schema_state' => 'unknown', 'sidecar' => ['cover_images' => ['one.jpg']]]);
    $before = $GLOBALS['cover_transfer_fixture']['galleries'][20];
    $applySidecarCover(['id' => 20, 'folder_path' => 'target', 'cover_image_ids' => null, 'cover_image_id' => null]);
    cover_transfer_assert($before, $GLOBALS['cover_transfer_fixture']['galleries'][20], 'Sidecar multi-selection refuses unknown schema state');

    cover_transfer_reset(['asset_schema' => false, 'sidecar' => ['cover_images' => ['two.jpg', 'one.jpg']]]);
    $applySidecarCover(['id' => 20, 'folder_path' => 'target', 'cover_image_id' => null,
        'cover_image_ids' => null, 'cover_image_path' => 'old-upload.png']);
    cover_transfer_assert('[202,201]', $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_ids'], 'Sidecar path import preserves manual selection order');
    cover_transfer_assert(202, $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_id'], 'Sidecar import keeps scalar compatibility primary');
    cover_transfer_assert(null, $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_path'], 'Explicit sidecar selection clears uploaded cover precedence');

    cover_transfer_reset([
        'asset_schema' => false,
        'sidecar' => ['cover_images' => ['one.jpg']],
        'galleries' => [20 => ['id' => 20, 'folder_path' => 'target', 'cover_image_id' => null, 'cover_image_ids' => null]],
    ]);
    $applySidecarCover(['id' => 20, 'folder_path' => 'target', 'cover_image_id' => null, 'cover_image_ids' => null]);
    cover_transfer_assert(false, array_key_exists('cover_image_path', $GLOBALS['cover_transfer_fixture']['galleries'][20]),
        'Sidecar import leaves an absent legacy cover-asset column untouched.');

    cover_transfer_reset(['sidecar' => ['cover_images' => []]]);
    $applySidecarCover(['id' => 20, 'folder_path' => 'target', 'cover_image_id' => 201,
        'cover_image_ids' => '[201]', 'cover_image_path' => null]);
    cover_transfer_assert('[]', $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_ids'], 'Empty sidecar selection restores Automatic mode');
    cover_transfer_assert(null, $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_id'], 'Automatic sidecar import clears scalar primary');

    cover_transfer_reset(['sidecar' => ['cover_images' => ['one.jpg', 'nested/child.jpg']]]);
    $before = $GLOBALS['cover_transfer_fixture']['galleries'][20];
    $applySidecarCover(['id' => 20, 'folder_path' => 'target', 'cover_image_id' => null, 'cover_image_ids' => null]);
    cover_transfer_assert($before, $GLOBALS['cover_transfer_fixture']['galleries'][20], 'Sidecar import rejects nested selection paths as a whole');

    cover_transfer_reset([
        'asset_schema' => false,
        'job' => ['job_id' => 'cover-job', 'target_gallery_id' => 20, 'imported_root_gallery_id' => 20,
            'manifest' => ['source_gallery_id' => 10, 'galleries' => [[
                'source_id' => 10, 'gallery' => ['cover_source_id' => 100, 'cover_selection_source_ids' => [100, 101]],
            ]]], 'gallery_map' => ['10' => 20], 'image_map' => ['100' => 202, '101' => 201], 'assets_received' => []],
    ]);
    $completeImport('cover-job', 20);
    cover_transfer_assert('[202,201]', $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_ids'], 'ZIP import remaps source IDs while preserving selection order');
    cover_transfer_assert(202, $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_id'], 'ZIP import retains first remapped ID as scalar primary');
    cover_transfer_assert(null, $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_path'], 'ZIP import gives explicit photos precedence over uploaded cover assets');

    cover_transfer_reset([
        'asset_schema' => false,
        'galleries' => [20 => ['id' => 20, 'folder_path' => 'target', 'cover_image_id' => null, 'cover_image_ids' => null]],
        'job' => ['job_id' => 'cover-job', 'target_gallery_id' => 20, 'imported_root_gallery_id' => 20,
            'manifest' => ['source_gallery_id' => 10, 'galleries' => [[
                'source_id' => 10, 'gallery' => ['cover_source_id' => 100, 'cover_selection_source_ids' => [100]],
            ]]], 'gallery_map' => ['10' => 20], 'image_map' => ['100' => 202], 'assets_received' => []],
    ]);
    $completeImport('cover-job', 20);
    cover_transfer_assert(false, array_key_exists('cover_image_path', $GLOBALS['cover_transfer_fixture']['galleries'][20]),
        'ZIP import leaves an absent legacy cover-asset column untouched.');

    cover_transfer_reset([
        'job' => ['job_id' => 'cover-job', 'target_gallery_id' => 20, 'imported_root_gallery_id' => 20,
            'manifest' => ['source_gallery_id' => 10, 'galleries' => [[
                'source_id' => 10, 'gallery' => ['cover_source_id' => 0, 'cover_selection_source_ids' => []],
            ]]], 'gallery_map' => ['10' => 20], 'image_map' => [], 'assets_received' => []],
    ]);
    $completeImport('cover-job', 20);
    cover_transfer_assert('[]', $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_ids'], 'ZIP import restores explicit Automatic mode');
    cover_transfer_assert(null, $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_id'], 'Automatic import clears compatible scalar primary');

    cover_transfer_reset([
        'galleries' => [20 => ['id' => 20, 'folder_path' => 'target', 'cover_image_id' => 201,
            'cover_image_ids' => '[201]', 'cover_image_path' => null]],
        'job' => ['job_id' => 'cover-job', 'target_gallery_id' => 20, 'imported_root_gallery_id' => 20,
            'manifest' => ['source_gallery_id' => 10, 'galleries' => [[
                'source_id' => 10, 'gallery' => ['cover_source_id' => 100],
            ]]], 'gallery_map' => ['10' => 20], 'image_map' => ['100' => 202], 'assets_received' => []],
    ]);
    $completeImport('cover-job', 20);
    cover_transfer_assert(202, $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_id'], 'Legacy ZIP import remaps its scalar primary');
    cover_transfer_assert(null, $GLOBALS['cover_transfer_fixture']['galleries'][20]['cover_image_ids'], 'Legacy ZIP import restores NULL legacy mode');

    foreach (['missing', 'unknown'] as $schemaState) {
        cover_transfer_reset([
            'schema_state' => $schemaState,
            'job' => ['job_id' => 'cover-job', 'target_gallery_id' => 20,
                'manifest' => ['source_gallery_id' => 10, 'galleries' => [[
                    'source_id' => 10, 'gallery' => ['cover_source_id' => 100, 'cover_selection_source_ids' => [100]],
                ]]], 'gallery_map' => ['10' => 20], 'image_map' => ['100' => 202], 'assets_received' => []],
        ]);
        $refused = false;
        try {
            $completeImport('cover-job', 20);
        } catch (RuntimeException $expectedSchemaFailure) {
            $refused = true;
        }
        cover_transfer_assert(true, $refused, 'Cover schema ' . $schemaState . ' is refused');
        cover_transfer_assert(false, in_array('gallery_migration_sync_received_assets', $GLOBALS['cover_transfer_fixture']['events'], true),
            'Cover schema ' . $schemaState . ' is rejected before import work begins');
    }

    cover_transfer_reset([
        'job' => ['job_id' => 'cover-job', 'target_gallery_id' => 20, 'imported_root_gallery_id' => 20,
            'manifest' => ['source_gallery_id' => 10, 'galleries' => [[
                'source_id' => 10, 'gallery' => ['cover_source_id' => 100, 'cover_selection_source_ids' => [100, 101]],
            ]]], 'gallery_map' => ['10' => 20], 'image_map' => ['100' => 202, '101' => 203], 'assets_received' => []],
    ]);
    $before = $GLOBALS['cover_transfer_fixture']['galleries'][20];
    $refused = false;
    try {
        $completeImport('cover-job', 20);
    } catch (RuntimeException $expectedOwnershipFailure) {
        $refused = true;
    }
    cover_transfer_assert(true, $refused, 'A moved/foreign remapped cover image is refused');
    cover_transfer_assert($before, $GLOBALS['cover_transfer_fixture']['galleries'][20], 'Foreign remapped cover IDs are rejected before persistence');

    echo "gallery cover transfer tests passed\n";
}
