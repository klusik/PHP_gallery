<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202604280003_exif_gps_maps.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Applies an incremental database schema or data update for PHP Gallery.
 *
 * Responsibilities:
 *   - Describe and execute one database change
 *   - Remain safe to run through the migration system
 *   - Avoid changing unrelated schema objects
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

return [
    "ALTER TABLE galleries ADD COLUMN gps_map_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER picture_game_enabled",
    "ALTER TABLE images ADD COLUMN exif_taken_at DATETIME NULL AFTER modified_at",
    "ALTER TABLE images ADD COLUMN exif_camera_make VARCHAR(128) NULL AFTER exif_taken_at",
    "ALTER TABLE images ADD COLUMN exif_camera_model VARCHAR(128) NULL AFTER exif_camera_make",
    "ALTER TABLE images ADD COLUMN exif_lens_model VARCHAR(128) NULL AFTER exif_camera_model",
    "ALTER TABLE images ADD COLUMN exif_focal_length VARCHAR(64) NULL AFTER exif_lens_model",
    "ALTER TABLE images ADD COLUMN exif_aperture VARCHAR(64) NULL AFTER exif_focal_length",
    "ALTER TABLE images ADD COLUMN exif_exposure_time VARCHAR(64) NULL AFTER exif_aperture",
    "ALTER TABLE images ADD COLUMN exif_iso INT UNSIGNED NULL AFTER exif_exposure_time",
    "ALTER TABLE images ADD COLUMN gps_lat DECIMAL(10,7) NULL AFTER exif_iso",
    "ALTER TABLE images ADD COLUMN gps_lng DECIMAL(10,7) NULL AFTER gps_lat",
    "ALTER TABLE images ADD COLUMN gps_altitude DECIMAL(10,2) NULL AFTER gps_lng",
    "ALTER TABLE images ADD COLUMN gps_extracted_at DATETIME NULL AFTER gps_altitude",
    "ALTER TABLE images ADD KEY images_gps_gallery_index (gallery_id, gps_lat, gps_lng)",
];
