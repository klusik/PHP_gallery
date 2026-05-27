<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605270001_navigation_data_cache.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds cache storage for optional remote navigation-data lookups.
 *
 * Responsibilities:
 *   - Cache resolved navigation points from optional remote providers
 *   - Keep cached data scoped by provider and AIRAC cycle
 *   - Avoid storing or exposing downloadable navigation databases
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
    "CREATE TABLE IF NOT EXISTS navigation_data_cache (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        cache_key CHAR(64) NOT NULL,
        ident VARCHAR(32) NOT NULL,
        kind VARCHAR(32) NOT NULL DEFAULT 'waypoint',
        source VARCHAR(64) NOT NULL,
        cycle VARCHAR(32) NULL,
        payload_json MEDIUMTEXT NOT NULL,
        expires_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY navigation_data_cache_key_unique (cache_key),
        KEY navigation_data_cache_ident_index (ident),
        KEY navigation_data_cache_source_cycle_index (source, cycle),
        KEY navigation_data_cache_expiry_index (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
