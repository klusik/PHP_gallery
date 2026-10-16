<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202610140002_gallery_cover_image_ids.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Preserve a gallery's explicit multi-photo or automatic title-picture choice.
 *
 * Responsibilities:
 *   - Add nullable ordered cover-selection storage without rewriting legacy galleries
 *   - Preserve the scalar cover_image_id as the compatible primary selection
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - NULL: legacy single-cover behavior.
 *   - JSON []: automatic, bounded collage of direct/child images.
 *   - JSON [id, ...]: manually selected photos in blue-star accumulation mode.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

return [
    "ALTER TABLE galleries ADD COLUMN cover_image_ids TEXT NULL AFTER cover_image_id",
];
