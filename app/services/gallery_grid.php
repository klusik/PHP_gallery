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
 *   - Allow each gallery to define its own grid override
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

/**
 * Return true when the gallery-grid override columns exist in the database.
 *
 * The application is often updated by uploading files first and running
 * migrations afterwards. This feature gate prevents edit forms and public
 * rendering from touching columns that may not exist yet on partially-updated
 * installations.
 */
function gallery_grid_schema_ready(): bool
{
    return db_column_exists('galleries', 'grid_columns')
        && db_column_exists('galleries', 'grid_rows')
        && db_column_exists('galleries', 'grid_use_for_subgalleries');
}

/**
 * Return true when one gallery row contains a complete explicit grid override.
 *
 * NULL columns intentionally mean "inherit". A gallery must have both columns
 * and rows filled before it becomes an override source, because rows are used by
 * pagination slicing while columns are used by the CSS grid.
 */
function gallery_grid_has_explicit_override(array $gallery): bool
{
    if (!gallery_grid_schema_ready()) {
        return false;
    }
    return isset($gallery['grid_columns'], $gallery['grid_rows'])
        && $gallery['grid_columns'] !== null
        && $gallery['grid_rows'] !== null;
}

/**
 * Normalize grid settings into the same shape used by the pagination renderer.
 *
 * The grid_columns_enabled flag deliberately decouples visual grid columns from
 * pagination_enabled. This lets admins use a 5-column gallery grid even when
 * pagination itself is disabled.
 */
function gallery_grid_settings_from_dimensions(int $columns, int $rows, bool $paginationEnabled, string $source): array
{
    // $safeColumns stores the clamped public-grid column count.
    $safeColumns = pagination_dimension_value($columns, CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS);
    // $safeRows stores the clamped public-grid row count used by optional pagination.
    $safeRows = pagination_dimension_value($rows, CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS);

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
 */
function main_page_gallery_grid_settings(): array
{
    // $global stores the compatibility fallback and the shared pagination switch.
    $global = pagination_global_settings(['listing' => 'home_galleries']);
    // $columns stores the home-page gallery-card column count.
    $columns = pagination_dimension_value(app_setting('home_gallery_grid_columns', (string) $global['columns']), (int) $global['columns'], CMS_PAGINATION_MAX_COLUMNS);
    // $rows stores the home-page row count used when pagination is enabled.
    $rows = pagination_dimension_value(app_setting('home_gallery_grid_rows', (string) $global['rows']), (int) $global['rows'], CMS_PAGINATION_MAX_ROWS);

    return gallery_grid_settings_from_dimensions($columns, $rows, !empty($global['enabled']), 'home');
}

/**
 * Return a gallery page grid using explicit, inherited, then global settings.
 *
 * Resolution order:
 * 1. The current gallery, when it has a custom grid.
 * 2. The nearest ancestor that has a custom grid and has enabled inheritance.
 * 3. The global Theme pagination dimensions, which preserve previous behavior.
 */
function gallery_effective_grid_settings(array $gallery): array
{
    // $global stores the existing cross-site fallback and pagination switch.
    $global = pagination_global_settings(['listing' => 'gallery', 'gallery' => $gallery]);
    if (!gallery_grid_schema_ready()) {
        return gallery_grid_settings_from_dimensions((int) $global['columns'], (int) $global['rows'], !empty($global['enabled']), 'global');
    }

    if (gallery_grid_has_explicit_override($gallery)) {
        return gallery_grid_settings_from_dimensions((int) $gallery['grid_columns'], (int) $gallery['grid_rows'], !empty($global['enabled']), 'gallery:' . (int) $gallery['id']);
    }

    // $ancestor stores the currently inspected parent gallery while walking upward.
    $ancestor = !empty($gallery['parent_id']) ? find_gallery((int) $gallery['parent_id']) : null;
    while ($ancestor) {
        if (gallery_grid_has_explicit_override($ancestor) && (int) ($ancestor['grid_use_for_subgalleries'] ?? 1) === 1) {
            return gallery_grid_settings_from_dimensions((int) $ancestor['grid_columns'], (int) $ancestor['grid_rows'], !empty($global['enabled']), 'ancestor:' . (int) $ancestor['id']);
        }
        $ancestor = !empty($ancestor['parent_id']) ? find_gallery((int) $ancestor['parent_id']) : null;
    }

    return gallery_grid_settings_from_dimensions((int) $global['columns'], (int) $global['rows'], !empty($global['enabled']), 'global');
}


/**
 * Remove every custom gallery-grid override from the database and gallery.json sidecars.
 *
 * The reset intentionally touches both persistence layers. The database is the
 * runtime source used by normal requests, while gallery.json is the filesystem
 * metadata source used by imports and repair workflows. Clearing only one of
 * them would allow stale custom grids to reappear after a rescan, so this helper
 * keeps both layers synchronized.
 */
function reset_all_gallery_grid_overrides(): array
{
    // $result stores counters for the Admin confirmation message after redirect.
    $result = [
        'database_rows' => 0,
        'sidecars' => 0,
        'schema_ready' => gallery_grid_schema_ready(),
    ];

    // $pdo stores the active database connection used for both reading and updating gallery rows.
    $pdo = db();
    // $galleryRows stores all known gallery folders, including their existing metadata when the grid schema exists.
    $galleryRows = $pdo->query('SELECT * FROM galleries ORDER BY folder_path')->fetchAll();

    if ($result['schema_ready']) {
        // $now stores a consistent update timestamp for every row changed by this reset operation.
        $now = now_sql();
        // $stmt clears explicit grid settings while preserving every other gallery option.
        $stmt = $pdo->prepare('UPDATE galleries SET grid_columns = NULL, grid_rows = NULL, grid_use_for_subgalleries = 1, updated_at = ? WHERE grid_columns IS NOT NULL OR grid_rows IS NOT NULL OR grid_use_for_subgalleries <> 1');
        $stmt->execute([$now]);
        $result['database_rows'] = $stmt->rowCount();
    }

    foreach ($galleryRows as $galleryRow) {
        // $folderPath stores the normalized gallery folder path used to locate gallery.json.
        $folderPath = normalize_relative_path((string) ($galleryRow['folder_path'] ?? ''));
        if ($folderPath === '') {
            continue;
        }

        // $sidecarPath stores the absolute path to the optional metadata file for this gallery.
        $sidecarPath = gallery_abs_path($folderPath) . DIRECTORY_SEPARATOR . 'gallery.json';
        if (!is_file($sidecarPath)) {
            continue;
        }

        // $sidecar stores existing metadata so non-grid settings are not lost during cleanup.
        $sidecar = read_gallery_sidecar($sidecarPath);
        // $hadGridMetadata stores whether this sidecar actually contained stale custom-grid keys.
        $hadGridMetadata = array_key_exists('grid_columns', $sidecar)
            || array_key_exists('grid_rows', $sidecar)
            || array_key_exists('grid_use_for_subgalleries', $sidecar);

        if (!$hadGridMetadata) {
            continue;
        }

        unset($sidecar['grid_columns'], $sidecar['grid_rows'], $sidecar['grid_use_for_subgalleries']);
        if (write_gallery_sidecar_for_path($folderPath, $sidecar)) {
            $result['sidecars']++;
        }
    }

    return $result;
}

/**
 * Return the selected custom-grid columns for an Admin form.
 */
function gallery_grid_form_columns(array $gallery): int
{
    if (gallery_grid_has_explicit_override($gallery)) {
        return pagination_dimension_value($gallery['grid_columns'], CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS);
    }
    $settings = gallery_effective_grid_settings($gallery);
    return (int) $settings['columns'];
}

/**
 * Return the selected custom-grid rows for an Admin form.
 */
function gallery_grid_form_rows(array $gallery): int
{
    if (gallery_grid_has_explicit_override($gallery)) {
        return pagination_dimension_value($gallery['grid_rows'], CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS);
    }
    $settings = gallery_effective_grid_settings($gallery);
    return (int) $settings['rows'];
}
