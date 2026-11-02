<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202611020001_gallery_newest_uploads_first.php
 * Module Type: Database Migration
 * Purpose: Add an opt-in automatic image ordering preference to galleries.
 * Responsibilities:
 *   - Preserve existing gallery order by default on upgrades and fresh installs.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    // Type: boolean. Units: on/off. Scope: one gallery. Consumers: image scans
    // and the editor. Rationale: default 0 preserves existing manual ordering.
    'ALTER TABLE galleries ADD COLUMN newest_uploads_first TINYINT(1) NOT NULL DEFAULT 0',
];
