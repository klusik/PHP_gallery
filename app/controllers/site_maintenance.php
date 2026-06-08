<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/site_maintenance.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles cron-safe site maintenance requests and Admin settings updates.
 *
 * Responsibilities:
 *   - Protect the public maintenance endpoint with a dedicated token
 *   - Return JSON for CLI, curl, and hosting cron integrations
 *   - Save Admin maintenance schedule settings with CSRF validation
 *   - Delegate all long-running work to the resumable service layer
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
 *   2026-06-08
 */

declare(strict_types=1);

/**
 * Process a token-protected site maintenance cron request.
 */
function cms_site_maintenance_cron(): void
{
    $token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ($_SERVER['HTTP_X_SITE_MAINTENANCE_TOKEN'] ?? '')));
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (!site_maintenance_token_is_valid($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid maintenance token.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $force = (string) ($_GET['force'] ?? $_POST['force'] ?? '') === '1';
    $chain = (string) ($_GET['chain'] ?? $_POST['chain'] ?? '1') !== '0';
    $source = preg_replace('/[^a-z0-9_]/i', '', (string) ($_GET['source'] ?? $_POST['source'] ?? 'web_cron')) ?: 'web_cron';
    $timeBudget = (int) ($_GET['time_budget'] ?? $_POST['time_budget'] ?? site_maintenance_time_budget_seconds());
    $result = site_maintenance_run([
        'source' => $source,
        'force' => $force,
        'time_budget_seconds' => $timeBudget,
        'chain' => $chain,
    ]);

    if (empty($result['ok'])) {
        http_response_code(500);
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Save Admin site-maintenance settings or run one manual maintenance invocation.
 */
function cms_admin_site_maintenance_settings(): void
{
    require_admin();
    verify_csrf();

    $action = (string) ($_POST['site_maintenance_action'] ?? 'save');

    if ($action === 'rotate_token') {
        site_maintenance_rotate_token();
        flash_message('admin_notice', t('admin.site_maintenance.token_rotated', 'Site maintenance cron token was rotated. Update any external cron URL that used the old token.'));
        redirect_to(url_for('admin') . '#admin-tab-maintenance');
    }

    if ($action === 'reset_state') {
        site_maintenance_reset_state();
        flash_message('admin_notice', t('admin.site_maintenance.state_reset', 'Interrupted site maintenance state was reset. The next cron call can start a fresh cycle when due.'));
        redirect_to(url_for('admin') . '#admin-tab-maintenance');
    }

    if ($action === 'run_now') {
        $result = site_maintenance_run([
            'source' => 'admin_manual',
            'force' => true,
            'time_budget_seconds' => site_maintenance_manual_time_budget_seconds(),
        ]);
        if (!empty($result['ok'])) {
            $message = !empty($result['done'])
                ? t('admin.site_maintenance.manual_done', 'Manual maintenance invocation completed the current cycle.')
                : t('admin.site_maintenance.manual_progress', 'Manual maintenance invocation processed one bounded chunk. Run again or let automatic maintenance continue it.');
            flash_message('admin_notice', $message);
        } else {
            flash_message('admin_notice', t('admin.site_maintenance.manual_failed', 'Manual maintenance invocation failed. Check the admin log for details.'));
        }
        redirect_to(url_for('admin') . '#admin-tab-maintenance');
    }

    set_site_maintenance_settings(
        !empty($_POST['site_maintenance_enabled']),
        (string) ($_POST['site_maintenance_utc_time'] ?? '00:00'),
        (int) ($_POST['site_maintenance_batch_size'] ?? 20),
        (int) ($_POST['site_maintenance_time_budget_seconds'] ?? 20),
        !empty($_POST['site_maintenance_request_trigger_enabled']),
        site_maintenance_window_hours_to_minutes((string) ($_POST['site_maintenance_window_hours'] ?? '3'))
    );

    flash_message('admin_notice', t('admin.site_maintenance.settings_saved', 'Site maintenance settings saved.'));
    redirect_to(url_for('admin') . '#admin-tab-maintenance');
}
