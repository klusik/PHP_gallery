<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_lookup.php
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

namespace Gallery\Services;

use function Gallery\Core\normalize_relative_path;
use function Gallery\Models\gallery_model_public_root_rows;
use function Gallery\Models\gallery_model_branch_descendant_rows;
use function Gallery\Models\gallery_model_child_rows_for_parents;
use function Gallery\Models\gallery_model_descendant_rows_for_roots;
use function Gallery\Models\gallery_model_find_by_folder_path_hash;
use function Gallery\Models\gallery_model_find_by_id;
use function Gallery\Models\gallery_model_find_by_slug;
use function Gallery\Models\image_model_counts_for_galleries;
use function Gallery\Models\image_model_find_by_id;
use function Gallery\Models\image_model_find_by_path_hash;
use function Gallery\Models\image_model_rows_for_gallery;

/**
Read-oriented gallery and image lookup helpers.
 *
 * This module groups database lookups used by public pages and admin pages.
 * The functions preserve their original SQL and return shapes so templates and
 * controllers can continue using the same global function names.
 */

/**
 * Resolve public listing schema policy before model-owned gallery queries.
 *
 * @param bool $publicOnly Whether the caller is loading public-only rows.
 * @return bool True when public queries must require access_listing = listed.
 */
function gallery_lookup_require_listed_access(bool $publicOnly): bool
{
    if (!$publicOnly) {
        return false;
    }
    gallery_visibility_assert_public_policy_available();
    gallery_access_assert_public_policy_available();
    return gallery_access_schema_ready();
}

/**
 * Return the request-local cache used for direct child gallery lookups.
 *
 * @return array<string,array<int,array<string,mixed>>> Cached rows keyed by parent and visibility mode.
 */
function &child_galleries_request_cache(): array
{
    static $cache = [];
    return $cache;
}

/**
 * Return the request-local cache key for one child-gallery lookup.
 *
 * @param int $parentId Parent id identifier.
 * @param bool $publicOnly Public only value.
 * @return string Text result for the caller.
 */
function child_galleries_cache_key(int $parentId, bool $publicOnly): string
{
    return $parentId . ':' . ($publicOnly ? '1' : '0');
}

/**
 * Store one direct child-gallery lookup in the request-local cache.
 *
 * @param int $parentId Parent id identifier.
 * @param bool $publicOnly Public only value.
 * @param array $children Child gallery rows for this parent.
 * @return array Structured result data for the caller.
 */
function child_galleries_cache_store(int $parentId, bool $publicOnly, array $children): array
{
    // $cache stores direct child-gallery rows reused by card cover and collage lookups.
    $cache = &child_galleries_request_cache();
    return $cache[child_galleries_cache_key($parentId, $publicOnly)] = array_values($children);
}

/**
 * Return direct child galleries for many parents and warm the single-parent cache.
 *
 * Public gallery cards often need child rows while resolving card collages. A
 * batched lookup avoids one database round-trip for each visible card and each
 * recursive collage branch.
 *
 * @param array $parentIds Parent gallery identifiers.
 * @param bool $publicOnly Public only value.
 * @return array<int,array<int,array<string,mixed>>> Child rows keyed by parent id.
 */
function child_galleries_preload(array $parentIds, bool $publicOnly): array
{
    // $parentIds stores sanitized parent ids requested by the caller.
    $parentIds = array_values(array_unique(array_filter(array_map('intval', $parentIds), static fn (int $parentId): bool => $parentId > 0)));
    if (!$parentIds) {
        return [];
    }

    // $cache stores direct child-gallery rows already discovered during this request.
    $cache = &child_galleries_request_cache();
    // $childrenByParent stores the final child rows keyed by parent id.
    $childrenByParent = [];
    // $missingParentIds stores parent ids that still need the batched query.
    $missingParentIds = [];
    foreach ($parentIds as $parentId) {
        // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
        $cacheKey = child_galleries_cache_key($parentId, $publicOnly);
        if (array_key_exists($cacheKey, $cache)) {
            $childrenByParent[$parentId] = $cache[$cacheKey];
            continue;
        }
        $childrenByParent[$parentId] = [];
        $missingParentIds[] = $parentId;
    }

    if (!$missingParentIds) {
        return $childrenByParent;
    }

    $requireListedAccess = gallery_lookup_require_listed_access($publicOnly);
    $rows = public_render_profile_db(
        'child_galleries_batch_db',
        static fn (): array => gallery_model_child_rows_for_parents($missingParentIds, $publicOnly, $requireListedAccess)
    );
    foreach ($rows as $child) {
        // $parentId stores the direct parent for the fetched child row.
        $parentId = (int) ($child['parent_id'] ?? 0);
        if (!in_array($parentId, $missingParentIds, true)) {
            continue;
        }
        $childrenByParent[$parentId][] = $child;
    }

    foreach ($missingParentIds as $parentId) {
        child_galleries_cache_store($parentId, $publicOnly, $childrenByParent[$parentId] ?? []);
    }
    return $childrenByParent;
}

/**
 * Warm direct child-gallery rows for every gallery below the requested roots.
 *
 * Collage cover resolution walks child galleries recursively. Preloading the
 * visible branch tree lets that existing recursive logic reuse cached rows
 * instead of issuing one database query per branch node.
 *
 * @param array $rootIds Root gallery identifiers.
 * @param bool $publicOnly Public only value.
 */
function child_galleries_tree_preload(array $rootIds, bool $publicOnly): void
{
    // $rootIds stores sanitized root ids requested by the caller.
    $rootIds = array_values(array_unique(array_filter(array_map('intval', $rootIds), static fn (int $rootId): bool => $rootId > 0)));
    if (!$rootIds) {
        return;
    }

    // $cache stores direct child-gallery rows already discovered during this request.
    $cache = &child_galleries_request_cache();
    // $missingRootIds stores roots that are not already represented in the child cache.
    $missingRootIds = [];
    foreach ($rootIds as $rootId) {
        if (!array_key_exists(child_galleries_cache_key($rootId, $publicOnly), $cache)) {
            $missingRootIds[] = $rootId;
        }
    }
    if (!$missingRootIds) {
        return;
    }

    $requireListedAccess = gallery_lookup_require_listed_access($publicOnly);
    $rows = public_render_profile_db(
        'child_galleries_tree_batch_db',
        static fn (): array => gallery_model_descendant_rows_for_roots($missingRootIds, $publicOnly, $requireListedAccess)
    );

    // $childrenByParent stores direct child rows keyed by parent id for the preloaded tree.
    $childrenByParent = [];
    foreach ($missingRootIds as $rootId) {
        $childrenByParent[$rootId] = [];
    }
    foreach ($rows as $gallery) {
        if ($publicOnly && !visitor_can_access_gallery($gallery)) {
            continue;
        }
        // $galleryId stores the descendant gallery id.
        $galleryId = (int) ($gallery['id'] ?? 0);
        // $parentId stores the direct parent id for this descendant.
        $parentId = (int) ($gallery['parent_id'] ?? 0);
        if ($galleryId <= 0 || $parentId <= 0) {
            continue;
        }
        $childrenByParent[$galleryId] ??= [];
        $childrenByParent[$parentId] ??= [];
        $childrenByParent[$parentId][] = $gallery;
    }

    foreach ($childrenByParent as $parentId => $children) {
        child_galleries_cache_store((int) $parentId, $publicOnly, $children);
    }
}

/**
 * Return direct child galleries for a parent gallery.
 *
 * @param int $parentId Parent id identifier.
 * @param bool $publicOnly Public only value.
 * @return array Structured result data for the caller.
 */
function child_galleries(int $parentId, bool $publicOnly): array
{
    // $cache stores direct child-gallery rows already discovered during this request.
    $cache = &child_galleries_request_cache();
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = child_galleries_cache_key($parentId, $publicOnly);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    // $childrenByParent stores the batched result for this single parent compatibility path.
    $childrenByParent = child_galleries_preload([$parentId], $publicOnly);
    return $childrenByParent[$parentId] ?? [];
}

/**
 * Walk from a gallery to its root ancestors for breadcrumb rendering.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 * @return array Structured result data for the caller.
 */
function gallery_ancestors(array $gallery, bool $publicOnly): array
{
    static $cache = [];
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = (int) $gallery['id'] . ':' . ($publicOnly ? '1' : '0');
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // Variable $ancestors stores this steps working value.
    $ancestors = [];
    // Variable $parentId stores this steps working value.
    $parentId = $gallery['parent_id'] ?? null;
    while ($parentId) {
        // Variable $parent stores this steps working value.
        $parent = find_gallery((int) $parentId);
        if (!$parent) {
            break;
        }
        if (!$publicOnly || gallery_is_public_listed($parent)) {
            array_unshift($ancestors, $parent);
        }
        // Variable $parentId stores this steps working value.
        $parentId = $parent['parent_id'] ?? null;
    }
    return $cache[$cacheKey] = $ancestors;
}

/**
 * Walk from a gallery to its root ancestors for breadcrumb display.
 *
 * Breadcrumbs describe the structural gallery path. They intentionally include
 * unpublished ancestors because those galleries can still be opened by direct
 * URL and must not disappear from the path shown for a public child gallery.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array Structured result data for the caller.
 */
function gallery_breadcrumb_ancestors(array $gallery): array
{
    return gallery_ancestors($gallery, false);
}

/**
 * Count visible images in one gallery branch.
 *
 * Public gallery cards are usually summaries of a whole folder branch. Counting
 * descendants makes parent/subgallery cards less misleading when a gallery node
 * contains only nested subgalleries and no direct pictures of its own.
 *
 * @param int $galleryId Gallery identifier.
 * @param bool $publicOnly Public only value.
 * @return int Integer result for the caller.
 */
function gallery_branch_image_count(int $galleryId, bool $publicOnly): int
{
    // $counts stores the batch count result reused by the single-gallery compatibility wrapper.
    $counts = gallery_branch_image_counts([$galleryId], $publicOnly);
    return (int) ($counts[$galleryId] ?? 0);
}

/**
 * Count visible images for many gallery branches in one batched lookup.
 *
 * Public listing pages render several gallery cards at once. Using one branch
 * lookup per card causes repeated subtree discovery and image COUNT queries.
 * This helper keeps the same visibility/access semantics while collapsing the
 * work into one descendant-gallery query and one grouped image-count query.
 *
 * @param array $galleryIds Gallery identifiers to count.
 * @param bool $publicOnly Public only value.
 * @return array<int, int> Image counts keyed by root gallery id.
 */
function gallery_branch_image_counts(array $galleryIds, bool $publicOnly): array
{
    static $cache = [];
    // $galleryIds stores an intermediate value used by the surrounding gallery workflow.
    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $galleryId): bool => $galleryId > 0)));
    if (!$galleryIds) {
        return [];
    }

    // $counts stores the final branch image counts keyed by requested gallery id.
    $counts = [];
    // $missingIds stores gallery ids not yet available in the request-local cache.
    $missingIds = [];
    foreach ($galleryIds as $galleryId) {
        // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
        $cacheKey = $galleryId . ':' . ($publicOnly ? '1' : '0');
        if (array_key_exists($cacheKey, $cache)) {
            $counts[$galleryId] = $cache[$cacheKey];
            continue;
        }
        $counts[$galleryId] = 0;
        $missingIds[] = $galleryId;
    }
    if (!$missingIds) {
        return $counts;
    }

    $requireListedAccess = gallery_lookup_require_listed_access($publicOnly);
    $descendantRows = gallery_model_branch_descendant_rows($missingIds, $publicOnly, $requireListedAccess);

    // $rootIdsByGallery stores reverse membership so one image count can serve every requested root.
    $rootIdsByGallery = [];
    foreach ($descendantRows as $row) {
        // $rootId stores the requested root gallery id for this descendant row.
        $rootId = (int) ($row['root_id'] ?? 0);
        // $descendantId stores the descendant gallery id that can contain images.
        $descendantId = (int) ($row['id'] ?? 0);
        if ($rootId <= 0 || $descendantId <= 0) {
            continue;
        }
        if ($publicOnly && !visitor_can_access_gallery($row)) {
            continue;
        }
        $rootIdsByGallery[$descendantId][$rootId] = $rootId;
    }

    // $countableGalleryIds stores every descendant gallery that needs one grouped image count.
    $countableGalleryIds = array_keys($rootIdsByGallery);
    $directCounts = image_model_counts_for_galleries($countableGalleryIds, $publicOnly);
    foreach ($directCounts as $descendantId => $imageCount) {
        foreach (($rootIdsByGallery[(int) $descendantId] ?? []) as $rootId) {
            $counts[$rootId] = (int) ($counts[$rootId] ?? 0) + (int) $imageCount;
        }
    }

    foreach ($missingIds as $galleryId) {
        // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
        $cacheKey = $galleryId . ':' . ($publicOnly ? '1' : '0');
        $cache[$cacheKey] = (int) ($counts[$galleryId] ?? 0);
        $counts[$galleryId] = $cache[$cacheKey];
    }

    return $counts;
}

/**
 * Fetch one gallery by numeric ID.
 *
 * @param int $id Identifier value.
 * @param bool $fresh Fresh value.
 * @return ?array Structured result data for the caller.
 */
function find_gallery(int $id, bool $fresh = false): ?array
{
    static $cache = [];

    if (!$fresh && array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    return $cache[$id] = gallery_model_find_by_id($id);
}

/**
 * Fetch one gallery by its public URL slug.
 *
 * @param string $slug Slug value.
 * @return ?array Structured result data for the caller.
 */
function find_gallery_by_slug(string $slug): ?array
{
    static $cache = [];

    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }

    return $cache[$slug] = gallery_model_find_by_slug($slug);
}

/**
 * Fetch one gallery by normalized folder path.
 *
 * @param string $folderPath Folder path filesystem path.
 * @param bool $fresh True to bypass the per-request lookup cache after mutations.
 * @return ?array Structured result data for the caller.
 */
function find_gallery_by_folder_path(string $folderPath, bool $fresh = false): ?array
{
    static $cache = [];

    // $normalizedPath stores an intermediate value used by the surrounding gallery workflow.
    $normalizedPath = normalize_relative_path($folderPath);
    if (!$fresh && array_key_exists($normalizedPath, $cache)) {
        return $cache[$normalizedPath];
    }

    return $cache[$normalizedPath] = gallery_model_find_by_folder_path_hash(hash('sha256', $normalizedPath));
}

/**
 * Find the nearest already-imported parent folder for a gallery path.
 *
 * @param string $folderPath Folder path filesystem path.
 * @return ?array Structured result data for the caller.
 */
function find_parent_gallery_for_path(string $folderPath): ?array
{
    // Variable $segments stores this steps working value.
    $segments = explode('/', normalize_relative_path($folderPath));
    while (count($segments) > 1) {
        array_pop($segments);
        // Variable $parent stores this steps working value.
        $parent = find_gallery_by_folder_path(implode('/', $segments));
        if ($parent) {
            return $parent;
        }
    }
    return null;
}

/**
 * Fetch one image by numeric ID.
 *
 * @param int $id Identifier value.
 * @param bool $fresh Bypass cached ownership after a same-process mutation.
 * @return ?array Structured result data for the caller.
 */
function find_image(int $id, bool $fresh = false): ?array
{
    static $cache = [];

    if (!$fresh && array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    return $cache[$id] = image_model_find_by_id($id);
}

/**
 * Fetch one image by gallery and normalized relative image path.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $relativePath Relative path filesystem path.
 * @return ?array Structured result data for the caller.
 */
function find_image_by_path(int $galleryId, string $relativePath): ?array
{
    static $cache = [];

    // $normalizedPath stores an intermediate value used by the surrounding gallery workflow.
    $normalizedPath = normalize_relative_path($relativePath);
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $galleryId . '|' . $normalizedPath;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $image = image_model_find_by_path_hash($galleryId, hash('sha256', $normalizedPath));
    if ($image !== null) {
        $cache[$cacheKey] = $image;
        return $cache[$cacheKey];
    }
    return null;
}

/**
 * Fetch images for admin/public rendering, optionally public-only.
 *
 * @param int $galleryId Gallery identifier.
 * @param bool $publicOnly Public only value.
 * @return array Structured result data for the caller.
 */
function gallery_images(int $galleryId, bool $publicOnly): array
{
    return image_model_rows_for_gallery($galleryId, $publicOnly);
}

/**
 * Return public physical root galleries for the home page.
 *
 * @return array<int,array<string,mixed>> Public root gallery rows with direct public image counts.
 */
function public_home_physical_galleries(): array
{
    gallery_visibility_assert_public_policy_available();
    gallery_access_assert_public_policy_available();
    return gallery_model_public_root_rows(gallery_access_schema_ready());
}

/**
 * Resolve physical-card context policy before requesting its model-owned count.
 *
 * @param int $parentGalleryId Positive parent ID, or zero for public root cards.
 * @return int Full physical gallery count for canonical mutation postconditions.
 */
function gallery_mutation_context_count(int $parentGalleryId): int
{
    if ($parentGalleryId <= 0) {
        gallery_visibility_assert_public_policy_available();
        gallery_access_assert_public_policy_available();
    }
    return \Gallery\Models\gallery_model_mutation_context_count(
        $parentGalleryId,
        $parentGalleryId <= 0 && gallery_access_schema_ready()
    );
}
