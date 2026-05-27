<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605270002_navigation_data_accounts.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Stores optional per-admin Navigraph connection state.
 *
 * Responsibilities:
 *   - Persist user-authorized Navigraph tokens outside the PHP session
 *   - Keep tokens scoped to one admin user account
 *   - Store package metadata used by the navigation-data status UI
 *   - Allow graceful fallback when this migration has not been applied yet
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
 *   2026-05-27
 */

return [
    "CREATE TABLE IF NOT EXISTS navigation_data_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        provider VARCHAR(32) NOT NULL DEFAULT 'navigraph',
        access_token_cipher MEDIUMTEXT NULL,
        refresh_token_cipher MEDIUMTEXT NULL,
        id_token_cipher MEDIUMTEXT NULL,
        token_expires_at INT UNSIGNED NOT NULL DEFAULT 0,
        scope_text VARCHAR(512) NULL,
        claims_json MEDIUMTEXT NULL,
        subscription_json MEDIUMTEXT NULL,
        package_cycle VARCHAR(32) NULL,
        package_status VARCHAR(64) NULL,
        package_format VARCHAR(64) NULL,
        package_checked_at DATETIME NULL,
        connected_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY navigation_data_accounts_user_provider_unique (user_id, provider),
        KEY navigation_data_accounts_provider_index (provider),
        CONSTRAINT navigation_data_accounts_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
