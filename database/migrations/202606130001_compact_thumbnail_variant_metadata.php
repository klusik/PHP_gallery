<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606130001_compact_thumbnail_variant_metadata.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Compacts durable thumbnail metadata so derivative rows no longer duplicate
 *   source image paths, EXIF summaries, checksums, or source dimensions.
 *
 * Responsibilities:
 *   - Move orientation-aware display geometry to the master images table
 *   - Preserve existing valid thumbnail rows while dropping duplicated payload columns
 *   - Add a small derivative version marker used to invalidate stale thumbnail rows
 *   - Keep the public renderer database-driven without probing thumbnail files
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
 *   2026-06-13
 */

return [
    "ALTER TABLE images ADD COLUMN display_width INT UNSIGNED NULL AFTER height",
    "ALTER TABLE images ADD COLUMN display_height INT UNSIGNED NULL AFTER display_width",
    "ALTER TABLE images ADD COLUMN exif_orientation TINYINT UNSIGNED NULL AFTER display_height",
    "ALTER TABLE images ADD COLUMN thumbnail_derivative_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER exif_orientation",
    "ALTER TABLE images ADD COLUMN thumbnail_metadata_refreshed_at DATETIME NULL AFTER thumbnail_derivative_version",
    "UPDATE images
        SET display_width = COALESCE(display_width, width),
            display_height = COALESCE(display_height, height),
            exif_orientation = COALESCE(exif_orientation, 1),
            thumbnail_derivative_version = GREATEST(1, thumbnail_derivative_version)
        WHERE display_width IS NULL
           OR display_height IS NULL
           OR exif_orientation IS NULL
           OR thumbnail_derivative_version < 1",
    "UPDATE images i
        JOIN (
            SELECT
                image_id,
                MAX(source_width) AS source_width,
                MAX(source_height) AS source_height,
                MAX(source_exif_orientation) AS source_exif_orientation
            FROM image_thumbnail_variants
            WHERE source_width IS NOT NULL
              AND source_height IS NOT NULL
              AND source_width > 0
              AND source_height > 0
            GROUP BY image_id
        ) v ON v.image_id = i.id
        SET i.display_width = v.source_width,
            i.display_height = v.source_height,
            i.exif_orientation = COALESCE(v.source_exif_orientation, i.exif_orientation, 1),
            i.thumbnail_metadata_refreshed_at = NOW()",
    "ALTER TABLE image_thumbnail_variants
        DROP FOREIGN KEY image_thumbnail_variants_gallery_id_foreign,
        DROP INDEX image_thumbnail_variants_gallery_index,
        ADD COLUMN derivative_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER format,
        DROP COLUMN gallery_id,
        DROP COLUMN thumbnail_rel_path,
        DROP COLUMN source_width,
        DROP COLUMN source_height,
        DROP COLUMN source_mime_type,
        DROP COLUMN source_file_size,
        DROP COLUMN source_modified_at,
        DROP COLUMN source_checksum_sha256,
        DROP COLUMN source_exif_orientation,
        DROP COLUMN source_exif_json",
    "UPDATE image_thumbnail_variants v
        JOIN images i ON i.id = v.image_id
        SET v.derivative_version = GREATEST(1, i.thumbnail_derivative_version)",
];
