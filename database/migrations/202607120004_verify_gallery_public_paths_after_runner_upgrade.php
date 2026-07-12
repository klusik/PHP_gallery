<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202607120004_verify_gallery_public_paths_after_runner_upgrade.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Re-runs and verifies the hierarchical URL repair after migration-runner compatibility was hardened.
 *
 * Responsibilities:
 *   - Recover installations where an earlier public-path migration was applied incompletely
 *   - Verify all nested galleries retain complete public URL ancestry
 *   - Remain compatible with both the former SQL-only and current migration runners
 *   - Leave already-correct installations unchanged apart from deterministic path regeneration
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
return \Gallery\Core\gallery_public_path_repair_migration_definition($legacyPdo, true);
