<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605050001_admin_log_observability.php
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
 *   2026-05-04
 */

return [
    "ALTER TABLE admin_logs ADD COLUMN category ENUM('system','gallery','media','upload','thumbnail','update','security','database','telemetry','admin','other') NOT NULL DEFAULT 'other' AFTER level",
    "ALTER TABLE admin_logs ADD COLUMN severity ENUM('debug','info','notice','warning','error','critical') NOT NULL DEFAULT 'info' AFTER category",
    "ALTER TABLE admin_logs ADD COLUMN subject_type VARCHAR(40) NULL AFTER event_key",
    "ALTER TABLE admin_logs ADD COLUMN subject_id BIGINT UNSIGNED NULL AFTER subject_type",
    "ALTER TABLE admin_logs ADD COLUMN request_id CHAR(26) NULL AFTER subject_id",
    "ALTER TABLE admin_logs ADD COLUMN route_name VARCHAR(80) NULL AFTER request_id",
    "ALTER TABLE admin_logs ADD COLUMN resolved_at DATETIME NULL AFTER status_updated_at",
    "ALTER TABLE admin_logs ADD COLUMN resolution_note VARCHAR(500) NULL AFTER resolved_at",
    "ALTER TABLE admin_logs ADD KEY admin_logs_category_created_index (category, created_at)",
    "ALTER TABLE admin_logs ADD KEY admin_logs_severity_created_index (severity, created_at)",
    "ALTER TABLE admin_logs ADD KEY admin_logs_event_created_index (event_key, created_at)",
    "ALTER TABLE admin_logs ADD KEY admin_logs_subject_index (subject_type, subject_id, created_at)",
    "ALTER TABLE admin_logs ADD KEY admin_logs_request_index (request_id)"
];
