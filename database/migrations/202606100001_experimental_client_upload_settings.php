<?php

declare(strict_types=1);

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606100001_experimental_client_upload_settings.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Seeds default settings for the experimental browser-side upload pipeline.
 *
 * Responsibilities:
 *   - Keep the feature opt-in per upload
 *   - Store administrator-controlled worker and batch defaults in app_settings
 *   - Avoid changing existing upload tables or historical migrations
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
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('experimental_upload_enabled', '1', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('experimental_upload_default_worker_count', '8', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('experimental_upload_max_worker_count', '32', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('experimental_upload_hard_worker_cap', '32', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('experimental_upload_batch_size_policy', 'upload_limit_ratio', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('experimental_upload_zip_size_threshold_ratio', '0.80', NOW()) ON DUPLICATE KEY UPDATE setting_value = setting_value",
];
