<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606080001_thumbnail_variant_metadata.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds durable metadata for generated thumbnail variants.
 *
 * Responsibilities:
 *   - Store one row for every known generated thumbnail variant
 *   - Keep source image dimensions, modification data, EXIF summary, and validation state with each derivative
 *   - Allow public rendering to choose thumbnails from database state instead of probing files on every page load
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
 *   2026-06-08
 */

return [
    "CREATE TABLE IF NOT EXISTS image_thumbnail_variants (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        image_id BIGINT UNSIGNED NOT NULL,
        gallery_id BIGINT UNSIGNED NOT NULL,
        size_px SMALLINT UNSIGNED NOT NULL,
        format ENUM('jpg','webp') NOT NULL,
        thumbnail_rel_path VARCHAR(1024) NOT NULL,
        width INT UNSIGNED NULL,
        height INT UNSIGNED NULL,
        file_size BIGINT UNSIGNED NULL,
        modified_at DATETIME NULL,
        source_width INT UNSIGNED NULL,
        source_height INT UNSIGNED NULL,
        source_mime_type VARCHAR(100) NULL,
        source_file_size BIGINT UNSIGNED NULL,
        source_modified_at DATETIME NULL,
        source_checksum_sha256 CHAR(64) NULL,
        source_exif_orientation TINYINT UNSIGNED NULL,
        source_exif_json LONGTEXT NULL,
        status ENUM('valid','missing','invalid','stale') NOT NULL DEFAULT 'valid',
        status_reason VARCHAR(100) NOT NULL DEFAULT 'ok',
        checked_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY image_thumbnail_variants_unique (image_id, size_px, format),
        KEY image_thumbnail_variants_gallery_index (gallery_id, status, size_px, format),
        KEY image_thumbnail_variants_image_status_index (image_id, status, size_px),
        CONSTRAINT image_thumbnail_variants_image_id_foreign FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
        CONSTRAINT image_thumbnail_variants_gallery_id_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
