<?php

declare(strict_types=1);

/**
Public URL path and slug helpers.
 *
 * This module owns clean public gallery paths, image slugs, sitemap entries, and
 * regeneration logic. It does not render public pages and does not touch theme
 * settings or custom assets.
 */

/**
 * Return all public gallery sitemap URLs in filesystem order.
 */
function public_gallery_sitemap_entries(): array
{
    $accessReady = gallery_access_schema_ready();
    $columns = $accessReady ? '*' : 'folder_path, slug, visibility';
    $stmt = db()->query("SELECT $columns
        FROM galleries
        WHERE visibility = 'public'
        ORDER BY folder_path");
    $urls = [];
    foreach ($stmt->fetchAll() as $gallery) {
        if ($accessReady && gallery_access_requirement($gallery) !== null) {
            continue;
        }
        $urls[] = gallery_public_url($gallery);
    }
    return array_values(array_unique($urls));
}

/**
 * Resolve a clean public gallery or image path into database records.
 */
function resolve_public_gallery_path(string $publicPath, bool $publicOnly = true): array
{
    $normalizedPath = trim(str_replace('\\', '/', rawurldecode($publicPath)), '/');
    $normalizedPath = preg_replace('#/+#', '/', $normalizedPath) ?: '';

    if ($normalizedPath === '') {
        return ['gallery' => null, 'image' => null];
    }

    $gallery = find_gallery_by_public_path($normalizedPath);
    if ($gallery) {
        return ['gallery' => $gallery, 'image' => null];
    }

    $segments = explode('/', $normalizedPath);
    $imageSlug = array_pop($segments);
    $galleryPath = implode('/', $segments);
    if ($imageSlug === null || $galleryPath === '') {
        return ['gallery' => null, 'image' => null];
    }

    $gallery = find_gallery_by_public_path($galleryPath);
    if (!$gallery) {
        return ['gallery' => null, 'image' => null];
    }

    $image = find_image_by_public_slug((int) $gallery['id'], (string) $imageSlug);
    if ($image && (!$publicOnly || (string) $image['visibility'] === 'public')) {
        return ['gallery' => $gallery, 'image' => $image];
    }

    return ['gallery' => $gallery, 'image' => null];
}

/**
 * Fetch one gallery by its preferred clean public URL path, with legacy fallbacks.
 */
function find_gallery_by_public_path(string $publicPath): ?array
{
    static $cache = [];

    $normalizedPath = trim(str_replace('\\', '/', rawurldecode($publicPath)), '/');
    $normalizedPath = preg_replace('#/+#', '/', $normalizedPath) ?: '';
    if ($normalizedPath === '') {
        return null;
    }
    $cacheKey = $normalizedPath;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (public_path_schema_ready()) {
        $stmt = db()->prepare('SELECT * FROM galleries WHERE url_path_hash = ?');
        $stmt->execute([hash('sha256', $normalizedPath)]);
        $gallery = $stmt->fetch();
        if ($gallery) {
            return $cache[$cacheKey] = $gallery;
        }
    }

    try {
        $gallery = find_gallery_by_folder_path($normalizedPath);
        if ($gallery) {
            return $cache[$cacheKey] = $gallery;
        }
    } catch (RuntimeException) {
    }

    $gallery = find_gallery_by_slug($normalizedPath);
    $cache[$cacheKey] = $gallery ?: null;
    return $cache[$cacheKey];
}

/**
 * Fetch one image by its clean slug inside a gallery, with legacy filename fallback.
 */
function find_image_by_public_slug(int $galleryId, string $slug): ?array
{
    static $cache = [];

    $cleanSlug = slugify(rawurldecode($slug));
    $cacheKey = $galleryId . '|' . $cleanSlug;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (public_path_schema_ready()) {
        $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? AND url_slug = ?');
        $stmt->execute([$galleryId, $cleanSlug]);
        $image = $stmt->fetch();
        if ($image) {
            return $cache[$cacheKey] = $image;
        }
    }

    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    foreach ($stmt->fetchAll() as $image) {
        $candidate = slugify((string) ($image['url_slug'] ?: pathinfo((string) $image['filename'], PATHINFO_FILENAME)));
        if ($candidate === $cleanSlug) {
            return $cache[$cacheKey] = $image;
        }
    }

    $cache[$cacheKey] = null;
    return null;
}

/**
 * SQL condition used by public gallery listing queries.
 */
function public_gallery_listing_condition(string $alias = 'g'): string
{
    $prefix = $alias . '.';
    $sql = $prefix . "visibility = 'public'";
    if (gallery_access_schema_ready()) {
        $sql .= ' AND ' . $prefix . "access_listing = 'listed'";
    }
    return $sql;
}

/**
 * Check whether the clean public path database columns are available.
 */
function public_path_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    try {
        $ready = db_column_exists('galleries', 'url_slug')
            && db_column_exists('galleries', 'url_path')
            && db_column_exists('galleries', 'url_path_hash')
            && db_column_exists('images', 'url_slug');
    } catch (Throwable $exception) {
        $ready = false;
    }

    return $ready;
}

/**
 * Regenerate clean public gallery and image URL paths from current titles and filenames.
 */
function regenerate_public_paths(): array
{
    if (!public_path_schema_ready()) {
        throw new RuntimeException('Clean public path columns are missing. Run database migrations first.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $galleryCount = regenerate_gallery_public_paths($pdo);
        $imageCount = regenerate_image_public_slugs($pdo);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'galleries' => $galleryCount,
        'images' => $imageCount,
    ];
}

/**
 * Regenerate clean URL path values for all galleries.
 */
function regenerate_gallery_public_paths(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT id, parent_id, title, folder_path, slug FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $galleries = $stmt->fetchAll();
    $byId = [];
    $childrenByParent = [];

    foreach ($galleries as $gallery) {
        $galleryId = (int) $gallery['id'];
        $parentId = $gallery['parent_id'] !== null ? (int) $gallery['parent_id'] : 0;
        $byId[$galleryId] = $gallery;
        $childrenByParent[$parentId][] = $gallery;
    }

    $pathsById = [];
    $slugById = [];
    $visited = [];
    $count = 0;

    $buildPath = static function (array $gallery) use (&$buildPath, &$pathsById, &$slugById, &$visited, $byId, $childrenByParent): string {
        $galleryId = (int) $gallery['id'];
        if (isset($pathsById[$galleryId])) {
            return $pathsById[$galleryId];
        }
        if (isset($visited[$galleryId])) {
            $fallback = slugify((string) ($gallery['slug'] ?: $gallery['title'] ?: basename((string) $gallery['folder_path'])));
            $slugById[$galleryId] = $fallback;
            return $pathsById[$galleryId] = $fallback;
        }

        $visited[$galleryId] = true;
        $parentId = $gallery['parent_id'] !== null ? (int) $gallery['parent_id'] : 0;
        $siblings = $childrenByParent[$parentId] ?? [$gallery];
        $usedSlugs = [];

        foreach ($siblings as $sibling) {
            $siblingId = (int) $sibling['id'];
            if (isset($slugById[$siblingId])) {
                $usedSlugs[$slugById[$siblingId]] = true;
                continue;
            }

            $baseName = basename(str_replace('\\', '/', trim((string) $sibling['folder_path'], '/')));
            $base = (string) ($sibling['title'] ?: $baseName ?: $sibling['slug'] ?: 'gallery');
            $slug = unique_public_slug_in_set($base, $usedSlugs);
            $slugById[$siblingId] = $slug;
            $usedSlugs[$slug] = true;
        }

        $slug = $slugById[$galleryId] ?? slugify((string) ($gallery['title'] ?: $gallery['slug'] ?: 'gallery'));
        if ($parentId > 0 && isset($byId[$parentId])) {
            $parentPath = $buildPath($byId[$parentId]);
            $path = trim($parentPath . '/' . $slug, '/');
        } else {
            $path = $slug;
        }

        $pathsById[$galleryId] = $path;
        unset($visited[$galleryId]);
        return $path;
    };

    $update = $pdo->prepare('UPDATE galleries SET url_slug = ?, url_path = ?, url_path_hash = ?, updated_at = ? WHERE id = ?');
    foreach ($galleries as $gallery) {
        $galleryId = (int) $gallery['id'];
        $path = $buildPath($gallery);
        $slug = $slugById[$galleryId] ?? basename($path);
        $update->execute([
            $slug,
            $path,
            hash('sha256', $path),
            now_sql(),
            $galleryId,
        ]);
        $count++;
    }

    return $count;
}

/**
 * Regenerate clean URL slug values for all images.
 */
function regenerate_image_public_slugs(PDO $pdo): int
{
    $stmt = $pdo->query('SELECT id, gallery_id, title, filename FROM images ORDER BY gallery_id, sort_order, filename, id');
    $images = $stmt->fetchAll();
    $usedByGallery = [];
    $update = $pdo->prepare('UPDATE images SET url_slug = ?, updated_at = ? WHERE id = ?');
    $count = 0;

    foreach ($images as $image) {
        $galleryId = (int) $image['gallery_id'];
        if (!isset($usedByGallery[$galleryId])) {
            $usedByGallery[$galleryId] = [];
        }

        $baseName = pathinfo((string) $image['filename'], PATHINFO_FILENAME);
        $base = (string) ($image['title'] ?: $baseName ?: 'image');
        $slug = unique_public_slug_in_set($base, $usedByGallery[$galleryId]);
        $usedByGallery[$galleryId][$slug] = true;

        $update->execute([
            $slug,
            now_sql(),
            (int) $image['id'],
        ]);
        $count++;
    }

    return $count;
}

/**
 * Generate a unique clean slug within a caller-provided used-slug set.
 */
function unique_public_slug_in_set(string $value, array $usedSlugs): string
{
    $base = slugify($value);
    if ($base === '') {
        $base = 'item';
    }

    $slug = $base;
    $counter = 2;
    while (isset($usedSlugs[$slug])) {
        $slug = $base . '-' . $counter;
        $counter++;
    }

    return $slug;
}
