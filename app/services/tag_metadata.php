<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/tag_metadata.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Provides tag parsing, persistence, metadata, and public tag lookup services.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one feature responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   2026-08-11
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use PDO;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\slugify;
use function Gallery\Core\url_for;

/**
 * Parse admin-entered comma/semicolon/newline tag text into unique names.
 *
 * @param string $tags Tags value.
 * @return array Structured result data for the caller.
 */
function split_tag_names(string $tags): array
{
    // Variable $names stores this steps working value.
    $names = [];
    foreach (preg_split('/[,;\n]+/', $tags) ?: [] as $name) {
        // Variable $name stores this steps working value.
        $name = sanitize_tag_name((string) $name);
        if ($name !== '') {
            $names[$name] = substr($name, 0, 100);
        }
    }
    return array_values($names);
}

/**
 * Convert user-entered tag text into the canonical safe lowercase tag name.
 *
 * @param string $name Name value.
 * @return string Text result for the caller.
 */
function sanitize_tag_name(string $name): string
{
    // Variable $slug stores this steps working value.
    $slug = slugify(trim($name));
    $slug = strtolower((string) preg_replace('/[^a-z0-9-]+/', '-', $slug));
    $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');
    return $slug !== '' && $slug !== 'gallery' ? substr($slug, 0, 100) : '';
}

/**
 * Function `tag_slug` handles this scoped operation.
 *
 * @param string $name Name value.
 * @return string Text result for the caller.
 */
function tag_slug(string $name): string
{
    // Variable $slug stores this steps working value.
    $slug = sanitize_tag_name($name);
    return $slug !== '' ? substr($slug, 0, 120) : 'tag';
}

/**
 * Return an existing tag ID or create a new tag row.
 *
 * @param string $name Name value.
 * @return int Integer result for the caller.
 */
function find_or_create_tag(string $name): int
{
    // Variable $name stores this steps working value.
    $name = sanitize_tag_name($name);
    if ($name === '') {
        return 0;
    }
    // Variable $slug stores this steps working value.
    $slug = tag_slug($name);
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT id FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    // Variable $existing stores this steps working value.
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('INSERT INTO tags (name, slug, created_at, updated_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $slug, now_sql(), now_sql()]);
    return (int) db()->lastInsertId();
}

/**
 * Replace all tags for one gallery or image with the submitted tag list.
 *
 * @param string $type Type value.
 * @param int $id Identifier value.
 * @param string $tagText Tag text value.
 */
function sync_entity_tags(string $type, int $id, string $tagText): void
{
    // Variable $mapTable stores this steps working value.
    $mapTable = $type === 'gallery' ? 'gallery_tags' : 'image_tags';
    // Variable $idColumn stores this steps working value.
    $idColumn = $type === 'gallery' ? 'gallery_id' : 'image_id';
    db()->prepare('DELETE FROM ' . $mapTable . ' WHERE ' . $idColumn . ' = ?')->execute([$id]);
    foreach (split_tag_names($tagText) as $name) {
        // Variable $tagId stores this steps working value.
        $tagId = find_or_create_tag($name);
        if ($tagId <= 0) {
            continue;
        }
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('INSERT IGNORE INTO ' . $mapTable . ' (' . $idColumn . ', tag_id) VALUES (?, ?)');
        $stmt->execute([$id, $tagId]);
    }
}

/**
 * Function `tags_for_entity` handles this scoped operation.
 *
 * @param string $type Type value.
 * @param int $id Identifier value.
 * @return array Structured result data for the caller.
 */
function tags_for_entity(string $type, int $id): array
{
    static $cache = [];
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $type . ':' . $id;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // Variable $mapTable stores this steps working value.
    $mapTable = $type === 'gallery' ? 'gallery_tags' : 'image_tags';
    // Variable $idColumn stores this steps working value.
    $idColumn = $type === 'gallery' ? 'gallery_id' : 'image_id';
    try {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT t.* FROM tags t JOIN ' . $mapTable . ' mt ON mt.tag_id = t.id WHERE mt.' . $idColumn . ' = ? ORDER BY t.name');
        $stmt->execute([$id]);
        return $cache[$cacheKey] = $stmt->fetchAll();
    } catch (PDOException) {
        return $cache[$cacheKey] = [];
    }
}


/**
 * Return direct gallery and image assignment counts for the supplied tag IDs.
 *
 * The public hero usage sort uses the same definition of tag usage as the
 * administration: every direct gallery assignment and every direct image
 * assignment contributes one use. The query is restricted to the IDs present
 * in the current hero so unrelated tags are not aggregated on every request.
 *
 * @param array $tagIds Tag identifiers required by the current render.
 * @return array<int,int> Usage counts keyed by tag ID.
 */
function tag_usage_counts(array $tagIds): array
{
    static $cache = [];
    // $ids stores normalized unique positive identifiers for a bounded SQL query.
    $ids = array_values(array_unique(array_filter(array_map('intval', $tagIds), static fn (int $id): bool => $id > 0)));
    sort($ids, SORT_NUMERIC);
    if (!$ids) {
        return [];
    }
    // $cacheKey lets repeated hero groups in the same request reuse the aggregate.
    $cacheKey = implode(',', $ids);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // $placeholders safely scopes both assignment-table branches to the requested tags.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // $parameters repeats the same tag IDs for the gallery and image branches.
    $parameters = array_merge($ids, $ids);
    try {
        // $stmt aggregates both supported direct assignment types before returning one count per tag.
        $stmt = db()->prepare(
            'SELECT usage_rows.tag_id, SUM(usage_rows.usage_count) AS usage_count
             FROM (
                 SELECT tag_id, COUNT(*) AS usage_count
                 FROM gallery_tags
                 WHERE tag_id IN (' . $placeholders . ')
                 GROUP BY tag_id
                 UNION ALL
                 SELECT tag_id, COUNT(*) AS usage_count
                 FROM image_tags
                 WHERE tag_id IN (' . $placeholders . ')
                 GROUP BY tag_id
             ) usage_rows
             GROUP BY usage_rows.tag_id'
        );
        $stmt->execute($parameters);
        // $counts is initialized with zeroes so tags without assignments remain sortable.
        $counts = array_fill_keys($ids, 0);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['tag_id']] = (int) $row['usage_count'];
        }
        return $cache[$cacheKey] = $counts;
    } catch (PDOException) {
        return $cache[$cacheKey] = array_fill_keys($ids, 0);
    }
}

/**
 * Sort public gallery hero tag groups without mixing their semantic sections.
 *
 * Direct gallery tags remain before contained tags. Within each group the
 * configured mode is applied independently. Usage ties use a natural,
 * case-insensitive alphabetical comparison and finally the tag ID for a stable
 * deterministic order.
 *
 * @param array<string,array> $groups Hero tag groups keyed by their semantic role.
 * @param string $sortMode Requested sort mode, normally usage or alphabetical.
 * @return array<string,array> Sorted groups with their original keys preserved.
 */
function sort_public_hero_tag_groups(array $groups, string $sortMode): array
{
    // $mode defensively normalizes callers that do not pass the Theme service result.
    $mode = in_array($sortMode, ['usage', 'alphabetical'], true) ? $sortMode : 'usage';
    // $tagIds collects all tag IDs once so usage mode needs only one aggregate query.
    $tagIds = [];
    foreach ($groups as $tags) {
        foreach ($tags as $tag) {
            $tagIds[] = (int) ($tag['id'] ?? 0);
        }
    }
    // $usageCounts is empty for alphabetical mode, avoiding an unnecessary database query.
    $usageCounts = $mode === 'usage' ? tag_usage_counts($tagIds) : [];
    foreach ($groups as $groupKey => $tags) {
        usort($tags, static function (array $left, array $right) use ($mode, $usageCounts): int {
            if ($mode === 'usage') {
                // $usageComparison sorts larger aggregate assignment counts first.
                $usageComparison = ($usageCounts[(int) ($right['id'] ?? 0)] ?? 0) <=> ($usageCounts[(int) ($left['id'] ?? 0)] ?? 0);
                if ($usageComparison !== 0) {
                    return $usageComparison;
                }
            }
            // $nameComparison keeps ties human-readable and stable across render pipelines.
            $nameComparison = strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            if ($nameComparison !== 0) {
                return $nameComparison;
            }
            return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
        });
        $groups[$groupKey] = $tags;
    }
    return $groups;
}

/**
 * Return all tags for many entities in one query, grouped by entity ID.
 *
 * @param string $type Type value.
 * @param array $ids Ids value.
 * @return array Structured result data for the caller.
 */
function tags_for_entities(string $type, array $ids): array
{
    static $cache = [];
    // $ids stores an intermediate value used by the surrounding gallery workflow.
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $type . ':' . implode(',', $ids);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // $mapTable stores an intermediate value used by the surrounding gallery workflow.
    $mapTable = $type === 'gallery' ? 'gallery_tags' : 'image_tags';
    // $idColumn stores an intermediate value used by the surrounding gallery workflow.
    $idColumn = $type === 'gallery' ? 'gallery_id' : 'image_id';
    // $placeholders stores an intermediate value used by the surrounding gallery workflow.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    try {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare(
            'SELECT mt.' . $idColumn . ' AS entity_id, t.*
             FROM tags t
             JOIN ' . $mapTable . ' mt ON mt.tag_id = t.id
             WHERE mt.' . $idColumn . ' IN (' . $placeholders . ')
             ORDER BY mt.' . $idColumn . ', t.name'
        );
        $stmt->execute($ids);
        // $grouped stores an intermediate value used by the surrounding gallery workflow.
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $grouped[(int) $row['entity_id']][] = $row;
        }
        return $cache[$cacheKey] = $grouped;
    } catch (PDOException) {
        return $cache[$cacheKey] = [];
    }
}

/**
 * Function `tag_names_for_entity` handles this scoped operation.
 *
 * @param string $type Type value.
 * @param int $id Identifier value.
 * @return string Text result for the caller.
 */
function tag_names_for_entity(string $type, int $id): string
{
    return implode(', ', array_column(tags_for_entity($type, $id), 'name'));
}

/**
 * Return all tag names for datalist suggestions in admin forms.
 *
 * @return array Structured result data for the caller.
 */
function all_tag_names(): array
{
    try {
        $stmt = db()->prepare('SELECT name FROM tags ORDER BY name');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        return [];
    }
}


/**
 * Return weighted tag suggestions for a gallery editor context.
 *
 * The score intentionally favors nearby galleries over global popularity:
 * current gallery images, siblings, descendants, ancestors, and finally all-site
 * usage. This keeps tag advice local to the folder where the admin is working.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function weighted_tag_suggestions_for_gallery(int $galleryId, int $limit = 80): array
{
    // Variable $gallery stores this steps working value.
    $gallery = null;
    if ($galleryId > 0) {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT id, parent_id, folder_path FROM galleries WHERE id = ?');
        $stmt->execute([$galleryId]);
        $gallery = $stmt->fetch() ?: null;
    }
    if (!$gallery) {
        return weighted_global_tag_suggestions($limit);
    }

    // Variable $scores stores this steps working value.
    $scores = [];
    // Variable $details stores this steps working value.
    $details = [];

    /**
     * Add weighted rows from one SQL query to the accumulated score table.
     *
     * @param string $sql SQL returning tag id, name, slug, and usage_count.
     * @param array<int, mixed> $params Bound query parameters.
     * @param float $weight Source multiplier.
     * @param string $source Human-readable internal source label.
     * @return void
     */
    $addRows = static function (string $sql, array $params, float $weight, string $source) use (&$scores, &$details): void {
        try {
            // Variable $stmt stores this steps working value.
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $row) {
                // Variable $tagId stores this steps working value.
                $tagId = (int) ($row['id'] ?? 0);
                if ($tagId <= 0) {
                    continue;
                }
                // Variable $usageCount stores this steps working value.
                $usageCount = max(1, (int) ($row['usage_count'] ?? 1));
                // Logarithmic usage keeps common tags important without letting them drown out local context.
                $score = $weight * (1.0 + log($usageCount + 1, 2));
                if (!isset($scores[$tagId])) {
                    $scores[$tagId] = [
                        'id' => $tagId,
                        'name' => (string) ($row['name'] ?? ''),
                        'slug' => (string) ($row['slug'] ?? ''),
                        'score' => 0.0,
                        'sources' => [],
                    ];
                }
                $scores[$tagId]['score'] += $score;
                $scores[$tagId]['sources'][$source] = true;
                $details[$tagId][$source] = ($details[$tagId][$source] ?? 0) + $usageCount;
            }
        } catch (PDOException) {
            return;
        }
    };

    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
    // Variable $parentId stores this steps working value.
    $parentId = (int) ($gallery['parent_id'] ?? 0);
    // Variable $pathParts stores this steps working value.
    $pathParts = array_values(array_filter(explode('/', $folderPath), static fn (string $part): bool => $part !== ''));
    // Variable $ancestorPatterns stores this steps working value.
    $ancestorPatterns = [];
    for ($index = 1; $index < count($pathParts); $index++) {
        $ancestorPatterns[] = implode('/', array_slice($pathParts, 0, $index));
    }

    $galleryTagSql = 'SELECT t.id, t.name, t.slug, COUNT(*) AS usage_count
        FROM tags t
        JOIN gallery_tags gt ON gt.tag_id = t.id
        JOIN galleries g ON g.id = gt.gallery_id
        WHERE %s
        GROUP BY t.id, t.name, t.slug';

    $imageTagSql = 'SELECT t.id, t.name, t.slug, COUNT(*) AS usage_count
        FROM tags t
        JOIN image_tags it ON it.tag_id = t.id
        JOIN images i ON i.id = it.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE %s
        GROUP BY t.id, t.name, t.slug';

    $addRows(sprintf($imageTagSql, 'g.id = ?'), [$galleryId], 18.0, 'current_images');
    $addRows(sprintf($galleryTagSql, 'g.parent_id = ? AND g.id <> ?'), [$parentId, $galleryId], 12.0, 'siblings');
    $addRows(sprintf($imageTagSql, 'g.parent_id = ? AND g.id <> ?'), [$parentId, $galleryId], 8.0, 'sibling_images');

    if ($folderPath !== '') {
        $addRows(sprintf($galleryTagSql, 'g.folder_path LIKE ? AND g.id <> ?'), [$folderPath . '/%', $galleryId], 10.0, 'descendants');
        $addRows(sprintf($imageTagSql, 'g.folder_path LIKE ? AND g.id <> ?'), [$folderPath . '/%', $galleryId], 6.0, 'descendant_images');
    }

    if ($ancestorPatterns) {
        // Variable $ancestorPlaceholders stores this steps working value.
        $ancestorPlaceholders = implode(',', array_fill(0, count($ancestorPatterns), '?'));
        $addRows(sprintf($galleryTagSql, 'g.folder_path IN (' . $ancestorPlaceholders . ')'), $ancestorPatterns, 5.0, 'ancestors');
        $addRows(sprintf($imageTagSql, 'g.folder_path IN (' . $ancestorPlaceholders . ')'), $ancestorPatterns, 3.0, 'ancestor_images');
    }

    $addRows('SELECT t.id, t.name, t.slug, COUNT(*) AS usage_count
        FROM tags t
        JOIN gallery_tags gt ON gt.tag_id = t.id
        GROUP BY t.id, t.name, t.slug', [], 1.2, 'global_galleries');
    $addRows('SELECT t.id, t.name, t.slug, COUNT(*) AS usage_count
        FROM tags t
        JOIN image_tags it ON it.tag_id = t.id
        GROUP BY t.id, t.name, t.slug', [], 0.8, 'global_images');

    // Variable $rows stores this steps working value.
    $rows = array_values($scores);
    usort($rows, static function (array $left, array $right): int {
        // Variable $scoreCompare stores this steps working value.
        $scoreCompare = ($right['score'] <=> $left['score']);
        return $scoreCompare !== 0 ? $scoreCompare : strcmp((string) $left['name'], (string) $right['name']);
    });

    return array_slice(array_map(static function (array $row): array {
        $row['score'] = round((float) $row['score'], 4);
        $row['sources'] = array_keys((array) ($row['sources'] ?? []));
        return $row;
    }, $rows), 0, max(1, $limit));
}

/**
 * Return weighted tag suggestions when no specific gallery context is available.
 *
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function weighted_global_tag_suggestions(int $limit = 80): array
{
    try {
        // Variable $stmt stores this steps working value.
        $stmt = db()->query('SELECT t.id, t.name, t.slug,
                COALESCE(gallery_usage.gallery_count, 0) AS gallery_count,
                COALESCE(image_usage.image_count, 0) AS image_count
            FROM tags t
            LEFT JOIN (SELECT tag_id, COUNT(*) AS gallery_count FROM gallery_tags GROUP BY tag_id) gallery_usage ON gallery_usage.tag_id = t.id
            LEFT JOIN (SELECT tag_id, COUNT(*) AS image_count FROM image_tags GROUP BY tag_id) image_usage ON image_usage.tag_id = t.id
            ORDER BY (COALESCE(gallery_usage.gallery_count, 0) + COALESCE(image_usage.image_count, 0)) DESC, t.name
            LIMIT ' . max(1, $limit));
        return array_map(static function (array $row): array {
            // Variable $usage stores this steps working value.
            $usage = (int) ($row['gallery_count'] ?? 0) + (int) ($row['image_count'] ?? 0);
            return [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'score' => (float) $usage,
                'sources' => ['global'],
            ];
        }, $stmt->fetchAll());
    } catch (PDOException) {
        return [];
    }
}

/**
 * Return true when the optional tag description column is available.
 *
 * @return bool True when the condition matches.
 */
function tag_description_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        $stmt = db()->prepare('SELECT description FROM tags LIMIT 1');
        $stmt->execute();
        return $ready = true;
    } catch (PDOException) {
        return $ready = false;
    }
}

/**
 * Return editable tag rows with gallery and image usage counts.
 *
 * @param string $sortField Sorting key, either name or usage.
 * @param string $sortDirection Sort direction, asc or desc.
 * @return array Structured result data for the caller.
 */
function admin_tag_rows(string $sortField = 'usage', string $sortDirection = 'desc'): array
{
    // Variable $descriptionReady stores this steps working value.
    $descriptionReady = tag_description_schema_ready();
    // Variable $descriptionColumn stores this steps working value.
    $descriptionColumn = $descriptionReady ? 't.description' : "'' AS description";
    // Variable $groupByDescription stores this steps working value.
    $groupByDescription = $descriptionReady ? ', t.description' : '';
    // Variable $safeSortField stores this steps working value.
    $safeSortField = in_array($sortField, ['name', 'usage'], true) ? $sortField : 'usage';
    // Variable $safeSortDirection stores this steps working value.
    $safeSortDirection = strtolower($sortDirection) === 'asc' ? 'ASC' : 'DESC';
    // Variable $orderBy stores this steps working value.
    $orderBy = $safeSortField === 'name'
        ? 't.name ' . $safeSortDirection . ', t.slug ' . $safeSortDirection
        : 'usage_count ' . $safeSortDirection . ', t.name ASC';
    // Variable $stmt stores this steps working value.
    $stmt = db()->query("SELECT t.id, t.name, t.slug, " . $descriptionColumn . ", t.created_at, t.updated_at,
        COUNT(DISTINCT gt.gallery_id) AS gallery_count,
        COUNT(DISTINCT it.image_id) AS image_count,
        COUNT(DISTINCT gt.gallery_id) + COUNT(DISTINCT it.image_id) AS usage_count
        FROM tags t
        LEFT JOIN gallery_tags gt ON gt.tag_id = t.id
        LEFT JOIN image_tags it ON it.tag_id = t.id
        GROUP BY t.id, t.name, t.slug" . $groupByDescription . ", t.created_at, t.updated_at
        ORDER BY " . $orderBy);
    return $stmt->fetchAll();
}

/**
 * Return the gallery and image records that use one tag.
 *
 * @param int $tagId Tag id identifier.
 * @return array Structured result data for the caller.
 */
function admin_tag_usage_rows(int $tagId): array
{
    // Variable $galleries stores this steps working value.
    $galleries = [];
    // Variable $galleryStmt stores this steps working value.
    $galleryStmt = db()->prepare("SELECT DISTINCT g.id, g.title, g.slug, g.url_path, g.folder_path
        FROM gallery_tags gt
        JOIN galleries g ON g.id = gt.gallery_id
        WHERE gt.tag_id = ?
        ORDER BY g.title, g.id");
    $galleryStmt->execute([$tagId]);
    foreach ($galleryStmt->fetchAll() as $row) {
        $galleries[] = [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'url_path' => (string) ($row['url_path'] ?? ''),
            'folder_path' => (string) ($row['folder_path'] ?? ''),
            'edit_url' => url_for('admin_edit_gallery', ['id' => (int) $row['id']]),
            'public_url' => gallery_public_url($row),
        ];
    }

    // Variable $images stores this steps working value.
    $images = [];
    // Variable $imageStmt stores this steps working value.
    $imageStmt = db()->prepare("SELECT DISTINCT i.id, i.relative_path, i.filename, i.gallery_id, i.sort_order AS image_sort_order, g.title AS gallery_title, g.slug AS gallery_slug
        FROM image_tags it
        JOIN images i ON i.id = it.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE it.tag_id = ?
        ORDER BY g.title, i.sort_order, i.filename, i.id");
    $imageStmt->execute([$tagId]);
    foreach ($imageStmt->fetchAll() as $row) {
        $images[] = [
            'id' => (int) $row['id'],
            'relative_path' => (string) ($row['relative_path'] ?? ''),
            'filename' => (string) ($row['filename'] ?? ''),
            'gallery_id' => (int) ($row['gallery_id'] ?? 0),
            'gallery_title' => (string) ($row['gallery_title'] ?? ''),
            'gallery_slug' => (string) ($row['gallery_slug'] ?? ''),
            'edit_url' => url_for('admin_edit_image', ['id' => (int) $row['id']]),
        ];
    }

    return [
        'galleries' => $galleries,
        'images' => $images,
    ];
}

/**
 * Fetch one tag by numeric ID for admin editing.
 *
 * @param int $id Identifier value.
 * @return ?array Structured result data for the caller.
 */
function find_tag_by_id(int $id): ?array
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM tags WHERE id = ?');
    $stmt->execute([$id]);
    // Variable $tag stores this steps working value.
    $tag = $stmt->fetch();
    return $tag ?: null;
}

/**
 * Update one tag row while keeping names and slugs canonical and unique.
 *
 * @param int $id Identifier value.
 * @param string $name Name value.
 * @param string $slug Slug value.
 * @param string $description Description value.
 * @return array Structured result data for the caller.
 */
function update_tag_metadata(int $id, string $name, string $slug, string $description): array
{
    // Variable $tag stores this steps working value.
    $tag = find_tag_by_id($id);
    if (!$tag) {
        return ['ok' => false, 'error' => 'not_found'];
    }

    // Variable $safeName stores this steps working value.
    $safeName = sanitize_tag_name($name);
    if ($safeName === '') {
        return ['ok' => false, 'error' => 'invalid_name'];
    }
    // Variable $safeSlug stores this steps working value.
    $safeSlug = sanitize_tag_name($slug !== '' ? $slug : $safeName);
    if ($safeSlug === '') {
        return ['ok' => false, 'error' => 'invalid_slug'];
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT id FROM tags WHERE slug = ? AND id <> ?');
    $stmt->execute([$safeSlug, $id]);
    if ($stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'slug_taken'];
    }

    if (tag_description_schema_ready()) {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE tags SET name = ?, slug = ?, description = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$safeName, $safeSlug, trim($description), now_sql(), $id]);
    } else {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('UPDATE tags SET name = ?, slug = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$safeName, $safeSlug, now_sql(), $id]);
    }

    return ['ok' => true, 'tag' => find_tag_by_id($id)];
}

/**
 * Delete one tag and detach it from galleries and images.
 *
 * @param int $id Identifier value.
 * @return array Structured result data for the caller.
 */
function delete_tag_by_id(int $id): array
{
    // Variable $tag stores this steps working value.
    $tag = find_tag_by_id($id);
    if (!$tag) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    // Variable $pdo stores this steps working value.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM gallery_tags WHERE tag_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM image_tags WHERE tag_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'delete_failed'];
    }
    return ['ok' => true, 'tag' => $tag];
}

/**
 * Normalize existing tags to safe lowercase values and merge duplicates.
 *
 * @return int Integer result for the caller.
 */
function normalize_existing_tags(): int
{
    // Variable $changed stores this steps working value.
    $changed = 0;
    // Variable $rows stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM tags ORDER BY id');
    $stmt->execute();
    $rows = $stmt->fetchAll();
    // Variable $seen stores this steps working value.
    $seen = [];
    foreach ($rows as $row) {
        // Variable $id stores this steps working value.
        $id = (int) $row['id'];
        // Variable $safeName stores this steps working value.
        $safeName = sanitize_tag_name((string) ($row['name'] ?? ''));
        // Variable $safeSlug stores this steps working value.
        $safeSlug = sanitize_tag_name((string) ($row['slug'] ?? $safeName));
        if ($safeName === '' && $safeSlug === '') {
            db()->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
            $changed++;
            continue;
        }
        if ($safeName === '') {
            $safeName = $safeSlug;
        }
        if ($safeSlug === '') {
            $safeSlug = $safeName;
        }
        // Variable $key stores this steps working value.
        $key = $safeSlug;
        if (isset($seen[$key])) {
            // Variable $targetId stores this steps working value.
            $targetId = (int) $seen[$key];
            db()->prepare('INSERT IGNORE INTO gallery_tags (gallery_id, tag_id) SELECT gallery_id, ? FROM gallery_tags WHERE tag_id = ?')->execute([$targetId, $id]);
            db()->prepare('INSERT IGNORE INTO image_tags (image_id, tag_id) SELECT image_id, ? FROM image_tags WHERE tag_id = ?')->execute([$targetId, $id]);
            db()->prepare('DELETE FROM gallery_tags WHERE tag_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM image_tags WHERE tag_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
            $changed++;
            continue;
        }
        $seen[$key] = $id;
        if ((string) $row['name'] !== $safeName || (string) $row['slug'] !== $safeSlug) {
            db()->prepare('UPDATE tags SET name = ?, slug = ?, updated_at = ? WHERE id = ?')->execute([$safeName, $safeSlug, now_sql(), $id]);
            $changed++;
        }
    }
    return $changed;
}

/**
 * Normalize gallery sidecar tag text recursively so filesystem metadata matches the database convention.
 *
 * @return int Integer result for the caller.
 */
function normalize_gallery_sidecar_tags_recursively(): int
{
    // Variable $root stores this steps working value.
    $root = rtrim((string) cms_config()['gallery_path'], DIRECTORY_SEPARATOR);
    if ($root === '' || !is_dir($root)) {
        return 0;
    }
    // Variable $changed stores this steps working value.
    $changed = 0;
    // Variable $iterator stores this steps working value.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getFilename() !== 'gallery.json') {
            continue;
        }
        // Variable $path stores this steps working value.
        $path = $file->getPathname();
        // Variable $data stores this steps working value.
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !array_key_exists('tags', $data)) {
            continue;
        }
        // Variable $rawTags stores this steps working value.
        $rawTags = $data['tags'];
        // Variable $source stores this steps working value.
        $source = is_array($rawTags) ? implode(', ', array_map('strval', $rawTags)) : (string) $rawTags;
        // Variable $normalized stores this steps working value.
        $normalized = implode(', ', split_tag_names($source));
        if ($normalized !== $source) {
            $data['tags'] = $normalized;
            file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $changed++;
        }
    }
    return $changed;
}

/**
 * Fetch one tag by slug for public tag-filter pages.
 *
 * @param string $slug Slug value.
 * @return ?array Structured result data for the caller.
 */
function find_tag_by_slug(string $slug): ?array
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    // Variable $tag stores this steps working value.
    $tag = $stmt->fetch();
    return $tag ?: null;
}

/**
 * Return public galleries that directly or indirectly contain a tag.
 *
 * @param int $tagId Tag id identifier.
 * @return array Structured result data for the caller.
 */
function public_galleries_for_tag(int $tagId): array
{
    // Variable $stmt stores this steps working value.
    $listingCondition = public_gallery_listing_sql_fragment('g');
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare("SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE $listingCondition AND (
            EXISTS (SELECT 1 FROM gallery_tags gt WHERE gt.gallery_id = g.id AND gt.tag_id = ?)
            OR EXISTS (SELECT 1 FROM image_tags it JOIN images tagged_image ON tagged_image.id = it.image_id WHERE tagged_image.gallery_id = g.id AND it.tag_id = ?)
        )
        GROUP BY g.id
        ORDER BY g.sort_order, g.title");
    $stmt->execute([$tagId, $tagId]);
    return $stmt->fetchAll();
}

/**
 * Aggregate tags from descendant galleries and descendant images.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @return array Structured result data for the caller.
 */
function contained_tags_for_gallery(array $gallery, bool $publicOnly): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    if ($folderPath === '') {
        return [];
    }
    // Variable $visibilitySql stores this steps working value.
    $visibilitySql = $publicOnly ? ' AND ' . public_gallery_listing_sql_fragment('g') : '';
    // Variable $imageVisibilitySql stores this steps working value.
    $imageVisibilitySql = $publicOnly ? " AND tagged_image.visibility = 'public'" : '';
    // Variable $sql stores this steps working value.
    $sql = "SELECT DISTINCT t.id, t.name, t.slug
        FROM tags t
        JOIN gallery_tags gt ON gt.tag_id = t.id
        JOIN galleries g ON g.id = gt.gallery_id
        WHERE g.folder_path LIKE ?" . $visibilitySql . "
        UNION
        SELECT DISTINCT t.id, t.name, t.slug
        FROM tags t
        JOIN image_tags it ON it.tag_id = t.id
        JOIN images tagged_image ON tagged_image.id = it.image_id
        JOIN galleries g ON g.id = tagged_image.gallery_id
        WHERE g.folder_path LIKE ?" . $visibilitySql . $imageVisibilitySql . "
        ORDER BY name";
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute([$folderPath . '/%', $folderPath . '/%']);
    return $stmt->fetchAll();
}
