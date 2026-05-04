<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605020001_public_url_slugs.php
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
    "ALTER TABLE galleries ADD COLUMN url_slug VARCHAR(255) NULL AFTER slug",
    "ALTER TABLE galleries ADD COLUMN url_path VARCHAR(1024) NULL AFTER url_slug",
    "ALTER TABLE galleries ADD COLUMN url_path_hash CHAR(64) NULL AFTER url_path",
    "ALTER TABLE galleries ADD UNIQUE KEY galleries_url_path_hash_unique (url_path_hash)",
    "ALTER TABLE galleries ADD KEY galleries_parent_url_slug_index (parent_id, url_slug)",
    "ALTER TABLE images ADD COLUMN url_slug VARCHAR(255) NULL AFTER filename",
    "ALTER TABLE images ADD KEY images_gallery_url_slug_index (gallery_id, url_slug)",
];
