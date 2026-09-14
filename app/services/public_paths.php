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
 *   2026-07-12
 */

declare(strict_types=1);

namespace Gallery\Services;

use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\absolute_public_url;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\gallery_seo_title;
use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_url;
use function Gallery\Core\now_sql;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\public_base_url;
use function Gallery\Core\slugify;
use function Gallery\Models\public_path_model_apply_full_regeneration;
use function Gallery\Models\public_path_model_apply_parent_assignments;
use function Gallery\Models\public_path_model_apply_gallery_regeneration;
use function Gallery\Models\public_path_model_apply_image_slugs;
use function Gallery\Models\public_path_model_find_gallery_by_path_hash;
use function Gallery\Models\public_path_model_find_image_by_slug;
use function Gallery\Models\public_path_model_gallery_image_regeneration_rows;
use function Gallery\Models\public_path_model_gallery_images;
use function Gallery\Models\public_path_model_gallery_regeneration_rows;
use function Gallery\Models\public_path_model_image_regeneration_rows;
use function Gallery\Models\public_path_model_public_gallery_max_updated_at;
use function Gallery\Models\public_path_model_public_gallery_rows;
use function Gallery\Models\public_path_model_public_images;
use function Gallery\Models\gallery_model_public_listing_filter_sql;

/**
Public URL path and slug helpers.
 *
 * This module owns clean public gallery paths, image slugs, sitemap entries, and
 * regeneration logic. It does not render public pages and does not touch theme
 * settings or custom assets.
 */

/**
 * Return all public gallery sitemap URLs in filesystem order.
 *
 * @return array Structured result data for the caller.
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
 *
 * @return array Structured result data for the caller.
 */
function public_sitemap_entries(): array
{
    // Sitemap generation is public policy and must not query visibility/access state before it is verified.
    gallery_visibility_assert_public_policy_available();
    gallery_access_assert_public_policy_available();
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
 *
 * @return array Structured result data for the caller.
 */
function public_homepage_sitemap_entry(): array
{
    $lastModified = null;
    try {
        $lastModified = public_path_model_public_gallery_max_updated_at();
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
 *
 * @return array Structured result data for the caller.
 */
function public_sitemap_gallery_rows(): array
{
    gallery_visibility_assert_public_policy_available();
    gallery_access_assert_public_policy_available();
    return public_path_model_public_gallery_rows(gallery_access_schema_ready());
}

/**
 * Fetch public images suitable for sitemap output for one gallery.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param int $limit Maximum number of items.
 * @return array Structured result data for the caller.
 */
function public_sitemap_gallery_images(array $gallery, int $limit = 50): array
{
    $images = [];
    foreach (public_path_model_public_images((int) $gallery['id'], $limit) as $image) {
        if (function_exists('Gallery\\Services\\image_nsfw_restricted') && image_nsfw_restricted($image, $gallery)) {
            continue;
        }
        $images[] = $image;
    }
    return $images;
}

/**
 * Convert public images into Google image sitemap payloads.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $images Images value.
 * @return array Structured result data for the caller.
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
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param int $position Position value.
 * @return string Text result for the caller.
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
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $images Images value.
 * @return ?string Text result for the caller.
 */
function public_sitemap_gallery_last_modified(array $gallery, array $images): ?string
{
    $values = public_sitemap_gallery_filesystem_dates($gallery);
    foreach (public_sitemap_gallery_freshness_images($gallery, $images) as $image) {
        $values[] = public_sitemap_image_last_modified($image, $gallery);
    }
    $values = array_filter($values, static fn (?string $value): bool => trim((string) $value) !== '');
    if (!$values) {
        return public_sitemap_newest_date([
            (string) ($gallery['updated_at'] ?? ''),
            (string) ($gallery['created_at'] ?? ''),
        ]);
    }
    return public_sitemap_newest_date($values);
}

/**
 * Return public images used only for gallery freshness calculation.
 *
 * The visible image sitemap payload intentionally stays capped, but the gallery
 * URL lastmod should still reflect newer public photos that are not part of the
 * first payload set.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $seedImages Seed images value.
 * @return array Structured result data for the caller.
 */
function public_sitemap_gallery_freshness_images(array $gallery, array $seedImages): array
{
    try {
        $images = [];
        foreach (public_path_model_public_images((int) $gallery['id']) as $image) {
            if (function_exists('Gallery\\Services\\image_nsfw_restricted') && image_nsfw_restricted($image, $gallery)) {
                continue;
            }
            $images[] = $image;
        }
        return $images;
    } catch (Throwable) {
        return $seedImages;
    }
}

/**
 * Return filesystem-derived dates that can make one gallery URL fresh.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array Structured result data for the caller.
 */
function public_sitemap_gallery_filesystem_dates(array $gallery): array
{
    $values = [];
    try {
        $galleryPath = gallery_abs_path((string) ($gallery['folder_path'] ?? ''));
        $values[] = public_sitemap_file_lastmod($galleryPath);
        $values[] = public_sitemap_file_lastmod($galleryPath . DIRECTORY_SEPARATOR . 'gallery.json');
    } catch (Throwable) {
        return [];
    }
    return $values;
}

/**
 * Return the strongest known last modified value for one image URL.
 *
 * @param array $image Image row or image data.
 * @param ?array $gallery Gallery row or gallery data.
 * @return ?string Text result for the caller.
 */
function public_sitemap_image_last_modified(array $image, ?array $gallery = null): ?string
{
    if ($gallery !== null) {
        try {
            $fileDate = public_sitemap_file_lastmod(image_abs_path($image, $gallery));
            if ($fileDate !== null) {
                return $fileDate;
            }
        } catch (Throwable) {
            // Fall back to database dates below when the original file cannot be resolved.
        }
    }

    foreach (['modified_at', 'updated_at', 'created_at'] as $column) {
        $value = trim((string) ($image[$column] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

/**
 * Return a sitemap-compatible date for one filesystem path.
 *
 * @param string $path Filesystem path.
 * @return ?string Text result for the caller.
 */
function public_sitemap_file_lastmod(string $path): ?string
{
    if ($path === '' || !file_exists($path)) {
        return null;
    }
    $mtime = @filemtime($path);
    if ($mtime === false) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $mtime);
}

/**
 * Return the newest valid date string from mixed SQL, ISO, and filesystem values.
 *
 * @param array $values Values value.
 * @return ?string Text result for the caller.
 */
function public_sitemap_newest_date(array $values): ?string
{
    $newestTimestamp = null;
    foreach ($values as $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            continue;
        }
        if ($newestTimestamp === null || $timestamp > $newestTimestamp) {
            $newestTimestamp = $timestamp;
        }
    }
    if ($newestTimestamp === null) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $newestTimestamp);
}

/**
 * Format a SQL or ISO timestamp as an XML sitemap date.
 *
 * @param ?string $value Value to process.
 * @return ?string Text result for the caller.
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
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
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
 *
 * @param string $value Value to process.
 * @return string Text result for the caller.
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
 *
 * @param array $entries Entries value.
 * @return array Structured result data for the caller.
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
 *
 * @param string $publicPath Public path filesystem path.
 * @param bool $publicOnly Public only value.
 * @return array Structured result data for the caller.
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
 *
 * @param string $publicPath Public path filesystem path.
 * @return ?array Structured result data for the caller.
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
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = public_path_model_find_gallery_by_path_hash(hash('sha256', $normalizedPath));
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
 *
 * @param int $galleryId Gallery identifier.
 * @param string $slug Slug value.
 * @return ?array Structured result data for the caller.
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
        // $image stores an intermediate value used by the surrounding gallery workflow.
        $image = public_path_model_find_image_by_slug($galleryId, $cleanSlug);
        if ($image) {
            return $cache[$cacheKey] = $image;
        }
    }

    foreach (public_path_model_gallery_images($galleryId) as $image) {
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
 *
 * @param string $alias Alias value.
 * @return string A hardcoded SQL fragment safe for interpolation — MUST NOT contain any user-derived values.
 * @internal
 */
function public_gallery_listing_sql_fragment(string $alias = 'g'): string
{
    gallery_visibility_assert_public_policy_available();
    gallery_access_assert_public_policy_available();
    return ltrim(gallery_model_public_listing_filter_sql($alias, true, gallery_access_schema_ready()), ' AND');
}

/**
 * Check whether the clean public path database columns are available.
 *
 * @return bool True when the condition matches.
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
 *
 * @return array Structured result data for the caller.
 */
function regenerate_public_paths(): array
{
    if (!public_path_schema_ready()) {
        throw new RuntimeException('Clean public path columns are missing. Run database migrations first.');
    }

    $galleryRows = public_path_model_gallery_regeneration_rows();
    $parentAssignments = gallery_parent_id_assignments_from_folder_paths($galleryRows);
    if (function_exists(__NAMESPACE__ . '\\smart_gallery_validate_gallery_parent_map')) {
        smart_gallery_validate_gallery_parent_map($parentAssignments);
    }
    $pathAssignments = gallery_public_path_assignments($galleryRows);
    $imageAssignments = image_public_slug_assignments(public_path_model_image_regeneration_rows());
    $result = public_path_model_apply_full_regeneration(
        $galleryRows,
        $parentAssignments,
        $pathAssignments,
        $imageAssignments,
        now_sql()
    );

    if ($result['parent_changed'] > 0 && function_exists(__NAMESPACE__ . '\\smart_gallery_graph_cache_clear')) {
        smart_gallery_graph_cache_clear();
    }

    return [
        'galleries' => $result['galleries'],
        'images' => $result['images'],
    ];
}

/**
 * Refresh clean public gallery paths without rebuilding image slugs.
 *
 * Gallery creation, title changes, and hierarchy moves use this narrower path
 * because image URL slugs are unaffected. The helper participates in an
 * existing transaction when called by a larger operation and otherwise owns a
 * short transaction around the complete path rebuild.
 *
 * @return int Number of gallery rows refreshed.
 */
function refresh_gallery_public_paths(): int
{
    if (!public_path_schema_ready()) {
        return 0;
    }

    $galleryRows = public_path_model_gallery_regeneration_rows();
    $parentAssignments = gallery_parent_id_assignments_from_folder_paths($galleryRows);
    if (function_exists(__NAMESPACE__ . '\\smart_gallery_validate_gallery_parent_map')) {
        smart_gallery_validate_gallery_parent_map($parentAssignments);
    }
    $pathAssignments = gallery_public_path_assignments($galleryRows);
    $result = public_path_model_apply_gallery_regeneration(
        $galleryRows,
        $parentAssignments,
        $pathAssignments,
        now_sql()
    );
    if ($result['parent_changed'] > 0 && function_exists(__NAMESPACE__ . '\\smart_gallery_graph_cache_clear')) {
        smart_gallery_graph_cache_clear();
    }
    return $result['galleries'];
}

/**
 * Build deterministic clean URL assignments for gallery rows.
 *
 * The function is database-independent so hierarchy, transliteration, sibling
 * collisions, and legacy filesystem names can be tested directly. Returned
 * assignments are keyed by gallery id and contain the final local slug and
 * complete hierarchical public path.
 *
 * @param array<int,array<string,mixed>> $galleries Gallery rows.
 * @return array<int,array{slug:string,path:string}> Assignments keyed by gallery id.
 */
function gallery_public_path_assignments(array $galleries): array
{
    $byId = [];
    $folderPathById = [];
    foreach ($galleries as $gallery) {
        $galleryId = (int) ($gallery['id'] ?? 0);
        if ($galleryId <= 0) {
            continue;
        }
        $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
        $byId[$galleryId] = $gallery;
        $folderPathById[$galleryId] = $folderPath;
    }

    $parentIdById = gallery_parent_id_assignments_from_folder_paths($galleries);
    $childrenByParent = [];
    foreach ($byId as $galleryId => $gallery) {
        $folderPath = $folderPathById[$galleryId] ?? '';
        $physicalParentPath = gallery_folder_parent_path($folderPath);
        if ($physicalParentPath !== '') {
            // Physical folder nesting is the canonical hierarchy. Keeping this
            // path as the sibling key also handles historical rows whose
            // parent_id was null or stale when a public-path repair ran.
            $siblingKey = 'path:' . $physicalParentPath;
        } else {
            $siblingKey = 'parent:' . (int) ($parentIdById[$galleryId] ?? 0);
        }
        $childrenByParent[$siblingKey][] = $gallery;
    }

    // Assign sibling slugs once in stable row order so duplicate titles receive
    // deterministic numeric suffixes regardless of which branch is built first.
    $slugById = [];
    foreach ($childrenByParent as $siblings) {
        $usedSlugs = [];
        foreach ($siblings as $sibling) {
            $siblingId = (int) ($sibling['id'] ?? 0);
            $baseName = basename(str_replace('\\', '/', trim((string) ($sibling['folder_path'] ?? ''), '/')));
            $base = (string) (($sibling['title'] ?? '') ?: $baseName ?: ($sibling['slug'] ?? '') ?: 'gallery');
            $slug = unique_public_slug_in_set($base, $usedSlugs);
            $slugById[$siblingId] = $slug;
            $usedSlugs[$slug] = true;
        }
    }

    $pathsById = [];
    $visiting = [];
    $buildPath = static function (int $galleryId) use (
        &$buildPath,
        &$pathsById,
        &$visiting,
        $byId,
        $folderPathById,
        $parentIdById,
        $slugById
    ): string {
        if (isset($pathsById[$galleryId])) {
            return $pathsById[$galleryId];
        }
        $gallery = $byId[$galleryId] ?? null;
        if (!$gallery) {
            return 'gallery';
        }
        if (isset($visiting[$galleryId])) {
            // Broken historical parent cycles must not make URL maintenance hang.
            return $pathsById[$galleryId] = $slugById[$galleryId] ?? 'gallery';
        }

        $visiting[$galleryId] = true;
        $slug = $slugById[$galleryId] ?? 'gallery';
        $parentId = (int) ($parentIdById[$galleryId] ?? 0);
        if ($parentId > 0 && isset($byId[$parentId]) && $parentId !== $galleryId) {
            $path = trim($buildPath($parentId) . '/' . $slug, '/');
        } else {
            // When an intermediate gallery row is absent, preserve every real
            // folder ancestor in the public URL instead of collapsing the leaf
            // gallery to a root URL. Existing ancestor rows still use their
            // titles through the recursive branch above.
            $physicalParentPath = gallery_folder_parent_path($folderPathById[$galleryId] ?? '');
            $prefix = gallery_public_path_from_folder_segments($physicalParentPath);
            $path = trim(($prefix !== '' ? $prefix . '/' : '') . $slug, '/');
        }
        unset($visiting[$galleryId]);
        return $pathsById[$galleryId] = $path;
    };

    $assignments = [];
    foreach (array_keys($byId) as $galleryId) {
        $assignments[$galleryId] = [
            'slug' => $slugById[$galleryId] ?? 'gallery',
            'path' => $buildPath($galleryId),
        ];
    }
    return $assignments;
}

/**
 * Return canonical parent assignments derived from stored filesystem paths.
 *
 * Folder nesting is the source of truth used by gallery moves and hierarchy
 * synchronization. This pure helper deliberately does not trust parent_id when
 * an immediate parent folder row exists, because older imports and interrupted
 * repairs can leave that column null or stale.
 *
 * @param array<int,array<string,mixed>> $galleries Gallery rows.
 * @return array<int,int|null> Parent identifiers keyed by gallery id.
 */
function gallery_parent_id_assignments_from_folder_paths(array $galleries): array
{
    $byId = [];
    $folderPathById = [];
    $galleryIdByFolderPath = [];
    foreach ($galleries as $gallery) {
        $galleryId = (int) ($gallery['id'] ?? 0);
        if ($galleryId <= 0) {
            continue;
        }
        $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
        $byId[$galleryId] = $gallery;
        $folderPathById[$galleryId] = $folderPath;
        if ($folderPath !== '') {
            $galleryIdByFolderPath[$folderPath] = $galleryId;
        }
    }

    $assignments = [];
    foreach ($byId as $galleryId => $gallery) {
        $parentPath = gallery_folder_parent_path($folderPathById[$galleryId] ?? '');
        $parentId = $parentPath !== '' ? (int) ($galleryIdByFolderPath[$parentPath] ?? 0) : 0;
        if ($parentId === $galleryId) {
            $parentId = 0;
        }
        $assignments[$galleryId] = $parentId > 0 ? $parentId : null;
    }
    return $assignments;
}

/**
 * Return the normalized immediate parent folder for one gallery path.
 *
 * @param string $folderPath Gallery folder path.
 * @return string Parent folder path or an empty string for a root gallery.
 */
function gallery_folder_parent_path(string $folderPath): string
{
    $folderPath = normalize_relative_path($folderPath);
    if ($folderPath === '' || !str_contains($folderPath, '/')) {
        return '';
    }
    $segments = explode('/', $folderPath);
    array_pop($segments);
    return implode('/', $segments);
}

/**
 * Convert physical folder ancestors into a clean public-path prefix.
 *
 * This fallback is used only when an intermediate gallery row is missing. It
 * keeps the URL hierarchy intact while the normal hierarchy repair creates or
 * reconnects the missing row later.
 *
 * @param string $folderPath Folder path containing zero or more ancestors.
 * @return string Clean slash-separated public path.
 */
function gallery_public_path_from_folder_segments(string $folderPath): string
{
    $folderPath = normalize_relative_path($folderPath);
    if ($folderPath === '') {
        return '';
    }
    $segments = [];
    foreach (explode('/', $folderPath) as $segment) {
        $segments[] = slugify($segment);
    }
    return implode('/', $segments);
}

/**
 * Repair parent_id values from canonical filesystem nesting.
 *
 * Public-path regeneration calls this first so a migration or maintenance run
 * cannot produce a root-level URL merely because an older row has a missing or
 * stale parent_id value.
 *
 * @param PDO $pdo Database connection.
 * @return int Number of parent links changed.
 */
function repair_gallery_parent_ids_from_folder_paths(PDO $pdo): int
{
    $rows = public_path_model_gallery_regeneration_rows($pdo);
    $assignments = gallery_parent_id_assignments_from_folder_paths($rows);
    if (function_exists(__NAMESPACE__ . '\\smart_gallery_validate_gallery_parent_map')) {
        smart_gallery_validate_gallery_parent_map($assignments);
    }

    $changed = public_path_model_apply_parent_assignments($rows, $assignments, $pdo);
    if ($changed > 0 && function_exists(__NAMESPACE__ . '\\smart_gallery_graph_cache_clear')) {
        smart_gallery_graph_cache_clear();
    }
    return $changed;
}

/**
 * Regenerate clean URL path values for all galleries.
 *
 * @param PDO $pdo Database connection.
 * @return int Integer result for the caller.
 */
function regenerate_gallery_public_paths(PDO $pdo): int
{
    $galleries = public_path_model_gallery_regeneration_rows($pdo);
    $parentAssignments = gallery_parent_id_assignments_from_folder_paths($galleries);
    if (function_exists(__NAMESPACE__ . '\\smart_gallery_validate_gallery_parent_map')) {
        smart_gallery_validate_gallery_parent_map($parentAssignments);
    }
    $assignments = gallery_public_path_assignments($galleries);
    $result = public_path_model_apply_gallery_regeneration(
        $galleries,
        $parentAssignments,
        $assignments,
        now_sql(),
        $pdo
    );
    if ($result['parent_changed'] > 0 && function_exists(__NAMESPACE__ . '\\smart_gallery_graph_cache_clear')) {
        smart_gallery_graph_cache_clear();
    }
    return $result['galleries'];
}

/**
 * Regenerate clean URL slug values for all images.
 *
 * @param PDO $pdo Database connection.
 * @return int Integer result for the caller.
 */
/**
 * Regenerate clean URL slug values for images in one gallery only.
 *
 * Upload requests only change one gallery at a time. Rebuilding image slugs for
 * every gallery after each accepted upload package is unnecessarily expensive on
 * shared hosting, so this helper keeps the existing per-gallery uniqueness rule
 * without touching unrelated image rows.
 *
 * @param int $galleryId Gallery identifier.
 * @return int Number of image rows refreshed for the gallery.
 */
function regenerate_gallery_image_public_slugs(int $galleryId): int
{
    if (!public_path_schema_ready() || $galleryId <= 0) {
        return 0;
    }

    $images = public_path_model_gallery_image_regeneration_rows($galleryId);
    if (!$images) {
        return 0;
    }
    return public_path_model_apply_image_slugs(image_public_slug_assignments($images), now_sql());
}

/**
 * Regenerate unique public slugs for every image in gallery order.
 *
 * @param PDO $pdo Active application database connection.
 * @return int Number of image records assigned a public slug.
 */
function regenerate_image_public_slugs(PDO $pdo): int
{
    $images = public_path_model_image_regeneration_rows($pdo);
    return public_path_model_apply_image_slugs(image_public_slug_assignments($images), now_sql(), $pdo);
}

/**
 * Build deterministic gallery-local public image slug assignments.
 *
 * @param array<int,array<string,mixed>> $images Image rows in gallery/display order.
 * @return array<int,string> Slugs keyed by image id.
 */
function image_public_slug_assignments(array $images): array
{
    $usedByGallery = [];
    $assignments = [];
    foreach ($images as $image) {
        $imageId = (int) ($image['id'] ?? 0);
        $galleryId = (int) ($image['gallery_id'] ?? 0);
        if ($imageId <= 0 || $galleryId <= 0) {
            continue;
        }
        if (!isset($usedByGallery[$galleryId])) {
            $usedByGallery[$galleryId] = [];
        }
        $baseName = pathinfo((string) ($image['filename'] ?? ''), PATHINFO_FILENAME);
        $base = (string) (($image['title'] ?? '') ?: $baseName ?: 'image');
        $slug = unique_public_slug_in_set($base, $usedByGallery[$galleryId]);
        $usedByGallery[$galleryId][$slug] = true;
        $assignments[$imageId] = $slug;
    }
    return $assignments;
}

/**
 * Generate a unique clean slug within a caller-provided used-slug set.
 *
 * @param string $value Value to process.
 * @param array $usedSlugs Used slugs value.
 * @return string Text result for the caller.
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
