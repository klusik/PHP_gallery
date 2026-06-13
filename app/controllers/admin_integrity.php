<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_integrity.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\integrity_status;
use function Gallery\Core\integrity_status_label;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\t;

/**
 * Admin integrity controller model.
 *
 * This module renders integrity summaries and path lists, and handles the admin integrity screen. It is separated from visual theme customization.
 *
 * @param array $integrityStatus Integrity status value.
 */
function render_admin_integrity_summary(array $integrityStatus): void
{
    // $status stores an intermediate value used by the surrounding gallery workflow.
    $status = (string) ($integrityStatus['status'] ?? 'error');
    // $label stores an intermediate value used by the surrounding gallery workflow.
    $label = integrity_status_label($status);
    // $modifiedCount stores an intermediate value used by the surrounding gallery workflow.
    $modifiedCount = count((array) ($integrityStatus['modified'] ?? []));
    // $missingCount stores an intermediate value used by the surrounding gallery workflow.
    $missingCount = count((array) ($integrityStatus['missing'] ?? []));
    // $unknownCount stores an intermediate value used by the surrounding gallery workflow.
    $unknownCount = count((array) ($integrityStatus['unknown'] ?? []));
    // $checkedAt stores an intermediate value used by the surrounding gallery workflow.
    $checkedAt = (string) ($integrityStatus['checked_at_iso'] ?? '');

    echo '<section class="panel"><h2>' . e(t('admin.integrity.title', 'System integrity')) . '</h2>';
    echo '<p><strong>' . e(t('admin.integrity.status', 'Status')) . ':</strong> ' . e($label) . '</p>';
    if ($status === 'ok') {
        echo '<p class="muted">' . e(t('admin.integrity.summary_ok', 'Core PHP, HTML, CSS and JavaScript files match the installed manifest.')) . '</p>';
    } elseif ($status === 'warning') {
        echo '<p class="notice">' . e(t('admin.integrity.summary_warning', 'Core files match, but {count} unknown core-like file(s) were found.', ['count' => (string) $unknownCount])) . '</p>';
    } elseif ($status === 'modified') {
        echo '<p class="notice">' . e(t('admin.integrity.summary_modified', 'Detected {modified} modified and {missing} missing core file(s).', ['modified' => (string) $modifiedCount, 'missing' => (string) $missingCount])) . '</p>';
    } else {
        echo '<p class="notice">' . e((string) ($integrityStatus['manifest_error'] ?? t('admin.integrity.check_failed', 'Integrity check failed.'))) . '</p>';
    }
    if ($checkedAt !== '') {
        echo '<p class="muted">' . e(t('admin.integrity.last_checked_value', 'Last checked: {time}', ['time' => $checkedAt])) . '</p>';
    }
    echo '<p><a class="button secondary" href="' . e(url_for('admin_integrity')) . '">' . e(t('admin.integrity.show_details', 'Show details')) . '</a></p>';
    echo '</section>';
}

/**
 * Handles render admin integrity path list logic for the gallery application.
 *
 * @param mixed $title Input used by this operation.
 * @param mixed $paths Input used by this operation.
 */
function render_admin_integrity_path_list(string $title, array $paths): void
{
    echo '<h3>' . e($title) . '</h3>';
    if (!$paths) {
        echo '<p class="muted">' . e(t('admin.integrity.none', 'None.')) . '</p>';
        return;
    }

    echo '<ul>';
    foreach ($paths as $path) {
        echo '<li><code>' . e((string) $path) . '</code></li>';
    }
    echo '</ul>';
}

/**
 * Handles cms admin integrity logic for the gallery application.
 */
function cms_admin_integrity(): void
{
    require_admin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        // $status stores an intermediate value used by the surrounding gallery workflow.
        $status = integrity_status(true);
        admin_log_event('info', 'integrity.checked', 'Admin ran the system integrity check.', [
            'status' => (string) ($status['status'] ?? 'unknown'),
            'modified' => count((array) ($status['modified'] ?? [])),
            'missing' => count((array) ($status['missing'] ?? [])),
            'unknown' => count((array) ($status['unknown'] ?? [])),
        ]);
        redirect_to(url_for('admin_integrity', ['checked' => 1]));
    }

    // $status stores an intermediate value used by the surrounding gallery workflow.
    $status = integrity_status(false);
    render_header(t('admin.integrity.title', 'System integrity'));
    echo '<section class="hero"><h1>' . e(t('admin.integrity.title', 'System integrity')) . '</h1><nav class="nav">';
    echo '<a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.common.back_to_dashboard', 'Back to dashboard')) . '</a>';
    echo '<form method="post" action="' . e(url_for('admin_integrity')) . '" class="inline-action-form">' . csrf_field();
    echo '<button type="submit">' . e(t('admin.integrity.check_now', 'Check now')) . '</button>';
    echo '</form>';
    echo '</nav></section>';

    if (isset($_GET['checked'])) {
        echo '<div class="notice">' . e(t('admin.integrity.completed', 'Integrity check completed.')) . '</div>';
    }

    echo '<section class="panel">';
    echo '<h2>' . e(t('admin.integrity.status_value', 'Status: {status}', ['status' => integrity_status_label((string) ($status['status'] ?? 'error'))])) . '</h2>';
    echo '<p><strong>' . e(t('admin.integrity.manifest_version', 'Manifest version')) . ':</strong> ' . e((string) ($status['version'] ?? '')) . '</p>';
    echo '<p><strong>' . e(t('admin.integrity.last_checked', 'Last checked')) . ':</strong> ' . e((string) ($status['checked_at_iso'] ?? '')) . '</p>';

    if (!empty($status['manifest_error'])) {
        echo '<p class="notice">' . e((string) $status['manifest_error']) . '</p>';
    }

    render_admin_integrity_path_list(t('admin.integrity.modified_core_files', 'Modified core files'), (array) ($status['modified'] ?? []));
    render_admin_integrity_path_list(t('admin.integrity.missing_core_files', 'Missing core files'), (array) ($status['missing'] ?? []));
    render_admin_integrity_path_list(t('admin.integrity.unknown_core_files', 'Unknown core-like files'), (array) ($status['unknown'] ?? []));
    echo '<p class="muted">' . e(t('admin.integrity.ignored_folders', 'Ignored folders include cache, galleries, custom CSS, local config, and common hosting/runtime files.')) . '</p>';
    echo '</section>';
    render_footer();
}

