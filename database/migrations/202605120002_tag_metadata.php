<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605120002_tag_metadata.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds editable public metadata for reusable tags.
 *
 * Responsibilities:
 *   - Store an optional public description for tag landing pages
 *   - Keep existing tag rows compatible with the new admin tag editor
 *   - Avoid changing existing gallery and image tag assignments
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
 *   2026-05-12
 */

return [
    "ALTER TABLE tags ADD COLUMN description TEXT NULL AFTER slug",
];
