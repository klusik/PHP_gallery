<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_maintenance_center.php
 * Module Type: Controller
 *
 * Purpose:
 *   Owns the Admin HTTP boundary for the central Maintenance Center.
 *
 * Responsibilities:
 *   - Require administrator authentication for every page and JSON endpoint
 *   - Enforce POST + CSRF for all state-changing operations
 *   - Normalize approved task/options input and call service orchestration
 *   - Return bounded JSON errors without raw exception traces or request payloads
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
 *   - SQL and maintenance policy remain outside this controller.
 *
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\csrf_token;
use function Gallery\Core\current_user;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\maintenance_center_analysis_step;
use function Gallery\Services\maintenance_center_cancel;
use function Gallery\Services\maintenance_center_execution_step;
use function Gallery\Services\maintenance_center_job_status;
use function Gallery\Services\maintenance_center_page_model;
use function Gallery\Services\maintenance_center_pause;
use function Gallery\Services\maintenance_center_start_analysis;
use function Gallery\Services\maintenance_center_start_execution;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_maintenance_center_page;

/** Return the current administrator id after the shared auth guard. */
function admin_maintenance_center_actor_id(): int
{
    $user = current_user();
    $actorId = is_array($user) ? max(0, (int) ($user['id'] ?? 0)) : 0;
    if ($actorId <= 0) {
        throw new \RuntimeException('Authenticated administrator identity is unavailable.');
    }
    return $actorId;
}

/** Require POST and CSRF for one Maintenance Center mutation endpoint. */
function admin_maintenance_center_require_post(): void
{
    if (request_method() !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        admin_maintenance_center_json_response(['ok' => false, 'error_code' => 'request.method_not_allowed', 'error' => t('admin.maintenance_center.error.method', 'POST is required.')], 405);
    }
    verify_csrf();
}

/** Emit one no-store Admin JSON response. */
function admin_maintenance_center_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, private, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Map bounded domain failures to stable browser-facing error codes. */
function admin_maintenance_center_error_payload(Throwable $exception): array
{
    $message = $exception->getMessage();
    if (str_contains($message, 'stale')) {
        return ['error_code' => 'maintenance_center.stale_plan', 'error' => t('admin.maintenance_center.error.stale', 'The analyzed plan is stale. Analyze again before running maintenance.'), 'status' => 409];
    }
    if (str_contains($message, 'mutation lock') || str_contains($message, 'already running') || str_contains($message, 'existing maintenance job')) {
        return ['error_code' => 'maintenance_center.busy', 'error' => t('admin.maintenance_center.error.busy', 'Another central maintenance job is active. Resume or cancel the existing job first.'), 'status' => 409];
    }
    if (str_contains($message, 'not installed') || str_contains($message, 'migrations')) {
        return ['error_code' => 'maintenance_center.schema_unavailable', 'error' => t('admin.maintenance_center.error.schema', 'Maintenance Center storage is not available. Apply pending database migrations first.'), 'status' => 409];
    }
    if (str_contains($message, 'not found')) {
        return ['error_code' => 'maintenance_center.not_found', 'error' => t('admin.maintenance_center.error.not_found', 'Maintenance job was not found.'), 'status' => 404];
    }
    return ['error_code' => 'maintenance_center.request_failed', 'error' => t('admin.maintenance_center.error.generic', 'The maintenance request could not be completed safely.'), 'status' => 409];
}

/** Execute one service callback and normalize its JSON success/failure envelope. */
function admin_maintenance_center_json_action(callable $callback, ?string $mutationAction = null, int $jobId = 0): never
{
    try {
        $job = $callback();
        if ($mutationAction === null) {
            admin_maintenance_center_json_response(['ok' => true, 'job' => $job]);
        }
        $resolvedJobId = max($jobId, (int) ($job['id'] ?? 0));
        $payload = admin_mutation_success_envelope(
            t('admin.maintenance_center.response.saved', 'Maintenance checkpoint saved.'),
            admin_mutation_descriptor('maintenance_center.' . $mutationAction, 'maintenance_job', $mutationAction, $resolvedJobId > 0 ? [$resolvedJobId] : [])
        );
        admin_maintenance_center_json_response(array_merge($payload, ['job' => $job]));
    } catch (Throwable $exception) {
        $error = admin_maintenance_center_error_payload($exception);
        if ($mutationAction === null) {
            admin_maintenance_center_json_response([
                'ok' => false,
                'error_code' => $error['error_code'],
                'error' => $error['error'],
            ], (int) $error['status']);
        }
        $payload = admin_mutation_error_envelope(
            (string) $error['error'],
            (string) $error['error_code'],
            admin_mutation_descriptor('maintenance_center.' . $mutationAction, 'maintenance_job', $mutationAction, $jobId > 0 ? [$jobId] : [])
        );
        admin_maintenance_center_json_response($payload, (int) $error['status']);
    }
}

/** Render the dedicated Maintenance Center page. */
function cms_admin_maintenance_center(): void
{
    require_admin();
    $actorId = admin_maintenance_center_actor_id();
    $model = maintenance_center_page_model($actorId);
    $model['csrf_token'] = csrf_token();
    $model['dashboard_url'] = url_for('admin');
    $model['endpoints'] = [
        'status' => url_for('admin_maintenance_center_status'),
        'analyze_start' => url_for('admin_maintenance_center_analyze_start'),
        'analyze_step' => url_for('admin_maintenance_center_analyze_step'),
        'execute_start' => url_for('admin_maintenance_center_execute_start'),
        'execute_step' => url_for('admin_maintenance_center_execute_step'),
        'pause' => url_for('admin_maintenance_center_pause'),
        'cancel' => url_for('admin_maintenance_center_cancel'),
    ];
    view_render_admin_maintenance_center_page($model);
}

/** Return persisted job status for reload/resume polling. */
function cms_admin_maintenance_center_status(): void
{
    require_admin();
    $actorId = admin_maintenance_center_actor_id();
    $jobId = max(0, (int) ($_GET['job_id'] ?? 0));
    admin_maintenance_center_json_action(static fn (): array => maintenance_center_job_status($jobId, $actorId));
}

/** Start a new read-only analysis. */
function cms_admin_maintenance_center_analyze_start(): void
{
    require_admin();
    admin_maintenance_center_require_post();
    $actorId = admin_maintenance_center_actor_id();
    admin_maintenance_center_json_action(static fn (): array => maintenance_center_start_analysis($actorId), 'analyze_start');
}

/** Execute exactly one read-only analysis task. */
function cms_admin_maintenance_center_analyze_step(): void
{
    require_admin();
    admin_maintenance_center_require_post();
    $actorId = admin_maintenance_center_actor_id();
    $jobId = max(0, (int) ($_POST['job_id'] ?? 0));
    admin_maintenance_center_json_action(static fn (): array => maintenance_center_analysis_step($jobId, $actorId), 'analyze_step', $jobId);
}

/** Start or resume approved plan execution. */
function cms_admin_maintenance_center_execute_start(): void
{
    require_admin();
    admin_maintenance_center_require_post();
    $actorId = admin_maintenance_center_actor_id();
    $jobId = max(0, (int) ($_POST['job_id'] ?? 0));
    $tasks = array_values(array_filter(array_map('strval', (array) ($_POST['tasks'] ?? [])), static fn (string $key): bool => preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $key) === 1));
    $options = ['full_physical_optimization' => !empty($_POST['full_physical_optimization'])];
    admin_maintenance_center_json_action(static fn (): array => maintenance_center_start_execution($jobId, $actorId, $tasks, $options), 'execute_start', $jobId);
}

/** Execute one bounded maintenance slice. */
function cms_admin_maintenance_center_execute_step(): void
{
    require_admin();
    admin_maintenance_center_require_post();
    $actorId = admin_maintenance_center_actor_id();
    $jobId = max(0, (int) ($_POST['job_id'] ?? 0));
    admin_maintenance_center_json_action(static fn (): array => maintenance_center_execution_step($jobId, $actorId), 'execute_step', $jobId);
}

/** Pause between operations while retaining the central job claim. */
function cms_admin_maintenance_center_pause(): void
{
    require_admin();
    admin_maintenance_center_require_post();
    $actorId = admin_maintenance_center_actor_id();
    $jobId = max(0, (int) ($_POST['job_id'] ?? 0));
    admin_maintenance_center_json_action(static fn (): array => maintenance_center_pause($jobId, $actorId), 'pause', $jobId);
}

/** Request cooperative cancellation. */
function cms_admin_maintenance_center_cancel(): void
{
    require_admin();
    admin_maintenance_center_require_post();
    $actorId = admin_maintenance_center_actor_id();
    $jobId = max(0, (int) ($_POST['job_id'] ?? 0));
    admin_maintenance_center_json_action(static fn (): array => maintenance_center_cancel($jobId, $actorId), 'cancel', $jobId);
}
