<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610170001_gallery_presentation_settings.php
 * Module Type: Database Migration
 * Purpose: Store versioned physical-gallery presentation defaults.
 * Responsibilities: Add nullable settings without changing existing galleries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Ordinary ALTER TABLE only; interrupted duplicate-column replay is
 *   handled by the existing migration runner. No trigger or elevated privilege.
 */
declare(strict_types=1);

return [
    'ALTER TABLE galleries ADD COLUMN presentation_json TEXT NULL',
];
