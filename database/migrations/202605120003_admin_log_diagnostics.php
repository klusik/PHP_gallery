<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605120003_admin_log_diagnostics.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Applies an incremental database schema or data update for PHP Gallery.
 *
 * Responsibilities:
 *   - Describe and execute one database change
 *   - Remain safe to run through the migration system
 *   - Avoid changing unrelated schema objects
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
 *   2026-05-12
 */

return [
    "ALTER TABLE admin_logs ADD COLUMN fingerprint CHAR(64) NULL AFTER route_name",
    "ALTER TABLE admin_logs ADD COLUMN http_method VARCHAR(12) NULL AFTER fingerprint",
    "ALTER TABLE admin_logs ADD COLUMN is_ajax TINYINT(1) NOT NULL DEFAULT 0 AFTER http_method",
    "ALTER TABLE admin_logs ADD KEY admin_logs_fingerprint_index (fingerprint)",
    "ALTER TABLE admin_logs ADD KEY admin_logs_route_method_created_index (route_name, http_method, created_at)",
    "UPDATE admin_logs SET category = CASE
        WHEN event_key LIKE 'auth.%' OR event_key LIKE 'password.%' OR event_key LIKE 'rate_limit.%' THEN 'security'
        WHEN event_key LIKE 'gallery.%' OR event_key LIKE 'galleries.%' OR event_key LIKE 'tags.%' OR event_key LIKE 'tag.%' THEN 'gallery'
        WHEN event_key LIKE 'image.%' OR event_key LIKE 'image_scan.%' OR event_key LIKE 'media.%' OR event_key LIKE 'picture_game.%' OR event_key LIKE 'votes.%' OR event_key LIKE 'vote.%' OR event_key LIKE 'gps_maps.%' THEN 'media'
        WHEN event_key LIKE '%upload%' THEN 'upload'
        WHEN event_key LIKE 'thumbnail.%' OR event_key LIKE 'dng.%' THEN 'thumbnail'
        WHEN event_key LIKE 'update.%' THEN 'update'
        WHEN event_key LIKE 'migrations.%' OR event_key LIKE 'database.%' THEN 'database'
        WHEN event_key LIKE 'telemetry.%' THEN 'telemetry'
        WHEN event_key LIKE 'admin_log.%' THEN 'admin'
        WHEN event_key LIKE 'integrity.%' OR event_key LIKE 'theme.%' OR event_key LIKE 'favicon.%' THEN 'system'
        ELSE category
    END WHERE category = 'other' OR category IS NULL",
    "UPDATE admin_logs SET status = 'done', status_updated_at = COALESCE(status_updated_at, created_at)
        WHERE status = 'todo'
        AND COALESCE(severity, level) IN ('debug', 'info', 'notice')",
    "UPDATE admin_logs SET subject_type = CASE
        WHEN event_key LIKE 'gallery.%' THEN 'gallery'
        WHEN event_key LIKE 'image.%' THEN 'image'
        WHEN event_key LIKE 'thumbnail.%' THEN 'thumbnail'
        WHEN event_key LIKE 'update.%' THEN 'update'
        WHEN event_key LIKE 'telemetry.%' THEN 'telemetry'
        WHEN event_key LIKE 'tags.%' OR event_key LIKE 'tag.%' THEN 'tag'
        ELSE subject_type
    END WHERE subject_type IS NULL OR subject_type = ''",
    "UPDATE admin_logs SET fingerprint = SHA2(CONCAT_WS('|', event_key, COALESCE(route_name, ''), message), 256)
        WHERE fingerprint IS NULL OR fingerprint = ''",
];
