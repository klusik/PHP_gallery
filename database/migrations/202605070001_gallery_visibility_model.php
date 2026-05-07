<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605070001_gallery_visibility_model.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Renames the legacy gallery draft visibility state to unpublished.
 *
 * Responsibilities:
 *   - Preserve existing public and private gallery behavior
 *   - Convert legacy draft galleries to unpublished galleries
 *   - Convert legacy public unlisted galleries to unpublished galleries
 *   - Leave image visibility values unchanged
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
    "ALTER TABLE galleries MODIFY visibility ENUM('draft','unpublished','public','private') NOT NULL DEFAULT 'unpublished'",
    "UPDATE galleries SET visibility = 'unpublished', access_listing = 'unlisted' WHERE visibility = 'draft'",
    "UPDATE galleries SET visibility = 'unpublished', access_listing = 'unlisted' WHERE visibility = 'public' AND access_listing = 'unlisted'",
    "UPDATE galleries SET access_listing = 'listed' WHERE visibility = 'public'",
    "ALTER TABLE galleries MODIFY visibility ENUM('unpublished','public','private') NOT NULL DEFAULT 'unpublished'",
];
