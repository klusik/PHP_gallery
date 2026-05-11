<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605110003_gallery_manual_date.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds an optional manual date field to galleries.
 *
 * Responsibilities:
 *   - Store an admin-selected gallery date independently from upload dates
 *   - Keep the value nullable so existing galleries display no date by default
 *   - Index the date for future sorting and timeline features
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
    "ALTER TABLE galleries ADD COLUMN gallery_date DATE NULL DEFAULT NULL AFTER description",
    "ALTER TABLE galleries ADD KEY galleries_gallery_date_index (gallery_date)",
];
