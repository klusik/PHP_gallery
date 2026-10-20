<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610200001_tag_media_types.php
 * Module Type: Database Migration
 * Purpose: Add explicit media ownership to the shared tag taxonomy.
 * Responsibilities:
 *   - Preserve all existing tag identities and image assignments as image tags
 *   - Keep the globally unique slug constraint unchanged
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

return [
    'ALTER TABLE tags ADD COLUMN media_type ENUM(\'image\', \'music\', \'pdf\') NOT NULL DEFAULT \'image\' AFTER slug',
];
