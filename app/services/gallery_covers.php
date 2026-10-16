<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_covers.php
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

use RuntimeException;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\path_inside;
use function Gallery\Core\now_sql;
use function Gallery\Core\url_for;
use function Gallery\Models\gallery_model_cover_image_path_column_exists;
use function Gallery\Models\gallery_model_set_cover_image_id;
use function Gallery\Models\gallery_model_set_cover_image_path;
use function Gallery\Models\image_model_first_direct_cover_candidate;
use function Gallery\Models\image_model_direct_cover_candidates;
use function Gallery\Models\gallery_model_update_fields;
use function Gallery\Services\schema_inspection_column;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\schema_inspection_is_missing;

/**
 * Gallery cover model.
 *
 * This module resolves gallery cover images, cover asset URLs, collage candidates, and sidecar cover choices. It avoids the separate theme background and favicon storage paths.
 *
 * @param int $galleryId Gallery identifier.
 * @return void Persist the first direct cover only for a legacy unconfigured gallery.
 */
function ensure_gallery_cover(int $galleryId): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery || !empty($gallery['cover_image_id']) || ($gallery['cover_image_ids'] ?? null) !== null) {
        return;
    }
    $cover = image_model_first_direct_cover_candidate($galleryId, false);
    if (!$cover) {
        return;
    }
    gallery_model_set_cover_image_id($galleryId, (int) $cover['id'], now_sql());
}

/**
 * Handles gallery cover path logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_path(array $gallery): ?string
{
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = trim((string) ($gallery['cover_image_path'] ?? ''));
    return $path !== '' ? $path : null;
}

/**
 * Resolve a persisted cover image only when its canonical file remains inside this gallery.
 *
 * Normalization rejects traversal in imported or corrupted cover metadata. The
 * resolved containment check also rejects symbolic links escaping the gallery.
 *
 * @param array<string,mixed> $gallery Persisted gallery row and cover metadata.
 * @return string|null Existing safe cover image path, or null when unsafe or missing.
 */
function gallery_cover_asset_abs_path(array $gallery): ?string
{
    $relativePath = gallery_cover_path($gallery);
    if ($relativePath === null) {
        return null;
    }
    try {
        $relativePath = normalize_relative_path($relativePath);
    } catch (RuntimeException) {
        return null;
    }
    if ($relativePath === '') {
        return null;
    }

    $galleryRoot = gallery_abs_path((string) ($gallery['folder_path'] ?? ''));
    $path = $galleryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!is_file($path) || !path_inside($galleryRoot, $path)) {
        return null;
    }
    return $path;
}

/**
 * Return whether the shared schema observation confirms cover-asset storage.
 *
 * @return bool Result produced by this operation.
 */
function gallery_cover_asset_schema_ready(): bool
{
    // Reuse the canonical request snapshot and its migration invalidation.
    return schema_inspection_is_available(schema_inspection_column('galleries', 'cover_image_path'));
}

/**
 * Handles set gallery cover path logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $relativePath Input used by this operation.
 */
function set_gallery_cover_path(int $galleryId, ?string $relativePath): void
{
    if (!gallery_cover_asset_schema_ready()) {
        return;
    }
    gallery_model_set_cover_image_path(
        $galleryId,
        $relativePath !== null && $relativePath !== '' ? $relativePath : null,
        now_sql()
    );
}

/**
 * Read the bounded, ordered explicit cover IDs from optional gallery metadata.
 * SQL NULL means the pre-existing legacy cover behavior. JSON [] opts into
 * automatic collage selection; a nonempty JSON list is the blue multi-star mode.
 *
 * @param array{cover_image_ids?:string|null,cover_image_id?:int|string|null} $gallery Gallery cover-selection columns; absent or NULL storage retains legacy mode.
 * @return list<int> Positive, unique IDs, limited to the existing four-slot collage.
 */
function gallery_cover_selection_ids(array $gallery): array
{
    $raw = $gallery['cover_image_ids'] ?? null;
    if (!is_string($raw)) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !array_is_list($decoded)) {
        return [];
    }
    $ids = [];
    foreach ($decoded as $id) {
        if (!is_int($id) || $id <= 0 || in_array($id, $ids, true)) {
            continue;
        }
        $ids[] = $id;
        if (count($ids) === 4) {
            break;
        }
    }
    return $ids;
}

/**
 * Validate persisted ordered selection JSON without normalizing away corrupt entries.
 *
 * @param array{cover_image_ids?:string|null} $gallery Gallery row; an absent or NULL cover_image_ids value is legacy-compatible.
 * @return bool True for legacy NULL or a valid ordered list of zero to four unique positive IDs.
 */
function gallery_cover_selection_storage_valid(array $gallery): bool
{
    if (!array_key_exists('cover_image_ids', $gallery) || $gallery['cover_image_ids'] === null) {
        return true;
    }
    if (!is_string($gallery['cover_image_ids'])) {
        return false;
    }
    $ids = json_decode($gallery['cover_image_ids'], true);
    if (!is_array($ids) || !array_is_list($ids) || count($ids) > 4) {
        return false;
    }
    $seen = [];
    foreach ($ids as $id) {
        if (!is_int($id) || $id <= 0 || isset($seen[$id])) {
            return false;
        }
        $seen[$id] = true;
    }
    return true;
}

/**
 * Resolve the stored selection mode without changing pre-migration galleries.
 *
 * @param array{cover_image_ids?:string|null,cover_image_id?:int|string|null} $gallery Stored cover-selection and primary-image columns.
 * @return 'legacy'|'single'|'automatic'|'multi' Stored state: unconfigured, exclusive image, automatic collage, or ordered manual selection.
 */
function gallery_cover_selection_mode(array $gallery): string
{
    if (!array_key_exists('cover_image_ids', $gallery) || $gallery['cover_image_ids'] === null) {
        return (int) ($gallery['cover_image_id'] ?? 0) > 0 ? 'single' : 'legacy';
    }
    return gallery_cover_selection_ids($gallery) === [] ? 'automatic' : 'multi';
}

/**
 * Stable public marker for the persisted primary cover, explicit selection
 * and uploaded-cover precedence. Hashing avoids revealing private image IDs.
 *
 * @param array{cover_image_id?:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,edit_revision?:int|string|null} $gallery Gallery cover columns and current editor revision; legacy columns may be absent.
 * @return non-empty-string Lowercase 20-character SHA-256 prefix over primary ID, selection JSON, uploaded path and edit revision.
 */
function gallery_cover_selection_revision(array $gallery): string
{
    $payload = implode(':', [
        (string) ($gallery['cover_image_id'] ?? ''),
        (string) ($gallery['cover_image_ids'] ?? 'legacy'),
        (string) ($gallery['cover_image_path'] ?? ''),
        (string) ($gallery['edit_revision'] ?? ''),
    ]);
    return substr(hash('sha256', $payload), 0, 20);
}

/**
 * Check whether the gallery cover-selection column is conclusively available.
 *
 * @return bool True only when shared schema inspection reports available.
 */
function gallery_cover_selection_schema_ready(): bool
{
    return gallery_cover_selection_schema_state() === 'available';
}

/**
 * Resolve the cover-selection column through shared three-state inspection.
 *
 * @return 'available'|'missing'|'unknown' Shared schema inspection outcome.
 */
function gallery_cover_selection_schema_state(): string
{
    $result = schema_inspection_column('galleries', 'cover_image_ids');
    if (schema_inspection_is_available($result)) {
        return 'available';
    }
    return schema_inspection_is_missing($result) ? 'missing' : 'unknown';
}

/**
 * Resolve one direct gallery cover in legacy or exclusive single-photo mode.
 *
 * Explicit automatic and multi-photo modes instead use the existing collage
 * renderer, so they deliberately return null from this single-photo helper.
 *
 * @param int $galleryId Physical gallery identifier.
 * @param bool $publicOnly Require a published image for public card rendering.
 * @return array{id:int|string,gallery_id:int|string,relative_path:string,visibility:string}|null Valid direct cover, or null for collage modes.
 */
function gallery_cover_image(int $galleryId, bool $publicOnly): ?array
{
    $gallery = find_gallery($galleryId);
    if ($gallery && in_array(gallery_cover_selection_mode($gallery), ['automatic', 'multi'], true)) {
        return null;
    }
    return gallery_direct_cover_image($galleryId, $publicOnly);
}

/**
 * Resolve the gallery's stored direct cover or a safe direct-image fallback.
 *
 * @param int $galleryId Physical gallery identifier.
 * @param bool $publicOnly Require visitor authorization, publication and NSFW visibility.
 * @return array{id:int|string,gallery_id:int|string,relative_path:string,visibility:string}|null Valid directly owned cover or null when unavailable/inaccessible.
 */
function gallery_direct_cover_image(int $galleryId, bool $publicOnly): ?array
{
    static $cache = [];
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $galleryId . ':' . ($publicOnly ? '1' : '0');
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($galleryId);
    if (!$gallery) {
        return $cache[$cacheKey] = null;
    }
    if (!empty($gallery['cover_image_id'])) {
        // Variable $cover stores this steps working value.
        $cover = find_image((int) $gallery['cover_image_id']);
        if ($cover && (int) ($cover['gallery_id'] ?? 0) === $galleryId
            && !str_contains((string) $cover['relative_path'], '/')
            && (!$publicOnly || public_image_visible_to_current_visitor_without_admin_bypass($cover, $gallery))) {
            return $cache[$cacheKey] = $cover;
        }
    }
    $candidates = image_model_direct_cover_candidates($galleryId, $publicOnly, $publicOnly ? 4 : 1);
    foreach ($candidates as $candidate) {
        if (!$publicOnly || public_image_visible_to_current_visitor_without_admin_bypass($candidate, $gallery)) {
            return $cache[$cacheKey] = $candidate;
        }
    }
    return $cache[$cacheKey] = null;
}

/**
 * Build the route URL for a gallery cover asset when the visitor may access it.
 *
 * @param array{id:int|string,folder_path:string,cover_image_ids?:string|null,cover_image_id?:int|string|null,cover_image_path?:string|null,access_mode?:string,access_listing?:string} $gallery Gallery row with its physical folder and optional cover/access fields.
 * @param bool $publicOnly Require current visitor access before exposing the route.
 * @return string Cover-asset route URL, or an empty string when no cover is available or access is denied.
 */
function gallery_cover_asset_url(array $gallery, bool $publicOnly): string
{
    if ($publicOnly && gallery_access_requirement($gallery) !== null) {
        return '';
    }
    // $coverPath stores an intermediate value used by the surrounding gallery workflow.
    $coverPath = gallery_cover_path($gallery);
    if ($coverPath === null) {
        return '';
    }
    return url_for('gallery_cover_asset', ['id' => (int) $gallery['id']]);
}

/**
 * Resolve existing collage slots for an automatic, explicit or child-gallery cover.
 *
 * The automatic mode uses at most two direct/accessible descendant photos.
 * A manual multi-selection uses at most four ordered direct photos. Legacy
 * parents retain the previous four-slot child-cover recursion. Missing or
 * unpublished choices never bypass gallery access checks.
 *
 * @param int $galleryId Owning gallery identifier.
 * @param bool $publicOnly Exclude unpublished photos and inaccessible child galleries.
 * @param int $limit Remaining collage slots available to this recursion.
 * @return list<array{id:int|string,gallery_id:int|string,relative_path:string,visibility:string}> Ordered collage candidates.
 */
function gallery_cover_collage_images(int $galleryId, bool $publicOnly, int $limit = 4): array
{
    static $cache = [];
    $cacheKey = $galleryId . ':' . ($publicOnly ? '1' : '0') . ':' . $limit;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    if ($limit <= 0) {
        return $cache[$cacheKey] = [];
    }
    $gallery = find_gallery($galleryId);
    $mode = $gallery ? gallery_cover_selection_mode($gallery) : 'legacy';
    // Explicit automatic mode uses two slots consistently; legacy galleries
    // continue to use the previously supported up-to-four child collage.
    $effectiveLimit = $mode === 'automatic' ? min(2, $limit) : min(4, $limit);
    $images = [];
    if ($mode === 'multi' && $gallery) {
        foreach (gallery_cover_selection_ids($gallery) as $imageId) {
            $cover = find_image($imageId);
            if (!$cover || (int) ($cover['gallery_id'] ?? 0) !== $galleryId
                || str_contains((string) ($cover['relative_path'] ?? ''), '/')
                || ($publicOnly && !public_image_visible_to_current_visitor_without_admin_bypass($cover, $gallery))) {
                continue;
            }
            $images[$imageId] = $cover;
            if (count($images) >= $effectiveLimit) {
                break;
            }
        }
        if ($images !== []) {
            return $cache[$cacheKey] = array_values($images);
        }
        // A deleted/moved or unpublished selection must never expose the old
        // image; use the same bounded automatic fallback rather than stale IDs.
        $effectiveLimit = min(2, $effectiveLimit);
    }
    if ($mode === 'automatic' || $mode === 'multi') {
        foreach (image_model_direct_cover_candidates($galleryId, $publicOnly, max(4, $effectiveLimit)) as $cover) {
            if ($publicOnly && !public_image_visible_to_current_visitor_without_admin_bypass($cover, $gallery)) {
                continue;
            }
            $images[(int) $cover['id']] = $cover;
            if (count($images) >= $effectiveLimit) {
                break;
            }
        }
        if (count($images) >= $effectiveLimit) {
            return $cache[$cacheKey] = array_values($images);
        }
    }
    // Reuse the existing child-cover recursion, access gate and thumbnail
    // collage markup instead of introducing an independent renderer.
    foreach (child_galleries($galleryId, $publicOnly) as $child) {
        if ($publicOnly && gallery_access_requirement($child) !== null) {
            continue;
        }
        $cover = gallery_direct_cover_image((int) $child['id'], $publicOnly);
        if ($cover && (!$publicOnly || public_image_visible_to_current_visitor_without_admin_bypass($cover, $child))) {
            $images[(int) $cover['id']] = $cover;
        }
        if (count($images) >= $effectiveLimit) {
            break;
        }
        foreach (gallery_cover_collage_images((int) $child['id'], $publicOnly, $effectiveLimit - count($images)) as $descendantCover) {
            $images[(int) $descendantCover['id']] = $descendantCover;
            if (count($images) >= $effectiveLimit) {
                break 2;
            }
        }
    }
    return $cache[$cacheKey] = array_values($images);
}

/**
 * Handles gallery cover choices logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $publicOnly Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_cover_choices(int $galleryId, bool $publicOnly): array
{
    // $choices stores an intermediate value used by the surrounding gallery workflow.
    $choices = [];
    // $root stores an intermediate value used by the surrounding gallery workflow.
    $root = find_gallery($galleryId);
    if (!$root) {
        return [];
    }
    // $stack stores an intermediate value used by the surrounding gallery workflow.
    $stack = [$root];
    while ($stack) {
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = array_shift($stack);
        // $cover stores an intermediate value used by the surrounding gallery workflow.
        $cover = gallery_direct_cover_image((int) $gallery['id'], $publicOnly);
        if ($cover) {
            $choices[] = [
                'gallery_id' => (int) $gallery['id'],
                'gallery_title' => (string) $gallery['title'],
                'image' => $cover,
            ];
        }
        foreach (child_galleries((int) $gallery['id'], $publicOnly) as $child) {
            $stack[] = $child;
        }
    }
    return $choices;
}

/**
 * Reconcile an imported gallery's title-picture choice with its sidecar paths.
 *
 * Explicit cover_images paths are remapped to this installation's owned image
 * IDs; malformed, duplicate or unavailable sidecar entries are rejected.
 * Legacy single cover paths still use the existing lookup and update path.
 *
 * @param array{id:int|string,folder_path:string,cover_image_ids?:string|null,cover_image_id?:int|string|null,cover_image_path?:string|null} $gallery Imported gallery row; the presence of cover_image_path proves the selected row includes that persistence field.
 * @return void Apply a complete valid selection, or retain current stored cover.
 */
function apply_gallery_cover_from_sidecar(array $gallery): void
{
    // Variable $metadata stores this steps working value.
    $metadata = read_gallery_sidecar(gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . 'gallery.json');
    if (array_key_exists('cover_images', $metadata)) {
        if (!array_key_exists('cover_image_ids', $gallery)
            || !gallery_cover_selection_schema_ready()
            || !is_array($metadata['cover_images']) || !array_is_list($metadata['cover_images'])
            || count($metadata['cover_images']) > 4) {
            return;
        }
        $ids = [];
        $valid = true;
        foreach ($metadata['cover_images'] as $path) {
            if (!is_string($path) || $path === '') {
                $valid = false;
                break;
            }
            try {
                $normalized = normalize_relative_path($path);
            } catch (RuntimeException) {
                $valid = false;
                break;
            }
            if ($normalized === '' || str_contains($normalized, '/')) {
                $valid = false;
                break;
            }
            $image = find_image_by_path((int) $gallery['id'], $normalized);
            if (!$image || in_array((int) $image['id'], $ids, true)) {
                $valid = false;
                break;
            }
            $ids[] = (int) $image['id'];
        }
        if ($valid) {
            $encoded = json_encode($ids, JSON_THROW_ON_ERROR);
            $primaryId = $ids[0] ?? null;
            if (($gallery['cover_image_ids'] ?? null) !== $encoded || (int) ($gallery['cover_image_id'] ?? 0) !== (int) ($primaryId ?? 0)
                || !empty($gallery['cover_image_path'])) {
                $fields = [
                    'cover_image_id' => $primaryId,
                    'cover_image_ids' => $encoded,
                ];
                // The fresh gallery row itself proves this column was selected;
                // clear its precedence in the same update as the photo choice.
                if (array_key_exists('cover_image_path', $gallery)) {
                    $fields['cover_image_path'] = null;
                }
                gallery_model_update_fields((int) $gallery['id'], $fields, now_sql());
            }
            return;
        }
        return;
    }
    if (empty($metadata['cover']) || !is_string($metadata['cover'])) {
        return;
    }
    try {
        // Variable $coverPath stores this steps working value.
        $coverPath = normalize_relative_path($metadata['cover']);
    } catch (RuntimeException) {
        return;
    }
    if ($coverPath === '' || str_contains($coverPath, '/')) {
        return;
    }
    // Variable $image stores this steps working value.
    $image = find_image_by_path((int) $gallery['id'], $coverPath);
    if (!$image) {
        return;
    }
    $fields = ['cover_image_id' => (int) $image['id']];
    if (array_key_exists('cover_image_ids', $gallery) && gallery_cover_selection_schema_ready()) {
        $fields['cover_image_ids'] = null;
    }
    gallery_model_update_fields((int) $gallery['id'], $fields, now_sql());
}

