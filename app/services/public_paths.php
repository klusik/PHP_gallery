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
    // $accessReady stores an intermediate value used by the surrounding gallery workflow.
    $accessReady = gallery_access_schema_ready();
    // $columns stores an intermediate value used by the surrounding gallery workflow.
    $columns = $accessReady ? '*' : 'folder_path, slug, visibility';
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->query("SELECT $columns
        FROM galleries
        WHERE visibility = 'public'
        ORDER BY folder_path");
    // $urls stores an intermediate value used by the surrounding gallery workflow.
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
    // $normalizedPath stores an intermediate value used by the surrounding gallery workflow.
    $normalizedPath = trim(str_replace('\\', '/', rawurldecode($publicPath)), '/');
    // $normalizedPath stores an intermediate value used by the surrounding gallery workflow.
    $normalizedPath = preg_replace('#/+#', '/', $normalizedPath) ?: '';

    if ($normalizedPath === '') {
        return ['gallery' => null, 'image' => null];
    }

    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery_by_public_path($normalizedPath);
    if ($gallery) {
        return ['gallery' => $gallery, 'image' => null];
    }

    // $segments stores an intermediate value used by the surrounding gallery workflow.
    $segments = explode('/', $normalizedPath);
    // $imageSlug stores an intermediate value used by the surrounding gallery workflow.
    $imageSlug = array_pop($segments);
    // $galleryPath stores an intermediate value used by the surrounding gallery workflow.
    $galleryPath = implode('/', $segments);
    if ($imageSlug === null || $galleryPath === '') {
        return ['gallery' => null, 'image' => null];
    }

    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery_by_public_path($galleryPath);
    if (!$gallery) {
        return ['gallery' => null, 'image' => null];
    }

    // $image stores an intermediate value used by the surrounding gallery workflow.
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

    // $normalizedPath stores an intermediate value used by the surrounding gallery workflow.
    $normalizedPath = trim(str_replace('\\', '/', rawurldecode($publicPath)), '/');
    // $normalizedPath stores an intermediate value used by the surrounding gallery workflow.
    $normalizedPath = preg_replace('#/+#', '/', $normalizedPath) ?: '';
    if ($normalizedPath === '') {
        return null;
    }
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $normalizedPath;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (public_path_schema_ready()) {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare('SELECT * FROM galleries WHERE url_path_hash = ?');
        $stmt->execute([hash('sha256', $normalizedPath)]);
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = $stmt->fetch();
        if ($gallery) {
            return $cache[$cacheKey] = $gallery;
        }
    }

    try {
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = find_gallery_by_folder_path($normalizedPath);
        if ($gallery) {
            return $cache[$cacheKey] = $gallery;
        }
    } catch (RuntimeException) {
    }

    // $gallery stores an intermediate value used by the surrounding gallery workflow.
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

    // $cleanSlug stores an intermediate value used by the surrounding gallery workflow.
    $cleanSlug = slugify(rawurldecode($slug));
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $galleryId . '|' . $cleanSlug;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    if (public_path_schema_ready()) {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? AND url_slug = ?');
        $stmt->execute([$galleryId, $cleanSlug]);
        // $image stores an intermediate value used by the surrounding gallery workflow.
        $image = $stmt->fetch();
        if ($image) {
            return $cache[$cacheKey] = $image;
        }
    }

    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    foreach ($stmt->fetchAll() as $image) {
        // $candidate stores an intermediate value used by the surrounding gallery workflow.
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
    // $prefix stores an intermediate value used by the surrounding gallery workflow.
    $prefix = $alias . '.';
    // $sql stores an intermediate value used by the surrounding gallery workflow.
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
        // $ready stores an intermediate value used by the surrounding gallery workflow.
        $ready = db_column_exists('galleries', 'url_slug')
            && db_column_exists('galleries', 'url_path')
            && db_column_exists('galleries', 'url_path_hash')
            && db_column_exists('images', 'url_slug');
    } catch (Throwable $exception) {
        // $ready stores an intermediate value used by the surrounding gallery workflow.
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

    // $pdo stores an intermediate value used by the surrounding gallery workflow.
    $pdo = db();
    $pdo->beginTransaction();

    try {
        // $galleryCount stores an intermediate value used by the surrounding gallery workflow.
        $galleryCount = regenerate_gallery_public_paths($pdo);
        // $imageCount stores an intermediate value used by the surrounding gallery workflow.
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
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = $pdo->query('SELECT id, parent_id, title, folder_path, slug FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    // $galleries stores an intermediate value used by the surrounding gallery workflow.
    $galleries = $stmt->fetchAll();
    // $byId stores an intermediate value used by the surrounding gallery workflow.
    $byId = [];
    // $childrenByParent stores an intermediate value used by the surrounding gallery workflow.
    $childrenByParent = [];

    foreach ($galleries as $gallery) {
        // $galleryId stores an intermediate value used by the surrounding gallery workflow.
        $galleryId = (int) $gallery['id'];
        // $parentId stores an intermediate value used by the surrounding gallery workflow.
        $parentId = $gallery['parent_id'] !== null ? (int) $gallery['parent_id'] : 0;
        $byId[$galleryId] = $gallery;
        $childrenByParent[$parentId][] = $gallery;
    }

    // $pathsById stores an intermediate value used by the surrounding gallery workflow.
    $pathsById = [];
    // $slugById stores an intermediate value used by the surrounding gallery workflow.
    $slugById = [];
    // $visited stores an intermediate value used by the surrounding gallery workflow.
    $visited = [];
    // $count stores an intermediate value used by the surrounding gallery workflow.
    $count = 0;

    // $buildPath stores an intermediate value used by the surrounding gallery workflow.
    $buildPath = static function (array $gallery) use (&$buildPath, &$pathsById, &$slugById, &$visited, $byId, $childrenByParent): string {
        // $galleryId stores an intermediate value used by the surrounding gallery workflow.
        $galleryId = (int) $gallery['id'];
        if (isset($pathsById[$galleryId])) {
            return $pathsById[$galleryId];
        }
        if (isset($visited[$galleryId])) {
            // $fallback stores an intermediate value used by the surrounding gallery workflow.
            $fallback = slugify((string) ($gallery['slug'] ?: $gallery['title'] ?: basename((string) $gallery['folder_path'])));
            $slugById[$galleryId] = $fallback;
            return $pathsById[$galleryId] = $fallback;
        }

        $visited[$galleryId] = true;
        // $parentId stores an intermediate value used by the surrounding gallery workflow.
        $parentId = $gallery['parent_id'] !== null ? (int) $gallery['parent_id'] : 0;
        // $siblings stores an intermediate value used by the surrounding gallery workflow.
        $siblings = $childrenByParent[$parentId] ?? [$gallery];
        // $usedSlugs stores an intermediate value used by the surrounding gallery workflow.
        $usedSlugs = [];

        foreach ($siblings as $sibling) {
            // $siblingId stores an intermediate value used by the surrounding gallery workflow.
            $siblingId = (int) $sibling['id'];
            if (isset($slugById[$siblingId])) {
                $usedSlugs[$slugById[$siblingId]] = true;
                continue;
            }

            // $baseName stores an intermediate value used by the surrounding gallery workflow.
            $baseName = basename(str_replace('\\', '/', trim((string) $sibling['folder_path'], '/')));
            // $base stores an intermediate value used by the surrounding gallery workflow.
            $base = (string) ($sibling['title'] ?: $baseName ?: $sibling['slug'] ?: 'gallery');
            // $slug stores an intermediate value used by the surrounding gallery workflow.
            $slug = unique_public_slug_in_set($base, $usedSlugs);
            $slugById[$siblingId] = $slug;
            $usedSlugs[$slug] = true;
        }

        // $slug stores an intermediate value used by the surrounding gallery workflow.
        $slug = $slugById[$galleryId] ?? slugify((string) ($gallery['title'] ?: $gallery['slug'] ?: 'gallery'));
        if ($parentId > 0 && isset($byId[$parentId])) {
            // $parentPath stores an intermediate value used by the surrounding gallery workflow.
            $parentPath = $buildPath($byId[$parentId]);
            // $path stores an intermediate value used by the surrounding gallery workflow.
            $path = trim($parentPath . '/' . $slug, '/');
        } else {
            // $path stores an intermediate value used by the surrounding gallery workflow.
            $path = $slug;
        }

        $pathsById[$galleryId] = $path;
        unset($visited[$galleryId]);
        return $path;
    };

    // $update stores an intermediate value used by the surrounding gallery workflow.
    $update = $pdo->prepare('UPDATE galleries SET url_slug = ?, url_path = ?, url_path_hash = ?, updated_at = ? WHERE id = ?');
    foreach ($galleries as $gallery) {
        // $galleryId stores an intermediate value used by the surrounding gallery workflow.
        $galleryId = (int) $gallery['id'];
        // $path stores an intermediate value used by the surrounding gallery workflow.
        $path = $buildPath($gallery);
        // $slug stores an intermediate value used by the surrounding gallery workflow.
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
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = $pdo->query('SELECT id, gallery_id, title, filename FROM images ORDER BY gallery_id, sort_order, filename, id');
    // $images stores an intermediate value used by the surrounding gallery workflow.
    $images = $stmt->fetchAll();
    // $usedByGallery stores an intermediate value used by the surrounding gallery workflow.
    $usedByGallery = [];
    // $update stores an intermediate value used by the surrounding gallery workflow.
    $update = $pdo->prepare('UPDATE images SET url_slug = ?, updated_at = ? WHERE id = ?');
    // $count stores an intermediate value used by the surrounding gallery workflow.
    $count = 0;

    foreach ($images as $image) {
        // $galleryId stores an intermediate value used by the surrounding gallery workflow.
        $galleryId = (int) $image['gallery_id'];
        if (!isset($usedByGallery[$galleryId])) {
            $usedByGallery[$galleryId] = [];
        }

        // $baseName stores an intermediate value used by the surrounding gallery workflow.
        $baseName = pathinfo((string) $image['filename'], PATHINFO_FILENAME);
        // $base stores an intermediate value used by the surrounding gallery workflow.
        $base = (string) ($image['title'] ?: $baseName ?: 'image');
        // $slug stores an intermediate value used by the surrounding gallery workflow.
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
    // $base stores an intermediate value used by the surrounding gallery workflow.
    $base = slugify($value);
    if ($base === '') {
        // $base stores an intermediate value used by the surrounding gallery workflow.
        $base = 'item';
    }

    // $slug stores an intermediate value used by the surrounding gallery workflow.
    $slug = $base;
    // $counter stores an intermediate value used by the surrounding gallery workflow.
    $counter = 2;
    while (isset($usedSlugs[$slug])) {
        // $slug stores an intermediate value used by the surrounding gallery workflow.
        $slug = $base . '-' . $counter;
        $counter++;
    }

    return $slug;
}
