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

namespace Gallery\Controllers;

require_once dirname(__DIR__) . '/services/cache_cleanup_diagnostics.php';

use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\format_bytes;
use function Gallery\Core\cms_runtime_kernel;
use function Gallery\Services\set_site_maintenance_settings;
use function Gallery\Services\site_maintenance_manual_time_budget_seconds;
use function Gallery\Services\site_maintenance_reset_state;
use function Gallery\Services\site_maintenance_rotate_token;
use function Gallery\Services\site_maintenance_run;
use function Gallery\Services\site_maintenance_time_budget_seconds;
use function Gallery\Services\site_maintenance_token_is_valid;
use function Gallery\Services\site_maintenance_window_hours_to_minutes;
use function Gallery\Services\t;
use function Gallery\Services\cache_cleanup_diagnostic_id;
use function Gallery\Services\cache_cleanup_diagnostic_event;
use function Gallery\Services\cache_cleanup_diagnostic_buffer;

/**
 * Process token-protected site maintenance or one independent cache-only slice.
 * @return void Writes the safe cron JSON result or a forbidden response.
 */
function cms_site_maintenance_cron(): void
{
    $token = trim((string) ($_SERVER['HTTP_X_SITE_MAINTENANCE_TOKEN'] ?? $_POST['token'] ?? $_GET['token'] ?? ''));
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (!site_maintenance_token_is_valid($token)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => t('site_maintenance.invalid_token', 'Invalid maintenance token.') ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    ignore_user_abort(true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $force = (string) ($_GET['force'] ?? $_POST['force'] ?? '') === '1';
    $cacheOnly = (string) ($_GET['cache_only'] ?? $_POST['cache_only'] ?? '') === '1';
    $chain = !$cacheOnly && (string) ($_GET['chain'] ?? $_POST['chain'] ?? '1') !== '0';
    $source = preg_replace('/[^a-z0-9_]/i', '', (string) ($_GET['source'] ?? $_POST['source'] ?? 'web_cron')) ?: 'web_cron';
    $timeBudget = (int) ($_GET['time_budget'] ?? $_POST['time_budget'] ?? site_maintenance_time_budget_seconds());
    $result = site_maintenance_run([
        'source' => $source,
        'force' => $force,
        'cache_only' => $cacheOnly,
        'time_budget_seconds' => $timeBudget,
        'chain' => $chain,
    ]);

    if (empty($result['ok'])) {
        http_response_code(500);
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Save authorized maintenance settings or dispatch a manual site/cache slice.
 * @return void Emits in-place JSON for manual cache cleanup or redirects the classic settings/maintenance POST fallback.
 */
function cms_admin_site_maintenance_settings(): void
{
    require_admin();
    verify_csrf();

    $action = (string) ($_POST['site_maintenance_action'] ?? 'save');

    if (in_array($action, ['cache_inventory_start','cache_inventory_step','cache_cleanup_start','cache_cleanup_step'], true)) {
        cms_admin_site_cache_lifecycle($action);
        return;
    }

    if ($action === 'cache_job_stop') {
        cms_admin_site_cache_job_stop();
        return;
    }

    if ($action === 'cache_diagnostic') {
        $runId = cache_cleanup_diagnostic_id((string) ($_POST['cache_run_id'] ?? ''));
        $code = (string) ($_POST['diagnostic_code'] ?? 'network_error');
        $allowed = ['request_timeout', 'network_error', 'invalid_json', 'http_error', 'completion_error'];
        $code = in_array($code, $allowed, true) ? $code : 'network_error';
        cache_cleanup_diagnostic_event($runId, 'browser_failed', ['failure_code' => $code,
            'http_status' => max(0, min(599, (int) ($_POST['http_status'] ?? 0)))]);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(['ok' => true, 'diagnostic_id' => $runId]);
        return;
    }

    if ($action === 'cache_cleanup') {
        cms_admin_site_cache_cleanup();
        return;
    }

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

/**
 * Dispatch one authenticated lifecycle preview/approval slice and preserve the canonical mutation envelope.
 * @param string $action Validated lifecycle HTTP action from the settings dispatcher.
 * @return void Emits in-place JSON with progress and correlated diagnostics, or the ordinary non-JavaScript fallback.
 */
function cms_admin_site_cache_lifecycle(string $action): void
{
    require_admin();
    verify_csrf();
    $runId = cache_cleanup_diagnostic_id((string) ($_POST['cache_run_id'] ?? ''));
    $started = microtime(true);
    $finished = false;
    register_shutdown_function(static function () use ($runId, &$finished): void {
        $error = error_get_last();
        if (!$finished && is_array($error) && in_array($error['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
            cache_cleanup_diagnostic_event($runId, 'fatal', ['reason'=>'cache_lifecycle','failure_code'=>'fatal_shutdown',
                'error_type'=>(int) $error['type'],'exception_line'=>(int) ($error['line'] ?? 0)]);
        }
    });
    $returnUrl = url_for('admin', ['dashboard_tab'=>'maintenance','maintenance_tab'=>'media']) . '#admin-tab-maintenance';
    $mutation = admin_mutation_descriptor('cache.lifecycle', 'cache', 'maintenance');
    $fallback = ['redirect_url'=>$returnUrl];
    cache_cleanup_diagnostic_event($runId, 'lifecycle_requested', ['source'=>'admin_manual','operation'=>$action]);
    try {
        cms_runtime_kernel()->load('site-maintenance-work');
        $result = \Gallery\Services\site_maintenance_cache_lifecycle_action(substr($action, 6), (string) ($_POST['preview_id'] ?? ''));
        $status = \Gallery\Services\cache_lifecycle_status();
        $ok = !empty($result['ok']);
        $message = $ok ? t('admin.cache_lifecycle.progress', 'Cache {phase}: observed {bytes}; eligible {eligible}; reclaimed {reclaimed}.',
            ['phase'=>$status['phase'],'bytes'=>format_bytes($status['bytes']),'eligible'=>format_bytes($status['pending_bytes']),'reclaimed'=>format_bytes($status['bytes_removed'])])
            : t('admin.cache_lifecycle.refused', 'Cache action stopped safely: {reason}.', ['reason'=>(string) ($result['reason'] ?? 'maintenance_busy')]);
        $payload = $ok ? admin_mutation_success_envelope($message, $mutation, null, [], $fallback)
            : admin_mutation_error_envelope($message, 'cache_lifecycle_refused', $mutation, $fallback);
        $payload['cache_lifecycle'] = $status;
        cache_cleanup_diagnostic_event($runId, $ok ? 'lifecycle_checkpoint' : 'skipped',
            ['ok'=>$ok,'phase'=>$status['phase'],'files'=>$status['files'],'bytes'=>$status['bytes'],
                'candidates'=>$status['candidates'],'candidate_bytes'=>$status['candidate_bytes'],
                'removed'=>$status['removed'],'bytes_removed'=>$status['bytes_removed'],'errors'=>$status['errors'],
                'blocked'=>$status['blocked'],'skipped'=>$status['skipped'],'failure_code'=>(string) ($result['last_error'] ?? ''),
                'reason'=>!empty($result['reason']) ? (string) $result['reason'] : (string) ($result['last_skip_reason'] ?? ''),
                'elapsed_total_ms'=>(microtime(true)-$started)*1000]);
    } catch (\Throwable $exception) {
        cache_cleanup_diagnostic_event($runId, 'lifecycle_failed', [], $exception);
        $message = t('admin.cache_lifecycle.failed', 'Cache action failed safely. Copy diagnostics or check the Admin log.');
        $payload = admin_mutation_error_envelope($message, 'cache_lifecycle_failed', $mutation, $fallback);
        $ok = false;
    }
    $payload['diagnostic_id'] = $runId;
    $payload['cache_diagnostics'] = cache_cleanup_diagnostic_buffer($runId);
    $finished = true;
    if (admin_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        http_response_code($ok ? 200 : 409);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }
    flash_message('admin_notice', $message);
    redirect_to($returnUrl);
}

/**
 * Execute an authenticated, CSRF-checked manual updater-cache slice in place.
 * Repeat the settings route's Admin/CSRF boundary before the isolated action.
 * @return void Writes the canonical JSON envelope or redirects only for non-JavaScript POSTs.
 */
function cms_admin_site_cache_cleanup(): void
{
    require_admin();
    verify_csrf();
    $runId = cache_cleanup_diagnostic_id((string) ($_POST['cache_run_id'] ?? ''));
    $startedAt = microtime(true);
    $phase = 'admission';
    $finished = false;
    cache_cleanup_diagnostic_event($runId, 'request_received', ['source' => 'admin_manual',
        'forced' => true, 'request_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'unknown')]);
    register_shutdown_function(static function () use ($runId, &$finished, &$phase): void {
        $error = error_get_last();
        if (!$finished && is_array($error) && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            cache_cleanup_diagnostic_event($runId, 'fatal', ['reason' => $phase,
                'failure_code' => 'fatal_shutdown', 'error_type' => (int) $error['type'],
                'exception_line' => (int) ($error['line'] ?? 0)]);
        }
    });
    $returnUrl = url_for('admin', ['dashboard_tab' => 'maintenance', 'maintenance_tab' => 'media']) . '#admin-tab-maintenance';
    $mutation = admin_mutation_descriptor('cache.cleanup', 'cache', 'cleanup');
    $fallback = ['redirect_url' => $returnUrl];
    try {
        $phase = 'runtime_loading';
        cache_cleanup_diagnostic_event($runId, 'runtime_loading');
        // Load the existing reviewed worker closure only for this explicit action.
        cms_runtime_kernel()->load('updater-work');
        cms_runtime_kernel()->load('site-maintenance-work');
        $phase = 'worker';
        cache_cleanup_diagnostic_event($runId, 'worker_started', ['forced' => true]);
        $result = site_maintenance_run(['source' => 'admin_cache_manual', 'force' => true,
            'cache_only' => true, 'time_budget_seconds' => 1, 'cache_run_id' => $runId]);
        $slice = (array) ($result['cache_gc']['value'] ?? []);
        $busy = !empty($result['busy']) || (!empty($slice['skipped']) && empty($slice['done']));
        $ok = !empty($result['ok']) && !$busy;
        $done = !empty($slice['done']);
        $files = max(0, (int) ($slice['removed'] ?? 0));
        $bytes = max(0, (int) ($slice['bytes_removed'] ?? 0));
        cache_cleanup_diagnostic_event($runId, $busy ? 'skipped' : ($ok ? 'completed' : 'failed'),
            ['ok' => $ok, 'done' => $done, 'blocked' => !empty($slice['blocked']),
                'reason' => (string) ($slice['reason'] ?? $result['reason'] ?? ''),
                'removed' => $files, 'bytes_removed' => $bytes,
                'visited' => (int) ($slice['visited'] ?? 0),
                'elapsed_total_ms' => (microtime(true) - $startedAt) * 1000,
                'failure_code' => (string) ($slice['failure_code'] ?? ''),
                'failure_operation' => (string) ($slice['failure_operation'] ?? ''),
                'exception_class' => (string) ($result['cache_gc']['diagnostic']['exception_class'] ?? ''),
                'central_job_id' => (int) ($result['central_job_id'] ?? 0),
                'central_phase' => (string) ($result['central_phase'] ?? '')]);
        $message = $busy
            ? t('admin.cache_cleanup.busy', 'Cleanup is waiting for active maintenance or an update. Safety locks remain in effect.')
            : (!$ok ? t('admin.cache_cleanup.failed', 'Cleanup stopped safely. Check maintenance diagnostics before retrying.')
                : t($done ? 'admin.cache_cleanup.done' : 'admin.cache_cleanup.progress',
                    $done ? 'Updater trash is empty. This step removed {files} file(s) and reclaimed {bytes}.'
                        : 'Removed {files} file(s) and reclaimed {bytes}. More updater trash remains; continue or let cron finish.',
                    ['files' => $files, 'bytes' => format_bytes($bytes)]));
        if ($busy) {
            $message .= ' [' . (string) ($slice['reason'] ?? $result['reason'] ?? 'owner_busy') . ']';
            if (!empty($result['central_job_id'])) {
                $message .= ' #' . (int) $result['central_job_id'];
            }
        }
        $payload = $ok ? admin_mutation_success_envelope($message, $mutation, null, [], $fallback)
            : admin_mutation_error_envelope($message, $busy ? 'cache_cleanup_busy' : 'cache_cleanup_blocked', $mutation, $fallback);
        $payload['cache_cleanup'] = ['done' => $done, 'can_continue' => $ok && !$done,
            'removed' => $files, 'bytes_removed' => $bytes];
    } catch (\Throwable $exception) {
        cache_cleanup_diagnostic_event($runId, 'failed', ['reason' => $phase,
            'elapsed_total_ms' => (microtime(true) - $startedAt) * 1000], $exception);
        $message = t('admin.cache_cleanup.failed', 'Cleanup stopped safely. Check maintenance diagnostics before retrying.');
        $payload = admin_mutation_error_envelope($message, 'cache_cleanup_failed', $mutation, $fallback);
        $ok = false;
        $busy = false;
    }
    $payload['diagnostic_id'] = $runId;
    cache_cleanup_diagnostic_event($runId, 'response_ready', ['http_status' => $ok ? 200 : ($busy ? 409 : 500)]);
    $payload['cache_diagnostics'] = cache_cleanup_diagnostic_buffer($runId);
    $finished = true;
    if (admin_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        http_response_code($ok ? 200 : ($busy ? 409 : 500));
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    flash_message('admin_notice', $message);
    redirect_to($returnUrl);
}

/**
 * Cancel an explicitly selected blocking updater through its existing locked owner.
 * @return void Emits a canonical in-place mutation result or the direct-page fallback.
 */
function cms_admin_site_cache_job_stop(): void
{
    require_admin();
    verify_csrf();
    $runId = cache_cleanup_diagnostic_id((string) ($_POST['cache_run_id'] ?? ''));
    $returnUrl = url_for('admin', ['dashboard_tab' => 'maintenance', 'maintenance_tab' => 'media']) . '#admin-tab-maintenance';
    $mutation = admin_mutation_descriptor('update.cancel', 'update', 'cancel');
    $fallback = ['redirect_url' => $returnUrl];
    $ok = false;
    cache_cleanup_diagnostic_event($runId, 'stop_requested', ['source' => 'admin_manual']);
    try {
        cms_runtime_kernel()->load('updater-work');
        $jobId = (string) ($_POST['job_id'] ?? '');
        $job = \Gallery\Services\application_update_job_public_state(\Gallery\Services\application_update_load_job($jobId));
        if (empty($job['can_cancel'])) {
            throw new \RuntimeException('Update cannot be cancelled after activation has begun. Resume or rollback instead.');
        }
        $job = \Gallery\Services\application_update_cancel_job($jobId);
        $ok = (string) ($job['status'] ?? '') === 'cancelled';
        $message = $ok ? t('admin.cache_cleanup.stopped', 'Update stopped. You can run cache cleanup again.')
            : t('admin.cache_cleanup.stop_busy', 'The update worker is busy. It has not been stopped; retry after its current step.');
        cache_cleanup_diagnostic_event($runId, $ok ? 'stop_completed' : 'skipped',
            ['reason' => $ok ? 'cancelled_before_activation' : 'updater_worker_busy', 'updater_job_id' => $jobId,
                'updater_status' => (string) $job['status'], 'updater_stage' => (string) $job['stage']]);
        $payload = $ok ? admin_mutation_success_envelope($message, $mutation, null, [], $fallback)
            : admin_mutation_error_envelope($message, 'updater_worker_busy', $mutation, $fallback);
    } catch (\Throwable $exception) {
        cache_cleanup_diagnostic_event($runId, 'stop_failed', [], $exception);
        $message = t('admin.cache_cleanup.stop_failed', 'Update could not be stopped safely. Check diagnostics and Admin Updates.');
        $payload = admin_mutation_error_envelope($message, 'update_cancel_refused', $mutation, $fallback);
    }
    $payload['diagnostic_id'] = $runId;
    $payload['cache_diagnostics'] = cache_cleanup_diagnostic_buffer($runId);
    if (admin_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        http_response_code($ok ? 200 : 409);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }
    flash_message('admin_notice', $message);
    redirect_to($returnUrl);
}
