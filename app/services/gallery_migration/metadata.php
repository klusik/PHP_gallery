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
 * Return manifest-safe gallery settings with their orientation semantics marker.
 *
 * @param array{id?:int|string,cover_image_id?:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,content_language?:string|null,title?:string,slug?:string,description?:string} $gallery Persisted gallery identity, cover choice and editable metadata fields.
 * @return array{description_layout_semantics_version:int,tags:string,cover_source_id:int,cover_selection_source_ids?:list<int>,content_language?:string|null,translations?:array<int,array<string,array{owner_id:int|string,language_code:string,title:string|null,description:string|null,created_at:string,updated_at:string}>>,title?:string,slug?:string,description?:string,sort_order?:int|string,visibility?:string,voting_enabled?:int|string,show_filenames?:int|string,gallery_date?:string|null,gallery_date_end?:string|null,description_layout?:string|null,count_badge_visibility?:string|null,picture_game_enabled?:int|string,gps_map_enabled?:int|string,grid_columns?:int|string|null,grid_rows?:int|string|null,grid_use_for_subgalleries?:int|string,thumbnail_min_size?:int|string|null,thumbnail_max_size?:int|string|null,background_source?:string|null,banner_image_path?:string|null,logo_image_path?:string|null,separator_image_path?:string|null,cover_image_path?:string|null,nsfw_enabled?:int|string} Manifest gallery metadata with selected source IDs retained for the normal image map.
 */
function gallery_migration_gallery_metadata(array $gallery): array
{
    if (
        array_key_exists('cover_image_ids', $gallery)
        && !gallery_cover_selection_storage_valid($gallery)
    ) {
        throw new RuntimeException(
            'Gallery cover selection metadata is invalid and cannot be exported safely.',
        );
    }
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

    $metadata = [
        'description_layout_semantics_version' =>
            GALLERY_DESCRIPTION_LAYOUT_SEMANTICS_VERSION,
    ];
    foreach ($fields as $field) {
        if (array_key_exists($field, $gallery)) {
            $metadata[$field] = $gallery[$field];
        }
    }

    $metadata['tags'] = function_exists(
        'Gallery\\Services\\tag_names_for_entity',
    )
        ? tag_names_for_entity('gallery', (int) ($gallery['id'] ?? 0))
        : '';
    $coverSelection = null;
    if (
        array_key_exists('cover_image_ids', $gallery)
        && $gallery['cover_image_ids'] !== null
    ) {
        // Export only current direct images owned by this gallery. The existing
        // per-image map will reconcile these source IDs when the job is applied.
        $coverSelection = [];
        foreach (gallery_cover_selection_ids($gallery) as $sourceImageId) {
            $sourceImage = find_image($sourceImageId, true);
            if (
                $sourceImage
                && (int) ($sourceImage['gallery_id'] ?? 0)
                    === (int) ($gallery['id'] ?? 0)
                && !str_contains(
                    (string) ($sourceImage['relative_path'] ?? ''),
                    '/',
                )
            ) {
                $coverSelection[] = $sourceImageId;
            }
        }
        // [] deliberately preserves explicit Automatic mode after pruning.
        $metadata['cover_selection_source_ids'] = $coverSelection;
    }
    $primaryId = (int) ($gallery['cover_image_id'] ?? 0);
    $primaryImage =
        $coverSelection === null && $primaryId > 0
            ? find_image($primaryId, true)
            : null;
    $metadata['cover_source_id'] = $coverSelection !== null
        ? $coverSelection[0] ?? 0
        : (
            $primaryImage
                && (int) ($primaryImage['gallery_id'] ?? 0)
                === (int) ($gallery['id'] ?? 0)
                && !str_contains(
                    (string) ($primaryImage['relative_path'] ?? ''),
                    '/',
                )
                    ? $primaryId
                    : 0
        );
    if (content_localization_schema_ready('gallery')) {
        $metadata['content_language'] = content_language_normalize(
            $gallery['content_language'] ?? null,
        );
        $translationRows = content_translation_rows('gallery', [
            (int) ($gallery['id'] ?? 0),
        ]);
        $metadata['translations'] = $translationRows[(int) (
            $gallery['id'] ?? 0
        )] ?? [];
    }
    return $metadata;
}

/**
 * Return manifest-safe image metadata.
 *
 * @param array{id?:int|string,relative_path?:string,filename?:string,title?:string|null,description?:string|null,width?:int|string|null,height?:int|string|null,mime_type?:string|null,file_size?:int|string|null,modified_at?:string|null,checksum_sha256?:string|null,sort_order?:int|string,visibility?:string,exif_taken_at?:string|null,exif_camera_make?:string|null,exif_camera_model?:string|null,exif_lens_model?:string|null,exif_focal_length?:string|null,exif_aperture?:string|null,exif_exposure_time?:string|null,exif_iso?:int|string|null,gps_lat?:float|string|null,gps_lng?:float|string|null,gps_altitude?:float|string|null,gps_extracted_at?:string|null,nsfw_enabled?:int|string,thumbnail_min_size?:int|string|null,thumbnail_max_size?:int|string|null,content_language?:string|null} $image Image identity and optional persisted metadata fields selected for transfer.
 * @return array{source_id:int,tags:string,relative_path?:string,filename?:string,title?:string|null,description?:string|null,width?:int|string|null,height?:int|string|null,mime_type?:string|null,file_size?:int|string|null,modified_at?:string|null,checksum_sha256?:string|null,sort_order?:int|string,visibility?:string,exif_taken_at?:string|null,exif_camera_make?:string|null,exif_camera_model?:string|null,exif_lens_model?:string|null,exif_focal_length?:string|null,exif_aperture?:string|null,exif_exposure_time?:string|null,exif_iso?:int|string|null,gps_lat?:float|string|null,gps_lng?:float|string|null,gps_altitude?:float|string|null,gps_extracted_at?:string|null,nsfw_enabled?:int|string,thumbnail_min_size?:int|string|null,thumbnail_max_size?:int|string|null,content_language?:string|null,translations?:array<int,array<string,array{owner_id:int|string,language_code:string,title:string|null,description:string|null,created_at:string,updated_at:string}>>} Manifest image metadata with source identity and optional translation rows.
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
        'tags' => function_exists('Gallery\\Services\\tag_names_for_entity')
            ? tag_names_for_entity('image', (int) ($image['id'] ?? 0))
            : '',
    ];
    foreach ($fields as $field) {
        if (array_key_exists($field, $image)) {
            $metadata[$field] = $image[$field];
        }
    }
    if (content_localization_schema_ready('image')) {
        $metadata['content_language'] = content_language_normalize(
            $image['content_language'] ?? null,
        );
        $translationRows = content_translation_rows('image', [
            (int) ($image['id'] ?? 0),
        ]);
        $metadata['translations'] = $translationRows[(int) ($image['id'] ?? 0)]
        ?? [];
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
function gallery_migration_asset_mime(
    string $path,
    string $fallback = 'application/octet-stream',
): string {
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
