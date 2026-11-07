<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202611060001_app_linking_watch_keys.php
 * Module Type: Database Migration
 * Purpose: Store one expiring watcher credential per delegated gallery.
 * Responsibilities: Bind credential hashes to the exact live grant and gallery.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS app_linking_watch_keys (
        grant_id BIGINT UNSIGNED NOT NULL,
        gallery_id BIGINT UNSIGNED NOT NULL,
        key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        expires_at DATETIME(6) NOT NULL,
        PRIMARY KEY (grant_id, gallery_id),
        KEY app_linking_watch_key_expiry (expires_at),
        CONSTRAINT app_linking_watch_grant_fk FOREIGN KEY (grant_id)
            REFERENCES app_link_grants(id) ON DELETE CASCADE,
        CONSTRAINT app_linking_watch_gallery_fk FOREIGN KEY (gallery_id)
            REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
