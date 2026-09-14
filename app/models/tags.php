<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/tags.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns tag, tag-assignment, tag-reporting, and public tag lookup persistence.
 *
 * Responsibilities:
 *   - Persist canonical tag rows and gallery/image tag assignments
 *   - Provide bulk tag reads and usage aggregates
 *   - Provide tag suggestion source rows without owning scoring policy
 *   - Support admin tag maintenance and duplicate merges atomically
 *   - Keep tag SQL out of service orchestration
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
 *   - Dynamic entity types and sort fields are constrained by explicit allowlists.
 *   - Public listing predicates are built only from boolean schema capability flags.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use PDO;
use function Gallery\Core\db;

/**
 * Return the storage table and entity-id column for a supported tag assignment type.
 *
 * @return array{0:string,1:string}
 */
function tag_model_assignment_target(string $type): array
{
    return match ($type) {
        'gallery' => ['gallery_tags', 'gallery_id'],
        'image' => ['image_tags', 'image_id'],
        default => throw new InvalidArgumentException('Unsupported tag assignment type.'),
    };
}

/** Return one tag id by canonical slug. */
function tag_model_id_by_slug(string $slug): int
{
    $stmt = db()->prepare('SELECT id FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/** Insert one canonical tag row and return its id. */
function tag_model_create(string $name, string $slug, string $now): int
{
    $pdo = db();
    $stmt = $pdo->prepare('INSERT INTO tags (name, slug, created_at, updated_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $slug, $now, $now]);
    return (int) $pdo->lastInsertId();
}

/** Remove every direct tag assignment from one gallery or image. */
function tag_model_clear_entity_tags(string $type, int $id): void
{
    [$table, $idColumn] = tag_model_assignment_target($type);
    db()->prepare('DELETE FROM ' . $table . ' WHERE ' . $idColumn . ' = ?')->execute([$id]);
}

/** Attach one tag to one gallery or image, ignoring an existing identical assignment. */
function tag_model_attach_entity_tag(string $type, int $id, int $tagId): void
{
    [$table, $idColumn] = tag_model_assignment_target($type);
    $stmt = db()->prepare('INSERT IGNORE INTO ' . $table . ' (' . $idColumn . ', tag_id) VALUES (?, ?)');
    $stmt->execute([$id, $tagId]);
}

/** @return array<int,array<string,mixed>> */
function tag_model_tags_for_entity(string $type, int $id): array
{
    [$table, $idColumn] = tag_model_assignment_target($type);
    $stmt = db()->prepare('SELECT t.* FROM tags t JOIN ' . $table . ' mt ON mt.tag_id = t.id WHERE mt.' . $idColumn . ' = ? ORDER BY t.name');
    $stmt->execute([$id]);
    return $stmt->fetchAll() ?: [];
}

/** @param array<int,int> $tagIds @return array<int,array<string,mixed>> */
function tag_model_usage_count_rows(array $tagIds): array
{
    if ($tagIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
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
    $stmt->execute(array_merge($tagIds, $tagIds));
    return $stmt->fetchAll() ?: [];
}

/** @param array<int,int> $ids @return array<int,array<string,mixed>> */
function tag_model_tags_for_entities(string $type, array $ids): array
{
    if ($ids === []) {
        return [];
    }
    [$table, $idColumn] = tag_model_assignment_target($type);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        'SELECT mt.' . $idColumn . ' AS entity_id, t.*
         FROM tags t
         JOIN ' . $table . ' mt ON mt.tag_id = t.id
         WHERE mt.' . $idColumn . ' IN (' . $placeholders . ')
         ORDER BY mt.' . $idColumn . ', t.name'
    );
    $stmt->execute($ids);
    return $stmt->fetchAll() ?: [];
}

/** @return array<int,string> */
function tag_model_all_names(): array
{
    $stmt = db()->prepare('SELECT name FROM tags ORDER BY name');
    $stmt->execute();
    return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
}

/** @return array<string,mixed>|null */
function tag_model_gallery_context(int $galleryId): ?array
{
    $stmt = db()->prepare('SELECT id, parent_id, folder_path FROM galleries WHERE id = ?');
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return raw usage rows for one weighted suggestion source.
 *
 * @param string $assignmentType gallery or image.
 * @param string $scope current, siblings, descendants, ancestors, or global.
 * @param array<string,mixed> $context Scope-specific normalized values.
 * @return array<int,array<string,mixed>>
 */
function tag_model_suggestion_usage_rows(string $assignmentType, string $scope, array $context = []): array
{
    if (!in_array($assignmentType, ['gallery', 'image'], true)) {
        throw new InvalidArgumentException('Unsupported tag suggestion assignment type.');
    }
    if (!in_array($scope, ['current', 'siblings', 'descendants', 'ancestors', 'global'], true)) {
        throw new InvalidArgumentException('Unsupported tag suggestion scope.');
    }

    $join = $assignmentType === 'gallery'
        ? 'JOIN gallery_tags a ON a.tag_id = t.id JOIN galleries g ON g.id = a.gallery_id'
        : 'JOIN image_tags a ON a.tag_id = t.id JOIN images i ON i.id = a.image_id JOIN galleries g ON g.id = i.gallery_id';
    $where = '';
    $params = [];
    if ($scope === 'current') {
        $where = ' WHERE g.id = ?';
        $params[] = (int) ($context['gallery_id'] ?? 0);
    } elseif ($scope === 'siblings') {
        $where = ' WHERE g.parent_id = ? AND g.id <> ?';
        $params[] = (int) ($context['parent_id'] ?? 0);
        $params[] = (int) ($context['gallery_id'] ?? 0);
    } elseif ($scope === 'descendants') {
        $where = ' WHERE g.folder_path LIKE ? AND g.id <> ?';
        $params[] = (string) ($context['folder_prefix'] ?? '');
        $params[] = (int) ($context['gallery_id'] ?? 0);
    } elseif ($scope === 'ancestors') {
        $paths = array_values(array_filter(array_map('strval', (array) ($context['paths'] ?? [])), static fn (string $path): bool => $path !== ''));
        if ($paths === []) {
            return [];
        }
        $where = ' WHERE g.folder_path IN (' . implode(',', array_fill(0, count($paths), '?')) . ')';
        $params = $paths;
    }

    $stmt = db()->prepare('SELECT t.id, t.name, t.slug, COUNT(*) AS usage_count
        FROM tags t ' . $join . $where . '
        GROUP BY t.id, t.name, t.slug');
    $stmt->execute($params);
    return $stmt->fetchAll() ?: [];
}

/** @return array<int,array<string,mixed>> */
function tag_model_global_suggestion_rows(int $limit): array
{
    $limit = max(1, $limit);
    return db()->query('SELECT t.id, t.name, t.slug,
            COALESCE(gallery_usage.gallery_count, 0) AS gallery_count,
            COALESCE(image_usage.image_count, 0) AS image_count
        FROM tags t
        LEFT JOIN (SELECT tag_id, COUNT(*) AS gallery_count FROM gallery_tags GROUP BY tag_id) gallery_usage ON gallery_usage.tag_id = t.id
        LEFT JOIN (SELECT tag_id, COUNT(*) AS image_count FROM image_tags GROUP BY tag_id) image_usage ON image_usage.tag_id = t.id
        ORDER BY (COALESCE(gallery_usage.gallery_count, 0) + COALESCE(image_usage.image_count, 0)) DESC, t.name
        LIMIT ' . $limit)->fetchAll() ?: [];
}

/** Probe whether the optional tag description column is available. */
function tag_model_description_column_ready(): bool
{
    $stmt = db()->prepare('SELECT description FROM tags LIMIT 1');
    $stmt->execute();
    return true;
}

/** @return array<int,array<string,mixed>> */
function tag_model_admin_rows(bool $descriptionReady, string $sortField, string $sortDirection): array
{
    $safeSortField = in_array($sortField, ['name', 'usage'], true) ? $sortField : 'usage';
    $safeSortDirection = strtolower($sortDirection) === 'asc' ? 'ASC' : 'DESC';
    $descriptionColumn = $descriptionReady ? 't.description' : "'' AS description";
    $groupByDescription = $descriptionReady ? ', t.description' : '';
    $orderBy = $safeSortField === 'name'
        ? 't.name ' . $safeSortDirection . ', t.slug ' . $safeSortDirection
        : 'usage_count ' . $safeSortDirection . ', t.name ASC';
    return db()->query('SELECT t.id, t.name, t.slug, ' . $descriptionColumn . ', t.created_at, t.updated_at,
        COUNT(DISTINCT gt.gallery_id) AS gallery_count,
        COUNT(DISTINCT it.image_id) AS image_count,
        COUNT(DISTINCT gt.gallery_id) + COUNT(DISTINCT it.image_id) AS usage_count
        FROM tags t
        LEFT JOIN gallery_tags gt ON gt.tag_id = t.id
        LEFT JOIN image_tags it ON it.tag_id = t.id
        GROUP BY t.id, t.name, t.slug' . $groupByDescription . ', t.created_at, t.updated_at
        ORDER BY ' . $orderBy)->fetchAll() ?: [];
}

/** @return array<int,array<string,mixed>> */
function tag_model_admin_gallery_usage_rows(int $tagId): array
{
    $stmt = db()->prepare('SELECT DISTINCT g.id, g.title, g.slug, g.url_path, g.folder_path
        FROM gallery_tags gt
        JOIN galleries g ON g.id = gt.gallery_id
        WHERE gt.tag_id = ?
        ORDER BY g.title, g.id');
    $stmt->execute([$tagId]);
    return $stmt->fetchAll() ?: [];
}

/** @return array<int,array<string,mixed>> */
function tag_model_admin_image_usage_rows(int $tagId): array
{
    $stmt = db()->prepare('SELECT DISTINCT i.id, i.relative_path, i.filename, i.gallery_id, i.sort_order AS image_sort_order, g.title AS gallery_title, g.slug AS gallery_slug
        FROM image_tags it
        JOIN images i ON i.id = it.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE it.tag_id = ?
        ORDER BY g.title, i.sort_order, i.filename, i.id');
    $stmt->execute([$tagId]);
    return $stmt->fetchAll() ?: [];
}

/** @return array<string,mixed>|null */
function tag_model_find_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tags WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Return whether another tag owns a slug. */
function tag_model_slug_exists_except(string $slug, int $excludeId): bool
{
    $stmt = db()->prepare('SELECT id FROM tags WHERE slug = ? AND id <> ?');
    $stmt->execute([$slug, $excludeId]);
    return (bool) $stmt->fetchColumn();
}

/** Persist canonical tag metadata. */
function tag_model_update_metadata(int $id, string $name, string $slug, string $description, bool $descriptionReady, string $now): void
{
    if ($descriptionReady) {
        $stmt = db()->prepare('UPDATE tags SET name = ?, slug = ?, description = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$name, $slug, $description, $now, $id]);
        return;
    }
    $stmt = db()->prepare('UPDATE tags SET name = ?, slug = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$name, $slug, $now, $id]);
}

/** Delete one tag and both assignment types atomically. */
function tag_model_delete_with_assignments(int $id): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM gallery_tags WHERE tag_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM image_tags WHERE tag_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @return array<int,array<string,mixed>> */
function tag_model_all_rows_by_id(): array
{
    $stmt = db()->prepare('SELECT * FROM tags ORDER BY id');
    $stmt->execute();
    return $stmt->fetchAll() ?: [];
}

/** Delete one tag row only. */
function tag_model_delete_row(int $id): void
{
    db()->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
}

/** Merge one duplicate tag into a canonical target atomically enough for existing maintenance semantics. */
function tag_model_merge_duplicate(int $targetId, int $duplicateId): void
{
    db()->prepare('INSERT IGNORE INTO gallery_tags (gallery_id, tag_id) SELECT gallery_id, ? FROM gallery_tags WHERE tag_id = ?')->execute([$targetId, $duplicateId]);
    db()->prepare('INSERT IGNORE INTO image_tags (image_id, tag_id) SELECT image_id, ? FROM image_tags WHERE tag_id = ?')->execute([$targetId, $duplicateId]);
    db()->prepare('DELETE FROM gallery_tags WHERE tag_id = ?')->execute([$duplicateId]);
    db()->prepare('DELETE FROM image_tags WHERE tag_id = ?')->execute([$duplicateId]);
    db()->prepare('DELETE FROM tags WHERE id = ?')->execute([$duplicateId]);
}

/** Update one tag to canonical name and slug. */
function tag_model_update_canonical(int $id, string $name, string $slug, string $now): void
{
    db()->prepare('UPDATE tags SET name = ?, slug = ?, updated_at = ? WHERE id = ?')->execute([$name, $slug, $now, $id]);
}

/** @return array<string,mixed>|null */
function tag_model_find_by_slug(string $slug): ?array
{
    $stmt = db()->prepare('SELECT * FROM tags WHERE slug = ?');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** @return array<int,array<string,mixed>> */
function tag_model_public_galleries(int $tagId, bool $accessListingReady): array
{
    $listing = "g.visibility = 'public'" . ($accessListingReady ? " AND g.access_listing = 'listed'" : '');
    $stmt = db()->prepare("SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE " . $listing . " AND (
            EXISTS (SELECT 1 FROM gallery_tags gt WHERE gt.gallery_id = g.id AND gt.tag_id = ?)
            OR EXISTS (SELECT 1 FROM image_tags it JOIN images tagged_image ON tagged_image.id = it.image_id WHERE tagged_image.gallery_id = g.id AND it.tag_id = ?)
        )
        GROUP BY g.id
        ORDER BY g.sort_order, g.title");
    $stmt->execute([$tagId, $tagId]);
    return $stmt->fetchAll() ?: [];
}

/** @return array<int,array<string,mixed>> */
function tag_model_contained_for_gallery(string $folderPath, bool $publicOnly, bool $accessListingReady): array
{
    $galleryVisibility = '';
    if ($publicOnly) {
        $galleryVisibility = " AND g.visibility = 'public'" . ($accessListingReady ? " AND g.access_listing = 'listed'" : '');
    }
    $imageVisibility = $publicOnly ? " AND tagged_image.visibility = 'public'" : '';
    $stmt = db()->prepare("SELECT DISTINCT t.id, t.name, t.slug
        FROM tags t
        JOIN gallery_tags gt ON gt.tag_id = t.id
        JOIN galleries g ON g.id = gt.gallery_id
        WHERE g.folder_path LIKE ?" . $galleryVisibility . "
        UNION
        SELECT DISTINCT t.id, t.name, t.slug
        FROM tags t
        JOIN image_tags it ON it.tag_id = t.id
        JOIN images tagged_image ON tagged_image.id = it.image_id
        JOIN galleries g ON g.id = tagged_image.gallery_id
        WHERE g.folder_path LIKE ?" . $galleryVisibility . $imageVisibility . "
        ORDER BY name");
    $stmt->execute([$folderPath . '/%', $folderPath . '/%']);
    return $stmt->fetchAll() ?: [];
}
