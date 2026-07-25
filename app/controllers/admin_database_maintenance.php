<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_database_maintenance.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles explicit administrator database maintenance actions.
 *
 * Responsibilities:
 *   - Require administrator authentication and CSRF validation
 *   - Keep inspection, logical cleanup, schema repair, ANALYZE, and OPTIMIZE separate
 *   - Enforce confirmation phrases for destructive data and schema operations
 *   - Redirect back to the dedicated maintenance tab with a clear status message
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

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\cms_not_found;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\database_maintenance_analyze_tables;
use function Gallery\Services\database_maintenance_apply_schema_repair;
use function Gallery\Services\database_maintenance_cleanup_step;
use function Gallery\Services\database_maintenance_inspect;
use function Gallery\Services\database_maintenance_optimize_tables;
use function Gallery\Services\database_maintenance_preview_optimize_tables;
use function Gallery\Services\database_maintenance_schema_repair_plan;
use function Gallery\Services\t;

/**
 * Require a POST request for a database maintenance action.
 */
function admin_database_maintenance_require_post(): bool
{
    if (request_method() === 'POST') {
        return true;
    }
    cms_not_found();
    return false;
}

/**
 * Redirect to the dedicated database maintenance tab.
 */
function admin_database_maintenance_redirect(): void
{
    redirect_to(url_for('admin_storage_statistics', ['tab' => 'maintenance']));
}

/**
 * Run the explicit read-only database inspection.
 */
function cms_admin_database_maintenance_inspect(): void
{
    require_admin();
    if (!admin_database_maintenance_require_post()) {
        return;
    }
    verify_csrf();

    try {
        $report = database_maintenance_inspect();
        flash_message('admin_notice', t('admin.database_maintenance.inspect_done', 'Database inspection completed. Audited {tables} table(s), found {candidates} safe cleanup candidate row(s), and {schema} legacy schema finding(s).', [
            'tables' => (string) (int) ($report['inventory']['table_count'] ?? 0),
            'candidates' => (string) array_sum(array_map(static fn (array $candidate): int => (int) ($candidate['candidate_count'] ?? 0), (array) ($report['cleanup_candidates'] ?? []))),
            'schema' => (string) count((array) ($report['legacy_schema_findings'] ?? [])),
        ]));
    } catch (Throwable $exception) {
        admin_log_event('error', 'database_maintenance.inspect_failed', 'Admin database inspection failed.', ['exception' => $exception->getMessage()], ['category' => 'database', 'severity' => 'error']);
        flash_message('admin_notice', t('admin.database_maintenance.inspect_failed', 'Database inspection failed: {error}', ['error' => $exception->getMessage()]));
    }

    admin_database_maintenance_redirect();
}

/**
 * Run one bounded safe cleanup step or a complete dry-run.
 */
function cms_admin_database_maintenance_cleanup(): void
{
    require_admin();
    if (!admin_database_maintenance_require_post()) {
        return;
    }
    verify_csrf();

    $dryRun = !empty($_POST['dry_run']);
    $restart = !empty($_POST['restart']);
    if (!$dryRun && strtoupper(trim((string) ($_POST['confirmation_text'] ?? ''))) !== 'CLEAN') {
        flash_message('admin_notice', t('admin.database_maintenance.cleanup_confirmation_required', 'Type CLEAN to confirm logical database cleanup.'));
        admin_database_maintenance_redirect();
    }

    try {
        $state = database_maintenance_cleanup_step($dryRun, (int) ($_POST['batch_size'] ?? 250), $restart);
        if (!empty($state['failed'])) {
            flash_message('admin_notice', t('admin.database_maintenance.cleanup_failed', 'Database cleanup stopped after a failure: {error}', ['error' => (string) ($state['error'] ?? 'Unknown error')]));
        } elseif ($dryRun) {
            flash_message('admin_notice', t('admin.database_maintenance.cleanup_dry_run_done', 'Database cleanup dry-run completed. {count} high-confidence row(s) would be removed.', ['count' => (string) (int) ($state['candidate_rows'] ?? 0)]));
        } elseif (!empty($state['completed'])) {
            flash_message('admin_notice', t('admin.database_maintenance.cleanup_done', 'Safe database cleanup completed. Removed {count} row(s). No filesystem files were deleted.', ['count' => (string) (int) ($state['deleted_rows'] ?? 0)]));
        } else {
            flash_message('admin_notice', t('admin.database_maintenance.cleanup_continue', 'Cleanup batch completed. Removed {count} row(s) so far. Continue the resumable operation to process remaining rules.', ['count' => (string) (int) ($state['deleted_rows'] ?? 0)]));
        }
    } catch (Throwable $exception) {
        admin_log_event('error', 'database_maintenance.cleanup_failed', 'Admin database cleanup request failed.', ['exception' => $exception->getMessage()], ['category' => 'database', 'severity' => 'error']);
        flash_message('admin_notice', t('admin.database_maintenance.cleanup_failed', 'Database cleanup failed: {error}', ['error' => $exception->getMessage()]));
    }

    admin_database_maintenance_redirect();
}

/**
 * Apply only the dedicated conditional legacy schema repair migration.
 */
function cms_admin_database_maintenance_repair(): void
{
    require_admin();
    if (!admin_database_maintenance_require_post()) {
        return;
    }
    verify_csrf();

    $dryRun = !empty($_POST['dry_run']);
    if (!$dryRun && strtoupper(trim((string) ($_POST['confirmation_text'] ?? ''))) !== 'REPAIR') {
        flash_message('admin_notice', t('admin.database_maintenance.repair_confirmation_required', 'Type REPAIR to confirm the listed legacy schema changes.'));
        admin_database_maintenance_redirect();
    }

    try {
        if ($dryRun) {
            $result = database_maintenance_schema_repair_plan();
            flash_message('admin_notice', t('admin.database_maintenance.repair_dry_run_done', 'Schema repair dry-run completed. {count} object change(s) are currently identified; no DDL or data deletion ran.', [
                'count' => (string) (int) ($result['finding_count'] ?? 0),
            ]));
        } else {
            $result = database_maintenance_apply_schema_repair();
            flash_message('admin_notice', (string) ($result['message'] ?? t('admin.database_maintenance.repair_done', 'Legacy schema repair completed.')));
        }
    } catch (Throwable $exception) {
        admin_log_event('error', 'database_maintenance.repair_failed', 'Admin legacy schema repair failed.', ['exception' => $exception->getMessage()], ['category' => 'database', 'severity' => 'error']);
        flash_message('admin_notice', t('admin.database_maintenance.repair_failed', 'Legacy schema repair failed: {error}', ['error' => $exception->getMessage()]));
    }

    admin_database_maintenance_redirect();
}

/**
 * Refresh optimizer statistics for explicitly selected current tables.
 */
function cms_admin_database_maintenance_analyze(): void
{
    require_admin();
    if (!admin_database_maintenance_require_post()) {
        return;
    }
    verify_csrf();

    try {
        $result = database_maintenance_analyze_tables(array_map('strval', (array) ($_POST['tables'] ?? [])));
        flash_message('admin_notice', t('admin.database_maintenance.analyze_done', 'ANALYZE TABLE finished for {tables} table(s), with {failed} failure(s). This refreshed optimizer metadata and did not reclaim table files.', [
            'tables' => (string) (int) ($result['table_count'] ?? 0),
            'failed' => (string) (int) ($result['failed_table_count'] ?? 0),
        ]));
    } catch (Throwable $exception) {
        admin_log_event('error', 'database_maintenance.analyze_failed', 'Admin ANALYZE TABLE request failed.', ['exception' => $exception->getMessage()], ['category' => 'database', 'severity' => 'error']);
        flash_message('admin_notice', t('admin.database_maintenance.analyze_failed', 'ANALYZE TABLE failed: {error}', ['error' => $exception->getMessage()]));
    }

    admin_database_maintenance_redirect();
}

/**
 * Format bytes for maintenance flash messages without loading a view helper.
 */
function admin_database_maintenance_format_bytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $unitIndex = 0;
    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }
    return number_format($value, $unitIndex === 0 ? 0 : 1) . ' ' . $units[$unitIndex];
}

/**
 * Rebuild or optimize explicitly selected tables after strong confirmation.
 */
function cms_admin_database_maintenance_optimize(): void
{
    require_admin();
    if (!admin_database_maintenance_require_post()) {
        return;
    }
    verify_csrf();

    $dryRun = !empty($_POST['dry_run']);
    if (!$dryRun && strtoupper(trim((string) ($_POST['confirmation_text'] ?? ''))) !== 'OPTIMIZE') {
        flash_message('admin_notice', t('admin.database_maintenance.optimize_confirmation_required', 'Type OPTIMIZE to confirm the selected table rebuild/optimization.'));
        admin_database_maintenance_redirect();
    }

    try {
        $selectedTables = array_map('strval', (array) ($_POST['tables'] ?? []));
        if ($dryRun) {
            $result = database_maintenance_preview_optimize_tables($selectedTables);
            flash_message('admin_notice', t('admin.database_maintenance.optimize_dry_run_done', 'OPTIMIZE dry-run validated {tables} selected table(s), totaling {size} with {free} reported data_free. No table operation ran.', [
                'tables' => (string) (int) ($result['table_count'] ?? 0),
                'size' => admin_database_maintenance_format_bytes((int) ($result['allocated_bytes'] ?? 0)),
                'free' => admin_database_maintenance_format_bytes((int) ($result['reclaimable_bytes_estimate'] ?? 0)),
            ]));
        } else {
            $result = database_maintenance_optimize_tables($selectedTables);
            flash_message('admin_notice', t('admin.database_maintenance.optimize_done', 'OPTIMIZE TABLE finished for {tables} table(s), with {failed} failure(s). Review database engine messages in Admin logs.', [
                'tables' => (string) (int) ($result['table_count'] ?? 0),
                'failed' => (string) (int) ($result['failed_table_count'] ?? 0),
            ]));
        }
    } catch (Throwable $exception) {
        admin_log_event('error', 'database_maintenance.optimize_failed', 'Admin OPTIMIZE TABLE request failed.', ['exception' => $exception->getMessage()], ['category' => 'database', 'severity' => 'error']);
        flash_message('admin_notice', t('admin.database_maintenance.optimize_failed', 'OPTIMIZE TABLE failed: {error}', ['error' => $exception->getMessage()]));
    }

    admin_database_maintenance_redirect();
}
