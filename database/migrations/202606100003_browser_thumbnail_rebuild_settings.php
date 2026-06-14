<?php

declare(strict_types=1);

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606100003_browser_thumbnail_rebuild_settings.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds browser-assisted thumbnail rebuild chunk configuration.
 *
 * Responsibilities:
 *   - Seed the default source ZIP chunk size for browser thumbnail rebuilds
 *   - Preserve administrator changes by inserting only missing app_settings rows
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
 *   2026-06-10
 */

return [
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('browser_thumbnail_rebuild_source_chunk_bytes', '536870912', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
];
