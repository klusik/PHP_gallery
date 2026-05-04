<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605060001_gallery_grid_overrides.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds per-gallery public display-grid overrides with optional inheritance.
 *
 * Responsibilities:
 *   - Keep grid overrides nullable so existing galleries inherit current defaults
 *   - Store row counts for pagination and column counts for CSS grid rendering
 *   - Allow a parent gallery to decide whether descendants inherit its grid
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
    "ALTER TABLE galleries ADD COLUMN grid_columns TINYINT UNSIGNED NULL AFTER show_filenames",
    "ALTER TABLE galleries ADD COLUMN grid_rows SMALLINT UNSIGNED NULL AFTER grid_columns",
    "ALTER TABLE galleries ADD COLUMN grid_use_for_subgalleries TINYINT(1) NOT NULL DEFAULT 1 AFTER grid_rows",
    "ALTER TABLE galleries ADD KEY galleries_grid_inheritance_index (parent_id, grid_use_for_subgalleries)",
];
