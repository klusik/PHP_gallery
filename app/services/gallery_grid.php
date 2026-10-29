<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_grid.php
 * Module Type: Service
 *
 * Purpose:
 *   Resolves public display-grid settings for the home page and gallery pages.
 *
 * Responsibilities:
 *   - Keep main-page gallery grid settings independent from gallery-page grids
 *   - Allow each gallery to define its own grid override and photo-card format
 *   - Allow child galleries to inherit an outer gallery grid only when that outer gallery allows it
 *   - Preserve the existing global pagination settings as the compatibility fallback
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/gallery_edit_concurrency.php';

use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Models\gallery_model_all_rows_by_folder_path;
use function Gallery\Models\gallery_model_reset_grid_overrides;

/**
 * Keep public photo-slot lookup independent of localized editor option models.
 * Type: array<string,string>. Units: CSS width/height ratio pairs.
 * Scope: physical-gallery photo-card formats; default emits no override.
 * Consumers: gallery_photo_card_ratio_options(), normalization and CSS lookup.
 * Rationale: one closed storage/CSS vocabulary preserves historic defaults and
 * avoids constructing or translating Admin controls on public render paths.
 */
const GALLERY_PHOTO_CARD_RATIOS = [
    'default' => '',
    '16:9' => '16 / 9',
    '21:9' => '21 / 9',
    '4:3' => '4 / 3',
    '1:1' => '1 / 1',
    '4:5' => '4 / 5',
    '9:16' => '9 / 16',
];

/**
 * Return true when the gallery-grid override columns exist in the database.
 *
 * The application is often updated by uploading files first and running
 * migrations afterwards. This feature gate prevents edit forms and public
 * rendering from touching columns that may not exist yet on partially-updated
 * installations.
 *
 * @return bool True when the condition matches.
 */
function gallery_grid_schema_ready(): bool
{
    return (
        db_column_exists('galleries', 'grid_columns')
            && db_column_exists('galleries', 'grid_rows')
            && db_column_exists('galleries', 'grid_use_for_subgalleries')
    );
}

/**
 * Return true when one gallery row contains a complete explicit grid override.
 *
 * NULL columns intentionally mean "inherit". A gallery must have both columns
 * and rows filled before it becomes an override source, because rows are used by
 * pagination slicing while columns are used by the CSS grid.
 *
 * @param array{grid_columns?:int|string|null,grid_rows?:int|string|null} $gallery Gallery row with optional grid override dimensions.
 * @return bool True when grid storage exists and both override dimensions are non-null.
 */
function gallery_grid_has_explicit_override(array $gallery): bool
{
    if (!gallery_grid_schema_ready()) {
        return false;
    }
    return (
        isset($gallery['grid_columns'], $gallery['grid_rows'])
            && $gallery['grid_columns'] !== null
            && $gallery['grid_rows'] !== null
    );
}

/**
 * Normalize grid settings into the same shape used by the pagination renderer.
 *
 * The grid_columns_enabled flag deliberately decouples visual grid columns from
 * pagination_enabled. This lets admins use a 5-column gallery grid even when
 * pagination itself is disabled.
 *
 * @param int $columns Columns value.
 * @param int $rows Rows to process.
 * @param bool $paginationEnabled Pagination enabled value.
 * @param string $source Source value.
 * @return array{enabled:bool,columns:int,rows:int,items_per_page:int,grid_columns_enabled:bool,grid_source:string} Normalized grid and pagination settings.
 */
function gallery_grid_settings_from_dimensions(
    int $columns,
    int $rows,
    bool $paginationEnabled,
    string $source,
): array {
    // $safeColumns stores the clamped public-grid column count.
    $safeColumns = pagination_dimension_value(
        $columns,
        CMS_PAGINATION_DEFAULT_COLUMNS,
        CMS_PAGINATION_MAX_COLUMNS,
    );
    // $safeRows stores the clamped public-grid row count used by optional pagination.
    $safeRows = pagination_dimension_value(
        $rows,
        CMS_PAGINATION_DEFAULT_ROWS,
        CMS_PAGINATION_MAX_ROWS,
    );

    return [
        'enabled' => $paginationEnabled,
        'columns' => $safeColumns,
        'rows' => $safeRows,
        'items_per_page' => $safeColumns * $safeRows,
        'grid_columns_enabled' => true,
        'grid_source' => $source,
    ];
}

/**
 * Return the independent grid used by the public home page gallery listing.
 *
 * The fallback intentionally uses the existing global pagination dimensions so
 * upgraded installations keep their current visual layout until the admin saves
 * a distinct main-page grid.
 *
 * @return array{enabled:bool,columns:int,rows:int,items_per_page:int,grid_columns_enabled:bool,grid_source:string} Home-page grid and pagination settings.
 */
function main_page_gallery_grid_settings(): array
{
    // $global stores the compatibility fallback and the shared pagination switch.
    $global = pagination_global_settings(['listing' => 'home_galleries']);
    // $columns stores the home-page gallery-card column count.
    $columns = pagination_dimension_value(
        app_setting('home_gallery_grid_columns', (string) $global['columns']),
        (int) $global['columns'],
        CMS_PAGINATION_MAX_COLUMNS,
    );
    // $rows stores the home-page row count used when pagination is enabled.
    $rows = pagination_dimension_value(
        app_setting('home_gallery_grid_rows', (string) $global['rows']),
        (int) $global['rows'],
        CMS_PAGINATION_MAX_ROWS,
    );

    return gallery_grid_settings_from_dimensions(
        $columns,
        $rows,
        !empty($global['enabled']),
        'home',
    );
}

/**
 * Return a gallery page grid using explicit, inherited, then global settings.
 *
 * Resolution order:
 * 1. The current gallery, when it has a custom grid.
 * 2. The nearest ancestor that has a custom grid and has enabled inheritance.
 * 3. The global Theme pagination dimensions, which preserve previous behavior.
 *
 * @param array{id?:int|string,parent_id?:int|string|null,grid_columns?:int|string|null,grid_rows?:int|string|null,grid_use_for_subgalleries?:int|string|null} $gallery Gallery row fields used for explicit and inherited overrides.
 * @return array{enabled:bool,columns:int,rows:int,items_per_page:int,grid_columns_enabled:bool,grid_source:string} Effective grid settings and the source identifier.
 */
function gallery_effective_grid_settings(array $gallery): array
{
    // $global stores the existing cross-site fallback and pagination switch.
    $global = pagination_global_settings([
        'listing' => 'gallery',
        'gallery' => $gallery,
    ]);
    if (!gallery_grid_schema_ready()) {
        return gallery_grid_settings_from_dimensions(
            (int) $global['columns'],
            (int) $global['rows'],
            !empty($global['enabled']),
            'global',
        );
    }

    if (gallery_grid_has_explicit_override($gallery)) {
        return gallery_grid_settings_from_dimensions(
            (int) $gallery['grid_columns'],
            (int) $gallery['grid_rows'],
            !empty($global['enabled']),
            'gallery:' . (int) $gallery['id'],
        );
    }

    // $ancestor stores the currently inspected parent gallery while walking upward.
    $ancestor = !empty($gallery['parent_id'])
        ? find_gallery((int) $gallery['parent_id'])
        : null;
    while ($ancestor) {
        if (
            gallery_grid_has_explicit_override($ancestor)
            && (int) ($ancestor['grid_use_for_subgalleries'] ?? 1) === 1
        ) {
            return gallery_grid_settings_from_dimensions(
                (int) $ancestor['grid_columns'],
                (int) $ancestor['grid_rows'],
                !empty($global['enabled']),
                'ancestor:' . (int) $ancestor['id'],
            );
        }
        $ancestor = !empty($ancestor['parent_id'])
            ? find_gallery((int) $ancestor['parent_id'])
            : null;
    }

    return gallery_grid_settings_from_dimensions(
        (int) $global['columns'],
        (int) $global['rows'],
        !empty($global['enabled']),
        'global',
    );
}

/**
 * Remove every custom gallery-grid override from the database and gallery.json sidecars.
 *
 * The reset intentionally touches both persistence layers. The database is the
 * runtime source used by normal requests, while gallery.json is the filesystem
 * metadata source used by imports and repair workflows. Clearing only one of
 * them would allow stale custom grids to reappear after a rescan, so this helper
 * keeps both layers synchronized.
 *
 * @return array{database_rows:int,sidecars:int,schema_ready:bool} Changed row/sidecar counts and whether database overrides were available.
 */
function reset_all_gallery_grid_overrides(): array
{
    $writerLock = gallery_edit_writer_begin();
    try {
        return reset_all_gallery_grid_overrides_owned();
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Reset stored gallery grid overrides and their sidecar copies.
 *
 * Internal implementation: enter through reset_all_gallery_grid_overrides() so
 * reads, early returns and failure cleanup remain inside the same writer lease.
 *
 * @return array{database_rows:int,sidecars:int,schema_ready:bool} Changed row/sidecar counts and whether database overrides were available.
 * @author Rudolf Klusal
 */
function reset_all_gallery_grid_overrides_owned(): array
{
    // $result stores counters for the Admin confirmation message after redirect.
    $result = [
        'database_rows' => 0,
        'sidecars' => 0,
        'schema_ready' => gallery_grid_schema_ready(),
    ];

    // $galleryRows stores all known gallery folders, including their existing metadata when the grid schema exists.
    $galleryRows = gallery_model_all_rows_by_folder_path();

    if ($result['schema_ready']) {
        // $now stores a consistent update timestamp for every row changed by this reset operation.
        $now = now_sql();
        $result['database_rows'] = gallery_model_reset_grid_overrides($now);
    }

    foreach ($galleryRows as $galleryRow) {
        // $folderPath stores the normalized gallery folder path used to locate gallery.json.
        $folderPath = normalize_relative_path(
            (string) ($galleryRow['folder_path'] ?? ''),
        );
        if ($folderPath === '') {
            continue;
        }

        // $sidecarPath stores the absolute path to the optional metadata file for this gallery.
        $sidecarPath =
            gallery_abs_path($folderPath)
            . DIRECTORY_SEPARATOR
            . 'gallery.json';
        if (!is_file($sidecarPath)) {
            continue;
        }

        // $sidecar stores existing metadata so non-grid settings are not lost during cleanup.
        $sidecar = read_gallery_sidecar($sidecarPath);
        // $hadGridMetadata stores whether this sidecar actually contained stale custom-grid keys.
        $hadGridMetadata =
            array_key_exists('grid_columns', $sidecar)
            || array_key_exists('grid_rows', $sidecar)
            || array_key_exists('grid_use_for_subgalleries', $sidecar);

        if (!$hadGridMetadata) {
            continue;
        }

        unset(
            $sidecar['grid_columns'],
            $sidecar['grid_rows'],
            $sidecar['grid_use_for_subgalleries'],
        );
        if (write_gallery_sidecar_for_path($folderPath, $sidecar)) {
            $result['sidecars']++;
        }
    }

    return $result;
}

/**
 * Return the selected custom-grid columns for an Admin form.
 *
 * @param array<string,mixed> $gallery Gallery row used to resolve an explicit, inherited, or global grid.
 * @return int Selected grid column count for the Admin form.
 */
function gallery_grid_form_columns(array $gallery): int
{
    if (gallery_grid_has_explicit_override($gallery)) {
        return pagination_dimension_value(
            $gallery['grid_columns'],
            CMS_PAGINATION_DEFAULT_COLUMNS,
            CMS_PAGINATION_MAX_COLUMNS,
        );
    }
    $settings = gallery_effective_grid_settings($gallery);
    return (int) $settings['columns'];
}

/**
 * Return the selected custom-grid rows for an Admin form.
 *
 * @param array<string,mixed> $gallery Gallery row used to resolve an explicit, inherited, or global grid.
 * @return int Selected grid row count for the Admin form.
 */
function gallery_grid_form_rows(array $gallery): int
{
    if (gallery_grid_has_explicit_override($gallery)) {
        return pagination_dimension_value(
            $gallery['grid_rows'],
            CMS_PAGINATION_DEFAULT_ROWS,
            CMS_PAGINATION_MAX_ROWS,
        );
    }
    $settings = gallery_effective_grid_settings($gallery);
    return (int) $settings['rows'];
}

/**
 * List the supported photo-card formats in their editor order.
 *
 * Default deliberately emits no CSS override: desktop retains 4:3 and narrow
 * layouts retain square cards. This permanent compatibility choice is owned
 * here, rather than rewriting existing galleries or their original media.
 *
 * @return list<array{value:string,label:string,ratio:string}> Stable storage
 *     values, localized labels and allowlisted CSS aspect ratios; default has
 *     an empty ratio so the existing responsive cascade remains authoritative.
 */
function gallery_photo_card_ratio_options(): array
{
    $options = [];
    foreach (GALLERY_PHOTO_CARD_RATIOS as $value => $ratio) {
        $options[] = [
            'value' => $value,
            'label' => $value === 'default'
                ? t('admin.features.factory_match', 'Default')
                : $value,
            'ratio' => $ratio,
        ];
    }
    return $options;
}

/**
 * Normalize stored or imported photo-card format metadata.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Untrusted
 *     stored/imported format; unsupported values preserve the historic layout.
 * @return string Supported format identifier, or default for absent/bad data.
 */
function gallery_photo_card_ratio_normalize(mixed $value): string
{
    return is_string($value)
        && array_key_exists($value, GALLERY_PHOTO_CARD_RATIOS)
        ? $value
        : 'default';
}

/**
 * Validate explicit editor input before the gallery edit lease or side effects.
 *
 * @param scalar|array<array-key,mixed>|object|resource|null $value Submitted
 *     format; unlike legacy reads, invalid explicit writes must be refused.
 * @return string Exact allowlisted format identifier.
 * @throws \InvalidArgumentException When the submitted format is unsupported.
 */
function gallery_photo_card_ratio_validate(mixed $value): string
{
    if (!is_string($value) || gallery_photo_card_ratio_normalize($value) !== $value) {
        throw new \InvalidArgumentException(t(
            'admin.gallery_editor.photo_ratio_invalid',
            'Choose a supported photo card format.',
        ));
    }
    return $value;
}

/**
 * Read this physical gallery's format without inheriting another gallery's choice.
 *
 * @param int $galleryId Physical gallery identity; nonpositive IDs use default.
 * @return string Saved supported identifier, or the historic responsive default.
 */
function gallery_photo_card_ratio(int $galleryId): string
{
    return $galleryId > 0
        ? gallery_photo_card_ratio_normalize(app_setting(
            'gallery_photo_card_ratio.' . $galleryId,
            'default',
        ))
        : 'default';
}

/**
 * Persist one validated photo-card format using the existing settings model.
 *
 * @param int $galleryId Physical gallery owning the preference.
 * @param scalar|array<array-key,mixed>|object|resource|null $value Explicit
 *     format identifier; invalid input never reaches persistence.
 * @return string Canonical persisted format identifier.
 * @throws \InvalidArgumentException When identity or format is invalid.
 */
function gallery_photo_card_ratio_save(int $galleryId, mixed $value): string
{
    $ratio = gallery_photo_card_ratio_validate($value);
    if ($galleryId < 1) {
        throw new \InvalidArgumentException('A physical gallery is required.');
    }
    set_app_setting('gallery_photo_card_ratio.' . $galleryId, $ratio);
    return $ratio;
}

/**
 * Resolve a safe CSS ratio for the selected physical gallery's photo grid.
 *
 * @param int $galleryId Physical gallery owning the rendered grid.
 * @return string Allowlisted CSS ratio or an empty string for the unchanged
 *     responsive layout; never a raw database value.
 */
function gallery_photo_card_css_ratio(int $galleryId): string
{
    return GALLERY_PHOTO_CARD_RATIOS[gallery_photo_card_ratio($galleryId)];
}
