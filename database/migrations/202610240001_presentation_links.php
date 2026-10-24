<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610240001_presentation_links.php
 * Module Type: Database Migration
 * Purpose: Store immutable gallery-scoped presentation snapshots.
 * Responsibilities: Enforce unique short codes and full settings fingerprints.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    'CREATE TABLE IF NOT EXISTS presentation_links (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        code CHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        gallery_kind VARCHAR(8) NOT NULL,
        gallery_id BIGINT UNSIGNED NOT NULL,
        settings_json TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY presentation_links_code (code),
        UNIQUE KEY presentation_links_fingerprint (fingerprint),
        KEY presentation_links_gallery (gallery_kind, gallery_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
];
