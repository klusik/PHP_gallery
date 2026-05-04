<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202604300001_gallery_voting.php
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
    "ALTER TABLE galleries ADD COLUMN voting_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility",
];
