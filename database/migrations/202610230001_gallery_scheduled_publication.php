<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610230001_gallery_scheduled_publication.php
 * Module Type: Database Migration
 * Purpose: Store one pending UTC publication deadline on its gallery.
 * Responsibilities: Add nullable storage and an index for visitor due checks.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    'ALTER TABLE galleries ADD COLUMN scheduled_publish_at DATETIME NULL',
    'ALTER TABLE galleries ADD INDEX idx_galleries_scheduled_publish_at (scheduled_publish_at)',
];
