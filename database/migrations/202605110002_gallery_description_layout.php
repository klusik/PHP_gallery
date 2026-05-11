<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605110002_gallery_description_layout.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds optional per-gallery public description-layout overrides.
 *
 * Responsibilities:
 *   - Keep existing galleries inheriting the Theme default
 *   - Store only explicit gallery-card layout choices on gallery rows
 *   - Support vertical and horizontal public gallery-card systems
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
 *   2026-05-11
 */

return [
    "ALTER TABLE galleries ADD COLUMN description_layout ENUM('vertical','horizontal') NULL AFTER show_filenames",
    "ALTER TABLE galleries ADD KEY galleries_description_layout_index (description_layout)",
];
