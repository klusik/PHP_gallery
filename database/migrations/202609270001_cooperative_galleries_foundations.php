<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202609270001_cooperative_galleries_foundations.php
 * Module Type: Database Migration
 * Purpose: Add isolated storage for dormant cooperative gallery foundations.
 * Responsibilities: Preserve existing API keys and store revisioned domain state.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS cooperative_albums (
        gallery_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        album_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        UNIQUE KEY cooperative_albums_public_id (album_id),
        CONSTRAINT cooperative_albums_gallery FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS cooperative_identity (
        singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        UNIQUE KEY cooperative_identity_instance (instance_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS cooperative_peers (
        instance_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        base_url VARCHAR(2048) NOT NULL,
        state VARCHAR(16) NOT NULL,
        revision BIGINT UNSIGNED NOT NULL,
        incoming_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        outgoing_cipher TEXT NULL,
        confirmations_json TEXT NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS cooperative_groups (
        group_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
        storage_revision BIGINT UNSIGNED NOT NULL,
        state_json MEDIUMTEXT NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];