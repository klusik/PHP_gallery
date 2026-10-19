<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610190001_gallery_attachment_storage.php
 * Module Type: Database Migration
 * Purpose: Persist attachment accounting independently of filesystem scans.
 * Responsibilities: Keep gallery-owned PDF, music and OFP byte/count totals.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS gallery_attachment_storage (
        gallery_id BIGINT UNSIGNED NOT NULL,
        kind VARCHAR(8) NOT NULL,
        file_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        file_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
        errors BIGINT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (gallery_id, kind),
        CONSTRAINT gallery_attachment_storage_gallery_foreign
            FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
