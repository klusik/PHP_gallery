<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608300001_link_favicon_cache.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds persistent hostname-level favicon cache metadata for gallery-description links.
 *
 * Responsibilities:
 *   - Deduplicate downloaded favicons across all galleries by hostname
 *   - Remember successful, missing, failed, and blocked fetch attempts
 *   - Bound automatic retry frequency without coupling cache files to gallery records
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
 *   2026-08-30
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS link_favicon_cache (
        hostname VARCHAR(253) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
        status ENUM('ok','missing','failed','blocked') NOT NULL,
        icon_file VARCHAR(96) NULL,
        mime_type VARCHAR(64) NULL,
        source_url VARCHAR(2048) NULL,
        content_sha256 CHAR(64) NULL,
        fetched_at DATETIME NULL,
        last_attempt_at DATETIME NOT NULL,
        retry_after DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY link_favicon_cache_status_retry_index (status, retry_after),
        KEY link_favicon_cache_updated_index (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
