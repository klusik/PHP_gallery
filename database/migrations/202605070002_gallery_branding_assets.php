<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605070002_gallery_branding_assets.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds optional per-gallery branding image paths.
 *
 * Responsibilities:
 *   - Persist banner, logo, and horizontal separator asset locations
 *   - Keep uploaded files in the existing gallery-folder storage model
 *   - Avoid changing existing gallery rendering when fields are empty
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

declare(strict_types=1);

return [
    "ALTER TABLE galleries ADD COLUMN banner_image_path VARCHAR(1024) NULL AFTER background_source",
    "ALTER TABLE galleries ADD COLUMN logo_image_path VARCHAR(1024) NULL AFTER banner_image_path",
    "ALTER TABLE galleries ADD COLUMN separator_image_path VARCHAR(1024) NULL AFTER logo_image_path",
];
