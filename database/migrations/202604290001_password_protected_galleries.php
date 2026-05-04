<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202604290001_password_protected_galleries.php
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
    "ALTER TABLE galleries ADD COLUMN access_mode ENUM('normal','password') NOT NULL DEFAULT 'normal' AFTER visibility",
    "ALTER TABLE galleries ADD COLUMN access_listing ENUM('listed','unlisted') NOT NULL DEFAULT 'listed' AFTER access_mode",
    "ALTER TABLE galleries ADD COLUMN access_password_hash VARCHAR(255) NULL AFTER access_listing",
    "ALTER TABLE galleries ADD COLUMN access_share_token VARCHAR(128) NULL AFTER access_password_hash",
    "ALTER TABLE galleries ADD COLUMN access_token_hash CHAR(64) NULL AFTER access_share_token",
    "ALTER TABLE galleries ADD COLUMN access_token_expires_at DATETIME NULL AFTER access_token_hash",
    "ALTER TABLE galleries ADD KEY galleries_access_listing_index (visibility, access_mode, access_listing, parent_id, sort_order, title)",
    "ALTER TABLE galleries ADD KEY galleries_access_token_hash_index (access_token_hash)",
];
