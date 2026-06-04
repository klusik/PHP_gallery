<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606010001_gallery_lightbox_browsing_mode.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds optional per-gallery public lightbox browsing-mode overrides.
 *
 * Responsibilities:
 *   - Keep existing galleries inheriting the global Theme lightbox mode default
 *   - Store only explicit single-image, picture-strip, or 3D-carousel choices on gallery rows
 *   - Index the override for future diagnostics and maintenance workflows
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
 *   2026-06-01
 */

declare(strict_types=1);

return [
    "ALTER TABLE galleries ADD COLUMN lightbox_browsing_mode ENUM('single','picture_strip','3d_carousel') NULL AFTER count_badge_visibility",
    "ALTER TABLE galleries ADD KEY galleries_lightbox_browsing_mode_index (lightbox_browsing_mode)",
];
