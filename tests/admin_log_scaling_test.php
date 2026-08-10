<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_log_scaling_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies Admin log large-table safeguards, lazy group loading, streaming exports,
 *   and archive-first retention compatibility.
 *
 * Responsibilities:
 *   - Confirm grouped page queries never attach every raw instance automatically
 *   - Confirm grouped instances are loaded through bounded browser requests
 *   - Confirm complete and grouped exports use bounded streaming batches
 *   - Confirm legacy direct retention cannot delete unarchived Admin logs
 *   - Confirm the scaling migration adds both supporting indexes in one ALTER TABLE
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
 *   2026-08-10
 */

declare(strict_types=1);

use function Gallery\Services\admin_log_normalize_retention_days;

require_once __DIR__ . '/../app/services/logs.php';

/**
 * Throw when an Admin log scaling expectation fails.
 */
function assert_admin_log_scaling(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/logs.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_logs.php');
$bootstrapSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap.php');
$maintenanceSource = (string) file_get_contents(__DIR__ . '/../app/services/site_maintenance.php');
$maintenanceViewSource = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard_sections.php');
$browserSource = (string) file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-logs.js');
$migrationSource = (string) file_get_contents(__DIR__ . '/../database/migrations/202608100001_admin_log_scaling.php');

assert_admin_log_scaling(
    !str_contains($serviceSource, 'return admin_log_attach_group_members($stmt->fetchAll());'),
    'Grouped Admin log listing must not attach every raw group member to the initial page result.'
);
assert_admin_log_scaling(
    str_contains($serviceSource, 'function admin_log_group_member_page(')
        && str_contains($serviceSource, 'LIMIT \' . $safeLimit . \' OFFSET \' . $safeOffset'),
    'Grouped raw-instance browsing must use a bounded SQL page.'
);
assert_admin_log_scaling(
    str_contains($controllerSource, 'function cms_admin_log_group_members(): void')
        && str_contains($controllerSource, 'ADMIN_LOG_GROUP_MEMBER_PAGE_SIZE + 1')
        && str_contains($bootstrapSource, "'admin_log_group_members' =>"),
    'Lazy grouped-instance loading must have a routed, bounded controller endpoint.'
);
assert_admin_log_scaling(
    str_contains($browserSource, 'data-admin-log-group-members')
        && str_contains($browserSource, "url.searchParams.set('offset', String(nextOffset))"),
    'Admin log browser code must request grouped members lazily by bounded offset.'
);
assert_admin_log_scaling(
    str_contains($controllerSource, 'admin_log_create_export_zip_streamed($filePath)')
        && str_contains($serviceSource, 'function admin_log_export_row_batch(')
        && str_contains($serviceSource, 'function admin_log_create_export_zip_streamed('),
    'Complete Admin log ZIP export must use bounded streaming batches.'
);
assert_admin_log_scaling(
    str_contains($controllerSource, 'admin_log_group_member_export_batch($entry, $beforeCreatedAt, $beforeId, $batchSize)')
        && str_contains($serviceSource, 'l.created_at < ? OR (l.created_at = ? AND l.id < ?)'),
    'Grouped TXT export must use descending keyset batches rather than one unbounded fetch.'
);
assert_admin_log_scaling(admin_log_normalize_retention_days(0) === 0, 'Zero must remain a supported legacy retention value.');
assert_admin_log_scaling(admin_log_normalize_retention_days(1) === 1, 'Legacy retention normalization must remain backward compatible.');
assert_admin_log_scaling(admin_log_normalize_retention_days(30) === 30, 'The live-log default window is now 30 days.');
assert_admin_log_scaling(admin_log_normalize_retention_days(9999) === 3650, 'Legacy retention normalization must keep its upper bound.');
assert_admin_log_scaling(
    str_contains($serviceSource, "'reason' => 'archive_service_required'")
        && !str_contains($serviceSource, 'DELETE FROM admin_logs WHERE created_at < ? ORDER BY created_at ASC, id ASC LIMIT '),
    'Legacy direct retention must no longer delete unarchived Admin log rows.'
);
assert_admin_log_scaling(
    !str_contains($maintenanceSource, 'admin_log_cleanup_retention(null, $deadline)')
        && !str_contains($maintenanceViewSource, 'site_maintenance_admin_log_retention_days'),
    'General site maintenance must no longer own Admin log retention or direct deletion.'
);
assert_admin_log_scaling(
    substr_count($migrationSource, 'ALTER TABLE admin_logs') === 1
        && str_contains($migrationSource, 'admin_logs_created_id_index')
        && str_contains($migrationSource, 'admin_logs_grouping_created_index'),
    'The scaling migration must add both Admin log indexes in a single ALTER TABLE operation.'
);

fwrite(STDOUT, "Admin log scaling tests passed.\n");
