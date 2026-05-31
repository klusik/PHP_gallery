<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605310001_admin_persistent_auth_and_google_login.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds durable admin login tokens and linked Google account identities.
 *
 * Responsibilities:
 *   - Keep persistent admin login tokens hashed at rest
 *   - Store linked Google identity metadata without storing Google access tokens
 *   - Remain safe to replay on hosts where DDL partially succeeded earlier
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
 *   2026-05-31
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS admin_remember_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        selector CHAR(36) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        user_agent_hash CHAR(64) NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY admin_remember_tokens_selector_unique (selector),
        KEY admin_remember_tokens_user_expires_index (user_id, expires_at, revoked_at),
        CONSTRAINT admin_remember_tokens_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS user_google_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        google_sub VARCHAR(255) NOT NULL,
        email VARCHAR(190) NULL,
        email_verified TINYINT(1) NOT NULL DEFAULT 0,
        name VARCHAR(255) NULL,
        picture_url TEXT NULL,
        linked_at DATETIME NOT NULL,
        last_login_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY user_google_accounts_user_unique (user_id),
        UNIQUE KEY user_google_accounts_sub_unique (google_sub),
        KEY user_google_accounts_email_index (email),
        CONSTRAINT user_google_accounts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
