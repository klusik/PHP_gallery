<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202611040003_app_linking_refresh.php
 * Module Type: Database Migration
 * Purpose: Store bounded, revocable native-client refresh families.
 * Responsibilities:
 *   - Bind one family to a grant, client, issuer, account and approved scope.
 *   - Retain only one-way token hashes for rotation and replay detection.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS app_link_refresh_families (
        grant_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        client_id VARBINARY(64) NOT NULL,
        issuer VARBINARY(512) NOT NULL,
        scope VARBINARY(64) NOT NULL,
        created_at DATETIME(6) NOT NULL,
        expires_at DATETIME(6) NOT NULL,
        idle_expires_at DATETIME(6) NOT NULL,
        issued_count INT UNSIGNED NOT NULL,
        revoked_at DATETIME(6) NULL,
        CONSTRAINT app_link_refresh_family_grant_fk FOREIGN KEY (grant_id)
            REFERENCES app_link_grants(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS app_link_refresh_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        grant_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        active_slot TINYINT UNSIGNED NULL,
        issued_at DATETIME(6) NOT NULL,
        consumed_at DATETIME(6) NULL,
        UNIQUE KEY app_link_refresh_token_hash (token_hash),
        UNIQUE KEY app_link_refresh_active_slot (grant_id, active_slot),
        CONSTRAINT app_link_refresh_token_family_fk FOREIGN KEY (grant_id)
            REFERENCES app_link_refresh_families(grant_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
