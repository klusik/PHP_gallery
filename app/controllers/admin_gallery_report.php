<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_gallery_report.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles Admin complete gallery overview report requests.
 *
 * Responsibilities:
 *   - Render the report generator maintenance page
 *   - Validate Admin access and CSRF tokens for report generation
 *   - Process browser-driven report batches and return the finished HTML only in the Ajax response
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
 *   2026-06-15
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use const Gallery\Services\ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE;
use const Gallery\Services\ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE;
use function Gallery\Core\flash_message;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_gallery_report_process_job;
use function Gallery\Services\admin_gallery_report_start_job;
use function Gallery\Services\admin_log_event;
use function Gallery\Views\view_render_admin_gallery_report_page;

/**
 * Render the complete gallery overview report generator page.
 */
function cms_admin_gallery_report(): void
{
    require_admin();
    view_render_admin_gallery_report_page((string) flash_message('admin_notice'));
}

/**
 * Process browser-driven complete gallery overview report generation requests.
 */
function cms_admin_gallery_report_generate(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }

    $bufferLevel = ob_get_level();
    ob_start();
    try {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'step');
        if ($action === 'start') {
            $telemetryDays = max(1, min(3650, (int) ($_POST['telemetry_days'] ?? 30)));
            $state = admin_gallery_report_start_job($telemetryDays);
        } else {
            $batchSize = max(1, min(ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE, (int) ($_POST['batch_size'] ?? ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE)));
            $state = admin_gallery_report_process_job($batchSize);
        }

        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        admin_gallery_report_json_response($state);
    } catch (Throwable $exception) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        admin_log_event('error', 'admin_report.generate_failed', 'Admin complete gallery overview report generation failed.', [
            'exception' => $exception->getMessage(),
        ], ['category' => 'admin', 'severity' => 'error', 'route_name' => 'admin_gallery_report_generate']);
        admin_gallery_report_json_response([
            'ok' => false,
            'status' => 'error',
            'error' => $exception->getMessage(),
        ]);
    }
}

/**
 * Emit a JSON response for the complete gallery overview report endpoint.
 *
 * @param array $payload Payload value.
 */
function admin_gallery_report_json_response(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
