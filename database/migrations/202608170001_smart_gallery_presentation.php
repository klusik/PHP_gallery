<?php

/**
 * Project: PHP Gallery
 * Module Type: Database Migration
 * Purpose: Add optional presentation overrides to Smart Gallery definitions.
 * Responsibilities:
 *   - Persist selected display settings while retaining default inheritance.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608170001_smart_gallery_presentation.php
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
/** Add optional presentation overrides to Smart Gallery definitions. */

declare(strict_types=1);

return [
    "ALTER TABLE smart_galleries
        ADD COLUMN presentation_json MEDIUMTEXT NULL AFTER sort_direction",
];
