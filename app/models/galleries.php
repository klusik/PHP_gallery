<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/galleries.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns reusable gallery-row persistence that is independent of HTTP and presentation.
 *
 * Responsibilities:
 *   - Persist bulk gallery state fields through semantic model operations
 *   - Return append-style child sort-order values for gallery creation workflows
 *   - Keep gallery-table SQL out of controllers and service orchestration
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
 *   - Callers must pass already-normalized positive gallery identifiers.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use function Gallery\Core\db;
use function Gallery\Core\slugify;

/**
 * Update one explicitly supported scalar gallery column for a set of rows.
 *
 * This is an internal model primitive. Public callers should use the semantic
 * wrappers below so domain intent remains visible at call sites.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param string $column Supported galleries column name.
 * @param mixed $value Scalar value written to every selected gallery.
 * @param string $now Shared SQL timestamp for every touched row.
 * @return int Number of rows reported as changed by the database driver.
 */
function gallery_model_update_scalar_for_ids(array $galleryIds, string $column, mixed $value, string $now): int
{
    if ($galleryIds === []) {
        return 0;
    }

    $allowedColumns = [
        'visibility',
        'gps_map_enabled',
        'voting_enabled',
        'show_filenames',
        'picture_game_enabled',
    ];
    if (!in_array($column, $allowedColumns, true)) {
        throw new InvalidArgumentException('Unsupported gallery bulk-update column.');
    }

    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    $stmt = db()->prepare('UPDATE galleries SET ' . $column . ' = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$value, $now], $galleryIds));
    return $stmt->rowCount();
}

/**
 * Persist one visibility value for selected galleries.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param string $visibility Storage visibility value.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_visibility(array $galleryIds, string $visibility, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'visibility', $visibility, $now);
}

/**
 * Persist an explicit or inherited GPS-map override for selected galleries.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param ?bool $enabled True/false for explicit state, null for inheritance.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_gps_map_enabled(array $galleryIds, ?bool $enabled, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'gps_map_enabled', $enabled === null ? null : ($enabled ? 1 : 0), $now);
}

/**
 * Persist image-voting state for selected galleries.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param bool $enabled Whether image voting is enabled.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_voting_enabled(array $galleryIds, bool $enabled, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'voting_enabled', $enabled ? 1 : 0, $now);
}

/**
 * Persist filename-display state for selected galleries.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param bool $enabled Whether filenames are shown.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_show_filenames(array $galleryIds, bool $enabled, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'show_filenames', $enabled ? 1 : 0, $now);
}

/**
 * Persist Picture Game state for selected galleries.
 *
 * @param array<int,int> $galleryIds Positive gallery identifiers.
 * @param bool $enabled Whether Picture Game is enabled.
 * @param string $now Shared SQL timestamp.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_picture_game_enabled(array $galleryIds, bool $enabled, string $now): int
{
    return gallery_model_update_scalar_for_ids($galleryIds, 'picture_game_enabled', $enabled ? 1 : 0, $now);
}

/**
 * Return the append-style sort order for a new child gallery.
 *
 * The query intentionally preserves the legacy `parent_id = ?` semantics,
 * including the current treatment of a zero parent identifier.
 *
 * @param int $parentGalleryId Parent gallery identifier.
 * @return int Next sort-order value spaced by ten.
 */
function gallery_model_next_child_sort_order(int $parentGalleryId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM galleries WHERE parent_id = ?');
    $stmt->execute([$parentGalleryId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return whether a gallery slug is already used by another gallery.
 *
 * @param string $slug Candidate normalized slug.
 * @param int $excludeGalleryId Gallery identifier excluded from the collision check.
 * @return bool True when another gallery already owns the slug.
 */
function gallery_model_slug_exists(string $slug, int $excludeGalleryId = 0): bool
{
    $sql = 'SELECT id FROM galleries WHERE slug = ?';
    $params = [$slug];
    if ($excludeGalleryId > 0) {
        $sql .= ' AND id <> ?';
        $params[] = $excludeGalleryId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (bool) $stmt->fetch();
}

/**
 * Persist a semantic set of editable gallery fields for one gallery row.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<string,mixed> $fields Column-value map prepared by service/controller orchestration.
 * @param string $now SQL timestamp written to updated_at.
 */
function gallery_model_update_fields(int $galleryId, array $fields, string $now): void
{
    if ($galleryId <= 0 || $fields === []) {
        return;
    }

    $allowedColumns = [
        'title',
        'description',
        'slug',
        'visibility',
        'sort_order',
        'parent_id',
        'cover_image_id',
        'gallery_date',
        'gallery_date_end',
        'picture_game_enabled',
        'gps_map_enabled',
        'voting_enabled',
        'show_filenames',
        'description_layout',
        'count_badge_visibility',
        'lightbox_browsing_mode',
        'nsfw_enabled',
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'access_listing',
        'access_mode',
        'access_password_hash',
        'access_share_token',
        'access_token_hash',
        'access_token_expires_at',
        'cover_image_path',
        'banner_image_path',
        'logo_image_path',
        'separator_image_path',
        'background_source',
    ];

    $assignments = [];
    $params = [];
    foreach ($fields as $column => $value) {
        if (!is_string($column) || !in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported gallery update field.');
        }
        $assignments[] = $column . ' = ?';
        $params[] = $value;
    }
    $assignments[] = 'updated_at = ?';
    $params[] = $now;
    $params[] = $galleryId;

    $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', $assignments) . ' WHERE id = ?');
    $stmt->execute($params);
}

/**
 * Return compact gallery rows used by Admin picker controls.
 *
 * @param bool $secondaryTitleSort Whether title is used as a secondary sort key.
 * @return array<int,array<string,mixed>> Gallery picker rows.
 */
function gallery_model_picker_rows(bool $secondaryTitleSort = false): array
{
    $order = $secondaryTitleSort ? 'folder_path, title' : 'folder_path';
    return db()->query('SELECT id, title, folder_path FROM galleries ORDER BY ' . $order)->fetchAll();
}

/**
 * Return compact gallery rows used by the new-gallery title completion control.
 *
 * Newer galleries are returned first so a repeated naming scheme naturally
 * proposes the most recent sibling before older entries with the same prefix.
 *
 * @return array<int,array<string,mixed>> Gallery title completion rows.
 */
function gallery_model_title_completion_rows(): array
{
    return db()->query('SELECT id, parent_id, title, folder_path, created_at FROM galleries ORDER BY created_at DESC, id DESC')->fetchAll();
}

/**
 * Return the first direct child gallery in normal display order.
 *
 * @param int $sourceGalleryId Source gallery identifier.
 * @return int Direct child identifier or zero.
 */
function gallery_model_likely_destination_id(int $sourceGalleryId): int
{
    $stmt = db()->prepare('SELECT id FROM galleries WHERE parent_id = ? ORDER BY sort_order, title, id LIMIT 1');
    $stmt->execute([$sourceGalleryId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Find a gallery by hashed share token, optionally restricting the gallery id.
 *
 * @param string $tokenHash SHA-256 access token hash.
 * @param int $galleryId Optional gallery identifier restriction.
 * @return array<string,mixed>|null Matching gallery row.
 */
function gallery_model_find_by_access_token_hash(string $tokenHash, int $galleryId = 0): ?array
{
    if ($galleryId > 0) {
        $stmt = db()->prepare('SELECT * FROM galleries WHERE id = ? AND access_token_hash = ? LIMIT 1');
        $stmt->execute([$galleryId, $tokenHash]);
    } else {
        $stmt = db()->prepare('SELECT * FROM galleries WHERE access_token_hash = ? ORDER BY updated_at DESC, id DESC LIMIT 1');
        $stmt->execute([$tokenHash]);
    }
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return public physical root galleries with direct public image counts.
 *
 * @param bool $requireListedAccess Whether access_listing must be listed.
 * @return array<int,array<string,mixed>> Public root gallery rows.
 */
function gallery_model_public_root_rows(bool $requireListedAccess): array
{
    $sql = "SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE g.visibility = 'public'";
    if ($requireListedAccess) {
        $sql .= " AND g.access_listing = 'listed'";
    }
    $sql .= ' AND g.parent_id IS NULL GROUP BY g.id ORDER BY g.sort_order, g.title';
    return db()->query($sql)->fetchAll();
}

/**
 * Clear all explicit gallery background-source overrides.
 *
 * @param string $now SQL timestamp written to updated_at.
 * @return int Number of rows reported as changed.
 */
function gallery_model_clear_background_sources(string $now): int
{
    $stmt = db()->prepare('UPDATE galleries SET background_source = NULL, updated_at = ? WHERE background_source IS NOT NULL');
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

/**
 * Build the model-owned public listing predicate for a fixed gallery alias.
 *
 * @param string $alias Trusted model-selected SQL alias.
 * @param bool $publicOnly Whether public visibility filtering is required.
 * @param bool $requireListedAccess Whether access_listing must be listed.
 * @return string Hardcoded SQL predicate prefixed with AND, or an empty string.
 */
function gallery_model_public_listing_filter_sql(string $alias, bool $publicOnly, bool $requireListedAccess): string
{
    if (!$publicOnly) {
        return '';
    }
    if (!in_array($alias, ['g'], true)) {
        throw new InvalidArgumentException('Unsupported gallery model SQL alias.');
    }

    $sql = " AND {$alias}.visibility = 'public'";
    if ($requireListedAccess) {
        $sql .= " AND {$alias}.access_listing = 'listed'";
    }
    return $sql;
}

/**
 * Return direct child gallery rows for multiple parents with direct public-image counts.
 *
 * @param array<int,int> $parentIds Positive parent gallery identifiers.
 * @param bool $publicOnly Whether only publicly listable gallery rows are eligible.
 * @param bool $requireListedAccess Whether public access_listing must be listed.
 * @return array<int,array<string,mixed>> Matching child rows.
 */
function gallery_model_child_rows_for_parents(array $parentIds, bool $publicOnly, bool $requireListedAccess): array
{
    if ($parentIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
    $sql = "SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE g.parent_id IN (" . $placeholders . ')'
        . gallery_model_public_listing_filter_sql('g', $publicOnly, $requireListedAccess)
        . ' GROUP BY g.id ORDER BY g.parent_id, g.sort_order, g.title';
    $stmt = db()->prepare($sql);
    $stmt->execute($parentIds);
    return $stmt->fetchAll();
}

/**
 * Return descendant gallery rows below one or more roots with direct public-image counts.
 *
 * @param array<int,int> $rootIds Positive root gallery identifiers.
 * @param bool $publicOnly Whether only publicly listable descendants are eligible.
 * @param bool $requireListedAccess Whether public access_listing must be listed.
 * @return array<int,array<string,mixed>> Descendant gallery rows.
 */
function gallery_model_descendant_rows_for_roots(array $rootIds, bool $publicOnly, bool $requireListedAccess): array
{
    if ($rootIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($rootIds), '?'));
    $sql = "SELECT g.*, COUNT(DISTINCT i.id) AS image_count
        FROM galleries root
        JOIN galleries g ON g.folder_path LIKE CONCAT(root.folder_path, '/%')
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE root.id IN (" . $placeholders . ')'
        . gallery_model_public_listing_filter_sql('g', $publicOnly, $requireListedAccess)
        . ' GROUP BY g.id ORDER BY g.parent_id, g.sort_order, g.title';
    $stmt = db()->prepare($sql);
    $stmt->execute($rootIds);
    return $stmt->fetchAll();
}

/**
 * Return every root-to-descendant gallery membership row for branch counting.
 *
 * @param array<int,int> $rootIds Positive root gallery identifiers.
 * @param bool $publicOnly Whether only publicly listable descendants are eligible.
 * @param bool $requireListedAccess Whether public access_listing must be listed.
 * @return array<int,array<string,mixed>> Rows containing root_id plus descendant gallery columns.
 */
function gallery_model_branch_descendant_rows(array $rootIds, bool $publicOnly, bool $requireListedAccess): array
{
    if ($rootIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($rootIds), '?'));
    $sql = "SELECT root.id AS root_id, g.*
        FROM galleries root
        JOIN galleries g ON g.folder_path = root.folder_path OR g.folder_path LIKE CONCAT(root.folder_path, '/%')
        WHERE root.id IN (" . $placeholders . ')'
        . gallery_model_public_listing_filter_sql('g', $publicOnly, $requireListedAccess);
    $stmt = db()->prepare($sql);
    $stmt->execute($rootIds);
    return $stmt->fetchAll();
}

/**
 * Fetch one gallery row by numeric identifier.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<string,mixed>|null Matching row.
 */
function gallery_model_find_by_id(int $galleryId): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE id = ?');
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Fetch one gallery row by public slug.
 *
 * @param string $slug Gallery slug.
 * @return array<string,mixed>|null Matching row.
 */
function gallery_model_find_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Fetch one gallery row by normalized folder-path hash.
 *
 * @param string $folderPathHash SHA-256 normalized folder path hash.
 * @return array<string,mixed>|null Matching row.
 */
function gallery_model_find_by_folder_path_hash(string $folderPathHash): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path_hash = ?');
    $stmt->execute([$folderPathHash]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}


/**
 * Return all normalized gallery folder paths known to the persistence index.
 *
 * @return array<int,string> Stored gallery folder paths.
 */
function gallery_model_folder_paths(): array
{
    $rows = db()->query('SELECT folder_path FROM galleries')->fetchAll(\PDO::FETCH_COLUMN);
    return array_values(array_filter(array_map('strval', $rows), static fn (string $path): bool => $path !== ''));
}

/**
 * Generate a unique gallery slug from a human-readable title.
 *
 * @param string $title Human-readable gallery title.
 * @param int $excludeGalleryId Existing gallery identifier excluded from collision checks.
 * @return string Unique normalized slug.
 */
function gallery_model_unique_slug(string $title, int $excludeGalleryId = 0): string
{
    $base = slugify($title);
    $slug = $base;
    $counter = 2;
    while (gallery_model_slug_exists($slug, $excludeGalleryId)) {
        $slug = $base . '-' . $counter;
        $counter++;
    }
    return $slug;
}

/**
 * Insert one gallery row using the explicitly supported persistence columns.
 *
 * Optional schema-aware fields may be omitted by callers when the respective
 * migration is unavailable. The model validates column names before composing
 * the INSERT statement so service orchestration never needs direct SQL access.
 *
 * @param array<string,mixed> $fields Column-value map for the new row.
 * @return int Newly created gallery identifier.
 */
function gallery_model_insert(array $fields): int
{
    if ($fields === []) {
        throw new InvalidArgumentException('Gallery insert fields cannot be empty.');
    }

    $allowedColumns = [
        'parent_id',
        'folder_path',
        'folder_path_hash',
        'slug',
        'title',
        'description',
        'sort_order',
        'visibility',
        'voting_enabled',
        'show_filenames',
        'content_language',
        'description_layout',
        'count_badge_visibility',
        'lightbox_browsing_mode',
        'gallery_date',
        'gallery_date_end',
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'access_mode',
        'access_listing',
        'cover_image_path',
        'banner_image_path',
        'logo_image_path',
        'separator_image_path',
        'background_source',
        'created_at',
        'updated_at',
    ];

    $columns = [];
    $values = [];
    foreach ($fields as $column => $value) {
        if (!is_string($column) || !in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported gallery insert field.');
        }
        $columns[] = $column;
        $values[] = $value;
    }

    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO galleries (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute($values);
    return (int) $pdo->lastInsertId();
}

/**
 * Calculate a sort order that places a newly created gallery before its siblings.
 *
 * @param int $parentGalleryId Parent gallery identifier, or zero for root galleries.
 * @return int Sort order preceding the current first sibling by ten.
 */
function gallery_model_prepend_sort_order(int $parentGalleryId): int
{
    if ($parentGalleryId > 0) {
        $stmt = db()->prepare('SELECT COALESCE(MIN(sort_order), 0) FROM galleries WHERE parent_id = ?');
        $stmt->execute([$parentGalleryId]);
    } else {
        $stmt = db()->query('SELECT COALESCE(MIN(sort_order), 0) FROM galleries WHERE parent_id IS NULL');
    }
    return (int) $stmt->fetchColumn() - 10;
}

/**
 * Return whether the galleries table exposes the custom cover-image path column.
 *
 * @return bool True when cover_image_path exists.
 */
function gallery_model_cover_image_path_column_exists(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'cover_image_path'");
        return (bool) $stmt->fetch();
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Persist the selected gallery cover image identifier.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $imageId Cover image identifier.
 * @param string $now SQL timestamp written to updated_at.
 */
function gallery_model_set_cover_image_id(int $galleryId, int $imageId, string $now): void
{
    $stmt = db()->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$imageId, $now, $galleryId]);
}

/**
 * Persist the optional gallery cover asset relative path.
 *
 * @param int $galleryId Gallery identifier.
 * @param ?string $relativePath Relative cover asset path, or null to clear it.
 * @param string $now SQL timestamp written to updated_at.
 */
function gallery_model_set_cover_image_path(int $galleryId, ?string $relativePath, string $now): void
{
    $stmt = db()->prepare('UPDATE galleries SET cover_image_path = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$relativePath, $now, $galleryId]);
}

/**
 * Return whether the galleries table exposes the per-gallery background source column.
 *
 * @return bool True when background_source exists.
 */
function gallery_model_background_source_column_exists(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'background_source'");
        return (bool) $stmt->fetch();
    } catch (\Throwable) {
        return false;
    }
}

/**
 * Return gallery date-maintenance rows in folder order.
 *
 * @param bool $includeRangeEnd Whether gallery_date_end is available.
 * @return array<int,array<string,mixed>> Date-maintenance gallery rows.
 */
function gallery_model_date_suggestion_rows(bool $includeRangeEnd): array
{
    $selects = ['id', 'parent_id', 'folder_path', 'title', 'gallery_date'];
    $selects[] = $includeRangeEnd ? 'gallery_date_end' : 'NULL AS gallery_date_end';
    return db()->query('SELECT ' . implode(', ', $selects) . ' FROM galleries ORDER BY folder_path')->fetchAll();
}

/**
 * Return all gallery rows in stable folder-path order.
 *
 * @return array<int,array<string,mixed>> Gallery rows.
 */
function gallery_model_all_rows_by_folder_path(): array
{
    return db()->query('SELECT * FROM galleries ORDER BY folder_path')->fetchAll();
}

/**
 * Clear every explicit gallery-grid override.
 *
 * @param string $now SQL timestamp written to updated_at.
 * @return int Number of rows reported as changed.
 */
function gallery_model_reset_grid_overrides(string $now): int
{
    $stmt = db()->prepare('UPDATE galleries SET grid_columns = NULL, grid_rows = NULL, grid_use_for_subgalleries = 1, updated_at = ? WHERE grid_columns IS NOT NULL OR grid_rows IS NOT NULL OR grid_use_for_subgalleries <> 1');
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

/**
 * Return imported gallery identifiers in stable folder-path order.
 *
 * @return array<int,int> Gallery identifiers.
 */
function gallery_model_ids_by_folder_path(): array
{
    $rows = db()->query('SELECT id FROM galleries ORDER BY folder_path')->fetchAll(\PDO::FETCH_COLUMN);
    return array_map('intval', $rows);
}


/**
 * Find a direct child gallery by exact title in display order.
 *
 * @param int $parentGalleryId Parent gallery identifier.
 * @param string $title Exact gallery title.
 * @return array<string,mixed>|null Matching child gallery row.
 */
function gallery_model_find_child_by_title(int $parentGalleryId, string $title): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE parent_id = ? AND title = ? ORDER BY sort_order, id LIMIT 1');
    $stmt->execute([$parentGalleryId, $title]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return direct child gallery identifiers in stable display order.
 *
 * @param int $parentGalleryId Parent gallery identifier.
 * @return array<int,int> Direct child identifiers.
 */
function gallery_model_direct_child_ids(int $parentGalleryId): array
{
    $stmt = db()->prepare('SELECT id FROM galleries WHERE parent_id = ? ORDER BY sort_order, id');
    $stmt->execute([$parentGalleryId]);
    return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
}


/**
 * Return one gallery branch as identifiers ordered from parent to descendants.
 *
 * @return array<int,int> Gallery identifiers.
 */
function gallery_model_branch_ids_by_folder_path(int $galleryId, string $folderPath): array
{
    $folderPath = trim(str_replace('\\', '/', $folderPath), '/');
    if ($folderPath === '') {
        return $galleryId > 0 ? [$galleryId] : [];
    }
    $stmt = db()->prepare('SELECT id FROM galleries WHERE folder_path = ? OR folder_path LIKE ? ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute([$folderPath, $folderPath . '/%']);
    $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    return $ids !== [] ? $ids : ($galleryId > 0 ? [$galleryId] : []);
}

/**
 * Persist responsive-thumbnail bounds for one bounded gallery set.
 *
 * @param array<int,int> $galleryIds Gallery identifiers.
 * @return int Number of rows reported as changed.
 */
function gallery_model_set_thumbnail_bounds(array $galleryIds, ?int $minSize, ?int $maxSize, string $now): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) {
        return 0;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('UPDATE galleries SET thumbnail_min_size = ?, thumbnail_max_size = ?, updated_at = ? WHERE id IN (' . $placeholders . ')');
    $stmt->execute(array_merge([$minSize, $maxSize, $now], $ids));
    return $stmt->rowCount();
}
