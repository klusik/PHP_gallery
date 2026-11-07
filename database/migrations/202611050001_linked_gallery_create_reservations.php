<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202611050001_linked_gallery_create_reservations.php
 * Module Type: Database Migration
 * Purpose: Retain linked-create path reservations across interrupted writes.
 * Responsibilities: Retain indexed claim and active target evidence without deleting claims.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    'CREATE TABLE IF NOT EXISTS linked_gallery_create_reservations (
        path_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        active_path_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        folder_path VARBINARY(1024) NOT NULL,
        actor_id BIGINT UNSIGNED NOT NULL,
        key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        state VARBINARY(16) NOT NULL,
        PRIMARY KEY (actor_id, key_hash),
        UNIQUE KEY linked_create_active_path_unique (active_path_hash),
        KEY linked_create_path (path_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
];
