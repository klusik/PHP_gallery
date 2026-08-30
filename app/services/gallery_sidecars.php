<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_sidecars.php
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

use DirectoryIterator;
use FilesystemIterator;
use PDO;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\unique_slug;

/**
Gallery discovery and sidecar metadata helpers.
 *
 * These functions keep the filesystem-first model intact. The database remains
 * an index, while gallery.json sidecars provide optional metadata next to the
 * gallery folder. No theme, favicon, or custom CSS settings are handled here.
 */

/**
 * Write gallery metadata into a sidecar before or after a DB row exists.
 *
 * @param string $folderPath Folder path filesystem path.
 * @param array $data Input data.
 * @return bool True when the condition matches.
 */
function write_gallery_sidecar_for_path(string $folderPath, array $data): bool
{
    // $path stores an intermediate value used by the surrounding gallery workflow.
    $path = gallery_abs_path($folderPath) . DIRECTORY_SEPARATOR . 'gallery.json';
    // $directory stores the destination folder where gallery.json should be written.
    $directory = dirname($path);
    if (!is_dir($directory)) {
        return false;
    }

    return @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false;
}

/**
 * Find folders under galleries_root that can become gallery records.
 *
 * A folder is a candidate when it contains direct images, descendant images, or
 * a gallery.json sidecar. Descendant images allow empty parent folders to become
 * top-level galleries that contain subgalleries.
 *
 * @return array Structured result data for the caller.
 */
function discover_gallery_candidates(): array
{
    // Variable $root stores this steps working value.
    $root = galleries_root();
    if (!is_dir($root)) {
        return [];
    }

    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $known stores this steps working value.
    $known = $pdo->query('SELECT folder_path FROM galleries')->fetchAll(PDO::FETCH_COLUMN);
    // Variable $known stores this steps working value.
    $known = array_flip($known);
    // Variable $candidates stores this steps working value.
    $candidates = [];
    // Variable $ignoreNames stores this steps working value.
    $ignoreNames = ['cache', 'thumbs', 'thumbnail', 'thumbnails', 'preview', 'previews', '_php-gallery-internal'];
    // Variable $iterator stores this steps working value.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            function (SplFileInfo $file) use ($ignoreNames): bool {
                if (!$file->isDir()) {
                    return true;
                }
                // Variable $name stores this steps working value.
                $name = $file->getFilename();
                return !str_starts_with($name, '.') && !in_array(strtolower($name), $ignoreNames, true);
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if (!$item->isDir()) {
            continue;
        }
        // Variable $relative stores this steps working value.
        $relative = normalize_relative_path(substr($item->getPathname(), strlen($root)));
        if ($relative === '' || isset($known[$relative])) {
            continue;
        }
        // Variable $hasImages stores this steps working value.
        $hasImages = false;
        foreach (new DirectoryIterator($item->getPathname()) as $child) {
            if ($child->isFile() && is_supported_image_path($child->getFilename())) {
                // Variable $hasImages stores this steps working value.
                $hasImages = true;
                break;
            }
        }
        // Variable $hasDescendantImages stores this steps working value.
        $hasDescendantImages = false;
        if (!$hasImages) {
            // Variable $descendants stores this steps working value.
            $descendants = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($item->getPathname(), FilesystemIterator::SKIP_DOTS));
            foreach ($descendants as $descendant) {
                if ($descendant->isFile() && is_supported_image_path($descendant->getFilename())) {
                    // Variable $hasDescendantImages stores this steps working value.
                    $hasDescendantImages = true;
                    break;
                }
            }
        }
        // Variable $jsonPath stores this steps working value.
        $jsonPath = $item->getPathname() . DIRECTORY_SEPARATOR . 'gallery.json';
        if ($hasImages || $hasDescendantImages || is_file($jsonPath)) {
            // Variable $metadata stores this steps working value.
            $metadata = read_gallery_sidecar($jsonPath);
            $candidates[] = [
                'folder_path' => $relative,
                'title' => $metadata['title'] ?? basename($relative),
                'description' => $metadata['description'] ?? '',
                'visibility' => gallery_visibility_storage_value((string) ($metadata['visibility'] ?? 'unpublished')),
                'access_mode' => $metadata['access_mode'] ?? 'normal',
                'access_listing' => $metadata['access_listing'] ?? 'listed',
                'banner_image_path' => $metadata['banner_image_path'] ?? null,
                'logo_image_path' => $metadata['logo_image_path'] ?? null,
                'separator_image_path' => $metadata['separator_image_path'] ?? null,
                'sort_order' => (int) ($metadata['sort_order'] ?? 0),
            ];
        }
    }

    return $candidates;
}


/**
 * Normalize tag values inside every gallery.json sidecar under the configured gallery root.
 */
function normalize_gallery_sidecar_tags_recursive(): void
{
    if (!function_exists('Gallery\\Services\\galleries_root') || !function_exists('normalize_tag_name')) {
        return;
    }
    // Variable $root stores this steps working value.
    $root = galleries_root();
    if (!is_dir($root)) {
        return;
    }
    try {
        // Variable $iterator stores this steps working value.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file): bool {
                    if (!$file->isDir()) {
                        return true;
                    }
                    // Variable $name stores this steps working value.
                    $name = strtolower($file->getFilename());
                    return !str_starts_with($name, '.') && !in_array($name, ['cache', 'thumbs', 'thumbnail', 'thumbnails', 'preview', 'previews', '_php-gallery-internal'], true);
                }
            )
        );
        foreach ($iterator as $item) {
            if (!$item->isFile() || $item->getFilename() !== 'gallery.json') {
                continue;
            }
            // Variable $path stores this steps working value.
            $path = $item->getPathname();
            // Variable $data stores this steps working value.
            $data = json_decode((string) file_get_contents($path), true);
            if (!is_array($data) || !array_key_exists('tags', $data)) {
                continue;
            }
            // Variable $rawTags stores this steps working value.
            $rawTags = is_array($data['tags']) ? $data['tags'] : (preg_split('/[,;\n]+/', (string) $data['tags']) ?: []);
            // Variable $tags stores this steps working value.
            $tags = [];
            foreach ($rawTags as $tag) {
                // Variable $name stores this steps working value.
                $name = normalize_tag_name((string) $tag);
                if ($name !== '') {
                    $tags[$name] = $name;
                }
            }
            // Variable $normalizedTags stores this steps working value.
            $normalizedTags = implode(', ', array_values($tags));
            if ((string) $data['tags'] === $normalizedTags) {
                continue;
            }
            $data['tags'] = $normalizedTags;
            @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    } catch (Throwable) {
        return;
    }
}

/**
 * Read optional gallery metadata from gallery.json.
 *
 * @param string $path Filesystem path.
 * @return array Structured result data for the caller.
 */
function read_gallery_sidecar(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    // Variable $data stores this steps working value.
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

/**
 * Return public SEO metadata for one gallery, combining gallery.json and DB values.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array Structured result data for the caller.
 */
function public_gallery_metadata(array $gallery): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
    // Variable $sidecar stores this steps working value.
    $sidecar = [];
    try {
        // $sidecar stores an intermediate value used by the surrounding gallery workflow.
        $sidecar = read_gallery_sidecar(gallery_abs_path($folderPath) . DIRECTORY_SEPARATOR . 'gallery.json');
    } catch (Throwable) {
        // $sidecar stores an intermediate value used by the surrounding gallery workflow.
        $sidecar = [];
    }

    // Variable $title stores this steps working value.
    $localized = isset($gallery['_content_localization']) && is_array($gallery['_content_localization']);
    $title = $localized ? trim((string) ($gallery['title'] ?? '')) : trim((string) ($sidecar['title'] ?? ''));
    if ($title === '') {
        // $title stores an intermediate value used by the surrounding gallery workflow.
        $title = trim((string) ($gallery['title'] ?? ''));
    }
    if ($title === '') {
        // $title stores an intermediate value used by the surrounding gallery workflow.
        $title = gallery_folder_name_from_path($folderPath);
    }

    // Variable $description stores this steps working value.
    $description = $localized ? trim((string) ($gallery['description'] ?? '')) : trim((string) ($sidecar['description'] ?? ''));
    if ($description === '') {
        // $description stores an intermediate value used by the surrounding gallery workflow.
        $description = trim((string) ($gallery['description'] ?? ''));
    }
    if ($description === '') {
        // $description stores an intermediate value used by the surrounding gallery workflow.
        $description = $title;
    }

    // Variable $tags stores this steps working value.
    $tags = [];
    // $rawTags stores an intermediate value used by the surrounding gallery workflow.
    $rawTags = $sidecar['tags'] ?? '';
    // $tagValues stores an intermediate value used by the surrounding gallery workflow.
    $tagValues = is_array($rawTags) ? $rawTags : (preg_split('/[,;\n]+/', (string) $rawTags) ?: []);
    foreach ($tagValues as $tag) {
        // $tag stores an intermediate value used by the surrounding gallery workflow.
        $tag = function_exists('normalize_tag_name') ? normalize_tag_name((string) $tag) : strtolower(trim((string) $tag));
        if ($tag !== '') {
            $tags[] = $tag;
        }
    }

    return [
        'title' => $title,
        'description' => $description,
        'tags' => array_values(array_unique($tags)),
    ];
}

/**
 * Persist editable gallery metadata back into gallery.json.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function write_gallery_sidecar(array $gallery): void
{
    // Variable $data stores this steps working value.
    $data = [
        'title' => $gallery['title'],
        'description' => $gallery['description'],
        'tags' => implode(', ', array_column(tags_for_entity('gallery', (int) $gallery['id']), 'name')),
        'visibility' => $gallery['visibility'],
        'sort_order' => (int) $gallery['sort_order'],
        'voting_enabled' => (int) ($gallery['voting_enabled'] ?? 0),
        'show_filenames' => (int) ($gallery['show_filenames'] ?? 0),
    ];
    if (content_localization_schema_ready('gallery')) {
        $data['content_language'] = content_language_normalize($gallery['content_language'] ?? null);
        $translationRows = content_translation_rows('gallery', [(int) $gallery['id']]);
        $data['translations'] = $translationRows[(int) $gallery['id']] ?? [];
    }
    if (gallery_date_schema_ready() && !empty($gallery['gallery_date'])) {
        $data['gallery_date'] = gallery_date_storage_value($gallery['gallery_date']);
    }
    if (gallery_date_range_schema_ready() && !empty($gallery['gallery_date_end'])) {
        $data['gallery_date_end'] = gallery_date_storage_value($gallery['gallery_date_end']);
    }
    if (gallery_description_layout_schema_ready()) {
        // $descriptionLayout stores a per-gallery card layout override, when this gallery has one.
        $descriptionLayout = gallery_description_layout_storage_value($gallery['description_layout'] ?? null);
        if ($descriptionLayout !== null) {
            $data['description_layout'] = $descriptionLayout;
        }
    }
    if (gallery_count_badge_schema_ready()) {
        // $countBadgeVisibility stores a per-gallery count badge override, when this gallery has one.
        $countBadgeVisibility = gallery_count_badge_storage_value($gallery['count_badge_visibility'] ?? null);
        if ($countBadgeVisibility !== null) {
            $data['count_badge_visibility'] = $countBadgeVisibility;
        }
    }
    if (gallery_lightbox_browsing_mode_schema_ready()) {
        // $lightboxBrowsingMode stores a per-gallery lightbox mode override, when this gallery has one.
        $lightboxBrowsingMode = gallery_lightbox_browsing_mode_storage_value($gallery['lightbox_browsing_mode'] ?? null);
        if ($lightboxBrowsingMode !== null) {
            $data['lightbox_browsing_mode'] = $lightboxBrowsingMode;
        }
    }
    if (gallery_grid_schema_ready() && gallery_grid_has_explicit_override($gallery)) {
        $data['grid_columns'] = (int) $gallery['grid_columns'];
        $data['grid_rows'] = (int) $gallery['grid_rows'];
        $data['grid_use_for_subgalleries'] = (int) ($gallery['grid_use_for_subgalleries'] ?? 1);
    }
    if (thumbnail_bounds_schema_ready()) {
        if (isset($gallery['thumbnail_min_size']) && $gallery['thumbnail_min_size'] !== null) {
            $data['thumbnail_min_size'] = (int) $gallery['thumbnail_min_size'];
        }
        if (isset($gallery['thumbnail_max_size']) && $gallery['thumbnail_max_size'] !== null) {
            $data['thumbnail_max_size'] = (int) $gallery['thumbnail_max_size'];
        }
    }
    gallery_access_assert_public_policy_available();
    if (gallery_access_schema_ready()) {
        $data['access_mode'] = $gallery['access_mode'] ?? 'normal';
        $data['access_listing'] = $gallery['access_listing'] ?? 'listed';
    }
    if (!empty($gallery['cover_image_id'])) {
        // Variable $cover stores this steps working value.
        $cover = find_image((int) $gallery['cover_image_id']);
        if ($cover) {
            $data['cover'] = $cover['relative_path'];
        }
    }
    if (!empty($gallery['cover_image_path'])) {
        $data['cover_image_path'] = (string) $gallery['cover_image_path'];
    }
    if (function_exists('Gallery\\Services\\gallery_branding_schema_ready') && gallery_branding_schema_ready()) {
        foreach (gallery_branding_asset_types() as $kind => $definition) {
            // $column stores an intermediate value used by the surrounding gallery workflow.
            $column = (string) $definition['column'];
            if (!empty($gallery[$column])) {
                $data[$column] = (string) $gallery[$column];
            }
        }
    }
    write_gallery_sidecar_for_path((string) $gallery['folder_path'], $data);
}

/**
 * Return lightweight metadata for one gallery folder, using gallery.json when it exists.
 *
 * This helper is intentionally small and filesystem-backed because it is used by
 * the importer and the parent-sync repair path. Empty parent folders can still
 * become real gallery rows when they are needed to preserve a nested gallery
 * hierarchy, even when those parent folders contain no direct photos.
 *
 * @param string $folderPath Folder path filesystem path.
 * @return array Structured result data for the caller.
 */
function gallery_folder_candidate_metadata(string $folderPath): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path($folderPath);
    // Variable $jsonPath stores this steps working value.
    $jsonPath = gallery_abs_path($folderPath) . DIRECTORY_SEPARATOR . 'gallery.json';
    // Variable $metadata stores this steps working value.
    $metadata = read_gallery_sidecar($jsonPath);

    return [
        'folder_path' => $folderPath,
        'title' => $metadata['title'] ?? basename($folderPath),
        'description' => $metadata['description'] ?? '',
        'content_language' => content_language_normalize($metadata['content_language'] ?? null),
        'translations' => is_array($metadata['translations'] ?? null) ? $metadata['translations'] : [],
        'gallery_date' => gallery_date_schema_ready() ? gallery_date_sidecar_value($metadata['gallery_date'] ?? '') : null,
        'gallery_date_end' => gallery_date_range_schema_ready() ? gallery_date_sidecar_range_values($metadata['gallery_date'] ?? '', $metadata['gallery_date_end'] ?? '')['end'] : null,
        'visibility' => gallery_visibility_storage_value((string) ($metadata['visibility'] ?? 'unpublished')),
        'voting_enabled' => (int) ($metadata['voting_enabled'] ?? 0),
        'show_filenames' => (int) ($metadata['show_filenames'] ?? 0),
        'description_layout' => gallery_description_layout_storage_value($metadata['description_layout'] ?? null),
        'count_badge_visibility' => gallery_count_badge_storage_value($metadata['count_badge_visibility'] ?? null),
        'lightbox_browsing_mode' => gallery_lightbox_browsing_mode_storage_value($metadata['lightbox_browsing_mode'] ?? null),
        'grid_columns' => isset($metadata['grid_columns']) ? (int) $metadata['grid_columns'] : null,
        'grid_rows' => isset($metadata['grid_rows']) ? (int) $metadata['grid_rows'] : null,
        'grid_use_for_subgalleries' => array_key_exists('grid_use_for_subgalleries', $metadata) ? (int) $metadata['grid_use_for_subgalleries'] : 1,
        'thumbnail_min_size' => isset($metadata['thumbnail_min_size']) ? (int) $metadata['thumbnail_min_size'] : null,
        'thumbnail_max_size' => isset($metadata['thumbnail_max_size']) ? (int) $metadata['thumbnail_max_size'] : null,
        'banner_image_path' => $metadata['banner_image_path'] ?? null,
        'logo_image_path' => $metadata['logo_image_path'] ?? null,
        'separator_image_path' => $metadata['separator_image_path'] ?? null,
        'access_mode' => $metadata['access_mode'] ?? 'normal',
        'access_listing' => $metadata['access_listing'] ?? 'listed',
        'sort_order' => (int) ($metadata['sort_order'] ?? 0),
    ];
}

/**
 * Create one gallery row for a real filesystem folder when it is missing.
 *
 * The function is used for two related cases:
 * 1. normal imports selected from the discovery screen;
 * 2. automatic repair of missing parent rows for already-imported deep folders.
 *
 * The created row is deliberately conservative: visibility defaults to unpublished
 * unless gallery.json says otherwise, and images are scanned only by the caller.
 *
 * @param string $folderPath Folder path filesystem path.
 * @return ?array Structured result data for the caller.
 */
function create_gallery_row_for_folder(string $folderPath): ?array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path($folderPath);
    if ($folderPath === '' || !is_dir(gallery_abs_path($folderPath))) {
        return null;
    }

    // Variable $existing stores this steps working value.
    $existing = find_gallery_by_folder_path($folderPath, true);
    if ($existing) {
        return $existing;
    }

    // Variable $candidate stores this steps working value.
    $candidate = gallery_folder_candidate_metadata($folderPath);
    if ((int) ($candidate['voting_enabled'] ?? 0) === 1) {
        presentation_schema_assert_write_available(
            presentation_voting_schema_status(),
            'gallery_sidecar_import.voting_enabled',
            'Image voting requires the current database migration before it can be enabled for an imported gallery.'
        );
    }
    if (($candidate['lightbox_browsing_mode'] ?? null) !== null) {
        presentation_schema_assert_known(
            presentation_lightbox_override_schema_status(),
            'gallery_sidecar_import.lightbox_override'
        );
    }
    // Variable $visibility stores this steps working value.
    $visibility = gallery_visibility_storage_value((string) ($candidate['visibility'] ?? 'unpublished'));
    // $votingEnabled stores an intermediate value used by the surrounding gallery workflow.
    $votingEnabled = (int) ($candidate['voting_enabled'] ?? 0) === 1 ? 1 : 0;
    // $showFilenames stores an intermediate value used by the surrounding gallery workflow.
    $showFilenames = gallery_filename_display_schema_ready() && (int) ($candidate['show_filenames'] ?? 0) === 1 ? 1 : 0;
    // $descriptionLayout stores a per-gallery card layout override read from gallery.json.
    $descriptionLayout = gallery_description_layout_schema_ready() ? gallery_description_layout_storage_value($candidate['description_layout'] ?? null) : null;
    // $countBadgeVisibility stores a per-gallery count badge override read from gallery.json.
    $countBadgeVisibility = gallery_count_badge_schema_ready() ? gallery_count_badge_storage_value($candidate['count_badge_visibility'] ?? null) : null;
    // $lightboxBrowsingMode stores a per-gallery lightbox mode override read from gallery.json.
    $lightboxBrowsingMode = gallery_lightbox_browsing_mode_schema_ready() ? gallery_lightbox_browsing_mode_storage_value($candidate['lightbox_browsing_mode'] ?? null) : null;
    // $galleryDateRange stores the optional manual gallery date range read from gallery.json.
    $galleryDateRange = gallery_date_schema_ready() ? gallery_date_sidecar_range_values($candidate['gallery_date'] ?? '', $candidate['gallery_date_end'] ?? '') : ['start' => null, 'end' => null];
    // $galleryDate stores the optional manual gallery date or range start read from gallery.json.
    $galleryDate = $galleryDateRange['start'];
    // $galleryDateEnd stores the optional manual gallery date range end read from gallery.json.
    $galleryDateEnd = $galleryDateRange['end'];
    // Access policy must not interpret partial/unknown schema as an unprotected gallery.
    gallery_access_assert_public_policy_available();
    // $accessMode stores an intermediate value used by the surrounding gallery workflow.
    $accessMode = gallery_access_schema_ready() && ($candidate['access_mode'] ?? '') === 'password' ? 'password' : 'normal';
    // $candidateHasGrid stores whether gallery.json defines a complete custom display grid.
    $candidateHasGrid = gallery_grid_schema_ready() && isset($candidate['grid_columns'], $candidate['grid_rows']) && $candidate['grid_columns'] !== null && $candidate['grid_rows'] !== null;
    // $candidateHasThumbnailBounds stores whether gallery.json defines responsive thumbnail size guardrails.
    $candidateHasThumbnailBounds = thumbnail_bounds_schema_ready() && ($candidate['thumbnail_min_size'] !== null || $candidate['thumbnail_max_size'] !== null);
    // $accessListing stores an intermediate value used by the surrounding gallery workflow.
    $accessListing = gallery_access_schema_ready() && ($candidate['access_listing'] ?? '') === 'unlisted' ? 'unlisted' : 'listed';
    // Variable $parent stores this steps working value.
    $parent = find_parent_gallery_for_path($folderPath);
    // Variable $pdo stores this steps working value.
    $pdo = db();
    // Variable $stmt stores this steps working value.
    $columns = ['parent_id', 'folder_path', 'folder_path_hash', 'slug', 'title', 'description', 'sort_order', 'visibility', 'voting_enabled'];
    // $values stores an intermediate value used by the surrounding gallery workflow.
    $values = [
        $parent ? (int) $parent['id'] : null,
        $folderPath,
        hash('sha256', $folderPath),
        unique_slug($pdo, (string) $candidate['title']),
        $candidate['title'],
        $candidate['description'],
        (int) $candidate['sort_order'],
        $visibility,
        $votingEnabled,
    ];
    if (gallery_filename_display_schema_ready()) {
        $columns[] = 'show_filenames';
        $values[] = $showFilenames;
    }
    if (content_localization_schema_ready('gallery')) {
        $columns[] = 'content_language';
        $values[] = content_language_normalize($candidate['content_language'] ?? null);
    }
    if (gallery_description_layout_schema_ready()) {
        $columns[] = 'description_layout';
        $values[] = $descriptionLayout;
    }
    if (gallery_count_badge_schema_ready()) {
        $columns[] = 'count_badge_visibility';
        $values[] = $countBadgeVisibility;
    }
    if (gallery_lightbox_browsing_mode_schema_ready()) {
        $columns[] = 'lightbox_browsing_mode';
        $values[] = $lightboxBrowsingMode;
    }
    if (gallery_date_schema_ready()) {
        $columns[] = 'gallery_date';
        $values[] = $galleryDate;
    }
    if (gallery_date_range_schema_ready()) {
        $columns[] = 'gallery_date_end';
        $values[] = $galleryDateEnd;
    }
    if (gallery_grid_schema_ready()) {
        $columns[] = 'grid_columns';
        $columns[] = 'grid_rows';
        $columns[] = 'grid_use_for_subgalleries';
        $values[] = $candidateHasGrid ? pagination_dimension_value($candidate['grid_columns'], CMS_PAGINATION_DEFAULT_COLUMNS, CMS_PAGINATION_MAX_COLUMNS) : null;
        $values[] = $candidateHasGrid ? pagination_dimension_value($candidate['grid_rows'], CMS_PAGINATION_DEFAULT_ROWS, CMS_PAGINATION_MAX_ROWS) : null;
        $values[] = !empty($candidate['grid_use_for_subgalleries']) ? 1 : 0;
    }
    if (thumbnail_bounds_schema_ready()) {
        $columns[] = 'thumbnail_min_size';
        $columns[] = 'thumbnail_max_size';
        $values[] = $candidateHasThumbnailBounds ? thumbnail_bound_post_value($candidate['thumbnail_min_size']) : null;
        $values[] = $candidateHasThumbnailBounds ? thumbnail_bound_post_value($candidate['thumbnail_max_size']) : null;
    }
    if (gallery_access_schema_ready()) {
        $columns[] = 'access_mode';
        $columns[] = 'access_listing';
        $values[] = $accessMode;
        $values[] = $accessMode === 'password' ? $accessListing : 'listed';
    }
    if (function_exists('Gallery\\Services\\gallery_branding_schema_ready') && gallery_branding_schema_ready()) {
        foreach (gallery_branding_asset_types() as $kind => $definition) {
            // $column stores an intermediate value used by the surrounding gallery workflow.
            $column = (string) $definition['column'];
            $columns[] = $column;
            $values[] = !empty($candidate[$column]) ? normalize_relative_path((string) $candidate[$column]) : null;
        }
    }
    $columns[] = 'created_at';
    $columns[] = 'updated_at';
    $values[] = now_sql();
    $values[] = now_sql();
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = $pdo->prepare('INSERT INTO galleries (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')');
    $stmt->execute($values);

    $createdGalleryId = (int) $pdo->lastInsertId();
    if (content_localization_schema_ready('gallery')) {
        content_save_localizations('gallery', $createdGalleryId, $candidate['content_language'] ?? null, $candidate['translations'] ?? []);
    }

    // Variable $gallery stores this steps working value.
    $gallery = find_gallery($createdGalleryId);
    if ($gallery) {
        write_gallery_sidecar($gallery);
    }
    return $gallery;
}

/**
 * Create a real empty folder and immediately index it as a gallery.
 */
/**
 * Calculate a sort order that places a newly created gallery before its siblings.
 *
 * Existing reorder behavior still uses explicit sort_order values. This helper is
 * only used when the creation flow did not provide a manual order value, so the
 * most recently added child appears first after the next server render.
 *
 * @param int $parentId Parent id identifier.
 * @return int Integer result for the caller.
 */
function next_gallery_prepend_sort_order(int $parentId): int
{
    if ($parentId > 0) {
        // $stmt stores the query used for normal child galleries.
        $stmt = db()->prepare('SELECT COALESCE(MIN(sort_order), 0) FROM galleries WHERE parent_id = ?');
        $stmt->execute([$parentId]);
    } else {
        // $stmt stores the query used for root-level galleries.
        $stmt = db()->query('SELECT COALESCE(MIN(sort_order), 0) FROM galleries WHERE parent_id IS NULL');
    }
    // $minimumSortOrder stores the first currently rendered sibling order.
    $minimumSortOrder = (int) $stmt->fetchColumn();
    return $minimumSortOrder - 10;
}

/**
 * Create empty gallery.
 *
 * Part of the related application service.
 *
 * @param array $input Input value.
 * @return array Structured result data for the caller.
 */
function create_empty_gallery(array $input): array
{
    // $title stores an intermediate value used by the surrounding gallery workflow.
    $title = trim((string) ($input['title'] ?? ''));
    if ($title === '') {
        throw new RuntimeException('Gallery title is required.');
    }
    // $description stores an intermediate value used by the surrounding gallery workflow.
    $description = (string) ($input['description'] ?? '');
    if (!empty($input['voting_enabled'])) {
        presentation_schema_assert_write_available(
            presentation_voting_schema_status(),
            'gallery_create.voting_enabled',
            'Image voting requires the current database migration before it can be enabled for a new gallery.'
        );
    }
    if (array_key_exists('lightbox_browsing_mode', $input)) {
        presentation_schema_assert_known(
            presentation_lightbox_override_schema_status(),
            'gallery_create.lightbox_override'
        );
    }
    // $galleryDateRange stores the optional manual date range for this gallery, independent from upload dates.
    $galleryDateRange = gallery_date_schema_ready()
        ? gallery_date_range_storage_values($input['gallery_date'] ?? '', $input['gallery_date_end'] ?? '')
        : ['start' => null, 'end' => null];
    // $galleryDate stores the optional manual date or range start for this gallery.
    $galleryDate = $galleryDateRange['start'];
    // $galleryDateEnd stores the optional manual date range end for this gallery.
    $galleryDateEnd = $galleryDateRange['end'];
    // $visibility stores an intermediate value used by the surrounding gallery workflow.
    $visibility = gallery_visibility_storage_value((string) ($input['visibility'] ?? 'unpublished'));
    // $votingEnabled stores an intermediate value used by the surrounding gallery workflow.
    $votingEnabled = !empty($input['voting_enabled']) ? 1 : 0;
    // $showFilenames stores an intermediate value used by the surrounding gallery workflow.
    $showFilenames = !empty($input['show_filenames']) ? 1 : 0;
    // $countBadgeVisibility stores the optional per-gallery count badge override.
    $countBadgeVisibility = gallery_count_badge_schema_ready() ? gallery_count_badge_storage_value($input['count_badge_visibility'] ?? 'inherit') : null;
    // $lightboxBrowsingMode stores the optional per-gallery lightbox browsing-mode override.
    $lightboxBrowsingMode = gallery_lightbox_browsing_mode_schema_ready() ? gallery_lightbox_browsing_mode_storage_value($input['lightbox_browsing_mode'] ?? 'inherit') : null;
    // $parentId stores an intermediate value used by the surrounding gallery workflow.
    $parentId = (int) ($input['parent_id'] ?? 0);
    // $parent stores an intermediate value used by the surrounding gallery workflow.
    $parent = $parentId > 0 ? find_gallery($parentId) : null;
    if ($parentId > 0 && !$parent) {
        throw new RuntimeException('Selected parent gallery does not exist.');
    }

    // $folderName stores an intermediate value used by the surrounding gallery workflow.
    $folderName = trim((string) ($input['folder_name'] ?? ''));
    // $requestedFolderPath stores the preferred folder path before suffix fallback is applied.
    $requestedFolderPath = gallery_child_folder_path($parent, $folderName !== '' ? $folderName : $title);
    if (!is_dir(gallery_target_abs_path($requestedFolderPath)) && function_exists('Gallery\Services\delete_missing_gallery_database_subtree_by_folder_path')) {
        delete_missing_gallery_database_subtree_by_folder_path($requestedFolderPath);
    }
    // $folderPath stores an intermediate value used by the surrounding gallery workflow.
    $folderPath = unique_gallery_child_folder_path($parent, $folderName !== '' ? $folderName : $title);
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = gallery_target_abs_path($folderPath);
    if (file_exists($target)) {
        throw new RuntimeException('Gallery folder already exists.');
    }
    if (!mkdir($target, 0775, true)) {
        throw new RuntimeException('Could not create gallery folder.');
    }

    // $sortOrder stores the persisted sibling order for the new gallery.
    $sortOrder = array_key_exists('sort_order', $input)
        ? (int) $input['sort_order']
        : next_gallery_prepend_sort_order($parentId);

    // $sidecarData stores the metadata persisted before the gallery row exists.
    $sidecarData = [
        'title' => $title,
        'description' => $description,
        'gallery_date' => $galleryDate,
        'gallery_date_end' => gallery_date_range_schema_ready() ? $galleryDateEnd : null,
        'visibility' => $visibility,
        'sort_order' => $sortOrder,
        'voting_enabled' => $votingEnabled,
        'show_filenames' => $showFilenames,
    ];
    if ($countBadgeVisibility !== null) {
        $sidecarData['count_badge_visibility'] = $countBadgeVisibility;
    }
    if ($lightboxBrowsingMode !== null) {
        $sidecarData['lightbox_browsing_mode'] = $lightboxBrowsingMode;
    }
    // $sidecarWritten stores an intermediate value used by the surrounding gallery workflow.
    $sidecarWritten = write_gallery_sidecar_for_path($folderPath, $sidecarData);
    if (!$sidecarWritten) {
        throw new RuntimeException('Gallery folder was created, but gallery.json could not be written.');
    }

    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = create_gallery_row_for_folder($folderPath);
    if (!$gallery) {
        throw new RuntimeException('Gallery folder was created, but the database row could not be created.');
    }
    sync_gallery_parent_ids();
    if (public_path_schema_ready()) {
        refresh_gallery_public_paths();
    }
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery((int) $gallery['id'], true) ?: $gallery;
    write_gallery_sidecar($gallery);
    return $gallery;
}
