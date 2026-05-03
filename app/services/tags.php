<?php

declare(strict_types=1);

/**
 * Tag and voting service functions.
 *
 * This module owns the public interaction metadata around images and galleries:
 * vote totals, current visitor vote lookups, tag parsing, tag slug generation,
 * tag persistence, entity tag synchronization, and tag-based gallery listing.
 * The legacy function names are intentionally preserved because controllers,
 * admin forms, and public views already call them directly.
 */

/**
 * Sum all votes for an image.
 */
function vote_score(int $imageId): int
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT COALESCE(SUM(vote), 0) FROM image_votes WHERE image_id = ?');
    $stmt->execute([$imageId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return the current logged-in user or visitor's vote for one image.
 */
function current_vote_for_image(int $imageId): int
{
    // Variable $user stores this steps working value.
    $user = current_user();
    if ($user) {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND user_id = ?');
        $stmt->execute([$imageId, (int) $user['id']]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
    $stmt->execute([$imageId, visitor_hash()]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Parse admin-entered comma/semicolon/newline tag text into unique names.
 */
function split_tag_names(string $tags): array
{
    // Variable $names stores this steps working value.
    $names = [];
    foreach (preg_split('/[,;\n]+/', $tags) ?: [] as $name) {
        // Variable $name stores this steps working value.
        $name = trim($name);
        if ($name !== '') {
            $names[strtolower($name)] = substr($name, 0, 100);
        }
    }
    return array_values($names);
}

/**
 * Function `tag_slug` handles this scoped operation.
 */
function tag_slug(string $name): string
{
    // Variable $slug stores this steps working value.
    $slug = slugify($name);
    return $slug !== '' ? substr($slug, 0, 120) : 'tag';
}

/**
 * Return an existing tag ID or create a new tag row.
 */
function find_or_create_tag(string $name): int
{
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
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('INSERT IGNORE INTO ' . $mapTable . ' (' . $idColumn . ', tag_id) VALUES (?, ?)');
        $stmt->execute([$id, $tagId]);
    }
}

/**
 * Function `tags_for_entity` handles this scoped operation.
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
 * Return all tags for many entities in one query, grouped by entity ID.
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
 * Return votes for many images for the current viewer in one query, keyed by image ID.
 */
function current_votes_for_images(array $imageIds): array
{
    // $imageIds stores an intermediate value used by the surrounding gallery workflow.
    $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if (!$imageIds) {
        return [];
    }
    // $user stores an intermediate value used by the surrounding gallery workflow.
    $user = current_user();
    // $placeholders stores an intermediate value used by the surrounding gallery workflow.
    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    try {
        if ($user) {
            // $stmt stores an intermediate value used by the surrounding gallery workflow.
            $stmt = db()->prepare('SELECT image_id, vote FROM image_votes WHERE user_id = ? AND image_id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([(int) $user['id']], $imageIds));
        } else {
            // $stmt stores an intermediate value used by the surrounding gallery workflow.
            $stmt = db()->prepare('SELECT image_id, vote FROM image_votes WHERE visitor_hash = ? AND image_id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([visitor_hash()], $imageIds));
        }
        // $votes stores an intermediate value used by the surrounding gallery workflow.
        $votes = [];
        foreach ($stmt->fetchAll() as $row) {
            $votes[(int) $row['image_id']] = (int) $row['vote'];
        }
        return $votes;
    } catch (PDOException) {
        return [];
    }
}

/**
 * Function `tag_names_for_entity` handles this scoped operation.
 */
function tag_names_for_entity(string $type, int $id): string
{
    return implode(', ', array_column(tags_for_entity($type, $id), 'name'));
}

/**
 * Return all tag names for datalist suggestions in admin forms.
 */
function all_tag_names(): array
{
    try {
        return db()->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException) {
        return [];
    }
}

/**
 * Fetch one tag by slug for public tag-filter pages.
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
 */
function public_galleries_for_tag(int $tagId): array
{
    // Variable $stmt stores this steps working value.
    $listingCondition = public_gallery_listing_condition('g');
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
 */
function contained_tags_for_gallery(array $gallery, bool $publicOnly): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    if ($folderPath === '') {
        return [];
    }
    // Variable $visibilitySql stores this steps working value.
    $visibilitySql = $publicOnly ? ' AND ' . public_gallery_listing_condition('g') : '';
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

