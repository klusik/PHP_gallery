<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606060001_exif_gps_default_display.php
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
 *   2026-06-06
 */

return [
    "ALTER TABLE galleries MODIFY gps_map_enabled TINYINT(1) NULL DEFAULT NULL",
    "UPDATE galleries SET gps_map_enabled = NULL WHERE gps_map_enabled = 0",
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('exif_gps_maps_default_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
];
