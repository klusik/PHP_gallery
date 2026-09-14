<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_integrity.php
 * Module Type: View
 *
 * Purpose:
 *   Renders system-integrity summaries and the full Admin integrity page.
 *
 * Responsibilities:
 *   - Render dashboard integrity summary and detailed path lists
 *   - Render the manual integrity-check page from controller-prepared state
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Integrity checks, logging, request handling, URL generation, and CSRF issuance stay in the controller.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/** @param array<string,mixed> $viewModel Controller-prepared dashboard integrity summary. */
function view_render_admin_integrity_summary(array $viewModel): void
{
    $status = (string) ($viewModel['status'] ?? 'error');
    $modifiedCount = (int) ($viewModel['modified_count'] ?? 0);
    $missingCount = (int) ($viewModel['missing_count'] ?? 0);
    $unknownCount = (int) ($viewModel['unknown_count'] ?? 0);
    $checkedAt = (string) ($viewModel['checked_at'] ?? '');

    echo '<section class="panel"><h2>' . e(t('admin.integrity.title', 'System integrity')) . '</h2><p><strong>' . e(t('admin.integrity.status', 'Status')) . ':</strong> ' . e((string) ($viewModel['status_label'] ?? '')) . '</p>';
    if ($status === 'ok') {
        echo '<p class="muted">' . e(t('admin.integrity.summary_ok', 'Core PHP, HTML, CSS and JavaScript files match the installed manifest.')) . '</p>';
    } elseif ($status === 'warning') {
        echo '<p class="notice">' . e(t('admin.integrity.summary_warning', 'Core files match, but {count} unknown core-like file(s) were found.', ['count' => (string) $unknownCount])) . '</p>';
    } elseif ($status === 'modified') {
        echo '<p class="notice">' . e(t('admin.integrity.summary_modified', 'Detected {modified} modified and {missing} missing core file(s).', ['modified' => (string) $modifiedCount, 'missing' => (string) $missingCount])) . '</p>';
    } else {
        echo '<p class="notice">' . e((string) ($viewModel['manifest_error'] ?? t('admin.integrity.check_failed', 'Integrity check failed.'))) . '</p>';
    }
    if ($checkedAt !== '') {
        echo '<p class="muted">' . e(t('admin.integrity.last_checked_value', 'Last checked: {time}', ['time' => $checkedAt])) . '</p>';
    }
    echo '<p><a class="button secondary" href="' . e((string) ($viewModel['details_url'] ?? '')) . '">' . e(t('admin.integrity.show_details', 'Show details')) . '</a></p></section>';
}

/** @param array<int,string> $paths Integrity path list. */
function view_render_admin_integrity_path_list(string $title, array $paths): void
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

/** @param array<string,mixed> $viewModel Controller-prepared full-page state. */
function view_render_admin_integrity_page(array $viewModel): void
{
    $status = (array) ($viewModel['status'] ?? []);
    render_header(t('admin.integrity.title', 'System integrity'));
    echo '<section class="hero"><h1>' . e(t('admin.integrity.title', 'System integrity')) . '</h1><nav class="nav"><a class="button secondary" href="' . e((string) ($viewModel['dashboard_url'] ?? '')) . '">' . e(t('admin.common.back_to_dashboard', 'Back to dashboard')) . '</a>';
    echo '<form method="post" action="' . e((string) ($viewModel['check_url'] ?? '')) . '" class="inline-action-form">' . (string) ($viewModel['csrf_html'] ?? '') . '<button type="submit">' . e(t('admin.integrity.check_now', 'Check now')) . '</button></form></nav></section>';
    if (!empty($viewModel['checked'])) {
        echo '<div class="notice">' . e(t('admin.integrity.completed', 'Integrity check completed.')) . '</div>';
    }
    echo '<section class="panel"><h2>' . e(t('admin.integrity.status_value', 'Status: {status}', ['status' => (string) ($viewModel['status_label'] ?? '')])) . '</h2>';
    echo '<p><strong>' . e(t('admin.integrity.manifest_version', 'Manifest version')) . ':</strong> ' . e((string) ($status['version'] ?? '')) . '</p><p><strong>' . e(t('admin.integrity.last_checked', 'Last checked')) . ':</strong> ' . e((string) ($status['checked_at_iso'] ?? '')) . '</p>';
    if (!empty($status['manifest_error'])) {
        echo '<p class="notice">' . e((string) $status['manifest_error']) . '</p>';
    }
    view_render_admin_integrity_path_list(t('admin.integrity.modified_core_files', 'Modified core files'), (array) ($status['modified'] ?? []));
    view_render_admin_integrity_path_list(t('admin.integrity.missing_core_files', 'Missing core files'), (array) ($status['missing'] ?? []));
    view_render_admin_integrity_path_list(t('admin.integrity.unknown_core_files', 'Unknown core-like files'), (array) ($status['unknown'] ?? []));
    echo '<p class="muted">' . e(t('admin.integrity.ignored_folders', 'Ignored folders include cache, galleries, custom CSS, local config, and common hosting/runtime files.')) . '</p></section>';
    render_footer();
}
