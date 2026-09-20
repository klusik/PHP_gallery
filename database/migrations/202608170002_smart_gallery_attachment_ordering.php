<?php

/**
 * Project: PHP Gallery
 * Module Type: Database Migration
 * Purpose: Add per-parent Smart Gallery placement area and ordering.
 * Responsibilities:
 *   - Persist deterministic attachment order within each parent context.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608170002_smart_gallery_attachment_ordering.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/** Add per-parent Smart Gallery placement area and deterministic ordering. */

declare(strict_types=1);

return [
    "ALTER TABLE smart_gallery_placements
        ADD COLUMN placement ENUM('top', 'bottom') NOT NULL DEFAULT 'bottom' AFTER gallery_id,
        ADD COLUMN placement_order INT NOT NULL DEFAULT 0 AFTER placement,
        ADD KEY smart_gallery_placements_render_order (gallery_id, placement, placement_order, smart_gallery_id)",
];
