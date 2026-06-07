<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606070001_gallery_date_ranges.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Extends manual gallery dates so galleries can store a date range.
 *
 * Responsibilities:
 *   - Add a nullable gallery_date_end column beside the existing gallery_date start value
 *   - Keep single-date galleries compatible by leaving the end date empty
 *   - Index the range end value for future timeline and maintenance tools
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
 *   2026-06-07
 */

return [
    "ALTER TABLE galleries ADD COLUMN gallery_date_end DATE NULL DEFAULT NULL AFTER gallery_date",
    "ALTER TABLE galleries ADD KEY galleries_gallery_date_end_index (gallery_date_end)",
];
