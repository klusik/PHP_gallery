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

use const Gallery\Models\GALLERY_VISIT_NODE_LIMIT;
use const Gallery\Models\GALLERY_VISIT_IMAGE_LIMIT;
use const Gallery\Models\GALLERY_VISIT_MEMBER_LIMIT;
use const Gallery\Models\GALLERY_VISIT_LANE_LIMIT;
use const Gallery\Models\GALLERY_VISIT_SMART_LIMIT;
use const Gallery\Models\GALLERY_VISIT_SMART_BATCH;
use const Gallery\Models\GALLERY_VISIT_SCOPE_LIMIT;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Models\gallery_model_public_root_rows;
use function Gallery\Models\gallery_model_branch_descendant_rows;
use function Gallery\Models\gallery_model_attachment_usage_rows;
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
 * Read-oriented gallery and image lookup helpers.
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
 * @param list<array<string,int|float|string|bool|null>> $children Child gallery rows to store under the parent cache key.
 * @return list<array<string,int|float|string|bool|null>> Direct child gallery rows stored for this parent and visibility mode.
 */
function child_galleries_cache_store(
    int $parentId,
    bool $publicOnly,
    array $children,
): array {
    // $cache stores direct child-gallery rows reused by card cover and collage lookups.
    $cache = &child_galleries_request_cache();
    return $cache[child_galleries_cache_key($parentId, $publicOnly)] =
        array_values($children);
}

/**
 * Return direct child galleries for many parents and warm the single-parent cache.
 * Public gallery cards often need child rows while resolving card collages. A
 * batched lookup avoids one database round-trip for each visible card and each
 * recursive collage branch.
 *
 * @param list<int> $parentIds Parent gallery IDs whose child rows should be loaded.
 * @param bool $publicOnly Public only value.
 * @return array<int,list<array<string,int|float|string|bool|null>>> Direct child gallery rows keyed by requested parent gallery ID.
 */
function child_galleries_preload(array $parentIds, bool $publicOnly): array
{
    // $parentIds stores sanitized parent ids requested by the caller.
    $parentIds = array_values(array_unique(array_filter(
        array_map('intval', $parentIds),
        static fn(int $parentId): bool => $parentId > 0,
    )));
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
        static fn(): array => gallery_model_child_rows_for_parents(
            $missingParentIds,
            $publicOnly,
            $requireListedAccess,
        ),
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
        child_galleries_cache_store(
            $parentId,
            $publicOnly,
            $childrenByParent[$parentId] ?? [],
        );
    }
    return $childrenByParent;
}

/**
 * Warm direct child-gallery rows for every gallery below the requested roots.
 * Collage cover resolution walks child galleries recursively. Preloading the
 * visible branch tree lets that existing recursive logic reuse cached rows
 * instead of issuing one database query per branch node.
 *
 * @param list<int> $rootIds Root gallery IDs whose descendant rows should be loaded.
 * @param bool $publicOnly Public only value.
 * @return void Returns after recursively loading descendant child rows into the request-local cache.
 */
function child_galleries_tree_preload(array $rootIds, bool $publicOnly): void
{
    // $rootIds stores sanitized root ids requested by the caller.
    $rootIds = array_values(array_unique(array_filter(
        array_map('intval', $rootIds),
        static fn(int $rootId): bool => $rootId > 0,
    )));
    if (!$rootIds) {
        return;
    }

    // $cache stores direct child-gallery rows already discovered during this request.
    $cache = &child_galleries_request_cache();
    // $missingRootIds stores roots that are not already represented in the child cache.
    $missingRootIds = [];
    foreach ($rootIds as $rootId) {
        if (!array_key_exists(
            child_galleries_cache_key($rootId, $publicOnly),
            $cache,
        )) {
            $missingRootIds[] = $rootId;
        }
    }
    if (!$missingRootIds) {
        return;
    }

    $requireListedAccess = gallery_lookup_require_listed_access($publicOnly);
    $rows = public_render_profile_db(
        'child_galleries_tree_batch_db',
        static fn(): array => gallery_model_descendant_rows_for_roots(
            $missingRootIds,
            $publicOnly,
            $requireListedAccess,
        ),
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
 * @param bool $withoutAdminBypass Apply ordinary visitor access even for Admin sessions.
 * @return int Integer result for the caller.
 */
function gallery_branch_image_count(
    int $galleryId,
    bool $publicOnly,
    bool $withoutAdminBypass = false,
): int {
    // $counts stores the batch count result reused by the single-gallery compatibility wrapper.
    $counts = gallery_branch_image_counts(
        [$galleryId],
        $publicOnly,
        $withoutAdminBypass,
    );
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
 * @param list<int> $galleryIds Gallery identifiers to count.
 * @param bool $publicOnly Public only value.
 * @param bool $withoutAdminBypass Apply ordinary visitor access even for Admin sessions.
 * @return array<int, int> Image counts keyed by root gallery id.
 */
function gallery_branch_image_counts(
    array $galleryIds,
    bool $publicOnly,
    bool $withoutAdminBypass = false,
): array {
    static $cache = [];
    // $galleryIds stores an intermediate value used by the surrounding gallery workflow.
    $galleryIds = array_values(array_unique(array_filter(
        array_map('intval', $galleryIds),
        static fn(int $galleryId): bool => $galleryId > 0,
    )));
    if (!$galleryIds) {
        return [];
    }

    // $counts stores the final branch image counts keyed by requested gallery id.
    $counts = [];
    // $missingIds stores gallery ids not yet available in the request-local cache.
    $missingIds = [];
    foreach ($galleryIds as $galleryId) {
        // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
        $cacheKey =
            $galleryId
            . ':'
            . ($publicOnly ? '1' : '0')
            . ':'
            . ($withoutAdminBypass ? 'visitor' : 'request');
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
    $descendantRows = gallery_model_branch_descendant_rows(
        $missingIds,
        $publicOnly,
        $requireListedAccess,
    );

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
        if (
            $publicOnly
            && !(
                $withoutAdminBypass
                    ? visitor_can_access_gallery_without_admin_bypass($row)
                    : visitor_can_access_gallery($row)
            )
        ) {
            continue;
        }
        $rootIdsByGallery[$descendantId][$rootId] = $rootId;
    }

    // $countableGalleryIds stores every descendant gallery that needs one grouped image count.
    $countableGalleryIds = array_keys($rootIdsByGallery);
    $directCounts = image_model_counts_for_galleries(
        $countableGalleryIds,
        $publicOnly,
    );
    foreach ($directCounts as $descendantId => $imageCount) {
        foreach ($rootIdsByGallery[(int) $descendantId] ?? [] as $rootId) {
            $counts[$rootId] =
                (int) ($counts[$rootId] ?? 0) + (int) $imageCount;
        }
    }

    foreach ($missingIds as $galleryId) {
        // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
        $cacheKey =
            $galleryId
            . ':'
            . ($publicOnly ? '1' : '0')
            . ':'
            . ($withoutAdminBypass ? 'visitor' : 'request');
        $cache[$cacheKey] = (int) ($counts[$galleryId] ?? 0);
        $counts[$galleryId] = $cache[$cacheKey];
    }

    return $counts;
}

/** Count public PDF or music files once per distinct content digest per branch.
 * @param list<int> $galleryIds Root gallery identifiers.
 * @param string $kind Attachment kind: `pdf` or `music`.
 * @param bool $withoutAdminBypass Apply ordinary visitor access even for Admin sessions.
 * @return array<int,int> Counts keyed only for roots whose complete visible branch snapshot is ready; absent roots are unknown and must omit the badge.
 */
function gallery_branch_attachment_usage_counts(
    array $galleryIds,
    string $kind,
    bool $withoutAdminBypass = true,
): array {
    if (!in_array($kind, ['pdf', 'music'], true)) {
        return [];
    }
    $galleryIds = array_values(array_unique(array_filter(
        array_map('intval', $galleryIds),
        static fn(int $galleryId): bool => $galleryId > 0,
    )));
    if ($galleryIds === []) {
        return [];
    }
    $capability = $kind === 'pdf'
        ? 'gallery_pdf_documents'
        : 'gallery_music';
    if (
        !function_exists(__NAMESPACE__ . '\\feature_capability_effective_enabled')
        || !feature_capability_effective_enabled($capability)
    ) {
        return [];
    }
    if (
        !function_exists(
            __NAMESPACE__ . '\\presentation_gallery_attachment_usage_schema_status',
        )
        || !function_exists(
            __NAMESPACE__ . '\\presentation_schema_render_available',
        )
        || !presentation_schema_render_available(
            presentation_gallery_attachment_usage_schema_status(),
            'gallery.card_attachment_usage',
        )
    ) {
        return [];
    }
    if ($kind === 'music') {
        if (
            !function_exists(
                __NAMESPACE__ . '\\presentation_gallery_attachment_usage_music_schema_status',
            )
            || !presentation_schema_render_available(
                presentation_gallery_attachment_usage_music_schema_status(),
                'gallery.card_attachment_usage.music',
            )
        ) {
            return [];
        }
    }
    static $availabilityLogged = false;
    try {
        $descendants = gallery_model_branch_descendant_rows(
            $galleryIds,
            true,
            gallery_lookup_require_listed_access(true),
        );
        $memberships = [];
        $rootsByDescendant = [];
        $descendantIds = [];
        foreach ($descendants as $row) {
            if (
                $withoutAdminBypass
                    ? !visitor_can_access_gallery_without_admin_bypass($row)
                    : !visitor_can_access_gallery($row)
            ) {
                continue;
            }
            $rootId = (int) ($row['root_id'] ?? 0);
            $descendantId = (int) ($row['id'] ?? 0);
            if ($rootId <= 0 || $descendantId <= 0) {
                continue;
            }
            $memberships[$rootId][$descendantId] = true;
            $rootsByDescendant[$descendantId][$rootId] = true;
            $descendantIds[$descendantId] = $descendantId;
        }
        if ($descendantIds === []) {
            return [];
        }
        $projection = gallery_model_attachment_usage_rows(
            array_values($descendantIds),
            $kind,
        );
        $counts = [];
        foreach ($memberships as $rootId => $branchIds) {
            $ready = true;
            foreach (array_keys($branchIds) as $branchId) {
                if (($projection['states'][$branchId] ?? '') !== 'ready') {
                    $ready = false;
                    break;
                }
            }
            if ($ready) {
                $counts[(int) $rootId] = 0;
            }
        }
        if ($counts === []) {
            return [];
        }
        $digests = [];
        foreach ($projection['items'] as $item) {
            if ($item['hidden']) {
                continue;
            }
            $digest = $item['sha256'];
            if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                continue;
            }
            foreach ($rootsByDescendant[(int) $item['gallery_id']] ?? [] as $rootId => $_) {
                if (array_key_exists($rootId, $counts)) {
                    $digests[$rootId][$digest] = true;
                }
            }
        }
        foreach ($counts as $rootId => $_) {
            $counts[$rootId] = count($digests[$rootId] ?? []);
        }
        return $counts;
    } catch (\Throwable $error) {
        if (!$availabilityLogged) {
            error_log(
                'Gallery attachment usage projection is unavailable; '
                . 'optional card counts were omitted.',
            );
            $availabilityLogged = true;
        }
        return [];
    }
}

/**
 * Fetch one gallery by numeric ID.
 *
 * @param int $id Identifier value.
 * @param bool $fresh Fresh value.
 * @return GalleryDatabaseRow|null Raw gallery row, or null for a missing ID;
 *     the concrete persistence fields are documented in models/galleries.php.
 */
function find_gallery(int $id, bool $fresh = false): ?array
{
    $cache = &gallery_lookup_request_rows();

    if (!$fresh && array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    return $cache[$id] = gallery_model_find_by_id($id);
}

/**
 * Share the canonical request-local gallery row cache with batched readers.
 * @return array<int,GalleryDatabaseRow|null> Raw cached identities, never HTML.
 */
function &gallery_lookup_request_rows(): array
{
    static $rows = [];
    return $rows;
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
function find_gallery_by_folder_path(
    string $folderPath,
    bool $fresh = false,
): ?array {
    static $cache = [];

    // $normalizedPath stores an intermediate value used by the surrounding gallery workflow.
    $normalizedPath = normalize_relative_path($folderPath);
    if (!$fresh && array_key_exists($normalizedPath, $cache)) {
        return $cache[$normalizedPath];
    }

    return $cache[$normalizedPath] = gallery_model_find_by_folder_path_hash(hash(
        'sha256',
        $normalizedPath,
    ));
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

    $image = image_model_find_by_path_hash($galleryId, hash(
        'sha256',
        $normalizedPath,
    ));
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
 * Confirm a completely empty catalog for administrator onboarding.
 * Existing private, nested and disabled definitions also suppress the first-gallery prompt.
 * This bounded read is used only for an administrator on an empty public home page;
 * it intentionally considers stored Smart Galleries even when their feature is off.
 * @return bool True only after confirming no physical or Smart Gallery exists; unknown storage hides the prompt.
 */
function gallery_catalog_is_empty(): bool
{
    try {
        if (\Gallery\Models\gallery_model_has_any()) {
            return false;
        }
        $smartTable = schema_inspection_table('smart_galleries');
        if (schema_inspection_is_missing($smartTable)) {
            return true;
        }
        if (schema_inspection_is_available($smartTable)) {
            return !\Gallery\Models\smart_gallery_model_has_any();
        }
    } catch (\Throwable) {
        // A failed catalog read must never be presented as a confirmed empty installation.
    }
    admin_log_event(
        'warning',
        'gallery.onboarding.catalog_unavailable',
        'The gallery catalog could not be verified for onboarding.',
        ['capability' => 'gallery_catalog'],
    );
    return false;
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
        $parentGalleryId <= 0 && gallery_access_schema_ready(),
    );
}

/**
 * Read direct editor inventory through the image persistence owner.
 * @param int $galleryId Positive gallery identity.
 * @return array{total:int,revision:string} Cheap direct count and drift observation.
 */
function gallery_editor_image_inventory(int $galleryId): array
{
    return \Gallery\Models\image_model_editor_inventory($galleryId);
}

/**
 * Read one bounded direct-image page for an already authorized editor.
 * @param int $galleryId Positive gallery identity.
 * @param int $offset Nonnegative row offset.
 * @return list<array{id:int|string,gallery_id:int|string,relative_path:string,filename:string,visibility:string,sort_order:int|string,exif_taken_at?:string|null,title?:string|null,width?:int|string|null,height?:int|string|null,updated_at?:string|null,modified_at?:string|null,thumbnail_source_identity_version?:int|string|null,thumbnail_derivative_version?:int|string|null,thumbnail_min_size?:int|string|null,thumbnail_max_size?:int|string|null}> Stored image rows consumed by the existing Images presentation adapter.
 */
function gallery_editor_image_page(int $galleryId, int $offset): array
{
    // Bound server rendering and transport work for each editor batch.
    // Type: int; Units: rows; Scope: one authorized editor response;
    // Consumers: image_model_editor_page; Rationale: cap each fragment at 100 rows.
    $pageSize = 100;
    return \Gallery\Models\image_model_editor_page(
        $galleryId,
        $offset,
        $pageSize,
    );
}



/**
 * Refresh only identified authored resources and previously interrupted scopes.
 * This is called by content owners after their own committed operation, never
 * from a shared database callback, shared writer release, shutdown or public GET.
 * @param list<int> $galleryIds Changed physical sources or placement parents.
 * @param list<int> $smartIds Changed virtual definitions, empty for source edits.
 * @return void Publishes bounded scoped evidence; failure remains explicitly
 *     pending and does not undo the successfully authored source operation.
 */
function gallery_visit_content_update(array $galleryIds, array $smartIds = []): void
{
    try {
        if (!gallery_visit_content_storage_available()) {
            return;
        }
        $galleryIds = array_values(array_unique(array_filter(
            $galleryIds, static fn(int $id): bool => $id > 0,
        )));
        $smartIds = array_values(array_unique(array_filter(
            $smartIds, static fn(int $id): bool => $id > 0,
        )));
        if ($smartIds !== [] && smart_gallery_attachment_schema_ready()) {
            $galleryIds = array_merge($galleryIds,
                \Gallery\Models\smart_gallery_model_visit_parent_ids(
                    $smartIds, GALLERY_VISIT_NODE_LIMIT,
                ));
        }
        // A child's visibility or insertion also changes its parent's placement
        // membership. Resolve only the explicitly changed physical identities.
        foreach ($galleryIds as $id) {
            $row = find_gallery($id, true);
            if (is_array($row) && !empty($row['parent_id'])) {
                $galleryIds[] = (int) $row['parent_id'];
            }
        }
        $galleryIds = array_values(array_unique($galleryIds));
        if ($galleryIds !== [] || $smartIds !== []) {
            \Gallery\Models\gallery_visit_content_model_dirty($galleryIds, $smartIds);
        }
        if (\Gallery\Models\gallery_visit_content_model_transaction_active()) {
            // Compound content owners publish their explicitly pending scopes
            // after commit; do not expose an intermediate membership snapshot.
            return;
        }
        $pending = \Gallery\Models\gallery_visit_content_model_pending(
            GALLERY_VISIT_SCOPE_LIMIT,
        );
        // Make bounded progress even after a larger interrupted batch; the
        // remaining durable scopes keep New evidence unavailable until recovered.
        $pending = array_slice($pending, 0, GALLERY_VISIT_SCOPE_LIMIT);
        if ($pending === []) {
            return;
        }
        $galleryIds = [];
        $smartIds = [];
        foreach ($pending as $scope) {
            if (str_starts_with($scope, 'g:')) {
                $galleryIds[] = (int) substr($scope, 2);
            } elseif (str_starts_with($scope, 's:')) {
                $smartIds[] = (int) substr($scope, 2);
            }
        }
        gallery_visit_content_refresh($galleryIds, $smartIds);
    } catch (\Throwable) {
        // Keep interruption evidence durable. A later explicit content update
        // may recover it; ordinary visitors never write the server index.
        error_log('Scoped gallery content evidence remains pending.');
    }
}

/**
 * Inspect optional index schema independently of security/access readiness.
 * @return bool True for verified storage; missing/unknown safely omits New.
 */
function gallery_visit_content_storage_available(): bool
{
    return presentation_schema_render_available(
        presentation_gallery_visit_content_schema_status(),
        'gallery_visit_content.read',
    );
}

/**
 * Read bounded existing gallery owners and validated virtual placements.
 * @param bool $authoring Whether to retain Smart evidence while its UI is off.
 * @return array{galleries:array<int,GalleryDatabaseRow>,
 *     smart:array<int,array<string,scalar|null>>,
 *     edges:array<string,list<string>>,roots:list<string>}
 *     Private policy inventory; callers must authorize it before serialization.
 */
function gallery_visit_inventory(bool $authoring = false): array
{
    $rows = \Gallery\Models\gallery_visit_content_model_galleries(
        GALLERY_VISIT_NODE_LIMIT,
    );
    if (count($rows) > GALLERY_VISIT_NODE_LIMIT) {
        throw new \RuntimeException('Visit hierarchy exceeds its bound.');
    }
    $galleries = [];
    $edges = [];
    $roots = [];
    $cache = &gallery_lookup_request_rows();
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $galleries[$id] = $row;
        $cache[$id] = $row;
        if (gallery_is_public_listed($row)) {
            if (empty($row['parent_id'])) {
                $roots[] = 'g:' . $id;
            } else {
                $edges['g:' . (int) $row['parent_id']][] = 'g:' . $id;
            }
        }
    }
    $validChains = [];
    foreach (array_keys($galleries) as $id) {
        $path = [];
        $current = $id;
        while ($current > 0 && isset($galleries[$current])
            && !isset($validChains[$current]) && !isset($path[$current])) {
            $path[$current] = true;
            $current = (int) ($galleries[$current]['parent_id'] ?? 0);
        }
        $valid = $current === 0 || ($validChains[$current] ?? false);
        foreach ($path as $visited => $_unused) {
            $validChains[$visited] = $valid;
        }
    }
    foreach ($validChains as $id => $valid) {
        if (!$valid) {
            unset($galleries[$id]);
        }
    }
    $smart = [];
    if (($authoring || feature_capability_effective_enabled('smart_galleries'))
        && smart_gallery_schema_ready() && smart_gallery_attachment_schema_ready()) {
        $inventory = \Gallery\Models\smart_gallery_model_visit_inventory(
            GALLERY_VISIT_NODE_LIMIT,
        );
        if (count($inventory['definitions']) > GALLERY_VISIT_SMART_LIMIT
            || count($inventory['placements']) > GALLERY_VISIT_NODE_LIMIT) {
            throw new \RuntimeException('Visit placements exceed their bound.');
        }
        foreach ($inventory['definitions'] as $row) {
            try {
                smart_gallery_assert_runtime_safe($row);
                $smart[(int) $row['id']] = $row;
                if ($row['placement_mode'] === 'root') {
                    $roots[] = 's:' . (int) $row['id'];
                }
            } catch (\InvalidArgumentException) {
                // The canonical Smart owner excludes invalid/cyclic definitions.
            }
        }
        foreach ($inventory['placements'] as $placement) {
            $id = (int) $placement['smart_gallery_id'];
            if (isset($smart[$id]) && $smart[$id]['placement_mode'] === 'gallery') {
                $edges['g:' . (int) $placement['gallery_id']][] = 's:' . $id;
            }
        }
    }
    return compact('galleries', 'smart', 'edges', 'roots');
}

/**
 * Admit one unique content identity into a bounded authoring snapshot.
 * @param array<string,array{node:string,item:string,lane:string}> $members
 *     Mutable identities keyed by node/item/lane, with no visitor information.
 * @param string $node Stable namespaced gallery identity.
 * @param string $item Stable image, digest or child identity.
 * @param string $lane Source/NSFW authorization lane or direct child edge.
 * @return void Adds the identity or throws before exceeding the snapshot bound.
 */
function gallery_visit_content_add(
    array &$members, string $node, string $item, string $lane,
): void {
    $members[$node . '|' . $item . '|' . $lane] = compact('node', 'item', 'lane');
    if (count($members) > GALLERY_VISIT_MEMBER_LIMIT) {
        throw new \RuntimeException('Visit membership exceeds its bound.');
    }
}

/**
 * Reconcile only the specified sources/definitions after content authoring.
 * The whole-site seed is reserved for the explicit migration repair callback.
 * @param list<int> $galleryIds Changed physical sources and placement parents.
 * @param list<int> $smartIds Changed virtual definitions requiring full membership.
 * @param bool $rebuild Whether the migration explicitly requested initial seeding.
 * @return bool True after publication; false for unavailable or concurrently
 *     changed storage. Failures retain the durable dirty state.
 */
function gallery_visit_content_refresh(
    array $galleryIds = [],
    array $smartIds = [],
    bool $rebuild = false,
): bool
{
    if (!gallery_visit_content_storage_available()) {
        return false;
    }
    $state = \Gallery\Models\gallery_visit_content_model_state();
    $inventory = gallery_visit_inventory(true);
    if ($rebuild) {
        $galleryIds = array_keys($inventory['galleries']);
        $smartIds = array_keys($inventory['smart']);
    }
    $members = [];
    $images = \Gallery\Models\gallery_visit_content_model_images(
        $galleryIds, GALLERY_VISIT_IMAGE_LIMIT,
    );
    if (count($images) > GALLERY_VISIT_IMAGE_LIMIT) {
        throw new \RuntimeException('Visit images exceed their bound.');
    }
    foreach ($images as $image) {
        $source = (int) $image['gallery_id'];
        gallery_visit_content_add($members, 'g:' . $source,
            'i:' . (int) $image['id'], 'm:' . $source . ':1');
        gallery_visit_content_add($members, 'g:' . $source,
            'i:' . (int) $image['id'], 'm:' . $source . ':'
                . ((int) $image['nsfw'] === 1 ? '2' : '0'));
    }
    $listedIds = [];
    foreach ($inventory['galleries'] as $id => $row) {
        if (gallery_is_public_listed($row)) {
            $listedIds[] = $id;
        }
    }
    $fullDefinitions = [];
    $sourceDefinitions = [];
    foreach ($inventory['smart'] as $id => $row) {
        $definition = ['id' => $id, 'rules' => smart_gallery_rules_from_json(
            (string) $row['rules_json'],
        )];
        if (in_array($id, $smartIds, true)) {
            $fullDefinitions[] = $definition;
        } else {
            $sourceDefinitions[] = $definition;
        }
    }
    $virtualSources = [];
    $groups = [
        ['definitions' => $fullDefinitions, 'sources' => $listedIds],
        ['definitions' => $sourceDefinitions,
            'sources' => array_values(array_intersect($listedIds, $galleryIds))],
    ];
    foreach ($groups as $group) {
        foreach (array_chunk($group['definitions'], GALLERY_VISIT_SMART_BATCH) as $batch) {
            if ($group['sources'] === []) {
                continue;
            }
            $rows = \Gallery\Models\smart_gallery_model_visit_members(
                $batch, $group['sources'], GALLERY_VISIT_MEMBER_LIMIT,
            );
            if (count($rows) > GALLERY_VISIT_MEMBER_LIMIT) {
                throw new \RuntimeException('Virtual membership exceeds its bound.');
            }
            foreach ($rows as $row) {
                $source = (int) $row['gallery_id'];
                $node = 's:' . (int) $row['smart_id'];
                gallery_visit_content_add($members, $node, 'i:' . (int) $row['id'],
                    'm:' . $source . ':1');
                gallery_visit_content_add($members, $node, 'i:' . (int) $row['id'],
                    'm:' . $source . ':' . ((int) $row['nsfw'] === 1 ? '2' : '0'));
                $virtualSources[$source][$node] = $node;
            }
        }
    }
    $attachmentIds = array_values(array_unique(array_merge(
        $galleryIds, array_keys($virtualSources),
    )));
    \Gallery\Models\gallery_visit_content_model_assert_attachment_bound($attachmentIds);
    foreach (['pdf', 'music'] as $kind) {
        if ($attachmentIds === []) {
            continue;
        }
        $usage = gallery_model_attachment_usage_rows($attachmentIds, $kind);
        foreach ($usage['states'] as $status) {
            if ($status !== 'ready') {
                throw new \RuntimeException('Attachment membership is not ready.');
            }
        }
        foreach ($usage['items'] as $item) {
            if ($item['hidden'] || !is_string($item['sha256'])) {
                continue;
            }
            $source = (int) $item['gallery_id'];
            $identity = $kind . ':' . $item['sha256'];
            if (in_array($source, $galleryIds, true)) {
                gallery_visit_content_add($members, 'g:' . $source,
                    $identity, 'a:' . $source);
            }
            foreach ($virtualSources[$source] ?? [] as $node) {
                gallery_visit_content_add($members, $node, $identity, 'a:' . $source);
            }
        }
    }
    foreach ($inventory['edges'] as $parent => $children) {
        if (!in_array((int) substr($parent, 2), $galleryIds, true)) {
            continue;
        }
        foreach ($children as $child) {
            gallery_visit_content_add($members, $parent, $child, 'e:' . $child);
        }
    }
    foreach ($inventory['roots'] as $child) {
        // The synthetic installation root stores discovery baselines only; it
        // is never a gallery visit and never appears in the notification list.
        gallery_visit_content_add($members, 'r:0', $child, 'e:' . $child);
    }
    return \Gallery\Models\gallery_visit_content_model_publish(
        $members, $state['generation'], GALLERY_VISIT_MEMBER_LIMIT,
        $galleryIds, $smartIds, $rebuild,
    );
}

/**
 * Prepare one access-safe hierarchy and compact current membership watermarks.
 * @param string $opened Authorized detail identity, or empty for listings/gates.
 * @param list<string> $observed Independently listed public discovery cards;
 *     each still passes the canonical access policy before entering the graph.
 * @return array{nodes:array<string,array{row:array<string,scalar|null>,
 *     children:list<string>,watermark:array<string,int>}>,ready:bool}
 *     Private rows for controller URL/title preparation; no inaccessible nodes.
 */
function gallery_visit_projection(string $opened = '', array $observed = []): array
{
    $inventory = gallery_visit_inventory();
    $allowed = [];
    foreach ($inventory['galleries'] as $id => $row) {
        if (visitor_can_access_gallery_without_admin_bypass($row)) {
            $allowed['g:' . $id] = $row;
        }
    }
    foreach ($inventory['smart'] as $id => $row) {
        $allowed['s:' . $id] = $row;
    }
    $queue = $inventory['roots'];
    foreach ($observed as $key) {
        if (isset($allowed[$key])) {
            $queue[] = $key;
        }
    }
    if (isset($allowed[$opened])) {
        $queue[] = $opened;
    }
    $nodes = [];
    for ($cursor = 0; $cursor < count($queue); $cursor++) {
        $key = $queue[$cursor];
        if (isset($nodes[$key]) || !isset($allowed[$key])) {
            continue;
        }
        $children = array_values(array_filter(
            $inventory['edges'][$key] ?? [],
            static fn(string $child): bool => isset($allowed[$child]),
        ));
        $nodes[$key] = ['row' => $allowed[$key], 'children' => $children,
            'watermark' => []];
        array_push($queue, ...$children);
        if (count($nodes) > GALLERY_VISIT_NODE_LIMIT) {
            throw new \RuntimeException('Visit projection exceeds its bound.');
        }
    }
    $nodes['r:0'] = ['row' => [], 'children' => array_values(array_filter(
        $inventory['roots'], static fn(string $key): bool => isset($nodes[$key]),
    )), 'watermark' => []];
    $ready = gallery_visit_content_storage_available();
    if ($ready) {
        $state = \Gallery\Models\gallery_visit_content_model_state();
        $ready = !$state['dirty'] && $state['revision'] > 0;
    }
    if ($ready) {
        $lanes = \Gallery\Models\gallery_visit_content_model_lanes(
            GALLERY_VISIT_LANE_LIMIT,
        );
        if (count($lanes) > GALLERY_VISIT_LANE_LIMIT) {
            throw new \RuntimeException('Visit lanes exceed their bound.');
        }
        $allowNsfw = visitor_can_access_nsfw_content_without_admin_bypass();
        foreach ($lanes as $lane) {
            $key = $lane['node_key'];
            $name = $lane['lane_key'];
            if (!isset($nodes[$key])) {
                continue;
            }
            if (str_starts_with($name, 'e:')) {
                if (!in_array(substr($name, 2), $nodes[$key]['children'], true)) {
                    continue;
                }
            } else {
                $parts = explode(':', $name);
                $source = 'g:' . (int) ($parts[1] ?? 0);
                if (!isset($allowed[$source])
                    || ($parts[0] === 'm' && ($parts[2] ?? '0') !== '0'
                        && !$allowNsfw)) {
                    continue;
                }
            }
            $nodes[$key]['watermark'][$name] = (int) $lane['revision'];
        }
        // Zero lanes record which physical sources were already authorized
        // at observation time. They distinguish a source move from newly
        // granted access without storing any image identities in the browser.
        $laneCount = array_sum(array_map(
            static fn(array $node): int => count($node['watermark']), $nodes,
        ));
        foreach ($nodes as $key => &$node) {
            if ($key === 'r:0') {
                continue;
            }
            $sources = str_starts_with($key, 'g:')
                ? [(int) substr($key, 2)] : array_keys($inventory['galleries']);
            foreach ($sources as $sourceId) {
                $source = $inventory['galleries'][$sourceId] ?? null;
                if (!isset($allowed['g:' . $sourceId]) || $source === null
                    || (str_starts_with($key, 's:') && !gallery_is_public_listed($source))) {
                    continue;
                }
                foreach ($allowNsfw ? ['0', '1'] : ['0'] as $class) {
                    $name = 'm:' . $sourceId . ':' . $class;
                    if (!array_key_exists($name, $node['watermark'])) {
                        $node['watermark'][$name] = 0;
                        $laneCount++;
                    }
                    if ($laneCount > GALLERY_VISIT_LANE_LIMIT) {
                        throw new \RuntimeException('Visit authorization lanes exceed their bound.');
                    }
                }
            }
        }
        unset($node);
    }
    return compact('nodes', 'ready');
}
