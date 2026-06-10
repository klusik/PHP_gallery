<?php

declare(strict_types=1);

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606100002_experimental_upload_batch_safety.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds shared-hosting safety settings for experimental browser-prepared upload batches.
 *
 * Responsibilities:
 *   - Keep browser-prepared ZIP batches bounded by item count
 *   - Keep browser-prepared ZIP batches bounded by an absolute byte cap
 *   - Preserve existing installations by seeding only missing app_settings rows
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
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('experimental_upload_max_items_per_batch', '8', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('experimental_upload_max_zip_batch_bytes', '25165824', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
];
