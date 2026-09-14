<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/smart_galleries.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns Smart Gallery persistence and database query compilation.
 *
 * Responsibilities:
 *   - Persist Smart Gallery definitions and physical-gallery placements
 *   - Load bounded relationship-graph source rows
 *   - Compile validated semantic Smart Gallery rules into parameterized SQL
 *   - Execute count, image, card-summary, and membership queries without leaking SQL fragments to services
 *   - Load supporting gallery/tag rows used by service-layer presentation and diagnostics
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
 *   - Rule documents passed here must already be normalized by the Smart Gallery service.
 *   - Dynamic identifiers and ORDER BY expressions are selected only from internal allowlists.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use Throwable;
use function Gallery\Core\db;

/**
 * Fetch gallery hierarchy rows with a pre-allocation row ceiling.
 *
 * @param int $maxRows Maximum accepted source row count.
 * @return array<int,array<string,mixed>> Gallery hierarchy rows.
 */
function smart_gallery_model_graph_gallery_rows(int $maxRows): array
{
    return smart_gallery_model_bounded_rows('SELECT id, parent_id FROM galleries ORDER BY id', $maxRows);
}

/**
 * Fetch Smart Gallery definition rows with a pre-allocation row ceiling.
 *
 * @param int $maxRows Maximum accepted source row count.
 * @return array<int,array<string,mixed>> Definition rows.
 */
function smart_gallery_model_graph_definition_rows(int $maxRows): array
{
    return smart_gallery_model_bounded_rows('SELECT id, rules_json FROM smart_galleries ORDER BY id', $maxRows);
}

/**
 * Fetch placement rows with a pre-allocation row ceiling.
 *
 * @param int $maxRows Maximum accepted source row count.
 * @param bool $metadataReady Whether placement/order columns are available.
 * @return array<int,array<string,mixed>> Placement rows.
 */
function smart_gallery_model_graph_placement_rows(int $maxRows, bool $metadataReady): array
{
    $columns = $metadataReady
        ? 'smart_gallery_id, gallery_id, placement, placement_order'
        : "smart_gallery_id, gallery_id, 'bottom' AS placement, 0 AS placement_order";
    return smart_gallery_model_bounded_rows(
        'SELECT ' . $columns . ' FROM smart_gallery_placements ORDER BY gallery_id, smart_gallery_id',
        $maxRows
    );
}

/**
 * Fetch one bounded result set before PHP materializes an unbounded relationship graph.
 *
 * @param string $sql Internal fixed SQL statement.
 * @param int $maxRows Maximum accepted source row count.
 * @return array<int,array<string,mixed>> Result rows, including at most ceiling+1 rows.
 */
function smart_gallery_model_bounded_rows(string $sql, int $maxRows): array
{
    $limit = max(1, $maxRows) + 1;
    return db()->query($sql . ' LIMIT ' . $limit)->fetchAll();
}

/**
 * Return existing Smart Gallery identifiers from one bounded candidate set.
 *
 * @param array<int,int> $ids Candidate identifiers.
 * @return array<int,int> Existing identifiers.
 */
function smart_gallery_model_existing_ids(array $ids): array
{
    $ids = smart_gallery_model_normalize_ids($ids);
    if ($ids === []) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT id FROM smart_galleries WHERE id IN (' . $marks . ')');
    $stmt->execute($ids);
    return array_values(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
}

/** Return all Smart Gallery definitions for the Admin list. */
function smart_gallery_model_all(): array
{
    return db()->query('SELECT * FROM smart_galleries ORDER BY title ASC, id ASC')->fetchAll();
}

/**
 * Return all placement rows used to attach placement metadata to Admin definitions.
 *
 * @param bool $metadataReady Whether placement/order columns are available.
 */
function smart_gallery_model_all_placements(bool $metadataReady): array
{
    $columns = $metadataReady
        ? 'smart_gallery_id, gallery_id, placement, placement_order'
        : "smart_gallery_id, gallery_id, 'bottom' AS placement, 0 AS placement_order";
    return db()->query('SELECT ' . $columns . ' FROM smart_gallery_placements ORDER BY gallery_id, smart_gallery_id')->fetchAll();
}

/** Find one Smart Gallery definition by id. */
function smart_gallery_model_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM smart_galleries WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Find one enabled public Smart Gallery definition by slug. */
function smart_gallery_model_find_public_by_slug(string $slug): ?array
{
    $stmt = db()->prepare("SELECT * FROM smart_galleries WHERE slug = ? AND enabled = 1 AND visibility = 'public' LIMIT 1");
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Find one enabled public Smart Gallery definition by id. */
function smart_gallery_model_find_public_by_id(int $id): ?array
{
    $stmt = db()->prepare("SELECT * FROM smart_galleries WHERE id = ? AND enabled = 1 AND visibility = 'public' LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Persist one normalized Smart Gallery definition.
 *
 * @param array<string,mixed> $definition Normalized service-owned fields.
 * @param int $id Existing identifier or zero for insert.
 * @param bool $presentationReady Whether presentation_json is available.
 * @return int Saved identifier.
 */
function smart_gallery_model_save(array $definition, int $id, bool $presentationReady): int
{
    $values = [
        (string) $definition['title'],
        (string) $definition['slug'],
        (string) $definition['description'],
        (string) $definition['rules_json'],
        (int) $definition['rule_version'],
        (int) $definition['enabled'],
        (string) $definition['visibility'],
        (string) $definition['placement_mode'],
        null,
        (string) $definition['sort_mode'],
        (string) $definition['sort_direction'],
    ];
    if ($id > 0) {
        if ($presentationReady) {
            $stmt = db()->prepare('UPDATE smart_galleries SET title=?, slug=?, description=?, rules_json=?, rule_version=?, enabled=?, visibility=?, placement_mode=?, parent_gallery_id=?, sort_mode=?, sort_direction=?, presentation_json=?, updated_at=? WHERE id=?');
            $stmt->execute(array_merge($values, [(string) $definition['presentation_json'], (string) $definition['updated_at'], $id]));
        } else {
            $stmt = db()->prepare('UPDATE smart_galleries SET title=?, slug=?, description=?, rules_json=?, rule_version=?, enabled=?, visibility=?, placement_mode=?, parent_gallery_id=?, sort_mode=?, sort_direction=?, updated_at=? WHERE id=?');
            $stmt->execute(array_merge($values, [(string) $definition['updated_at'], $id]));
        }
        return $id;
    }

    if ($presentationReady) {
        $stmt = db()->prepare('INSERT INTO smart_galleries (title,slug,description,rules_json,rule_version,enabled,visibility,placement_mode,parent_gallery_id,sort_mode,sort_direction,presentation_json,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute(array_merge($values, [(string) $definition['presentation_json'], (string) $definition['created_at'], (string) $definition['updated_at']]));
    } else {
        $stmt = db()->prepare('INSERT INTO smart_galleries (title,slug,description,rules_json,rule_version,enabled,visibility,placement_mode,parent_gallery_id,sort_mode,sort_direction,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute(array_merge($values, [(string) $definition['created_at'], (string) $definition['updated_at']]));
    }
    return (int) db()->lastInsertId();
}

/** Return whether a slug is already owned by another definition. */
function smart_gallery_model_slug_exists(string $slug, int $excludeId): bool
{
    $stmt = db()->prepare('SELECT id FROM smart_galleries WHERE slug = ? AND id <> ? LIMIT 1');
    $stmt->execute([$slug, $excludeId]);
    return (bool) $stmt->fetchColumn();
}

/** Delete one Smart Gallery definition. */
function smart_gallery_model_delete(int $id): void
{
    $stmt = db()->prepare('DELETE FROM smart_galleries WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Return attachment rows for one physical gallery.
 *
 * @param int $galleryId Physical gallery identifier.
 * @param bool $metadataReady Whether placement/order metadata is available.
 */
function smart_gallery_model_attachment_rows_for_gallery(int $galleryId, bool $metadataReady): array
{
    $placementSelect = $metadataReady ? 'sgp.placement, sgp.placement_order' : "'bottom' AS placement, 0 AS placement_order";
    $order = $metadataReady ? "CASE sgp.placement WHEN 'top' THEN 0 ELSE 1 END, sgp.placement_order, sg.id" : 'sg.id';
    $stmt = db()->prepare('SELECT sg.*, ' . $placementSelect . ' FROM smart_gallery_placements sgp INNER JOIN smart_galleries sg ON sg.id = sgp.smart_gallery_id WHERE sgp.gallery_id = ? ORDER BY ' . $order);
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll();
}

/**
 * Return enabled public root definitions or definitions attached to one physical gallery.
 *
 * @param ?int $parentGalleryId Null for root listings.
 * @param bool $metadataReady Whether placement/order metadata is available.
 */
function smart_gallery_model_for_placement(?int $parentGalleryId, bool $metadataReady): array
{
    if ($parentGalleryId === null) {
        return db()->query("SELECT sg.* FROM smart_galleries sg WHERE sg.placement_mode = 'root' AND sg.enabled = 1 AND sg.visibility = 'public' ORDER BY sg.title, sg.id")->fetchAll();
    }
    $select = $metadataReady ? ', sgp.placement, sgp.placement_order' : ", 'bottom' AS placement, 0 AS placement_order";
    $order = $metadataReady ? "CASE sgp.placement WHEN 'top' THEN 0 ELSE 1 END, sgp.placement_order, sg.id" : 'sg.id';
    $stmt = db()->prepare("SELECT sg.*{$select} FROM smart_galleries sg INNER JOIN smart_gallery_placements sgp ON sgp.smart_gallery_id = sg.id WHERE sg.placement_mode = 'gallery' AND sgp.gallery_id = ? AND sg.enabled = 1 AND sg.visibility = 'public' ORDER BY {$order}");
    $stmt->execute([$parentGalleryId]);
    return $stmt->fetchAll();
}

/**
 * Atomically replace every Smart Gallery attachment beneath one physical gallery.
 *
 * @param int $galleryId Physical gallery identifier.
 * @param array<int,array{smart_gallery_id:int,placement:string,placement_order:int}> $attachments Normalized attachments.
 * @param string $now Shared SQL timestamp.
 */
function smart_gallery_model_replace_gallery_attachments(int $galleryId, array $attachments, string $now): void
{
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $clear = $pdo->prepare('DELETE FROM smart_gallery_placements WHERE gallery_id = ?');
        $clear->execute([$galleryId]);
        if ($attachments !== []) {
            $place = $pdo->prepare('INSERT INTO smart_gallery_placements (smart_gallery_id, gallery_id, placement, placement_order, created_at) VALUES (?, ?, ?, ?, ?)');
            $ids = [];
            foreach ($attachments as $attachment) {
                $smartId = (int) ($attachment['smart_gallery_id'] ?? 0);
                if ($smartId <= 0) continue;
                $place->execute([$smartId, $galleryId, (string) ($attachment['placement'] ?? 'bottom'), (int) ($attachment['placement_order'] ?? 0), $now]);
                $ids[$smartId] = $smartId;
            }
            if ($ids !== []) {
                $idList = array_values($ids);
                $marks = implode(',', array_fill(0, count($idList), '?'));
                $markAsChildren = $pdo->prepare("UPDATE smart_galleries SET placement_mode='gallery', parent_gallery_id=NULL, updated_at=? WHERE id IN ($marks)");
                $markAsChildren->execute(array_merge([$now], $idList));
            }
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

/**
 * Return every physical gallery currently listing one Smart Gallery.
 *
 * @param int $smartGalleryId Smart Gallery identifier.
 * @param bool $metadataReady Whether placement/order metadata is available.
 */
function smart_gallery_model_placement_galleries(int $smartGalleryId, bool $metadataReady): array
{
    $select = $metadataReady ? 'sgp.placement, sgp.placement_order' : "'bottom' AS placement, 0 AS placement_order";
    $stmt = db()->prepare('SELECT g.id, g.title, g.folder_path, g.visibility, g.access_mode, ' . $select . ' FROM smart_gallery_placements sgp INNER JOIN galleries g ON g.id = sgp.gallery_id WHERE sgp.smart_gallery_id = ? ORDER BY g.folder_path, g.title, g.id');
    $stmt->execute([$smartGalleryId]);
    return $stmt->fetchAll();
}

/** Update one existing attachment placement/order. */
function smart_gallery_model_update_placement(int $smartGalleryId, int $galleryId, string $placement, int $placementOrder): bool
{
    $stmt = db()->prepare('UPDATE smart_gallery_placements SET placement = ?, placement_order = ? WHERE smart_gallery_id = ? AND gallery_id = ?');
    $stmt->execute([$placement, $placementOrder, $smartGalleryId, $galleryId]);
    if ($stmt->rowCount() > 0) return true;
    $exists = db()->prepare('SELECT 1 FROM smart_gallery_placements WHERE smart_gallery_id = ? AND gallery_id = ? LIMIT 1');
    $exists->execute([$smartGalleryId, $galleryId]);
    return (bool) $exists->fetchColumn();
}

/** Remove one physical placement. */
function smart_gallery_model_remove_placement(int $smartGalleryId, int $galleryId): bool
{
    $stmt = db()->prepare('DELETE FROM smart_gallery_placements WHERE smart_gallery_id = ? AND gallery_id = ?');
    $stmt->execute([$smartGalleryId, $galleryId]);
    return $stmt->rowCount() > 0;
}

/** Return every physical gallery row for service-layer access filtering. */
function smart_gallery_model_all_source_galleries(): array
{
    return db()->query('SELECT * FROM galleries ORDER BY id')->fetchAll();
}

/**
 * Count images matching one normalized semantic Smart Gallery query.
 *
 * @param array<string,mixed> $rules Validated rule document.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 */
function smart_gallery_model_count_images(array $rules, array $accessibleGalleryIds, bool $publicOnly, bool $allowNsfw): int
{
    $query = smart_gallery_model_result_query($rules, $accessibleGalleryIds, $publicOnly, $allowNsfw, 'capture_date', 'desc');
    $stmt = db()->prepare('SELECT COUNT(*) FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE ' . $query['where']);
    $stmt->execute($query['params']);
    return (int) $stmt->fetchColumn();
}

/**
 * Query one bounded page of matching Smart Gallery images.
 *
 * @param array<string,mixed> $rules Validated rule document.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @param string $sortMode Allowlisted sort mode.
 * @param string $sortDirection asc or desc.
 * @param int $limit Bounded row limit.
 * @param int $offset Non-negative offset.
 */
function smart_gallery_model_query_images(array $rules, array $accessibleGalleryIds, bool $publicOnly, bool $allowNsfw, string $sortMode, string $sortDirection, int $limit, int $offset): array
{
    $query = smart_gallery_model_result_query($rules, $accessibleGalleryIds, $publicOnly, $allowNsfw, $sortMode, $sortDirection);
    $limit = max(1, $limit);
    $offset = max(0, $offset);
    $sql = 'SELECT i.*, g.title AS source_gallery_title, g.slug AS source_gallery_slug, g.folder_path AS source_gallery_folder_path, g.visibility AS source_gallery_visibility, g.access_mode AS source_gallery_access_mode FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE ' . $query['where'] . ' ORDER BY ' . $query['order'] . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    $stmt = db()->prepare($sql);
    $stmt->execute($query['params']);
    return $stmt->fetchAll();
}

/**
 * Return whether one image remains a member of one normalized Smart Gallery result set.
 *
 * @param array<string,mixed> $rules Validated rule document.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @param int $imageId Image identifier to re-authorize.
 */
function smart_gallery_model_contains_image(array $rules, array $accessibleGalleryIds, bool $publicOnly, bool $allowNsfw, int $imageId): bool
{
    if ($imageId <= 0) return false;
    $query = smart_gallery_model_result_query($rules, $accessibleGalleryIds, $publicOnly, $allowNsfw, 'capture_date', 'desc');
    $params = $query['params'];
    $params[] = $imageId;
    $stmt = db()->prepare('SELECT 1 FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE ' . $query['where'] . ' AND i.id = ? LIMIT 1');
    $stmt->execute($params);
    return (bool) $stmt->fetchColumn();
}

/**
 * Return count/cover rows for a bounded Smart Gallery card chunk.
 *
 * @param array<int,array{id:int,rules:array<string,mixed>,sort_mode:string,sort_direction:string}> $definitions Semantic definitions keyed arbitrarily.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @return array<int,array{smart_gallery_id:int,image_count:int,cover_image_id:int}> Rows keyed sequentially.
 */
function smart_gallery_model_card_summary_rows(array $definitions, array $accessibleGalleryIds, bool $publicOnly, bool $allowNsfw): array
{
    $selects = [];
    $params = [];
    foreach ($definitions as $definition) {
        $smartId = (int) ($definition['id'] ?? 0);
        if ($smartId <= 0) continue;
        $query = smart_gallery_model_result_query(
            (array) ($definition['rules'] ?? []),
            $accessibleGalleryIds,
            $publicOnly,
            $allowNsfw,
            (string) ($definition['sort_mode'] ?? ''),
            (string) ($definition['sort_direction'] ?? 'desc')
        );
        $base = ' FROM images i INNER JOIN galleries g ON g.id=i.gallery_id WHERE ' . $query['where'];
        $selects[] = 'SELECT ' . $smartId . ' AS smart_gallery_id, '
            . '(SELECT COUNT(*)' . $base . ') AS image_count, '
            . '(SELECT i.id' . $base . ' ORDER BY ' . $query['order'] . ' LIMIT 1) AS cover_image_id';
        $params = array_merge($params, $query['params'], $query['params']);
    }
    if ($selects === []) return [];
    $stmt = db()->prepare(implode(' UNION ALL ', $selects));
    $stmt->execute($params);
    return array_values(array_map(static fn (array $row): array => [
        'smart_gallery_id' => (int) ($row['smart_gallery_id'] ?? 0),
        'image_count' => max(0, (int) ($row['image_count'] ?? 0)),
        'cover_image_id' => max(0, (int) ($row['cover_image_id'] ?? 0)),
    ], $stmt->fetchAll()));
}

/** Load source image rows with source-gallery metadata, keyed by image id. */
function smart_gallery_model_images_by_ids(array $imageIds): array
{
    $imageIds = smart_gallery_model_normalize_ids($imageIds);
    if ($imageIds === []) return [];
    $stmt = db()->prepare(
        'SELECT i.*, g.title AS source_gallery_title, g.slug AS source_gallery_slug, '
        . 'g.folder_path AS source_gallery_folder_path, g.visibility AS source_gallery_visibility, '
        . 'g.access_mode AS source_gallery_access_mode '
        . 'FROM images i INNER JOIN galleries g ON g.id=i.gallery_id '
        . 'WHERE i.id IN (' . implode(',', array_fill(0, count($imageIds), '?')) . ')'
    );
    $stmt->execute($imageIds);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) $rows[$id] = $row;
    }
    return $rows;
}

/** Load physical source galleries keyed by gallery id. */
function smart_gallery_model_galleries_by_ids(array $galleryIds): array
{
    $galleryIds = smart_gallery_model_normalize_ids($galleryIds);
    if ($galleryIds === []) return [];
    $stmt = db()->prepare('SELECT * FROM galleries WHERE id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')');
    $stmt->execute($galleryIds);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) $rows[$id] = $row;
    }
    return $rows;
}

/** Return one tag display name or null when the reference is missing. */
function smart_gallery_model_tag_name(int $tagId): ?string
{
    $stmt = db()->prepare('SELECT name FROM tags WHERE id = ?');
    $stmt->execute([$tagId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

/** Return one physical gallery display title or null when the reference is missing. */
function smart_gallery_model_gallery_title(int $galleryId): ?string
{
    $stmt = db()->prepare('SELECT title FROM galleries WHERE id = ?');
    $stmt->execute([$galleryId]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

/** Persist a private editorial image rating, where null clears the rating. */
function smart_gallery_model_set_image_rating(int $imageId, ?int $rating, string $updatedAt): void
{
    $stmt = db()->prepare('UPDATE images SET editorial_rating = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$rating, $updatedAt, $imageId]);
}

/**
 * Compile a validated rule document into SQL and bound parameters.
 *
 * @param array<string,mixed> $rules Validated semantic rule document.
 * @return array{sql:string,params:array<int,mixed>}
 */
function smart_gallery_model_compile_rules(array $rules): array
{
    $params = [];
    $sql = smart_gallery_model_compile_node((array) ($rules['root'] ?? []), $params);
    return ['sql' => $sql, 'params' => $params];
}

/** Compile one trusted, validated rule node. */
function smart_gallery_model_compile_node(array $node, array &$params): string
{
    if (($node['type'] ?? '') === 'group') {
        $parts = [];
        foreach ((array) ($node['children'] ?? []) as $child) {
            $parts[] = smart_gallery_model_compile_node((array) $child, $params);
        }
        if ($parts === []) return '1=1';
        $operator = (string) ($node['operator'] ?? 'AND');
        if ($operator === 'NOT') return '(NOT (' . $parts[0] . '))';
        if (!in_array($operator, ['AND', 'OR'], true)) throw new InvalidArgumentException('Unsupported Smart Gallery rule group.');
        return '(' . implode(' ' . $operator . ' ', $parts) . ')';
    }
    return smart_gallery_model_compile_condition($node, $params);
}

/** Compile one allowlisted condition into a parameterized SQL predicate. */
function smart_gallery_model_compile_condition(array $condition, array &$params): string
{
    $field = (string) ($condition['field'] ?? '');
    $operator = (string) ($condition['operator'] ?? '');
    $value = $condition['value'] ?? null;
    $columns = [
        'capture_date' => 'i.exif_taken_at', 'camera_make' => 'i.exif_camera_make', 'camera_model' => 'i.exif_camera_model',
        'lens' => 'i.exif_lens_model', 'iso' => 'i.exif_iso', 'aperture' => 'i.exif_aperture', 'focal_length' => 'i.exif_focal_length',
        'exposure_time' => 'i.exif_exposure_time', 'exif_orientation' => 'i.exif_orientation', 'filename' => 'i.filename',
        'title' => 'i.title', 'description' => 'i.description', 'gallery_title' => 'g.title', 'rating' => 'i.editorial_rating',
        'extension' => "LOWER(SUBSTRING_INDEX(i.filename, '.', -1))", 'width' => 'i.width', 'height' => 'i.height', 'file_size' => 'i.file_size',
    ];
    if ($field === 'gallery') {
        if (in_array($operator, ['equals', 'not_equals'], true)) {
            $params[] = (int) $value;
            return 'i.gallery_id ' . ($operator === 'equals' ? '=' : '<>') . ' ?';
        }
        $params[] = (int) $value;
        $params[] = (int) $value;
        $predicate = "(i.gallery_id = ? OR g.folder_path LIKE CONCAT((SELECT sg_parent.folder_path FROM galleries sg_parent WHERE sg_parent.id = ?), '/%'))";
        return $operator === 'under' ? $predicate : '(NOT ' . $predicate . ')';
    }
    if ($field === 'tag') {
        if ($operator === 'untagged') {
            return "NOT EXISTS (SELECT 1 FROM image_tags sg_it WHERE sg_it.image_id = i.id) AND NOT EXISTS (SELECT 1 FROM gallery_tags sg_gt JOIN galleries sg_tag_gallery ON sg_tag_gallery.id = sg_gt.gallery_id WHERE g.folder_path = sg_tag_gallery.folder_path OR g.folder_path LIKE CONCAT(sg_tag_gallery.folder_path, '/%'))";
        }
        $ids = is_array($value) ? array_values(array_map('intval', $value)) : [(int) $value];
        if ($operator === 'has_all_tags') {
            $predicates = [];
            foreach ($ids as $tagId) {
                $params[] = $tagId;
                $params[] = $tagId;
                $predicates[] = "(EXISTS (SELECT 1 FROM image_tags sg_it WHERE sg_it.image_id = i.id AND sg_it.tag_id = ?) OR EXISTS (SELECT 1 FROM gallery_tags sg_gt JOIN galleries sg_tag_gallery ON sg_tag_gallery.id = sg_gt.gallery_id WHERE sg_gt.tag_id = ? AND (g.folder_path = sg_tag_gallery.folder_path OR g.folder_path LIKE CONCAT(sg_tag_gallery.folder_path, '/%'))))";
            }
            return '(' . implode(' AND ', $predicates) . ')';
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        array_push($params, ...$ids, ...$ids);
        $exists = "(EXISTS (SELECT 1 FROM image_tags sg_it WHERE sg_it.image_id = i.id AND sg_it.tag_id IN ($marks)) OR EXISTS (SELECT 1 FROM gallery_tags sg_gt JOIN galleries sg_tag_gallery ON sg_tag_gallery.id = sg_gt.gallery_id WHERE sg_gt.tag_id IN ($marks) AND (g.folder_path = sg_tag_gallery.folder_path OR g.folder_path LIKE CONCAT(sg_tag_gallery.folder_path, '/%'))))";
        return $operator === 'not_has_tag' ? '(NOT ' . $exists . ')' : $exists;
    }
    if ($field === 'gps') {
        $exists = '(i.gps_lat IS NOT NULL AND i.gps_lng IS NOT NULL)';
        return $operator === 'exists' ? $exists : '(NOT ' . $exists . ')';
    }
    if ($field === 'ai_text' || $field === 'ai_metadata') {
        $base = 'EXISTS (SELECT 1 FROM image_ai_metadata sg_ai WHERE sg_ai.image_id = i.id';
        if ($field === 'ai_metadata') return $operator === 'exists' ? $base . ')' : 'NOT ' . $base . ')';
        $expression = 'sg_ai.searchable_text';
        $innerParams = [];
        $predicate = smart_gallery_model_compile_scalar($expression, $operator, $value, $innerParams);
        array_push($params, ...$innerParams);
        return $operator === 'missing' || $operator === 'is_empty'
            ? 'NOT EXISTS (SELECT 1 FROM image_ai_metadata sg_ai WHERE sg_ai.image_id = i.id AND ' . smart_gallery_model_nonempty_sql($expression) . ')'
            : $base . ' AND ' . $predicate . ')';
    }
    if ($field === 'duplicate_status') {
        $pair = "EXISTS (SELECT 1 FROM images sg_dupe WHERE sg_dupe.id <> i.id AND i.checksum IS NOT NULL AND i.checksum <> '' AND sg_dupe.checksum = i.checksum)";
        $unresolved = "EXISTS (SELECT 1 FROM images sg_dupe WHERE sg_dupe.id <> i.id AND i.checksum IS NOT NULL AND i.checksum <> '' AND sg_dupe.checksum = i.checksum AND NOT EXISTS (SELECT 1 FROM duplicate_photo_ledger_pairs sg_dl WHERE sg_dl.image_id_low = LEAST(i.id, sg_dupe.id) AND sg_dl.image_id_high = GREATEST(i.id, sg_dupe.id)))";
        return match ($operator) { 'unresolved' => $unresolved, 'resolved' => '(' . $pair . ' AND NOT ' . $unresolved . ')', 'exists' => $pair, default => '(NOT ' . $pair . ')' };
    }
    if ($field === 'media_orientation') {
        return match ($operator) { 'landscape' => 'i.width > i.height', 'portrait' => 'i.height > i.width', default => 'i.width = i.height' };
    }
    if ($operator === 'unrated') return 'i.editorial_rating IS NULL';
    if (!isset($columns[$field])) throw new InvalidArgumentException('Unsupported Smart Gallery rule field.');
    return smart_gallery_model_compile_scalar($columns[$field], $operator, $value, $params);
}

/** Compile a scalar comparison using only a trusted column expression. */
function smart_gallery_model_compile_scalar(string $column, string $operator, mixed $value, array &$params): string
{
    if ($operator === 'exists') return smart_gallery_model_nonempty_sql($column);
    if ($operator === 'missing') return '(' . $column . ' IS NULL OR ' . $column . " = '')";
    if ($operator === 'is_empty') return '(' . $column . ' IS NULL OR TRIM(' . $column . ") = '')";
    if ($operator === 'not_empty') return smart_gallery_model_nonempty_sql($column);
    if ($operator === 'between') { $params[] = $value[0]; $params[] = $value[1]; return "$column BETWEEN ? AND ?"; }
    if ($operator === 'year') { $params[] = (int) $value; return "YEAR($column) = ?"; }
    if ($operator === 'month') { $params[] = (int) $value; return "MONTH($column) = ?"; }
    $sqlOperators = ['equals' => '=', 'not_equals' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=', 'before' => '<', 'after' => '>', 'exact' => '='];
    if (isset($sqlOperators[$operator])) { $params[] = $value; return "$column {$sqlOperators[$operator]} ?"; }
    if (!in_array($operator, ['contains', 'not_contains', 'starts_with', 'ends_with'], true)) {
        throw new InvalidArgumentException('Unsupported Smart Gallery scalar operator.');
    }
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $value);
    $pattern = match ($operator) { 'starts_with' => $escaped . '%', 'ends_with' => '%' . $escaped, default => '%' . $escaped . '%' };
    $params[] = $pattern;
    return $column . ($operator === 'not_contains' ? ' NOT LIKE ?' : ' LIKE ?') . " ESCAPE '\\\\'";
}

/** Return a reusable SQL non-empty test for a trusted column expression. */
function smart_gallery_model_nonempty_sql(string $column): string
{
    return '(' . $column . ' IS NOT NULL AND TRIM(' . $column . ") <> '')";
}

/**
 * Build the internal rule/access/order query definition.
 *
 * @param array<string,mixed> $rules Validated rule document.
 * @param array<int,int> $accessibleGalleryIds Allowed physical gallery identifiers.
 * @param bool $publicOnly Whether public image visibility is required.
 * @param bool $allowNsfw Whether NSFW rows may be included when publicOnly is true.
 * @param string $sortMode Allowlisted service-owned sort mode.
 * @param string $sortDirection asc or desc.
 * @return array{where:string,params:array<int,mixed>,order:string}
 */
function smart_gallery_model_result_query(array $rules, array $accessibleGalleryIds, bool $publicOnly, bool $allowNsfw, string $sortMode, string $sortDirection): array
{
    $compiled = smart_gallery_model_compile_rules($rules);
    $ids = smart_gallery_model_normalize_ids($accessibleGalleryIds);
    $order = smart_gallery_model_order_sql($sortMode, $sortDirection);
    if ($ids === []) return ['where' => '1=0', 'params' => [], 'order' => $order];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $where = $compiled['sql'] . ' AND i.gallery_id IN (' . $marks . ')';
    $params = array_merge($compiled['params'], $ids);
    if ($publicOnly) {
        $where .= " AND i.visibility = 'public'";
        if (!$allowNsfw) $where .= ' AND COALESCE(i.nsfw_enabled, 0) = 0 AND COALESCE(g.nsfw_enabled, 0) = 0';
    }
    return ['where' => $where, 'params' => $params, 'order' => $order];
}

/** Return a hardcoded safe ORDER BY expression for one supported mode. */
function smart_gallery_model_order_sql(string $mode, string $direction): string
{
    $directionSql = $direction === 'asc' ? 'ASC' : 'DESC';
    $column = match ($mode) {
        'filename' => 'i.filename',
        'created_at' => 'i.created_at',
        'title' => "COALESCE(NULLIF(i.title,''),i.filename)",
        'rating' => 'i.editorial_rating',
        'default' => 'i.sort_order',
        default => 'i.exif_taken_at',
    };
    return $column . ' ' . $directionSql . ', i.id ' . $directionSql;
}

/** Normalize a list of positive integer identifiers. */
function smart_gallery_model_normalize_ids(array $ids): array
{
    return array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
}
