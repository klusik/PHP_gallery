<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_log_archive_maintenance_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the Admin log daily filesystem archive model and its maintenance safety contract.
 *
 * Responsibilities:
 *   - Validate supported live-retention choices and complete-day boundaries
 *   - Confirm daily ZIP naming and immutable filesystem layout
 *   - Confirm archive creation is streamed, verified, and published before DB cleanup
 *   - Confirm authenticated Admin page loads use a lightweight 24-hour request counter and lock
 *   - Confirm the Admin Logs page exposes retention, manual maintenance, and archive-file actions
 *   - Confirm archive ZIPs are never automatically deleted
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

use function Gallery\Services\admin_log_archive_day_bounds;
use function Gallery\Services\admin_log_archive_file_name;
use function Gallery\Services\admin_log_archive_normalize_retention_days;
use function Gallery\Services\admin_log_archive_retention_options;
use function Gallery\Services\admin_log_archive_valid_date;

require_once __DIR__ . '/../app/services/logs.php';
require_once __DIR__ . '/../app/services/admin_log_archives.php';

/**
 * Throw when an Admin log archive maintenance expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function assert_admin_log_archive_maintenance(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$archiveServiceSource = (string) file_get_contents(__DIR__ . '/../app/services/admin_log_archives.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_logs.php');
$bootstrapSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap.php')
    . (string) file_get_contents(__DIR__ . '/../app/bootstrap/maintenance.php')
    . (string) file_get_contents(__DIR__ . '/../app/bootstrap/dispatch.php');
$servicesSource = (string) file_get_contents(__DIR__ . '/../app/services.php');
$siteMaintenanceSource = (string) file_get_contents(__DIR__ . '/../app/services/site_maintenance.php');
$siteMaintenanceViewSource = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard_sections.php');
$archiveHtaccess = (string) file_get_contents(__DIR__ . '/../data/admin-log-archives/.htaccess');
$databaseMaintenanceSource = (string) file_get_contents(__DIR__ . '/../app/services/database_maintenance.php');

assert_admin_log_archive_maintenance(
    admin_log_archive_retention_options() === [2, 7, 30, 90, 0],
    'Admin log retention choices must be 2, 7, 30, 90 days, and Forever.'
);
assert_admin_log_archive_maintenance(admin_log_archive_normalize_retention_days(2) === 2, 'Two-day retention must be supported.');
assert_admin_log_archive_maintenance(admin_log_archive_normalize_retention_days(7) === 7, 'Seven-day retention must be supported.');
assert_admin_log_archive_maintenance(admin_log_archive_normalize_retention_days(30) === 30, 'Thirty-day retention must be supported and remain the default.');
assert_admin_log_archive_maintenance(admin_log_archive_normalize_retention_days(90) === 90, 'Ninety-day retention must be supported.');
assert_admin_log_archive_maintenance(admin_log_archive_normalize_retention_days(0) === 0, 'Forever must disable automatic archiving.');
assert_admin_log_archive_maintenance(admin_log_archive_normalize_retention_days(14) === 30, 'Unsupported retention values must fall back to 30 days.');
assert_admin_log_archive_maintenance(admin_log_archive_valid_date('2026-08-10'), 'Canonical archive dates must validate.');
assert_admin_log_archive_maintenance(!admin_log_archive_valid_date('2026-02-30'), 'Impossible calendar dates must be rejected.');
assert_admin_log_archive_maintenance(admin_log_archive_file_name('2026-08-10') === 'admin-logs-2026-08-10.zip', 'Daily archive ZIP filenames must expose their calendar date.');
$bounds = admin_log_archive_day_bounds('2026-08-10');
assert_admin_log_archive_maintenance(
    $bounds === ['start' => '2026-08-10 00:00:00', 'end' => '2026-08-11 00:00:00'],
    'One archive must cover exactly one completed local calendar day.'
);

assert_admin_log_archive_maintenance(
    str_contains($servicesSource, "require_once __DIR__ . '/services/admin_log_archives.php';"),
    'The Admin log archive service must load after the existing log service.'
);
assert_admin_log_archive_maintenance(
    str_contains($archiveServiceSource, "'json_file' => 'admin-logs-' . (string) (\$snapshot['date'] ?? '') . '.json'")
        && str_contains($archiveServiceSource, "'html_file' => 'admin-logs-' . (string) (\$snapshot['date'] ?? '') . '.html'")
        && str_contains($archiveServiceSource, "'json_sha256'")
        && str_contains($archiveServiceSource, "'html_sha256'"),
    'Each daily ZIP must carry canonical JSON, static HTML, and manifest checksums.'
);
assert_admin_log_archive_maintenance(
    str_contains($archiveServiceSource, 'admin_log_archive_row_batch($snapshot, $afterId, ADMIN_LOG_ARCHIVE_ROW_BATCH_SIZE)')
        && str_contains($archiveServiceSource, "fwrite(\$jsonHandle")
        && str_contains($archiveServiceSource, 'admin_log_archive_write_html_entry($htmlHandle, $normalized, $written)'),
    'Daily archive generation must stream bounded DB batches directly to files.'
);
assert_admin_log_archive_maintenance(
    str_contains($archiveServiceSource, 'admin_log_archive_verify_file($zipPath, $date)')
        && str_contains($archiveServiceSource, "if (!@rename(\$zipPath, \$finalPath))")
        && str_contains($archiveServiceSource, 'admin_log_archive_delete_verified_rows((array) $verification[\'manifest\'], $deadline)'),
    'Archive maintenance must verify and atomically publish the ZIP before deleting represented DB rows.'
);
assert_admin_log_archive_maintenance(
    str_contains($archiveServiceSource, 'DELETE FROM admin_logs WHERE created_at >= ? AND created_at < ? AND id >= ? AND id <= ?')
        && str_contains($archiveServiceSource, 'ADMIN_LOG_ARCHIVE_DELETE_BATCH_SIZE'),
    'Post-archive database cleanup must be bounded and limited to the verified day/id range.'
);
assert_admin_log_archive_maintenance(
    str_contains($archiveServiceSource, 'admin_log_archive_reconcile_existing($date, $deadline)')
        && str_contains($archiveServiceSource, 'Existing Admin log archive failed verification'),
    'An existing ZIP must be verified and reconciled after interrupted cleanup instead of overwritten.'
);
assert_admin_log_archive_maintenance(
    str_contains($archiveServiceSource, 'LOCK_EX | LOCK_NB')
        && str_contains($archiveServiceSource, 'ADMIN_LOG_ARCHIVE_INTERVAL_SECONDS = 86400')
        && str_contains($archiveServiceSource, 'ADMIN_LOG_ARCHIVE_BACKLOG_RETRY_SECONDS = 60'),
    'Automatic archive maintenance must use a non-blocking cross-request lock and lightweight daily/backlog counter.'
);
assert_admin_log_archive_maintenance(
    str_contains($bootstrapSource, 'admin_log_archive_register_request_trigger($page)')
        && str_contains($archiveServiceSource, "['admin', 'admin_logs']")
        && str_contains($archiveServiceSource, 'register_shutdown_function'),
    'Only authenticated Admin page loads may register due archive work, and it must execute after response.'
);
assert_admin_log_archive_maintenance(
    str_contains($bootstrapSource, "'admin_log_archive_maintenance' =>")
        && str_contains($bootstrapSource, "'admin_log_archive_view' =>")
        && str_contains($bootstrapSource, "'admin_log_archive_download' =>"),
    'Admin archive maintenance, view, and ZIP download endpoints must be routed.'
);
assert_admin_log_archive_maintenance(
    str_contains($controllerSource, 'Run maintenance cycle now')
        && str_contains($controllerSource, 'Keep live logs')
        && str_contains($controllerSource, 'View HTML')
        && str_contains($controllerSource, 'View JSON')
        && str_contains($controllerSource, 'Download ZIP')
        && str_contains($controllerSource, "name=\"action\" value=\"delete_archive\""),
    'Admin Logs must expose retention, force-run, view, download, and manual archive deletion controls.'
);
assert_admin_log_archive_maintenance(
    str_contains($controllerSource, 'function render_admin_log_section_tabs(string $activeSection): void')
        && str_contains($controllerSource, "admin.logs.section_logs', 'Logs'")
        && str_contains($controllerSource, "admin.logs.section_maintenance', 'Maintenance & archives'")
        && str_contains($controllerSource, 'if ($section === \'maintenance\')'),
    'Admin Logs must separate live browsing from maintenance and archive management with server-backed subtabs.'
);
assert_admin_log_archive_maintenance(
    strpos($controllerSource, 'if ($section === \'maintenance\')') < strpos($controllerSource, 'admin_log_grouped_count($status, $filters)')
        && str_contains($controllerSource, "redirect_to(url_for('admin_logs', ['section' => 'maintenance']));"),
    'The Maintenance & archives subtab must bypass live-log queries and maintenance actions must return to that subtab.'
);
assert_admin_log_archive_maintenance(
    !str_contains($siteMaintenanceSource, 'admin_log_cleanup_retention(null, $deadline)')
        && !str_contains($siteMaintenanceViewSource, 'site_maintenance_admin_log_retention_days'),
    'General site maintenance must no longer purge Admin logs or own the retention setting.'
);
assert_admin_log_archive_maintenance(
    substr_count($archiveServiceSource, 'admin_log_archive_delete_file(') === 1
        && str_contains($controllerSource, 'admin_log_archive_delete_file($date)'),
    'Archive ZIP deletion must exist only as an explicit Admin action, never in automatic maintenance.'
);
assert_admin_log_archive_maintenance(
    str_contains($archiveHtaccess, 'Require all denied'),
    'The archive directory must deny direct Apache access as defense in depth.'
);
assert_admin_log_archive_maintenance(
    str_contains($databaseMaintenanceSource, 'only after a verified daily filesystem ZIP has been created'),
    'Generic database-maintenance policy text must describe the archive-first Admin log contract.'
);

fwrite(STDOUT, "Admin log archive maintenance tests passed.\n");
