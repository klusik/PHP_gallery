<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202606010002_gallery_lightbox_browsing_mode_carousel.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Extends public lightbox browsing-mode persistence for the 3D carousel view.
 *
 * Responsibilities:
 *   - Preserve existing galleries that already stored the earlier strip value
 *   - Rename strip to the final public picture_strip value used by markup and sidecars
 *   - Allow galleries to explicitly select the new 3D carousel mode
 *   - Keep NULL as the inherited Theme-default state
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
    "ALTER TABLE galleries MODIFY lightbox_browsing_mode ENUM('single','strip','picture_strip','3d_carousel') NULL",
    "UPDATE galleries SET lightbox_browsing_mode = 'picture_strip' WHERE lightbox_browsing_mode = 'strip'",
    "ALTER TABLE galleries MODIFY lightbox_browsing_mode ENUM('single','picture_strip','3d_carousel') NULL",
    "UPDATE app_settings SET setting_value = 'picture_strip' WHERE setting_key = 'theme_lightbox_browsing_mode' AND setting_value = 'strip'",
];
