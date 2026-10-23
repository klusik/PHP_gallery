<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_cron.php
 * Module Type: Controller
 * Purpose: Own Cron Overview authentication, CSRF, HTTP and prepared view models.
 * Responsibilities: Keep passive status separate from explicit scheduler admission.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Controllers;

use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\csrf_token;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Core\cms_runtime_kernel;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Services\request_scheduler_snapshot;
use function Gallery\Services\request_scheduler_tick;
use function Gallery\Services\request_scheduler_host_token;
use function Gallery\Services\request_scheduler_host_url;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_cron;

/**
 * Render familiar duration units without locale-dependent calendar arithmetic.
 * Type: array<int,string>. Units: seconds per day/hour/minute/second.
 * Scope: next-eligible display only. Consumers: admin_cron_relative_label.
 * Rationale: fixed 86400/3600/60/1 conversions describe elapsed time to an epoch;
 * the owner's absolute timestamp and scheduling policy remain authoritative.
 */
const ADMIN_CRON_DURATION_UNITS = [86400 => 'd', 3600 => 'h', 60 => 'min', 1 => 's'];

/**
 * Keep countdowns readable beside the complete absolute timestamp.
 * Type: int. Units: duration components. Scope: Admin countdown presentation.
 * Consumers: admin_cron_relative_label.
 * Rationale: two components retain useful precision without widening every row.
 */
const ADMIN_CRON_DURATION_PARTS = 2;

/**
 * Describe time remaining until a known future eligibility threshold.
 * @param int|null $nextAt Authoritative Unix threshold, or null for unknown.
 * @param int $now Current display clock in Unix seconds.
 * @return string Localized relative text, or empty for unknown/due thresholds.
 */
function admin_cron_relative_label(?int $nextAt, int $now): string
{
    if ($nextAt === null || $nextAt <= $now) {
        return '';
    }
    $remaining = $nextAt - $now;
    $parts = [];
    foreach (ADMIN_CRON_DURATION_UNITS as $seconds => $unit) {
        $count = intdiv($remaining, $seconds);
        if ($count === 0) {
            continue;
        }
        $parts[] = $count . ' ' . t('admin.cron.unit.' . $unit, $unit);
        $remaining %= $seconds;
        if (count($parts) === ADMIN_CRON_DURATION_PARTS) {
            break;
        }
    }
    return t('admin.cron.in', 'in {duration}', ['duration' => implode(' ', $parts)]);
}

/**
 * Distinguish an absent deadline from unavailable schedule evidence.
 * @param int|null $nextAt Authoritative eligibility epoch, or null.
 * @param string $task Stable scheduler task ID.
 * @param string $state Safe current owner/coordination state.
 * @param int $now Current display clock in Unix seconds.
 * @return string Absolute threshold with a future duration, or a factual reason
 * for no deadline; genuinely unavailable evidence remains Unknown.
 */
function admin_cron_next_label(
    ?int $nextAt, string $task, string $state, int $now,
): string {
    if ($nextAt === null) {
        return match (true) {
            $state === 'unknown' => t('admin.cron.unknown', 'Unknown'),
            $state === 'disabled' => t('admin.cron.state.disabled', 'Disabled'),
            $state === 'completed' => t('admin.cron.state.completed', 'Completed'),
            $task === 'update_cache' && $state === 'idle'
                => t('admin.cron.no_pending_cleanup', 'Nothing pending cleanup'),
            default => t('admin.cron.not_scheduled', 'Not scheduled'),
        };
    }
    if ($nextAt === 0) {
        return t('admin.cron.state.due', 'Due');
    }
    $label = date('Y-m-d H:i:s T', $nextAt);
    $relative = admin_cron_relative_label($nextAt, $now);
    return $label . ($relative === '' ? '' : ' (' . $relative . ')');
}

/**
 * Link each task to its existing settings or operational owner.
 * @param string $task Stable scheduler task ID.
 * @return string Installation-correct Admin settings/control URL. Attachment
 * reconciliation has no separate preference, so it uses Maintenance Center.
 */
function admin_cron_settings_url(string $task): string
{
    return match ($task) {
        'updates' => url_for('admin_update'),
        'logs' => url_for('admin_logs', ['section' => 'maintenance']),
        'site', 'cache', 'update_cache' => url_for('admin', [
            'dashboard_tab' => 'maintenance', 'maintenance_tab' => 'media',
        ]) . '#admin-tab-maintenance',
        'telemetry' => url_for('admin_telemetry'),
        'attachments' => url_for('admin_maintenance_center'),
        default => throw new \InvalidArgumentException('Unknown scheduler task.'),
    };
}

/**
 * Prepare localized browser rows from the passive domain snapshot.
 * @return list<array{id:string,name:string,cadence:string,cadence_seconds:int,next_at:?int,last_at:?int,state:string,state_label:string,can_run:bool,detail:string,next_label:string,last_label:string,settings_url:string}> Current rows with existing owner links; timing strings are display only, never sort keys.
 */
function admin_cron_rows(): array
{
    cms_runtime_kernel()->load('archive-request-trigger');
    $rows = request_scheduler_snapshot(
        static fn(string $module): mixed => cms_runtime_kernel()->load($module));
    $now = time();
    foreach ($rows as &$row) {
        $row['next_label'] = admin_cron_next_label(
            $row['next_at'], $row['id'], $row['state'], $now);
        $row['last_label'] = $row['last_at'] === null
            ? ($row['state'] === 'unknown' ? t('admin.cron.unknown', 'Unknown')
                : t('admin.cron.no_recorded_run', 'No recorded run'))
            : date('Y-m-d H:i:s T', $row['last_at']);
        $row['settings_url'] = admin_cron_settings_url($row['id']);
    }
    unset($row);
    return $rows;
}

/**
 * Emit a small private JSON response without caching or raw diagnostics.
 * @param array{ok:bool,rows?:list<array{id:string,name:string,cadence:string,cadence_seconds:int,next_at:?int,last_at:?int,state:string,state_label:string,can_run:bool,detail:string,next_label:string,last_label:string,settings_url:string}>,message?:string,outcome?:string,cron_url?:string} $payload Safe task/status/action projection, including existing owner URLs.
 * @param int $status HTTP response status.
 * @return void Writes one JSON response without terminating other controllers.
 */
function admin_cron_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Render the dedicated dense overview from prepared data and installation URLs.
 * @return void Outputs an authenticated HTML page with a native POST fallback.
 */
function cms_admin_cron_overview(): void
{
    require_admin();
    cms_runtime_kernel()->load('archive-request-trigger');
    header('Cache-Control: private, no-store, max-age=0');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    $rows = admin_cron_rows();
    usort($rows, static fn(array $a, array $b): int =>
        (($a['next_at'] ?? PHP_INT_MAX) <=> ($b['next_at'] ?? PHP_INT_MAX))
            ?: strcmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));
    view_render_admin_cron([
        'rows' => $rows, 'csrf' => csrf_token(),
        'status_url' => url_for('admin_cron_status'),
        'run_url' => url_for('admin_cron_run'),
        'token_url' => url_for('admin_cron_token'),
        'cron_url' => request_scheduler_host_url(),
    ]);
}

/**
 * Observe persisted state; this route is excluded from all startup scheduling.
 * @return void Emits current rows or a safe unavailable response, without creating state.
 */
function cms_admin_cron_status(): void
{
    require_admin();
    cms_runtime_kernel()->load('archive-request-trigger');
    if (request_method() !== 'GET') {
        header('Allow: GET');
        admin_cron_json(['ok' => false], 405);
        return;
    }
    try {
        admin_cron_json(['ok' => true, 'rows' => admin_cron_rows()]);
    } catch (\Throwable) {
        admin_cron_json(['ok' => false], 503);
    }
}

/**
 * Admit exactly the requested task, bypassing its timing threshold only.
 * @return void Returns in-place feedback and rows, or redirects after a native POST.
 */
function cms_admin_cron_run(): void
{
    require_admin();
    cms_runtime_kernel()->load('archive-request-trigger');
    if (request_method() !== 'POST') {
        header('Allow: POST');
        admin_cron_json(['ok' => false], 405);
        return;
    }
    verify_csrf();
    $id = is_string($_POST['task'] ?? null) ? $_POST['task'] : '';
    if (!isset(\Gallery\Services\request_scheduler_task_names()[$id])) {
        admin_cron_json(['ok' => false], 400);
        return;
    }
    try {
        $result = request_scheduler_tick(
            static fn(string $module): mixed => cms_runtime_kernel()->load($module), $id);
        $outcome = $result['outcome'];
        $payload = ['ok' => $result['ok'], 'outcome' => $outcome,
            'message' => t('admin.cron.outcome.' . $outcome, ucfirst($outcome)),
            'rows' => array_values(array_filter(admin_cron_rows(),
                static fn(array $row): bool => $row['id'] === $id))];
    } catch (\Throwable) {
        $payload = ['ok' => false, 'outcome' => 'failed',
            'message' => t('admin.cron.outcome.failed', 'Failed')];
    }
    if (admin_wants_json()) {
        admin_cron_json($payload, $payload['ok'] ? 200 : 409);
        return;
    }
    flash_message($payload['ok'] ? 'success' : 'error', $payload['message']);
    redirect_to(url_for('admin_cron_overview'));
}

/**
 * Rotate or revoke only the optional hosting credential after explicit Admin POST.
 * @return void Returns the new copyable URL or redirects for the no-JavaScript fallback.
 */
function cms_admin_cron_token(): void
{
    require_admin();
    cms_runtime_kernel()->load('archive-request-trigger');
    if (request_method() !== 'POST') {
        header('Allow: POST');
        admin_cron_json(['ok' => false], 405);
        return;
    }
    verify_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!in_array($action, ['rotate', 'revoke'], true)) {
        admin_cron_json(['ok' => false], 400);
        return;
    }
    try {
        request_scheduler_host_token($action);
        if (admin_wants_json()) {
            admin_cron_json(['ok' => true, 'cron_url' => request_scheduler_host_url()]);
            return;
        }
    } catch (\Throwable) {
        if (admin_wants_json()) {
            admin_cron_json(['ok' => false], 503);
            return;
        }
        flash_message('error', t('admin.cron.connection_error',
            'Refresh failed; showing last known state.'));
    }
    redirect_to(url_for('admin_cron_overview'));
}

/**
 * Offer the same due-only scheduler tick to an optional authenticated hosting call.
 * @return void Emits safe accepted/skipped/busy/throttled status; no task or secret detail.
 */
function cms_request_scheduler_cron(): void
{
    cms_runtime_kernel()->load('archive-request-trigger');
    if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        admin_cron_json(['ok' => false], 405);
        return;
    }
    if (array_diff(array_keys($_GET), ['page', 'token']) !== []
        || $_POST !== []) {
        admin_cron_json(['ok' => false], 400);
        return;
    }
    try {
        $provided = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
        if (preg_match('/^[a-f0-9]{64}$/D', $provided) !== 1) {
            admin_cron_json(['ok' => false], 403);
            return;
        }
        $stored = request_scheduler_host_token();
        if ($stored === '' || !hash_equals($stored, $provided)) {
            admin_cron_json(['ok' => false], 403);
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if (!\Gallery\Services\request_scheduler_claim_host_call()) {
            header('Retry-After: '
                . \Gallery\Services\REQUEST_SCHEDULER_HOST_INTERVAL_SECONDS);
            admin_cron_json(['ok' => true, 'outcome' => 'throttled']);
            return;
        }
        $result = request_scheduler_tick(
            static fn(string $module): mixed => cms_runtime_kernel()->load($module));
        admin_cron_json(['ok' => $result['ok'], 'outcome' => $result['outcome']]);
    } catch (\Throwable) {
        admin_cron_json(['ok' => false, 'outcome' => 'failed'], 503);
    }
}
