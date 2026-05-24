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
 *   2026-05-12
 */

declare(strict_types=1);

/**
 * Parse admin-entered comma/semicolon/newline tag text into unique names.
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
 */
function tag_slug(string $name): string
{
    // Variable $slug stores this steps working value.
    $slug = sanitize_tag_name($name);
    return $slug !== '' ? substr($slug, 0, 120) : 'tag';
}

/**
 * Return an existing tag ID or create a new tag row.
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
 * Return true when the optional tag description column is available.
 */
function tag_description_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db()->query('SELECT description FROM tags LIMIT 1');
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
 */
function admin_tag_usage_rows(int $tagId): array
{
    // Variable $galleries stores this steps working value.
    $galleries = [];
    // Variable $galleryStmt stores this steps working value.
    $galleryStmt = db()->prepare("SELECT DISTINCT g.id, g.title, g.slug, g.folder_path
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
            'folder_path' => (string) ($row['folder_path'] ?? ''),
            'edit_url' => url_for('admin_edit_gallery', ['id' => (int) $row['id']]),
            'public_url' => gallery_public_url($row),
        ];
    }

    // Variable $images stores this steps working value.
    $images = [];
    // Variable $imageStmt stores this steps working value.
    $imageStmt = db()->prepare("SELECT DISTINCT i.id, i.relative_path, i.filename, i.gallery_id, g.title AS gallery_title, g.slug AS gallery_slug
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
 */
function normalize_existing_tags(): int
{
    // Variable $changed stores this steps working value.
    $changed = 0;
    // Variable $rows stores this steps working value.
    $rows = db()->query('SELECT * FROM tags ORDER BY id')->fetchAll();
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
