<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608100001_admin_log_scaling.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds indexes required for bounded Admin log retention and grouped browsing
 *   when the operational log table grows large.
 *
 * Responsibilities:
 *   - Make age-based retention deletion use created_at without a full table scan
 *   - Support grouped Admin log aggregation by its stable grouping columns
 *   - Keep the existing Admin log payload and application semantics unchanged
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
 *   2026-08-10
 */

declare(strict_types=1);

return [
    "ALTER TABLE admin_logs
        ADD KEY admin_logs_created_id_index (created_at, id),
        ADD KEY admin_logs_grouping_created_index (event_key, level, category, severity, created_at, id)",
];
