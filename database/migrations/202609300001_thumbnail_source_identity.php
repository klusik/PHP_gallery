<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202609300001_thumbnail_source_identity.php
 * Module Type: Database Migration
 * Purpose:
 *   Preserve existing thumbnail names and select canonical names for new sources.
 * Responsibilities:
 *   - Keep thumbnail source identities stable and access-safe.
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Preserve existing thumbnails without bulk regeneration.
 */

declare(strict_types=1);

// Preserve existing derivative identities; subsequent inserts receive canonical names.
return [
    'ALTER TABLE images ADD COLUMN thumbnail_source_identity_version TINYINT UNSIGNED NOT NULL DEFAULT 0',
    'ALTER TABLE images ALTER COLUMN thumbnail_source_identity_version SET DEFAULT 1',
    'CREATE INDEX idx_images_gallery_filename ON images (gallery_id, filename)',
];
