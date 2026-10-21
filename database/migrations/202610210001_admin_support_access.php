<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610210001_admin_support_access.php
 * Module Type: Database Migration
 * Purpose: Persist revocable, single-use temporary administrator grants.
 * Responsibilities:
 *   - Store only hashed invitation and session verifiers
 *   - Enforce an owner foreign key and indexed grant lifecycle
 *   - Keep security audit attribution separate from credentials
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

return [
    'CREATE TABLE IF NOT EXISTS admin_support_grants (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        owner_user_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        created_at DATETIME(6) NOT NULL,
        expires_at DATETIME(6) NOT NULL,
        redeemed_at DATETIME(6) NULL,
        revoked_at DATETIME(6) NULL,
        expiry_logged_at DATETIME(6) NULL,
        last_used_at DATETIME(6) NULL,
        UNIQUE KEY admin_support_grants_token_unique (token_hash),
        UNIQUE KEY admin_support_grants_session_unique (session_hash),
        KEY admin_support_grants_owner_index
            (owner_user_id, created_at, expires_at, revoked_at),
        CONSTRAINT admin_support_grants_owner_foreign
            FOREIGN KEY (owner_user_id) REFERENCES users(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    'CREATE TABLE IF NOT EXISTS admin_support_grant_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        grant_id BIGINT UNSIGNED NOT NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        actor_type VARCHAR(16) NOT NULL,
        event_key VARCHAR(32) NOT NULL,
        created_at DATETIME(6) NOT NULL,
        KEY admin_support_events_grant_index (grant_id, created_at),
        CONSTRAINT admin_support_events_grant_foreign
            FOREIGN KEY (grant_id) REFERENCES admin_support_grants(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
];
