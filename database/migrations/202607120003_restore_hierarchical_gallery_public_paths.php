<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202607120003_restore_hierarchical_gallery_public_paths.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Restores complete hierarchical public paths after the first URL hardening repair.
 *
 * Responsibilities:
 *   - Infer gallery parents from canonical filesystem folder nesting
 *   - Repair historical null or stale parent_id values
 *   - Rebuild every gallery url_slug, url_path, and url_path_hash
 *   - Verify nested filesystem galleries remain nested in their public URLs
 *   - Remain compatible with both the former SQL-only and current migration runners
 *   - Remain harmless on fresh installations with no gallery rows
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
