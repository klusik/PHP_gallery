<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202609250001_gallery_creation_preferences.php
 * Module Type: Database Migration
 * Purpose: Store per-administrator defaults for the gallery creation workflow.
 * Responsibilities: Create a user-owned preference table without altering gallery rows.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS user_gallery_creation_preferences (
        user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        simbrief_pilot_id VARCHAR(32) NOT NULL DEFAULT '',
        simbrief_pilot_name VARCHAR(80) NOT NULL DEFAULT '',
        content_language VARCHAR(16) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT user_gallery_creation_preferences_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
