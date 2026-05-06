<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605060002_nsfw_guard.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds the NSFW Guard flags used by gallery-level inheritance and optional
 *   per-photo restrictions.
 *
 * Responsibilities:
 *   - Store an admin-controlled NSFW marker on galleries
 *   - Store an admin-controlled NSFW marker on individual images
 *   - Add narrow indexes for public filtering and admin lookups
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
 *   2026-05-06
 */

return [
    "ALTER TABLE galleries ADD COLUMN nsfw_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER grid_use_for_subgalleries",
    "ALTER TABLE galleries ADD KEY galleries_nsfw_parent_index (parent_id, nsfw_enabled)",
    "ALTER TABLE images ADD COLUMN nsfw_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER visibility",
    "ALTER TABLE images ADD KEY images_gallery_nsfw_visibility_index (gallery_id, nsfw_enabled, visibility, sort_order, filename)",
];
