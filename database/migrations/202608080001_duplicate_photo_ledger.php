<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608080001_duplicate_photo_ledger.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds persistent per-administrator ledger rules for reviewed duplicate-photo findings.
 *
 * Responsibilities:
 *   - Store canonical ignored image pairs
 *   - Store independently ignored exact gallery ids
 *   - Remove ledger rows automatically when their users, images, or galleries disappear
 *   - Keep historical migrations unchanged
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
 *   2026-08-08
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS duplicate_photo_ledger_pairs (
        user_id BIGINT UNSIGNED NOT NULL,
        image_id_low BIGINT UNSIGNED NOT NULL,
        image_id_high BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, image_id_low, image_id_high),
        KEY duplicate_photo_ledger_pairs_low_index (image_id_low),
        KEY duplicate_photo_ledger_pairs_high_index (image_id_high),
        CONSTRAINT duplicate_photo_ledger_pairs_user_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT duplicate_photo_ledger_pairs_low_foreign FOREIGN KEY (image_id_low) REFERENCES images(id) ON DELETE CASCADE,
        CONSTRAINT duplicate_photo_ledger_pairs_high_foreign FOREIGN KEY (image_id_high) REFERENCES images(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS duplicate_photo_ledger_galleries (
        user_id BIGINT UNSIGNED NOT NULL,
        gallery_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, gallery_id),
        KEY duplicate_photo_ledger_galleries_gallery_index (gallery_id),
        CONSTRAINT duplicate_photo_ledger_galleries_user_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT duplicate_photo_ledger_galleries_gallery_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
