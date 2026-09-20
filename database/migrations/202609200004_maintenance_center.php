<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202609200004_maintenance_center.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds durable, resumable Maintenance Center job state.
 *
 * Responsibilities:
 *   - Persist analyzed plans and bounded execution checkpoints
 *   - Provide one durable central mutation claim across browser requests
 *   - Retain bounded operator diagnostics without a high-cardinality step ledger
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
 *   - The nullable UNIQUE lock_key intentionally permits unlimited historical rows
 *     while allowing at most one running/paused central mutation owner.
 *   - Task-level progress remains bounded JSON inside the job row.
 *
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS maintenance_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        actor_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL,
        mode VARCHAR(24) NOT NULL DEFAULT 'standard',
        phase VARCHAR(64) NOT NULL,
        plan_json LONGTEXT NULL,
        state_json LONGTEXT NOT NULL,
        plan_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        app_version VARCHAR(32) NOT NULL,
        schema_revision VARCHAR(128) NOT NULL,
        registry_revision VARCHAR(64) NOT NULL,
        progress_done BIGINT UNSIGNED NOT NULL DEFAULT 0,
        progress_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
        progress_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
        cancel_requested TINYINT(1) NOT NULL DEFAULT 0,
        lock_key VARCHAR(32) NULL,
        error_category VARCHAR(64) NULL,
        error_message VARCHAR(255) NULL,
        created_at DATETIME NOT NULL,
        started_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY maintenance_jobs_mutation_lock (lock_key),
        KEY maintenance_jobs_actor_status (actor_id, status, updated_at),
        KEY maintenance_jobs_status_updated (status, updated_at),
        KEY maintenance_jobs_finished (finished_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
