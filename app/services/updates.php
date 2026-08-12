<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates.php
 * Module Type: Service
 *
 * Purpose:
 *   Loads focused update service modules while preserving the legacy app/services.php include contract.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;


require_once __DIR__ . '/updates_status.php';
require_once __DIR__ . '/updates_patch_notes.php';
require_once __DIR__ . '/updates_install.php';
require_once __DIR__ . '/updates_remote.php';
require_once __DIR__ . '/updates_filesystem.php';