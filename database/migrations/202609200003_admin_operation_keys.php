<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Create durable operation-key storage for safe request replay.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202609200003_admin_operation_keys.php
 * Module Type: Database Migration
 * Purpose: Persist actor-bound create/upload claims and their original completion response.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS admin_operation_keys (
        actor_id BIGINT UNSIGNED NOT NULL,
        key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        operation_name VARCHAR(40) NOT NULL,
        payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        owner_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        state VARCHAR(32) NOT NULL,
        response_json MEDIUMTEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (actor_id, key_hash),
        KEY admin_operation_keys_pending (state, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
