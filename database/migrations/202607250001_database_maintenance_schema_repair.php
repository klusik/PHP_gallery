<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202607250001_database_maintenance_schema_repair.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Repairs partially applied thumbnail metadata compaction without modifying
 *   historical migration files.
 *
 * Responsibilities:
 *   - Create the transactional database cleanup audit table when absent
 *   - Inspect every table, column, index, and foreign key before altering it
 *   - Preserve source geometry before removing duplicated legacy columns
 *   - Tolerate legacy, partially compacted, and already compact schemas
 *   - Preserve compatibility with the former SQL-only migration runner
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
 *   2026-07-25
 */

declare(strict_types=1);

use function Gallery\Core\database_maintenance_schema_repair_migration_definition;

require_once dirname(__DIR__, 2) . '/app/migration_repairs.php';

return database_maintenance_schema_repair_migration_definition(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
