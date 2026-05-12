<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605120001_gallery_count_badge_visibility.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds optional per-gallery public contained-picture count badge overrides.
 *
 * Responsibilities:
 *   - Keep existing galleries inheriting the global Theme count badge default
 *   - Store only explicit gallery-card count badge choices on gallery rows
 *   - Support show and hide overrides for public gallery cards
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
    "ALTER TABLE galleries ADD COLUMN count_badge_visibility ENUM('show','hide') NULL AFTER description_layout",
    "ALTER TABLE galleries ADD KEY galleries_count_badge_visibility_index (count_badge_visibility)",
];
