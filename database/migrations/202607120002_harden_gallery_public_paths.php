<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202607120002_harden_gallery_public_paths.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Rebuilds clean public gallery paths using the current deterministic slug rules.
 *
 * Responsibilities:
 *   - Repair galleries whose url_path was never generated after creation or movement
 *   - Remove spaces, diacritics, combining marks, and unsafe characters from public URLs
 *   - Preserve hierarchical gallery URLs while resolving sibling collisions deterministically
 *   - Remain compatible with both the former SQL-only and current migration runners
 *   - Remain harmless on a fresh installation with no gallery rows
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
 *   2026-07-12
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/app/migration_repairs.php';

$legacyPdo = isset($pdo) && $pdo instanceof \PDO ? $pdo : null;
return \Gallery\Core\gallery_public_path_repair_migration_definition(
    $legacyPdo,
    false,
);
