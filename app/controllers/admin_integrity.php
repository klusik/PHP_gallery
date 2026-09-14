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
use function Gallery\Services\admin_log_event;

/**
 * Admin integrity controller model.
 *
 * This module renders integrity summaries and path lists, and handles the admin integrity screen. It is separated from visual theme customization.
 *
 * @param array $integrityStatus Integrity status value.
 */
function render_admin_integrity_summary(array $integrityStatus): void
{
    $status = (string) ($integrityStatus['status'] ?? 'error');
    \Gallery\Views\view_render_admin_integrity_summary([
        'status' => $status,
        'status_label' => integrity_status_label($status),
        'modified_count' => count((array) ($integrityStatus['modified'] ?? [])),
        'missing_count' => count((array) ($integrityStatus['missing'] ?? [])),
        'unknown_count' => count((array) ($integrityStatus['unknown'] ?? [])),
        'checked_at' => (string) ($integrityStatus['checked_at_iso'] ?? ''),
        'manifest_error' => (string) ($integrityStatus['manifest_error'] ?? ''),
        'details_url' => url_for('admin_integrity'),
    ]);
}

/**
 * Handles render admin integrity path list logic for the gallery application.
 *
 * @param mixed $title Input used by this operation.
 * @param mixed $paths Input used by this operation.
 */
function render_admin_integrity_path_list(string $title, array $paths): void
{
    \Gallery\Views\view_render_admin_integrity_path_list($title, $paths);
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
    \Gallery\Views\view_render_admin_integrity_page([
        'status' => $status,
        'status_label' => integrity_status_label((string) ($status['status'] ?? 'error')),
        'checked' => isset($_GET['checked']),
        'dashboard_url' => url_for('admin'),
        'check_url' => url_for('admin_integrity'),
        'csrf_html' => csrf_field(),
    ]);
}

