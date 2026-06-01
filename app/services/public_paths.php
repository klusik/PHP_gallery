<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/public_paths.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
    return array_values(array_map(
        static fn (array $entry): string => (string) $entry['loc'],
        array_filter(
            public_sitemap_entries(),
            static fn (array $entry): bool => (string) ($entry['type'] ?? '') === 'gallery'
        )
    ));
}

/**
 * Return crawler-facing sitemap entries for public galleries and public images.
 *
 * Each gallery entry may carry a conservative image sitemap payload so Google
 * Image Search can discover strong thumbnails, titles, captions, and canonical
 * image detail URLs without crawling every lightbox interaction first.
 */
function public_sitemap_entries(): array
{
    $entries = [public_homepage_sitemap_entry()];
    foreach (public_sitemap_gallery_rows() as $gallery) {
        if (gallery_access_schema_ready() && gallery_access_requirement($gallery) !== null) {
            continue;
        }
        $images = public_sitemap_gallery_images($gallery, 50);
        $entries[] = [
            'type' => 'gallery',
            'loc' => gallery_public_url($gallery),
            'lastmod' => public_sitemap_lastmod(public_sitemap_gallery_last_modified($gallery, $images)),
            'priority' => public_sitemap_gallery_priority($gallery),
            'images' => public_sitemap_image_payloads($gallery, $images),
        ];
        foreach ($images as $image) {
            $entries[] = [
                'type' => 'image',
                'loc' => image_public_url($image, $gallery),
                'lastmod' => public_sitemap_lastmod(public_sitemap_image_last_modified($image)),
                'priority' => '0.5',
                'images' => public_sitemap_image_payloads($gallery, [$image]),
            ];
        }
    }
    return public_sitemap_unique_entries($entries);
}

/**
 * Build the homepage sitemap entry using the newest public gallery timestamp.
 */
function public_homepage_sitemap_entry(): array
{
    $lastModified = null;
    try {
        $lastModified = (string) db()->query("SELECT MAX(updated_at) FROM galleries WHERE visibility = 'public'")->fetchColumn();
    } catch (Throwable) {
        $lastModified = null;
    }
    return [
        'type' => 'home',
        'loc' => public_base_url() . '/',
        'lastmod' => public_sitemap_lastmod($lastModified),
        'priority' => '1.0',
        'images' => [],
    ];
}

/**
 * Fetch public galleries in their stable path order.
 */
function public_sitemap_gallery_rows(): array
{
    $columns = gallery_access_schema_ready() ? '*' : 'id, parent_id, folder_path, slug, title, description, visibility, cover_image_id, created_at, updated_at';
    $stmt = db()->query("SELECT $columns
        FROM galleries
        WHERE visibility = 'public'
        ORDER BY folder_path");
    return $stmt->fetchAll();
}

/**
 * Fetch public images suitable for sitemap output for one gallery.
 */
function public_sitemap_gallery_images(array $gallery, int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT *
        FROM images
        WHERE gallery_id = ? AND visibility = ?
        ORDER BY sort_order, filename
        LIMIT ' . $limit);
    $stmt->execute([(int) $gallery['id'], 'public']);
    $images = [];
    foreach ($stmt->fetchAll() as $image) {
        if (function_exists('image_nsfw_restricted') && image_nsfw_restricted($image, $gallery)) {
            continue;
        }
        $images[] = $image;
    }
    return $images;
}

/**
 * Convert public images into Google image sitemap payloads.
 */
function public_sitemap_image_payloads(array $gallery, array $images): array
{
    $payloads = [];
    $position = 1;
    foreach ($images as $image) {
        $payloads[] = [
            'loc' => absolute_public_url(thumbnail_url($image, 1200, 'jpg')),
            'title' => public_sitemap_clean_text(image_alt_text($image, $gallery, $position)),
            'caption' => public_sitemap_clean_text(public_sitemap_image_caption($image, $gallery, $position)),
        ];
        $position++;
    }
    return $payloads;
}

/**
 * Build a meaningful image caption from the image description and gallery context.
 */
function public_sitemap_image_caption(array $image, array $gallery, int $position): string
{
    $description = trim((string) ($image['description'] ?? ''));
    if ($description !== '') {
        return $description;
    }
    $title = trim((string) ($image['title'] ?? ''));
    if ($title !== '') {
        return $title . ' - ' . gallery_seo_title($gallery);
    }
    return image_alt_text($image, $gallery, $position) . ' - ' . gallery_seo_title($gallery);
}

/**
 * Return the strongest known last modified value for one gallery URL.
 */
function public_sitemap_gallery_last_modified(array $gallery, array $images): ?string
{
    $values = [
        (string) ($gallery['updated_at'] ?? ''),
        (string) ($gallery['created_at'] ?? ''),
    ];
    foreach ($images as $image) {
        $values[] = public_sitemap_image_last_modified($image);
    }
    $values = array_filter($values, static fn (?string $value): bool => trim((string) $value) !== '');
    if (!$values) {
        return null;
    }
    rsort($values, SORT_STRING);
    return $values[0];
}

/**
 * Return the strongest known last modified value for one image URL.
 */
function public_sitemap_image_last_modified(array $image): ?string
{
    foreach (['updated_at', 'modified_at', 'created_at'] as $column) {
        $value = trim((string) ($image[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

/**
 * Format a SQL or ISO timestamp as an XML sitemap date.
 */
function public_sitemap_lastmod(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }
    return gmdate('Y-m-d', $timestamp);
}

/**
 * Assign a light priority hint by gallery depth.
 */
function public_sitemap_gallery_priority(array $gallery): string
{
    $path = trim((string) ($gallery['url_path'] ?? $gallery['folder_path'] ?? ''), '/');
    if ($path === '') {
        return '0.8';
    }
    $depth = substr_count($path, '/') + 1;
    if ($depth <= 1) {
        return '0.8';
    }
    if ($depth <= 3) {
        return '0.7';
    }
    return '0.6';
}

/**
 * Normalize sitemap text fields and keep them compact.
 */
function public_sitemap_clean_text(string $value): string
{
    $value = trim(strip_tags($value));
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 300);
    }
    return substr($value, 0, 300);
}

/**
 * Remove duplicate sitemap URLs while preserving their first occurrence.
 */
function public_sitemap_unique_entries(array $entries): array
{
    $seen = [];
    $unique = [];
    foreach ($entries as $entry) {
        $loc = (string) ($entry['loc'] ?? '');
        if ($loc === '' || isset($seen[$loc])) {
            continue;
        }
        $seen[$loc] = true;
        $unique[] = $entry;
    }
    return $unique;
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
