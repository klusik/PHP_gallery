<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608310001_reconcile_updater_server_policy_files.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Repairs application-owned Apache policy files during the first update from an older updater.
 *
 * Responsibilities:
 *   - Bridge releases whose previous updater skipped root and nested .htaccess files
 *   - Reuse the already downloaded and validated release instead of performing new remote I/O
 *   - Preserve rollback safety before changing any policy file
 *   - Remain a harmless no-op on fresh installs, manual migrations, and already-correct updates
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
 *   - The helper is required directly because an updater request that started on the
 *     previous release still has the previous service definitions loaded in memory.
 *
 * Last Updated:
 *   2026-08-31
 */

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/app/services/update_server_policy_reconciliation.php';

return [
    'statements' => [],
    'after' => static function ($_pdo): void {
        \Gallery\Services\application_update_reconcile_server_policy_files_from_active_job();
    },
];
