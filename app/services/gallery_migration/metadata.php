<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/metadata.php
 * Module Type: Service
 *
 * Purpose:
 *   Serializes gallery and image rows into transferable metadata.
 *
 * Responsibilities:
 *   - Reduce a gallery row to the fields the target instance can apply
 *   - Reduce an image row to its transferable metadata
 *   - Resolve asset MIME types with an explicit fallback
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
 *   - Loaded by app/services/gallery_migration.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/gallery_migration.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use CURLFile;
use RuntimeException;
use Throwable;
use ZipArchive;
use const Gallery\Core\CMS_VERSION;
use function Gallery\Controllers\admin_edit_gallery_tab_url;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;
use function Gallery\Core\unique_slug;

/**
 * Return manifest-safe gallery settings.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array Structured result data for the caller.
 */
function gallery_migration_gallery_metadata(array $gallery): array
{
    $fields = [
        'title',
        'slug',
        'description',
        'sort_order',
        'visibility',
        'voting_enabled',
        'show_filenames',
        'gallery_date',
        'gallery_date_end',
        'description_layout',
        'count_badge_visibility',
        'picture_game_enabled',
        'gps_map_enabled',
        'grid_columns',
        'grid_rows',
        'grid_use_for_subgalleries',
        'thumbnail_min_size',
        'thumbnail_max_size',
        'background_source',
        'banner_image_path',
        'logo_image_path',
        'separator_image_path',
        'cover_image_path',
        'nsfw_enabled',
    ];

    $metadata = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $gallery)) {
            $metadata[$field] = $gallery[$field];
        }
    }

    $metadata['tags'] = function_exists('Gallery\\Services\\tag_names_for_entity') ? tag_names_for_entity('gallery', (int) ($gallery['id'] ?? 0)) : '';
    $metadata['cover_source_id'] = (int) ($gallery['cover_image_id'] ?? 0);
    if (content_localization_schema_ready('gallery')) {
        $metadata['content_language'] = content_language_normalize($gallery['content_language'] ?? null);
        $translationRows = content_translation_rows('gallery', [(int) ($gallery['id'] ?? 0)]);
        $metadata['translations'] = $translationRows[(int) ($gallery['id'] ?? 0)] ?? [];
    }
    return $metadata;
}

/**
 * Return manifest-safe image metadata.
 *
 * @param array $image Image row or image data.
 * @return array Structured result data for the caller.
 */
function gallery_migration_image_metadata(array $image): array
{
    $fields = [
        'relative_path',
        'filename',
        'title',
        'description',
        'width',
        'height',
        'mime_type',
        'file_size',
        'modified_at',
        'checksum_sha256',
        'sort_order',
        'visibility',
        'exif_taken_at',
        'exif_camera_make',
        'exif_camera_model',
        'exif_lens_model',
        'exif_focal_length',
        'exif_aperture',
        'exif_exposure_time',
        'exif_iso',
        'gps_lat',
        'gps_lng',
        'gps_altitude',
        'gps_extracted_at',
        'nsfw_enabled',
        'thumbnail_min_size',
        'thumbnail_max_size',
    ];

    $metadata = [
        'source_id' => (int) ($image['id'] ?? 0),
        'tags' => function_exists('Gallery\\Services\\tag_names_for_entity') ? tag_names_for_entity('image', (int) ($image['id'] ?? 0)) : '',
    ];
    foreach ($fields as $field) {
        if (array_key_exists($field, $image)) {
            $metadata[$field] = $image[$field];
        }
    }
    if (content_localization_schema_ready('image')) {
        $metadata['content_language'] = content_language_normalize($image['content_language'] ?? null);
        $translationRows = content_translation_rows('image', [(int) ($image['id'] ?? 0)]);
        $metadata['translations'] = $translationRows[(int) ($image['id'] ?? 0)] ?? [];
    }

    return $metadata;
}

/**
 * Return a safe MIME type for one local asset.
 *
 * @param string $path Filesystem path.
 * @param string $fallback Fallback value.
 * @return string Text result for the caller.
 */
function gallery_migration_asset_mime(string $path, string $fallback = 'application/octet-stream'): string
{
    $info = @getimagesize($path);
    if (is_array($info) && !empty($info['mime'])) {
        return (string) $info['mime'];
    }

    if (function_exists('mime_content_type')) {
        $mime = @mime_content_type($path);
        if (is_string($mime) && $mime !== '') {
            return $mime;
        }
    }

    return $fallback;
}
