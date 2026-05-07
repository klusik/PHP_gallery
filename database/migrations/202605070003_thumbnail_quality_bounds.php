<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605070003_thumbnail_quality_bounds.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds optional responsive thumbnail quality bounds for galleries and images.
 *
 * Responsibilities:
 *   - Keep both values nullable so automatic responsive thumbnail behavior remains unchanged
 *   - Store per-gallery guardrails that can later be inherited by public rendering
 *   - Store per-image guardrails for precise photo-level overrides
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
 *   2026-05-07
 */

return [
    "ALTER TABLE galleries ADD COLUMN thumbnail_min_size SMALLINT UNSIGNED NULL AFTER grid_use_for_subgalleries",
    "ALTER TABLE galleries ADD COLUMN thumbnail_max_size SMALLINT UNSIGNED NULL AFTER thumbnail_min_size",
    "ALTER TABLE images ADD COLUMN thumbnail_min_size SMALLINT UNSIGNED NULL AFTER nsfw_enabled",
    "ALTER TABLE images ADD COLUMN thumbnail_max_size SMALLINT UNSIGNED NULL AFTER thumbnail_min_size",
    "ALTER TABLE galleries ADD KEY galleries_thumbnail_bounds_index (thumbnail_min_size, thumbnail_max_size)",
    "ALTER TABLE images ADD KEY images_thumbnail_bounds_index (gallery_id, thumbnail_min_size, thumbnail_max_size)",
];
