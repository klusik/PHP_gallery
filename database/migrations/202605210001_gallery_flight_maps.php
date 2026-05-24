<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605210001_gallery_flight_maps.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds persistent route-map storage for simflying galleries.
 *
 * Responsibilities:
 *   - Store already-resolved gallery flight paths
 *   - Store optional nav points used only during admin route save
 *   - Keep public map rendering independent from AIRAC lookup
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
 *   2026-05-21
 */

return [
    "CREATE TABLE IF NOT EXISTS gallery_flight_maps (
        gallery_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        map_source_type ENUM('flight_path') NOT NULL DEFAULT 'flight_path',
        route_text MEDIUMTEXT NULL,
        resolved_points_json MEDIUMTEXT NOT NULL,
        unresolved_points_json TEXT NULL,
        point_count INT UNSIGNED NOT NULL DEFAULT 0,
        resolved_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY gallery_flight_maps_source_index (map_source_type, point_count),
        CONSTRAINT gallery_flight_maps_gallery_id_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS flight_map_nav_points (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        ident VARCHAR(32) NOT NULL,
        kind VARCHAR(32) NOT NULL DEFAULT 'waypoint',
        region VARCHAR(32) NULL,
        latitude DECIMAL(10,7) NOT NULL,
        longitude DECIMAL(10,7) NOT NULL,
        source VARCHAR(64) NULL,
        cycle VARCHAR(16) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY flight_map_nav_points_ident_index (ident),
        KEY flight_map_nav_points_kind_ident_index (kind, ident),
        UNIQUE KEY flight_map_nav_points_unique (ident, kind, region)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
