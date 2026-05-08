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

/**
Read-oriented gallery and image lookup helpers.
 *
 * This module groups database lookups used by public pages and admin pages.
 * The functions preserve their original SQL and return shapes so templates and
 * controllers can continue using the same global function names.
 */

/**
 * Return direct child galleries for a parent gallery.
 */
function child_galleries(int $parentId, bool $publicOnly): array
{
    static $cache = [];
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $parentId . ':' . ($publicOnly ? '1' : '0');
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // Variable $sql stores this steps working value.
    $sql = "SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE g.parent_id = ?";
    // Variable $params stores this steps working value.
    $params = [$parentId];
    if ($publicOnly) {
        $sql .= ' AND ' . public_gallery_listing_condition('g');
    }
    $sql .= ' GROUP BY g.id ORDER BY g.sort_order, g.title';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $cache[$cacheKey] = $stmt->fetchAll();
}

/**
 * Walk from a gallery to its root ancestors for breadcrumb rendering.
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
 */
function gallery_branch_image_count(int $galleryId, bool $publicOnly): int
{
    static $cache = [];
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $galleryId . ':' . ($publicOnly ? '1' : '0');
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // Variable $galleryIds stores this steps working value.
    $galleryIds = gallery_subtree_ids($galleryId);
    if (!$galleryIds) {
        return $cache[$cacheKey] = 0;
    }
    if ($publicOnly) {
        // $galleryIds stores an intermediate value used by the surrounding gallery workflow.
        $galleryIds = array_values(array_filter($galleryIds, static function (int $candidateId): bool {
            // $candidate stores an intermediate value used by the surrounding gallery workflow.
            $candidate = find_gallery($candidateId);
            return $candidate && visitor_can_access_gallery($candidate);
        }));
        if (!$galleryIds) {
            return $cache[$cacheKey] = 0;
        }
    }

    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    // Variable $sql stores this steps working value.
    $sql = 'SELECT COUNT(*) FROM images i';
    if ($publicOnly) {
        $sql .= ' JOIN galleries g ON g.id = i.gallery_id';
    }
    $sql .= ' WHERE i.gallery_id IN (' . $placeholders . ')';
    // Variable $params stores this steps working value.
    $params = $galleryIds;
    if ($publicOnly) {
        $sql .= ' AND i.visibility = ? AND ' . public_gallery_listing_condition('g');
        $params[] = 'public';
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $cache[$cacheKey] = (int) $stmt->fetchColumn();
}

/**
 * Fetch one gallery by numeric ID.
 */
function find_gallery(int $id, bool $fresh = false): ?array
{
    static $cache = [];

    if (!$fresh && array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM galleries WHERE id = ?');
    $stmt->execute([$id]);
    // Variable $gallery stores this steps working value.
    $gallery = $stmt->fetch();
    $cache[$id] = $gallery ?: null;
    return $cache[$id];
}

/**
 * Fetch one gallery by its public URL slug.
 */
function find_gallery_by_slug(string $slug): ?array
{
    static $cache = [];

    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM galleries WHERE slug = ?');
    $stmt->execute([$slug]);
    // Variable $gallery stores this steps working value.
    $gallery = $stmt->fetch();
    $cache[$slug] = $gallery ?: null;
    return $cache[$slug];
}

/**
 * Fetch one gallery by normalized folder path.
 */
function find_gallery_by_folder_path(string $folderPath): ?array
{
    static $cache = [];

    // $normalizedPath stores an intermediate value used by the surrounding gallery workflow.
    $normalizedPath = normalize_relative_path($folderPath);
    if (array_key_exists($normalizedPath, $cache)) {
        return $cache[$normalizedPath];
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM galleries WHERE folder_path_hash = ?');
    $stmt->execute([hash('sha256', $normalizedPath)]);
    // Variable $gallery stores this steps working value.
    $gallery = $stmt->fetch();
    $cache[$normalizedPath] = $gallery ?: null;
    return $cache[$normalizedPath];
}

/**
 * Find the nearest already-imported parent folder for a gallery path.
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
 */
function find_image(int $id): ?array
{
    static $cache = [];

    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM images WHERE id = ?');
    $stmt->execute([$id]);
    // Variable $image stores this steps working value.
    $image = $stmt->fetch();
    $cache[$id] = $image ?: null;
    return $cache[$id];
}

/**
 * Fetch one image by gallery and normalized relative image path.
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

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT * FROM images WHERE gallery_id = ? AND relative_path_hash = ?');
    $stmt->execute([$galleryId, hash('sha256', $normalizedPath)]);
    // Variable $image stores this steps working value.
    $image = $stmt->fetch();
    $cache[$cacheKey] = $image ?: null;
    return $cache[$cacheKey];
}

/**
 * Fetch images for admin/public rendering, optionally public-only.
 */
function gallery_images(int $galleryId, bool $publicOnly): array
{
    // Variable $sql stores this steps working value.
    $sql = "SELECT * FROM images WHERE gallery_id = ? AND relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $sql .= " AND visibility = 'public'";
    }
    $sql .= ' ORDER BY sort_order, filename';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll();
}
