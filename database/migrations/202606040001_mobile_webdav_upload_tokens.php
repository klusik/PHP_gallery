<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606040001_mobile_webdav_upload_tokens.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds WebDAV-compatible mobile upload tokens for external photo-transfer clients.
 *
 * Responsibilities:
 *   - Store scoped upload credentials for PhotoSync-style clients
 *   - Bind each credential to one destination gallery
 *   - Keep token metadata auditable without storing plaintext passwords
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
 *   2026-06-04
 */

return [
    "CREATE TABLE IF NOT EXISTS mobile_webdav_upload_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        gallery_id BIGINT UNSIGNED NOT NULL,
        label VARCHAR(190) NOT NULL,
        username VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        path_token CHAR(48) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        UNIQUE KEY mobile_webdav_upload_tokens_path_unique (path_token),
        KEY mobile_webdav_upload_tokens_user_index (user_id),
        KEY mobile_webdav_upload_tokens_gallery_index (gallery_id),
        CONSTRAINT mobile_webdav_upload_tokens_user_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT mobile_webdav_upload_tokens_gallery_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
