<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_search_diagnostics.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles the Admin progressive public-search diagnostics workflow.
 *
 * Responsibilities:
 *   - Require administrator authentication and CSRF protection for diagnostic execution
 *   - Validate the query/repeat controls and invoke the diagnostics service
 *   - Keep the latest generated JSON report in the current Admin session for rendering/download
 *   - Delegate HTML presentation to the dedicated view module
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
 *   - Never expose database credentials or raw database exceptions through the report/download boundary.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\public_search_diagnostics_encode_report;
use function Gallery\Services\public_search_diagnostics_normalize_runs;
use function Gallery\Services\public_search_diagnostics_run;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_search_diagnostics_page;

const ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY = 'admin_search_diagnostics_last_report';

/**
 * Handle the Admin search-diagnostics page and report download.
 */
function cms_admin_search_diagnostics(): void
{
    require_admin();

    if (isset($_GET['download'])) {
        admin_search_diagnostics_download_latest();
    }

    if (request_method() === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'run');
        if ($action === 'clear') {
            unset($_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY]);
            flash_message('admin_notice', t('admin.search_diagnostics.cleared_notice', 'Search diagnostic report cleared.'));
            redirect_to(url_for('admin_search_diagnostics'));
        }

        $query = trim((string) ($_POST['query'] ?? ''));
        $runs = public_search_diagnostics_normalize_runs((int) ($_POST['runs'] ?? 3));
        $includeExplain = isset($_POST['include_explain']) && (string) $_POST['include_explain'] === '1';

        try {
            $report = public_search_diagnostics_run($query, $runs, $includeExplain);
            $json = public_search_diagnostics_encode_report($report);
            $_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY] = [
                'json' => $json,
                'query' => $query,
                'runs' => $runs,
                'include_explain' => $includeExplain,
                'generated_at' => time(),
            ];
            admin_log_event('info', 'search_diagnostics.completed', 'Admin completed progressive public-search diagnostics.', [
                'query_length' => function_exists('mb_strlen') ? mb_strlen($query) : strlen($query),
                'runs' => $runs,
                'include_explain' => $includeExplain,
                'diagnostic_elapsed_ms' => (float) ($report['diagnostic_elapsed_ms'] ?? 0.0),
            ], ['category' => 'admin', 'severity' => 'notice', 'route_name' => 'admin_search_diagnostics']);
            flash_message('admin_notice', t('admin.search_diagnostics.completed_notice', 'Search diagnostics completed. The JSON report is ready to copy or download.'));
        } catch (Throwable $exception) {
            admin_log_event('error', 'search_diagnostics.failed', 'Admin progressive public-search diagnostics failed.', [
                'query_length' => function_exists('mb_strlen') ? mb_strlen($query) : strlen($query),
                'runs' => $runs,
                'include_explain' => $includeExplain,
                'exception_class' => get_class($exception),
            ], ['category' => 'admin', 'severity' => 'error', 'route_name' => 'admin_search_diagnostics']);
            flash_message('admin_error', t('admin.search_diagnostics.failed_notice', 'Search diagnostics failed. Review Admin Logs for the bounded error event.'));
            $_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY . '_form'] = [
                'query' => $query,
                'runs' => $runs,
                'include_explain' => $includeExplain,
            ];
        }

        redirect_to(url_for('admin_search_diagnostics'));
    }

    $stored = is_array($_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY] ?? null)
        ? $_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY]
        : [];
    $form = is_array($_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY . '_form'] ?? null)
        ? $_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY . '_form']
        : [];
    unset($_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY . '_form']);

    $reportJson = (string) ($stored['json'] ?? '');
    $report = null;
    if ($reportJson !== '') {
        $decoded = json_decode($reportJson, true);
        if (is_array($decoded)) {
            $report = $decoded;
        }
    }
    $query = (string) ($form['query'] ?? ($stored['query'] ?? '320'));
    $runs = public_search_diagnostics_normalize_runs((int) ($form['runs'] ?? ($stored['runs'] ?? 3)));
    $includeExplain = array_key_exists('include_explain', $form)
        ? !empty($form['include_explain'])
        : (!array_key_exists('include_explain', $stored) || !empty($stored['include_explain']));

    view_render_admin_search_diagnostics_page(
        $report,
        $reportJson,
        $query,
        $runs,
        $includeExplain,
        (string) flash_message('admin_notice'),
        (string) flash_message('admin_error')
    );
}

/**
 * Download the latest session-owned diagnostic JSON report.
 */
function admin_search_diagnostics_download_latest(): never
{
    $stored = is_array($_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY] ?? null)
        ? $_SESSION[ADMIN_SEARCH_DIAGNOSTICS_SESSION_KEY]
        : [];
    $json = (string) ($stored['json'] ?? '');
    if ($json === '') {
        flash_message('admin_error', t('admin.search_diagnostics.no_report_notice', 'No search diagnostic report is available for download.'));
        redirect_to(url_for('admin_search_diagnostics'));
    }

    $timestamp = (int) ($stored['generated_at'] ?? time());
    $filename = 'php-gallery-search-diagnostics-' . date('Ymd-His', $timestamp) . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
}
