<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610150001_cache_lifecycle_regions.php
 * Module Type: Database Migration
 * Purpose: Persist bounded rotating cache regions and their authoritative checkpoints.
 * Responsibilities: Create stable region keys, scheduling indexes and retained findings; runtime workers never change schema.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS cache_lifecycle_regions (
        region_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        relative_path TEXT NOT NULL,
        owner VARCHAR(32) NOT NULL,
        kind VARCHAR(16) NOT NULL DEFAULT 'region',
        last_serviced_at BIGINT NOT NULL DEFAULT 0,
        last_completed_at BIGINT NOT NULL DEFAULT 0,
        next_due_at BIGINT NOT NULL DEFAULT 0,
        retry_at BIGINT NOT NULL DEFAULT 0,
        failures INT NOT NULL DEFAULT 0,
        drained SMALLINT NOT NULL DEFAULT 0,
        seen_generation CHAR(32) NOT NULL DEFAULT '',
        missing_since BIGINT NOT NULL DEFAULT 0,
        checkpoint_json MEDIUMTEXT NOT NULL,
        finding_json MEDIUMTEXT NOT NULL,
        completed_json MEDIUMTEXT NOT NULL,
        PRIMARY KEY (region_key),
        KEY cache_lifecycle_regions_due (retry_at, next_due_at, last_completed_at, last_serviced_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
