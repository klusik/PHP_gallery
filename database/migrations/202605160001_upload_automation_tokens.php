<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605160001_upload_automation_tokens.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds gallery-scoped API keys for upload automation.
 *
 * Responsibilities:
 *   - Store only one-way hashes of upload automation API keys
 *   - Scope each key to one target gallery
 *   - Allow API keys to be revoked without deleting audit metadata
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
 *   2026-05-16
 */

return [
    "CREATE TABLE IF NOT EXISTS gallery_upload_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        gallery_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        label VARCHAR(190) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_by_user_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY gallery_upload_tokens_hash_unique (token_hash),
        KEY gallery_upload_tokens_gallery_active_index (gallery_id, active, created_at),
        KEY gallery_upload_tokens_created_by_index (created_by_user_id),
        CONSTRAINT gallery_upload_tokens_gallery_id_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
        CONSTRAINT gallery_upload_tokens_created_by_user_id_foreign FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
