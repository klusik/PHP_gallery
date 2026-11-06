<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202611040001_app_linking_identity.php
 * Module Type: Database Migration
 * Purpose: Store one-time linking codes and revocable identity grants.
 * Responsibilities:
 *   - Persist only one-way hashes of codes and access credentials.
 *   - Bind codes to an administrator and create grants only at code exchange.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS app_link_grants (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        client_id VARBINARY(64) NOT NULL,
        issuer VARBINARY(512) NOT NULL,
        scope VARBINARY(64) NOT NULL,
        created_at DATETIME(6) NOT NULL,
        revoked_at DATETIME(6) NULL,
        access_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        access_issued_at DATETIME(6) NULL,
        access_expires_at DATETIME(6) NULL,
        UNIQUE KEY app_link_grants_token_hash (access_token_hash),
        KEY app_link_grants_owner (user_id, revoked_at, id),
        CONSTRAINT app_link_grants_user_fk FOREIGN KEY (user_id)
            REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS app_link_codes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        client_id VARBINARY(64) NOT NULL,
        issuer VARBINARY(512) NOT NULL,
        scope VARBINARY(64) NOT NULL,
        redirect_uri VARBINARY(512) NOT NULL,
        pkce_s256 CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        created_at DATETIME(6) NOT NULL,
        expires_at DATETIME(6) NOT NULL,
        consumed_at DATETIME(6) NULL,
        UNIQUE KEY app_link_codes_hash (code_hash),
        KEY app_link_codes_owner (user_id, expires_at, id),
        KEY app_link_codes_expiry (expires_at, id),
        CONSTRAINT app_link_codes_user_fk FOREIGN KEY (user_id)
            REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
