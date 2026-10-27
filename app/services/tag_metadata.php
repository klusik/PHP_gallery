<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/tag_metadata.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Coordinates tag parsing, model persistence, metadata, and public tag lookup.
 *
 * Responsibilities:
 *   - Normalize shared tag names and coordinate tag persistence through models
 *   - Expose focused functions for tag metadata and suggestion workflows
 *   - Keep transactions and SQL in the canonical tag model
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
 *   2026-10-21
 */

declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use InvalidArgumentException;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\slugify;
use function Gallery\Core\url_for;
use function Gallery\Models\tag_model_admin_gallery_usage_rows;
use function Gallery\Models\tag_model_admin_image_usage_rows;
use function Gallery\Models\tag_model_admin_song_usage_rows;
use function Gallery\Models\tag_model_admin_rows;
use function Gallery\Models\tag_model_all_names;
use function Gallery\Models\tag_model_all_rows_by_id;
use function Gallery\Models\tag_model_attach_entity_tag;
use function Gallery\Models\tag_model_clear_entity_tags;
use function Gallery\Models\tag_model_contained_for_gallery;
use function Gallery\Models\tag_model_delete_row;
use function Gallery\Models\tag_model_delete_with_assignments;
use function Gallery\Models\tag_model_description_column_ready;
use function Gallery\Models\tag_model_find_by_id;
use function Gallery\Models\tag_model_find_by_slug;
use function Gallery\Models\tag_model_gallery_context;
use function Gallery\Models\tag_model_global_suggestion_rows;
use function Gallery\Models\tag_model_image_catalog;
use function Gallery\Models\tag_model_ids_match_image_category;
use function Gallery\Models\tag_model_merge_duplicate;
use function Gallery\Models\tag_model_find_or_create_by_slug_candidates;
use function Gallery\Models\tag_model_mutate_image_tag_atomically;
use function Gallery\Models\tag_model_public_galleries;
use function Gallery\Models\tag_model_slug_exists_except;
use function Gallery\Models\tag_model_suggestion_usage_rows;
use function Gallery\Models\tag_model_tags_for_entities;
use function Gallery\Models\tag_model_tags_for_entity;
use function Gallery\Models\tag_model_update_canonical;
use function Gallery\Models\tag_model_update_metadata;
use function Gallery\Models\tag_model_usage_count_rows;

/**
 * Preserve the existing globally unique ASCII slug column's storage bound.
 * Type: int. Units: bytes. Scope: shared tag slug allocation.
 * Consumers: find_or_create_tag and normalize_existing_tags.
 * Rationale: 120 matches tags.slug while reserving space for a media suffix.
 */
const TAG_MEDIA_SLUG_MAX_BYTES = 120;

/**
 * Bound repeated slug collisions without turning user input into unlimited SQL.
 * Type: int. Units: attempts. Scope: one shared tag allocation.
 * Consumers: find_or_create_tag and normalize_existing_tags.
 * Rationale: 128 permits practical name reuse and refuses pathological clashes.
 */
const TAG_MEDIA_SLUG_MAX_ATTEMPTS = 128;

/**
 * Parse admin-entered comma/semicolon/newline tag text into unique names.
 *
 * @param string $tags Tags value.
 * @return array Structured result data for the caller.
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
 *
 * @param string $name Name value.
 * @return string Text result for the caller.
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
 *
 * @param string $name Name value.
 * @return string Text result for the caller.
 */
function tag_slug(string $name): string
{
    // Variable $slug stores this steps working value.
    $slug = sanitize_tag_name($name);
    return $slug !== '' ? substr($slug, 0, 120) : 'tag';
}

/**
 * Build the ordered global-slug candidates for one canonical tag name.
 *
 * @param string $name Canonical normalized tag name.
 * @param string $mediaType Validated category: image, music, or pdf.
 * @return list<string> Unique-slug candidates in the established collision order.
 */
function tag_slug_candidates(string $name, string $mediaType): array
{
    $baseSlug = tag_slug($name);
    $firstSlug = $mediaType === 'image'
        ? $baseSlug
        : $mediaType . '-' . $baseSlug;
    $candidates = [$firstSlug];
    for (
        $suffix = 2;
        $suffix <= TAG_MEDIA_SLUG_MAX_ATTEMPTS;
        $suffix++
    ) {
        $suffixText = '-' . $mediaType . '-' . $suffix;
        $candidates[] = substr(
            $baseSlug,
            0,
            TAG_MEDIA_SLUG_MAX_BYTES - strlen($suffixText),
        ) . $suffixText;
    }
    return $candidates;
}

/**
 * Return an existing tag ID or create a new tag row.
 *
 * @param string $name Name value.
 * @param string $mediaType Requested category: image, music, or pdf; defaults to image.
 * @return int Existing or new tag ID, or 0 when input/schema is invalid or the bounded slug allocation cannot find an available row.
 */
function find_or_create_tag(string $name, string $mediaType = 'image'): int
{
    if (!in_array($mediaType, ['image', 'music', 'pdf'], true)) {
        return 0;
    }
    $schemaState = tag_media_type_schema_state();
    if (
        $schemaState === 'unknown'
        || ($mediaType !== 'image' && $schemaState !== 'available')
    ) {
        return 0;
    }
    // Variable $name stores this steps working value.
    $name = sanitize_tag_name($name);
    if ($name === '') {
        return 0;
    }
    return tag_model_find_or_create_by_slug_candidates(
        $name,
        tag_slug_candidates($name, $mediaType),
        now_sql(),
        $mediaType,
        tag_media_type_schema_ready(),
    );
}

/**
 * Report whether the media category column is confirmed available.
 *
 * @return string One of available, missing, or unknown based on schema inspection.
 */
function tag_media_type_schema_state(): string
{
    // The inspection owner caches per request and clears its observations
    // after migration DDL. A second static cache here would survive that reset.
    $status = \Gallery\Services\schema_inspection_column('tags', 'media_type');
    if (\Gallery\Services\schema_inspection_is_available($status)) {
        return 'available';
    }
    return ($status['state'] ?? 'unknown') === 'missing'
        ? 'missing'
        : 'unknown';
}

/**
 * Return whether typed-tag SQL can safely use tags.media_type.
 *
 * @return bool True only when inspection confirms the column exists.
 */
function tag_media_type_schema_ready(): bool
{
    return tag_media_type_schema_state() === 'available';
}

/**
 * Return whether the shared music-song tag assignment schema is available.
 *
 * @return bool True only when inspection confirms music_song_tags exists.
 */
function tag_music_assignment_schema_ready(): bool
{
    $status = \Gallery\Services\schema_inspection_table('music_song_tags');
    return \Gallery\Services\schema_inspection_is_available($status);
}

/**
 * Replace all tags for one gallery or image with the submitted tag list.
 *
 * @param string $type Assignment owner: gallery, image, or music_song.
 * @param int $id Positive gallery, image, or song identifier.
 * @param string $tagText Submitted delimited tag names; an empty string clears assignments.
 * @param string $mediaType Expected category: image for gallery/image, music for music_song; defaults to image.
 * @return bool True after replacement succeeds; false when the category, tag input, or required schema is unavailable.
 * @throws \InvalidArgumentException When an assignment type or resolved tag ID is invalid.
 */
function sync_entity_tags(
    string $type,
    int $id,
    string $tagText,
    string $mediaType = 'image',
): bool
{
    $expectedType = $type === 'music_song' ? 'music' : 'image';
    $schemaState = tag_media_type_schema_state();
    if (
        $mediaType !== $expectedType
        || $schemaState === 'unknown'
        || ($mediaType !== 'image' && $schemaState !== 'available')
        || ($type === 'music_song' && !tag_music_assignment_schema_ready())
    ) {
        return false;
    }
    // Resolve every name before changing assignments. A rejected tag must not
    // silently erase the previous valid selection, including inside a catalog
    // transaction that will roll back the complete logical operation.
    $tagIds = [];
    foreach (split_tag_names($tagText) as $name) {
        // Variable $tagId stores this steps working value.
        $tagId = find_or_create_tag($name, $mediaType);
        if ($tagId <= 0) {
            return false;
        }
        $tagIds[] = $tagId;
    }
    \Gallery\Models\tag_model_replace_entity_tags(
        $type,
        $id,
        array_values(array_unique($tagIds)),
        $schemaState === 'available',
    );
    if ($type === 'gallery') {
        gallery_visit_content_update(
            \Gallery\Models\gallery_visit_content_model_branch_sources($id),
        );
    } elseif ($type === 'image') {
        $image = find_image($id, true);
        if (is_array($image)) {
            gallery_visit_content_update([(int) $image['gallery_id']]);
        }
    }
    return true;
}

/**
 * Apply one targeted, idempotent tag change to a physical image.
 *
 * @param int $imageId Stable physical image identifier.
 * @param int $expectedGalleryId Physical source gallery observed for this mutation.
 * @param string $operation Requested assignment change: add or remove.
 * @param int|null $tagId Existing image tag identifier for an add/remove.
 * @param string|null $tagName New or canonical image tag name for an add.
 * @return array{changed_tag:array{id:int,name:string,slug:string},already_present:bool,tags:list<array{id:int,name:string,slug:string}>} Acknowledged tag and authoritative direct image-tag state.
 * @throws \DomainException When the image was moved from the expected physical gallery.
 * @throws \InvalidArgumentException When input or typed tag storage is invalid.
 * @throws \RuntimeException When tag schema is unknown or persistence fails.
 */
function mutate_image_tag(
    int $imageId,
    int $expectedGalleryId,
    string $operation,
    ?int $tagId = null,
    ?string $tagName = null,
): array {
    $schemaState = tag_media_type_schema_state();
    if (
        $imageId < 1
        || $expectedGalleryId < 1
        || !in_array($operation, ['add', 'remove'], true)
    ) {
        throw new InvalidArgumentException('Invalid image tag mutation.');
    }
    if ($schemaState === 'unknown') {
        throw new \RuntimeException('Image tag storage is unavailable.');
    }
    $newTag = null;
    if ($operation === 'add' && $tagId === null) {
        $canonicalName = sanitize_tag_name((string) $tagName);
        if ($canonicalName === '') {
            throw new InvalidArgumentException('Select a valid image tag.');
        }
        $newTag = [
            'name' => $canonicalName,
            'slug_candidates' => tag_slug_candidates(
                $canonicalName,
                'image',
            ),
            'created_at' => now_sql(),
        ];
    }
    $result = tag_model_mutate_image_tag_atomically(
        $imageId,
        $expectedGalleryId,
        $operation,
        $tagId,
        $newTag,
        $schemaState === 'available',
    );
    gallery_visit_content_update([$expectedGalleryId]);
    return $result;
}

/**
 * Return selectable image tags for an authorized quick-tag control.
 *
 * @return list<array{id:int,name:string,slug:string}> Image-tag choices, or
 *     an empty list while the media-type schema is unknown.
 */
function image_tag_catalog(): array
{
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return [];
    }
    try {
        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
            ],
            tag_model_image_catalog($schemaState === 'available'),
        );
    } catch (PDOException) {
        return [];
    }
}

/**
 * Return tags assigned to one gallery, image, or music song.
 *
 * @param string $type Assignment owner: gallery, image, or music_song.
 * @param int $id Entity identifier.
 * @return list<array{entity_id:int|string,id:int|string,name:string,slug:string,description?:string|null,created_at?:string|null,updated_at?:string|null}> Tag rows, or an empty list when unavailable.
 */
function tags_for_entity(string $type, int $id): array
{
    $schemaState = tag_media_type_schema_state();
    if (
        $schemaState === 'unknown'
        || ($type === 'music_song' && $schemaState !== 'available')
    ) {
        return [];
    }
    static $cache = [];
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $schemaState . ':' . $type . ':' . $id;
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    try {
        return $cache[$cacheKey] = tag_model_tags_for_entity(
            $type,
            $id,
            $schemaState === 'available',
        );
    } catch (PDOException) {
        return $cache[$cacheKey] = [];
    }
}

/**
 * Return direct gallery and image assignment counts for the supplied tag IDs.
 *
 * The public hero usage sort uses the same definition of tag usage as the
 * administration: every direct gallery assignment and every direct image
 * assignment contributes one use. The query is restricted to the IDs present
 * in the current hero so unrelated tags are not aggregated on every request.
 *
 * @param list<int|string> $tagIds Tag identifiers required by the current render.
 * @return array<int,int> Usage counts keyed by tag ID.
 */
function tag_usage_counts(array $tagIds): array
{
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return [];
    }
    static $cache = [];
    // $ids stores normalized unique positive identifiers for a bounded persistence query.
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $tagIds),
        static fn(int $id): bool => $id > 0,
    )));
    sort($ids, SORT_NUMERIC);
    if (!$ids) {
        return [];
    }
    // $cacheKey lets repeated hero groups in the same request reuse the aggregate.
    $cacheKey = $schemaState . ':' . implode(',', $ids);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    try {
        // $counts is initialized with zeroes so tags without assignments remain sortable.
        $counts = array_fill_keys($ids, 0);
        foreach (tag_model_usage_count_rows(
            $ids,
            $schemaState === 'available',
        ) as $row) {
            $counts[(int) $row['tag_id']] = (int) $row['usage_count'];
        }
        return $cache[$cacheKey] = $counts;
    } catch (PDOException) {
        return $cache[$cacheKey] = array_fill_keys($ids, 0);
    }
}

/**
 * Sort public gallery hero tag groups without mixing their semantic sections.
 *
 * Direct gallery tags remain before contained tags. Within each group the
 * configured mode is applied independently. Usage ties use a natural,
 * case-insensitive alphabetical comparison and finally the tag ID for a stable
 * deterministic order.
 *
 * @param array<string,array> $groups Hero tag groups keyed by their semantic role.
 * @param string $sortMode Requested sort mode, normally usage or alphabetical.
 * @return array<string,array> Sorted groups with their original keys preserved.
 */
function sort_public_hero_tag_groups(array $groups, string $sortMode): array
{
    // $mode defensively normalizes callers that do not pass the Theme service result.
    $mode = in_array($sortMode, ['usage', 'alphabetical'], true)
        ? $sortMode
        : 'usage';
    // $tagIds collects all tag IDs once so usage mode needs only one aggregate query.
    $tagIds = [];
    foreach ($groups as $tags) {
        foreach ($tags as $tag) {
            $tagIds[] = (int) ($tag['id'] ?? 0);
        }
    }
    // $usageCounts is empty for alphabetical mode, avoiding an unnecessary database query.
    $usageCounts = $mode === 'usage' ? tag_usage_counts($tagIds) : [];
    foreach ($groups as $groupKey => $tags) {
        usort($tags, static function (array $left, array $right) use (
            $mode,
            $usageCounts,
        ): int {
            if ($mode === 'usage') {
                // $usageComparison sorts larger aggregate assignment counts first.
                $usageComparison =
                    ($usageCounts[(int) ($right['id'] ?? 0)] ?? 0)
                    <=> ($usageCounts[(int) ($left['id'] ?? 0)] ?? 0);
                if ($usageComparison !== 0) {
                    return $usageComparison;
                }
            }
            // $nameComparison keeps ties human-readable and stable across render pipelines.
            $nameComparison = strnatcasecmp(
                (string) ($left['name'] ?? ''),
                (string) ($right['name'] ?? ''),
            );
            if ($nameComparison !== 0) {
                return $nameComparison;
            }
            return (int) ($left['id'] ?? 0) <=> (int) ($right['id'] ?? 0);
        });
        $groups[$groupKey] = $tags;
    }
    return $groups;
}

/**
 * Return all tags for many entities in one query, grouped by entity ID.
 *
 * @param string $type Entity type whose tags are requested.
 * @param list<int|string> $ids Entity identifiers.
 * @return array<int,list<array{entity_id:int|string,id:int|string,name:string,slug:string,description?:string|null,created_at?:string|null,updated_at?:string|null}>> Tag rows keyed by entity ID.
 */
function tags_for_entities(string $type, array $ids): array
{
    $schemaState = tag_media_type_schema_state();
    if (
        $schemaState === 'unknown'
        || ($type === 'music_song' && $schemaState !== 'available')
    ) {
        return [];
    }
    static $cache = [];
    // $ids stores an intermediate value used by the surrounding gallery workflow.
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $ids),
        static fn(int $id): bool => $id > 0,
    )));
    if (!$ids) {
        return [];
    }
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = $schemaState . ':' . $type . ':' . implode(',', $ids);
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    try {
        // $grouped stores an intermediate value used by the surrounding gallery workflow.
        $grouped = [];
        foreach (tag_model_tags_for_entities(
            $type,
            $ids,
            $schemaState === 'available',
        ) as $row) {
            $grouped[(int) $row['entity_id']][] = $row;
        }
        return $cache[$cacheKey] = $grouped;
    } catch (PDOException) {
        return $cache[$cacheKey] = [];
    }
}

/**
 * Function `tag_names_for_entity` handles this scoped operation.
 *
 * @param string $type Type value.
 * @param int $id Identifier value.
 * @return string Text result for the caller.
 */
function tag_names_for_entity(string $type, int $id): string
{
    return implode(', ', array_column(tags_for_entity($type, $id), 'name'));
}

/**
 * Return comma-separated tag names for many entities using one batch query.
 *
 * @param string $type Assignment owner such as image or music_song.
 * @param list<int> $ids Positive entity identifiers.
 * @param string $mediaType Expected category: image or music.
 * @return array<int,string> Tag-name strings keyed by entity identifier.
 */
function tag_names_for_entities(
    string $type,
    array $ids,
    string $mediaType = 'image',
): array {
    $expectedType = $type === 'music_song' ? 'music' : 'image';
    if ($mediaType !== $expectedType) {
        return [];
    }
    $grouped = tags_for_entities($type, $ids);
    $names = [];
    foreach ($grouped as $entityId => $rows) {
        $names[(int) $entityId] = implode(', ', array_map(
            static fn(array $row): string => (string) ($row['name'] ?? ''),
            $rows,
        ));
    }
    return $names;
}

/**
 * Return all tag names for datalist suggestions in admin forms.
 *
 * @return list<string> Unique normalized names in their first-seen order.
 */
function all_tag_names(): array
{
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return [];
    }
    try {
        return tag_model_all_names($schemaState === 'available');
    } catch (PDOException) {
        return [];
    }
}

/**
 * Return weighted tag suggestions for a gallery editor context.
 *
 * The score intentionally favors nearby galleries over global popularity:
 * current gallery images, siblings, descendants, ancestors, and finally all-site
 * usage. This keeps tag advice local to the folder where the admin is working.
 *
 * @param int $galleryId Gallery providing local suggestion context.
 * @param int $limit Maximum number of suggestions.
 * @return list<array{id:int,name:string,slug:string,score:float,sources:list<string>}> Ranked suggestions with source labels, or an empty list when unavailable.
 */
function weighted_tag_suggestions_for_gallery(
    int $galleryId,
    int $limit = 80,
): array {
    $mediaSchemaState = tag_media_type_schema_state();
    if ($mediaSchemaState === 'unknown') {
        return [];
    }
    if ($mediaSchemaState === 'missing') {
        return weighted_global_tag_suggestions($limit);
    }
    // Variable $gallery stores this steps working value.
    $gallery = $galleryId > 0 ? tag_model_gallery_context($galleryId) : null;
    if (!$gallery) {
        return weighted_global_tag_suggestions($limit);
    }

    // Variable $scores stores this steps working value.
    $scores = [];
    // Variable $details stores this steps working value.
    $details = [];

    /**
     * Add weighted persistence rows to the accumulated score table.
     *
     * @param array<int,array<string,mixed>> $rows Tag usage rows.
     * @param float $weight Source multiplier.
     * @param string $source Human-readable internal source label.
     * @return void
     */
    $addRows = static function (
        array $rows,
        float $weight,
        string $source,
    ) use (&$scores, &$details): void {
        foreach ($rows as $row) {
            // Variable $tagId stores this steps working value.
            $tagId = (int) ($row['id'] ?? 0);
            if ($tagId <= 0) {
                continue;
            }
            // Variable $usageCount stores this steps working value.
            $usageCount = max(1, (int) ($row['usage_count'] ?? 1));
            // Logarithmic usage keeps common tags important without letting them drown out local context.
            $score = $weight * (1.0 + log($usageCount + 1, 2));
            if (!isset($scores[$tagId])) {
                $scores[$tagId] = [
                    'id' => $tagId,
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'score' => 0.0,
                    'sources' => [],
                ];
            }
            $scores[$tagId]['score'] += $score;
            $scores[$tagId]['sources'][$source] = true;
            $details[$tagId][$source] =
                ($details[$tagId][$source] ?? 0) + $usageCount;
        }
    };

    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path(
        (string) ($gallery['folder_path'] ?? ''),
    );
    // Variable $parentId stores this steps working value.
    $parentId = (int) ($gallery['parent_id'] ?? 0);
    // Variable $pathParts stores this steps working value.
    $pathParts = array_values(array_filter(
        explode('/', $folderPath),
        static fn(string $part): bool => $part !== '',
    ));
    // Variable $ancestorPatterns stores this steps working value.
    $ancestorPatterns = [];
    for ($index = 1; $index < count($pathParts); $index++) {
        $ancestorPatterns[] = implode('/', array_slice($pathParts, 0, $index));
    }

    try {
        $addRows(tag_model_suggestion_usage_rows('image', 'current', [
            'gallery_id' => $galleryId,
        ]), 18.0, 'current_images');
        $addRows(tag_model_suggestion_usage_rows('gallery', 'siblings', [
            'parent_id' => $parentId,
            'gallery_id' => $galleryId,
        ]), 12.0, 'siblings');
        $addRows(tag_model_suggestion_usage_rows('image', 'siblings', [
            'parent_id' => $parentId,
            'gallery_id' => $galleryId,
        ]), 8.0, 'sibling_images');

        if ($folderPath !== '') {
            $context = [
                'folder_prefix' => $folderPath . '/%',
                'gallery_id' => $galleryId,
            ];
            $addRows(
                tag_model_suggestion_usage_rows(
                    'gallery',
                    'descendants',
                    $context,
                ),
                10.0,
                'descendants',
            );
            $addRows(
                tag_model_suggestion_usage_rows(
                    'image',
                    'descendants',
                    $context,
                ),
                6.0,
                'descendant_images',
            );
        }

        if ($ancestorPatterns) {
            $addRows(tag_model_suggestion_usage_rows('gallery', 'ancestors', [
                'paths' => $ancestorPatterns,
            ]), 5.0, 'ancestors');
            $addRows(tag_model_suggestion_usage_rows('image', 'ancestors', [
                'paths' => $ancestorPatterns,
            ]), 3.0, 'ancestor_images');
        }

        $addRows(
            tag_model_suggestion_usage_rows('gallery', 'global'),
            1.2,
            'global_galleries',
        );
        $addRows(
            tag_model_suggestion_usage_rows('image', 'global'),
            0.8,
            'global_images',
        );
    } catch (PDOException) {
        return weighted_global_tag_suggestions($limit);
    }

    // Variable $rows stores this steps working value.
    $rows = array_values($scores);
    usort($rows, static function (array $left, array $right): int {
        // Variable $scoreCompare stores this steps working value.
        $scoreCompare = $right['score'] <=> $left['score'];
        return $scoreCompare !== 0
            ? $scoreCompare
            : strcmp((string) $left['name'], (string) $right['name']);
    });

    return array_slice(array_map(static function (array $row): array {
        $row['score'] = round((float) $row['score'], 4);
        $row['sources'] = array_keys((array) ($row['sources'] ?? []));
        return $row;
    }, $rows), 0, max(1, $limit));
}

/**
 * Return weighted tag suggestions when no specific gallery context is available.
 *
 * @param int $limit Maximum number of suggestions.
 * @param string $mediaType Category to suggest: image, music, or pdf; defaults to image.
 * @return list<array{id:int,name:string,slug:string,score:float,sources:list<string>}> Globally ranked suggestions, or an empty list when storage is unavailable.
 */
function weighted_global_tag_suggestions(
    int $limit = 80,
    string $mediaType = 'image',
): array
{
    if (!in_array($mediaType, ['image', 'music', 'pdf'], true)) {
        return [];
    }
    $schemaState = tag_media_type_schema_state();
    if (
        $schemaState === 'unknown'
        || ($mediaType !== 'image' && $schemaState !== 'available')
    ) {
        return [];
    }
    $musicAssignmentReady = $mediaType === 'music'
        && tag_music_assignment_schema_ready();
    try {
        return array_map(
            static function (array $row): array {
                // Variable $usage stores this steps working value.
                $usage =
                    (int) ($row['gallery_count'] ?? 0)
                    + (int) ($row['image_count'] ?? 0)
                    + (int) ($row['music_count'] ?? 0);
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'name' => (string) ($row['name'] ?? ''),
                    'slug' => (string) ($row['slug'] ?? ''),
                    'score' => (float) $usage,
                    'sources' => ['global'],
                ];
            },
            tag_model_global_suggestion_rows(
                max(1, $limit),
                $schemaState === 'available',
                $mediaType,
                $musicAssignmentReady,
            ),
        );
    } catch (PDOException) {
        return [];
    }
}

/**
 * Return true when the optional tag description column is available.
 *
 * @return bool True when the condition matches.
 */
function tag_description_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        return $ready = tag_model_description_column_ready();
    } catch (PDOException) {
        return $ready = false;
    }
}

/**
 * Define the Admin catalog's usage filters independently of tag namespaces.
 *
 * @return array<string,array{label:string,media_type:string,
 *     assignment_scope:string,available:bool}> Filter values mapped to safe
 *     vocabulary/assignment inputs. Documents reserves future PDF UI only;
 *     its unavailable definition must not probe or edit PDF records.
 */
function admin_tag_catalog_filters(): array
{
    return [
        'all' => [
            'label' => t('admin.tags.filter_all', 'All tags'),
            'media_type' => 'all',
            'assignment_scope' => 'all',
            'available' => true,
        ],
        'image' => [
            'label' => t('admin.tags.filter_image', 'Images'),
            'media_type' => 'image',
            'assignment_scope' => 'image',
            'available' => true,
        ],
        'gallery' => [
            'label' => t('admin.tags.filter_gallery', 'Galleries'),
            'media_type' => 'image',
            'assignment_scope' => 'gallery',
            'available' => true,
        ],
        'music' => [
            'label' => t('admin.tags.filter_music', 'Music'),
            'media_type' => 'music',
            'assignment_scope' => 'all',
            'available' => true,
        ],
        'documents' => [
            'label' => t('admin.tags.filter_documents', 'Documents (PDF, later)'),
            'media_type' => 'pdf',
            'assignment_scope' => 'all',
            'available' => false,
        ],
    ];
}

/**
 * Return editable tag rows with gallery and image usage counts.
 *
 * @param string $sortField Sorting key, either name or usage.
 * @param string $sortDirection Sort direction, asc or desc.
 * @param string $mediaType Requested category image, music, or all; defaults to image.
 * @param string $assignmentScope Usage filter all (default), gallery or image. The default
 *     preserves the complete image vocabulary for existing non-Admin callers.
 * @return list<array{id:int|string,name:string,slug:string,media_type:string,description:?string,created_at:string,updated_at:string,gallery_count:int|string,image_count:int|string,music_count:int|string,usage_count:int|string}> Editable tag rows with database-native scalar values, or an empty list when unavailable or outside the catalog allowlists.
 */
function admin_tag_rows(
    string $sortField = 'usage',
    string $sortDirection = 'desc',
    string $mediaType = 'image',
    string $assignmentScope = 'all',
): array {
    if (!in_array($assignmentScope, ['all', 'gallery', 'image'], true)
        || ($assignmentScope !== 'all' && $mediaType !== 'image')) {
        return [];
    }
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return [];
    }
    if (!in_array($mediaType, ['all', 'image', 'music'], true)) {
        return [];
    }
    if ($mediaType !== 'image' && $mediaType !== 'all' && $schemaState !== 'available') {
        return [];
    }
    return tag_model_admin_rows(
        tag_description_schema_ready(),
        $sortField,
        $sortDirection,
        $mediaType,
        $schemaState === 'available',
        ($mediaType === 'music' || $mediaType === 'all')
            && tag_music_assignment_schema_ready(),
        $assignmentScope,
    );
}

/**
 * Return the gallery and image records that use one tag.
 *
 * @param int $tagId Tag id identifier.
 * @return array{galleries:list<array{id:int,title:string,slug:string,url_path:string,folder_path:string,edit_url:string,public_url:string}>,images:list<array{id:int,relative_path:string,filename:string,gallery_id:int,gallery_title:string,gallery_slug:string,edit_url:string}>,songs:list<array{id:int,title:string,edit_url:string}>} Admin links for the records using this tag.
 * @throws \RuntimeException When the music title presentation owner cannot be loaded.
 */
function admin_tag_usage_rows(int $tagId): array
{
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return ['galleries' => [], 'images' => [], 'songs' => []];
    }
    $tag = find_tag_by_id($tagId);
    if (!$tag) {
        return ['galleries' => [], 'images' => [], 'songs' => []];
    }
    $mediaType = $schemaState === 'available'
        ? (string) ($tag['media_type'] ?? '')
        : 'image';
    // Variable $galleries stores this steps working value.
    $galleries = [];
    foreach ($mediaType === 'image'
        ? tag_model_admin_gallery_usage_rows($tagId)
        : [] as $row) {
        $galleries[] = [
            'id' => (int) $row['id'],
            'title' => (string) ($row['title'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'url_path' => (string) ($row['url_path'] ?? ''),
            'folder_path' => (string) ($row['folder_path'] ?? ''),
            'edit_url' => url_for('admin_edit_gallery', [
                'id' => (int) $row['id'],
            ]),
            'public_url' => gallery_public_url($row),
        ];
    }

    // Variable $images stores this steps working value.
    $images = [];
    foreach ($mediaType === 'image'
        ? tag_model_admin_image_usage_rows($tagId)
        : [] as $row) {
        $images[] = [
            'id' => (int) $row['id'],
            'relative_path' => (string) ($row['relative_path'] ?? ''),
            'filename' => (string) ($row['filename'] ?? ''),
            'gallery_id' => (int) ($row['gallery_id'] ?? 0),
            'gallery_title' => (string) ($row['gallery_title'] ?? ''),
            'gallery_slug' => (string) ($row['gallery_slug'] ?? ''),
            'edit_url' => url_for('admin_edit_image', [
                'id' => (int) $row['id'],
            ]),
        ];
    }

    $songs = [];
    if ($mediaType === 'music' && tag_music_assignment_schema_ready()) {
        if (!function_exists('Gallery\\Services\\music_catalog_display_title')) {
            if (function_exists('Gallery\\Core\\cms_runtime_kernel')) {
                \Gallery\Core\cms_runtime_kernel()->load(
                    'gallery-pdf-documents',
                );
            }
        }
        if (!function_exists('Gallery\\Services\\music_catalog_display_title')) {
            throw new \RuntimeException(
                'Music title presentation is unavailable for tag usage.',
            );
        }
        foreach (tag_model_admin_song_usage_rows($tagId) as $row) {
            $songId = (int) ($row['id'] ?? 0);
            $title = music_catalog_display_title(
                (string) ($row['filename'] ?? ''),
                (string) ($row['indexed_title'] ?? ''),
                isset($row['title']) ? (string) $row['title'] : null,
            );
            $songs[] = [
                'id' => $songId,
                'title' => $title !== '' ? $title : t(
                    'admin.music.untitled_song',
                    'Song #{id}',
                    ['id' => $songId],
                ),
                'edit_url' => url_for('admin_music', [
                    'song_id' => $songId,
                ]),
            ];
        }
    }
    return [
        'galleries' => $galleries,
        'images' => $images,
        'songs' => $songs,
    ];
}

/**
 * Fetch one tag by numeric ID for admin editing.
 *
 * @param int $id Identifier value.
 * @return ?array Structured result data for the caller.
 */
function find_tag_by_id(int $id): ?array
{
    return tag_model_find_by_id($id);
}

/**
 * Update one tag row while keeping names, category, and slug canonical.
 *
 * @param int $id Identifier value.
 * @param string $name Name value.
 * @param string $slug Slug value.
 * @param string $description Description value.
 * @param string $mediaType Expected tag category: image, music, or pdf; defaults to image.
 * @return array{ok:false,error:'not_found'|'invalid_name'|'invalid_slug'|'slug_taken'|'schema_unavailable'|'media_type_mismatch'}|array{ok:true,tag:array{id:int|string,name:string,slug:string,media_type?:string,description?:string|null,created_at?:string|null,updated_at?:string|null}|null} Update result and refreshed database row when successful.
 */
function update_tag_metadata(
    int $id,
    string $name,
    string $slug,
    string $description,
    string $mediaType = 'image',
): array {
    // Variable $tag stores this steps working value.
    $tag = find_tag_by_id($id);
    if (!$tag) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return ['ok' => false, 'error' => 'schema_unavailable'];
    }
    $actualType = $schemaState === 'available'
        ? (string) ($tag['media_type'] ?? '')
        : 'image';
    if ($actualType !== $mediaType) {
        return ['ok' => false, 'error' => 'media_type_mismatch'];
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

    if (tag_model_slug_exists_except($safeSlug, $id)) {
        return ['ok' => false, 'error' => 'slug_taken'];
    }

    tag_model_update_metadata(
        $id,
        $safeName,
        $safeSlug,
        trim($description),
        tag_description_schema_ready(),
        now_sql(),
    );
    return ['ok' => true, 'tag' => find_tag_by_id($id)];
}

/**
 * Delete one image or music tag after checking the requested category.
 *
 * @param int $id Tag identifier to delete.
 * @param string $expectedMediaType Expected category image or music for this Admin operation; defaults to image.
 * @return array{ok:false,error:'not_found'|'schema_unavailable'|'media_type_mismatch'|'delete_failed'}|array{ok:true,tag:array{id:int|string,name:string,slug:string,media_type?:string,description?:string|null,created_at?:string|null,updated_at?:string|null}} Deletion result and deleted tag snapshot.
 */
function delete_tag_by_id(
    int $id,
    string $expectedMediaType = 'image',
): array
{
    // Variable $tag stores this steps working value.
    $tag = find_tag_by_id($id);
    if (!$tag) {
        return ['ok' => false, 'error' => 'not_found'];
    }
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return ['ok' => false, 'error' => 'schema_unavailable'];
    }
    $mediaType = $schemaState === 'available'
        ? (string) ($tag['media_type'] ?? '')
        : 'image';
    if ($mediaType !== $expectedMediaType) {
        return ['ok' => false, 'error' => 'media_type_mismatch'];
    }
    try {
        $visitSources = $mediaType === 'image'
            ? \Gallery\Models\gallery_visit_content_model_tag_sources($id) : [];
        tag_model_delete_with_assignments(
            $id,
            $mediaType,
            $mediaType === 'music' && tag_music_assignment_schema_ready(),
        );
    } catch (Throwable) {
        return ['ok' => false, 'error' => 'delete_failed'];
    }
    gallery_visit_content_update($visitSources);
    return ['ok' => true, 'tag' => $tag];
}

/**
 * Normalize existing tags to safe lowercase values and merge duplicates.
 *
 * @return int Number of tag rows normalized, merged, or removed; PDF rows remain unchanged.
 */
function normalize_existing_tags(): int
{
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return 0;
    }
    // Variable $changed stores this steps working value.
    $changed = 0;
    // Variable $rows stores this steps working value.
    $rows = tag_model_all_rows_by_id();
    // Variable $seen stores reserved slugs and same-type canonical rows.
    $seen = [];
    // Untouched PDF rows still own globally unique slugs during normalization.
    foreach ($rows as $row) {
        if (($row['media_type'] ?? '') === 'pdf') {
            $seen['slug:' . (string) ($row['slug'] ?? '')] = true;
        }
    }
    foreach ($rows as $row) {
        // Variable $id stores this steps working value.
        $id = (int) $row['id'];
        // Variable $safeName stores this steps working value.
        $safeName = sanitize_tag_name((string) ($row['name'] ?? ''));
        // Variable $safeSlug stores this steps working value.
        $safeSlug = sanitize_tag_name((string) ($row['slug'] ?? $safeName));
        $mediaType = $schemaState === 'available'
            ? (string) ($row['media_type'] ?? '')
            : 'image';
        if ($mediaType === 'pdf') {
            continue;
        }
        if ($safeName === '' && $safeSlug === '') {
            tag_model_delete_row($id);
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
        $sameTypeKey = $mediaType . ':' . $key;
        $canonicalSameTypeKey = $sameTypeKey;
        if (isset($seen[$sameTypeKey])) {
            // Variable $targetId stores this steps working value.
            $targetId = (int) $seen[$sameTypeKey];
            tag_model_merge_duplicate(
                $targetId,
                $id,
                $schemaState === 'available',
                tag_music_assignment_schema_ready(),
            );
            $changed++;
            continue;
        }
        $slugTaken = isset($seen['slug:' . $key])
            || tag_model_slug_exists_except($key, $id);
        if ($slugTaken) {
            $safeSlug = '';
            for (
                $attempt = 1;
                $attempt <= TAG_MEDIA_SLUG_MAX_ATTEMPTS;
                $attempt++
            ) {
                $suffix = '-' . $mediaType . '-' . $id
                    . ($attempt === 1 ? '' : '-' . $attempt);
                $candidate = substr(
                    $key,
                    0,
                    TAG_MEDIA_SLUG_MAX_BYTES - strlen($suffix),
                ) . $suffix;
                if (
                    isset($seen['slug:' . $candidate])
                    || tag_model_slug_exists_except($candidate, $id)
                ) {
                    continue;
                }
                $safeSlug = $candidate;
                break;
            }
            if ($safeSlug === '') {
                // Preserve the row when no bounded globally unique slug exists.
                continue;
            }
            $key = $safeSlug;
            $sameTypeKey = $mediaType . ':' . $key;
        }
        $seen['slug:' . $key] = true;
        $seen[$canonicalSameTypeKey] = $id;
        $seen[$sameTypeKey] = $id;
        if (
            (string) $row['name'] !== $safeName
            || (string) $row['slug'] !== $safeSlug
        ) {
            tag_model_update_canonical($id, $safeName, $safeSlug, now_sql());
            $changed++;
        }
    }
    return $changed;
}

/**
 * Normalize gallery sidecar tag text recursively so filesystem metadata matches the database convention.
 *
 * @return int Integer result for the caller.
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
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root,
        FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($iterator as $file) {
        if (
            !$file instanceof SplFileInfo
            || $file->getFilename() !== 'gallery.json'
        ) {
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
        $source = is_array($rawTags)
            ? implode(', ', array_map('strval', $rawTags))
            : (string) $rawTags;
        // Variable $normalized stores this steps working value.
        $normalized = implode(', ', split_tag_names($source));
        if ($normalized !== $source) {
            $data['tags'] = $normalized;
            file_put_contents($path, json_encode(
                $data,
                JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            ));
            $changed++;
        }
    }
    return $changed;
}

/**
 * Fetch one tag by slug for public tag-filter pages.
 *
 * @param string $slug Canonical public tag slug.
 * @return ?array{id:int|string,name:string,slug:string,media_type?:string,description?:string|null,created_at?:string|null,updated_at?:string|null} Matching image tag row, or null when unavailable or absent.
 */
function find_tag_by_slug(string $slug): ?array
{
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return null;
    }
    return tag_model_find_by_slug(
        $slug,
        $schemaState === 'available',
        'image',
    );
}

/**
 * Return public galleries that directly or indirectly contain a tag.
 *
 * @param int $tagId Image tag identifier.
 * @return list<array{id:int,title:string,slug:string,url_path:string,folder_path:string}> Public galleries using the tag, or an empty list.
 */
function public_galleries_for_tag(int $tagId): array
{
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return [];
    }
    gallery_visibility_assert_public_policy_available();
    gallery_access_assert_public_policy_available();
    return tag_model_public_galleries(
        $tagId,
        gallery_access_schema_ready(),
        $schemaState === 'available',
    );
}

/**
 * Aggregate tags from descendant galleries and descendant images.
 *
 * @param array{folder_path:string} $gallery Gallery row anchoring the descendant search.
 * @param bool $publicOnly Whether to apply public visibility and access policy.
 * @return list<array{id:int|string,name:string,slug:string}> Unique tags on descendant galleries and images.
 */
function contained_tags_for_gallery(array $gallery, bool $publicOnly): array
{
    $schemaState = tag_media_type_schema_state();
    if ($schemaState === 'unknown') {
        return [];
    }
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    if ($folderPath === '') {
        return [];
    }
    if ($publicOnly) {
        gallery_visibility_assert_public_policy_available();
        gallery_access_assert_public_policy_available();
    }
    return tag_model_contained_for_gallery(
        $folderPath,
        $publicOnly,
        $publicOnly && gallery_access_schema_ready(),
        $schemaState === 'available',
    );
}
