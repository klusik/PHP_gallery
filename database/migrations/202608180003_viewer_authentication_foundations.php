<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608180003_viewer_authentication_foundations.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds the final dormant viewer-authentication capacity foundation.
 *
 * Responsibilities:
 *   - Serialize durable viewer-account creation at a hard installation cap
 *   - Keep the account counter independently reconcilable after interrupted maintenance or future deletion
 *   - Avoid modifying existing administrator, gallery, or viewer-content tables
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
 *   - The singleton row is created lazily by the service so migration replay remains purely additive.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS viewer_account_state (
        state_key VARCHAR(64) NOT NULL PRIMARY KEY,
        account_count INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
