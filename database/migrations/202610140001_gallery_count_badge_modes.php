<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202610140001_gallery_count_badge_modes.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Extend per-gallery photo-count mode choices while retaining historical
 *   show/hide override values and nullable Theme inheritance.
 *
 * Responsibilities:
 *   - Expand supported per-gallery count display overrides without data loss
 *   - Preserve existing show/hide values and NULL Theme inheritance semantics
 *   - Keep the database compatible with public/all/both display mode writes
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Existing show/hide overrides keep their original meaning.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

return [
    "ALTER TABLE galleries MODIFY COLUMN count_badge_visibility ENUM('show','hide','public','all','both') NULL DEFAULT NULL",
];
