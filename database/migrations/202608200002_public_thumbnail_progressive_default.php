<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608200002_public_thumbnail_progressive_default.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Makes progressive selected-gallery thumbnail rendering the effective default on upgraded installations.
 *
 * Responsibilities:
 *   - Seed progressive mode on installations that do not yet have a persisted renderer setting
 *   - Move the previously persisted responsive default to progressive during this update
 *   - Keep responsive as a valid legacy renderer that administrators can select again after the migration
 *   - Bump the public content revision so cached public markup cannot retain the previous renderer choice
 *
 * Invariants:
 *   - The permanent machine values remain `progressive` and `responsive`
 *   - This migration is idempotent when replayed by migration validation or recovery tooling
 *   - No gallery, thumbnail derivative, media authorization, or renderer-specific data is deleted
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
 *   2026-08-20
 */

declare(strict_types=1);

return [
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('public_thumbnail_rendering_mode', 'progressive', NOW()) ON DUPLICATE KEY UPDATE setting_value = 'progressive', updated_at = NOW()",
    "INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('theme_public_content_revision', CAST(UNIX_TIMESTAMP() AS CHAR), NOW()) ON DUPLICATE KEY UPDATE setting_value = CAST(UNIX_TIMESTAMP() AS CHAR), updated_at = NOW()",
];
