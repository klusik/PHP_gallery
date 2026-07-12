<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202607120001_browser_upload_legacy_settings_cleanup.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Removes obsolete setting rows left behind by the former browser upload names.
 *
 * Responsibilities:
 *   - Keep browser upload configuration under one canonical setting namespace
 *   - Remove settings that are no longer read by the application
 *   - Leave current browser upload and thumbnail rebuild settings unchanged
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
 *   2026-07-12
 */

declare(strict_types=1);

return [
    "DELETE FROM app_settings WHERE setting_key IN (
        'experimental_upload_enabled',
        'experimental_upload_default_worker_count',
        'experimental_upload_max_worker_count',
        'experimental_upload_hard_worker_cap',
        'experimental_upload_batch_size_policy',
        'experimental_upload_zip_size_threshold_ratio',
        'experimental_upload_max_items_per_batch',
        'experimental_upload_max_zip_batch_bytes',
        'experimental_thumbnail_rebuild_source_chunk_bytes'
    )",
];
