<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/request_scheduler/execution.php
 * Module Type: Service Part
 * Purpose: Admit one task fairly and delegate bounded work to its existing owner.
 * Responsibilities: Serialize visitor/cron/manual admission and consume private tickets.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Select the least recently admitted eligible task with stable ID ties.
 * @param list<array{id:string,can_run:bool,next_at:?int}> $rows Minimal task observations; additional presentation fields are ignored.
 * @param array<string,int> $turns Last dispatch epoch per stable task ID.
 * @param int $now Eligibility cutoff in Unix seconds.
 * @param string $requested Explicit task, or empty for normal due scheduling.
 * @return string|null Eligible task ID, or null when all tasks are disabled/busy/not due.
 */
function request_scheduler_select(
    array $rows, array $turns, int $now, string $requested = '',
): ?string {
    $eligible = array_values(array_filter($rows,
        static fn(array $row): bool => $row['can_run']
            && ($requested !== '' ? $row['id'] === $requested
                : $row['next_at'] !== null && $row['next_at'] <= $now)));
    usort($eligible, static fn(array $left, array $right): int =>
        (($turns[$left['id']] ?? 0) <=> ($turns[$right['id']] ?? 0))
            ?: strcmp($left['id'], $right['id']));
    return $eligible[0]['id'] ?? null;
}

/**
 * Admit at most one existing task through the shared visitor/hosting-cron seam.
 * @param callable(string):mixed|null $loadDependencies Selective runtime loader.
 * @param string $requested Stable task ID for an authorized manual timing override; empty admits due work only.
 * @param bool $afterResponse Whether an existing server primitive has already finished this response; true may execute the selected bounded owner directly, without an HTTP handoff.
 * @return array{ok:bool,outcome:string,task?:string} Safe admission or actual bounded owner outcome without private tickets or errors.
 */
function request_scheduler_tick(
    ?callable $loadDependencies = null, string $requested = '',
    bool $afterResponse = false,
): array {
    if ($requested !== '' && !isset(request_scheduler_task_names()[$requested])) {
        return ['ok' => false, 'outcome' => 'skipped'];
    }
    $rows = request_scheduler_snapshot($loadDependencies);
    $state = request_scheduler_read_state();
    if (request_scheduler_select($rows, $state['turns'] ?? [], time(), $requested) === null) {
        return ['ok' => true, 'outcome' => 'skipped'];
    }
    $lock = request_scheduler_lock();
    if (!is_resource($lock)) {
        return ['ok' => true, 'outcome' => 'busy'];
    }
    try {
        $state = request_scheduler_read_state();
        if ((int) ($state['expires_at'] ?? 0) > time()) {
            return ['ok' => true, 'outcome' => 'busy'];
        }
        $rows = request_scheduler_snapshot($loadDependencies);
        $id = request_scheduler_select($rows, $state['turns'] ?? [], time(), $requested);
        if ($id === null) {
            return ['ok' => true, 'outcome' => 'skipped'];
        }
        if (\Gallery\Models\maintenance_center_model_mutation_owner(true) !== null) {
            return ['ok' => true, 'outcome' => 'busy', 'task' => $id];
        }
        $ticket = bin2hex(random_bytes(REQUEST_SCHEDULER_TOKEN_BYTES));
        $state['ticket'] = $ticket;
        $state['task'] = $id;
        $state['force'] = $requested !== '';
        $state['expires_at'] = time() + REQUEST_SCHEDULER_LEASE_SECONDS;
        $state['turns'][$id] = time();
        request_scheduler_write_state($state);
        if (!$afterResponse) {
            if (site_maintenance_fire_background_worker(false, '', '', '', $ticket)) {
                return ['ok' => true, 'outcome' => 'started', 'task' => $id];
            }
            // Retire failed transport immediately. Never convert acceptance
            // into completion; another eligible request can retry the owner.
            $state['expires_at'] = 0;
            unset($state['ticket']);
            $state['receipts'][$id] = ['attempt_at' => time(), 'outcome' => 'failed'];
            request_scheduler_write_state($state);
            return ['ok' => false, 'outcome' => 'failed', 'task' => $id];
        }
    } finally {
        request_scheduler_unlock($lock);
    }
    // The normal response has already finished. Consume the identical one-use
    // ticket through the same admission/owner checks without a loopback request.
    return request_scheduler_work($ticket, $loadDependencies) + ['task' => $id];
}

/**
 * Consume one private handoff while retaining global single-flight exclusion.
 * @param string $ticket Exact 256-bit ticket from the private worker transport.
 * @param callable(string):mixed|null $loadDependencies Existing worker loader.
 * @return array{ok:bool,outcome:string} Safe execution result; stale/replayed tickets cannot run work.
 */
function request_scheduler_work(
    string $ticket, ?callable $loadDependencies = null,
): array {
    if (preg_match('/^[a-f0-9]{64}$/D', $ticket) !== 1) {
        return ['ok' => false, 'outcome' => 'skipped'];
    }
    $lock = request_scheduler_lock();
    if (!is_resource($lock)) {
        return ['ok' => true, 'outcome' => 'busy'];
    }
    try {
        $state = request_scheduler_read_state();
        if ((int) ($state['expires_at'] ?? 0) <= time()
            || !hash_equals((string) ($state['ticket'] ?? ''), $ticket)) {
            return ['ok' => true, 'outcome' => 'skipped'];
        }
        $id = (string) ($state['task'] ?? '');
        $force = !empty($state['force']);
        // Consume before work, retaining the flock for the complete bounded
        // invocation. A crash expires the UI lease but cannot replay its ticket.
        unset($state['ticket']);
        request_scheduler_write_state($state);
        $outcome = 'failed';
        try {
            request_scheduler_snapshot($loadDependencies);
            $owner = request_scheduler_observe_task($id, time());
            if (!$owner['enabled'] || $owner['state'] === 'paused') {
                $outcome = 'skipped';
            } elseif (!$force && ($owner['next_at'] === null
                || $owner['next_at'] > time())) {
                $outcome = 'skipped';
            } elseif (\Gallery\Models\maintenance_center_model_mutation_owner(true) !== null) {
                $outcome = 'busy';
            } else {
                $outcome = request_scheduler_execute_owner($id, $force, $loadDependencies);
            }
        } catch (\Throwable) {
            // Domain state retains detailed diagnostics. This receipt contains
            // no exception text, filesystem paths, credentials or request input.
            $outcome = 'failed';
        }
        $state['expires_at'] = 0;
        $state['receipts'][$id] = ['attempt_at' => time(), 'outcome' => $outcome];
        request_scheduler_write_state($state);
        return ['ok' => $outcome !== 'failed', 'outcome' => $outcome];
    } finally {
        request_scheduler_unlock($lock);
    }
}

/**
 * Translate an actual owner result into an honest bounded-slice outcome.
 * @param array{ok?:bool,busy?:bool,skipped?:bool,blocked?:bool,done?:bool,completed?:bool,queued?:bool,has_more?:bool,reason?:string,status?:string} $result Owner admission/progress fields; other domain fields are ignored.
 * @return string completed, continued, running, busy, skipped or failed.
 */
function request_scheduler_outcome(array $result): string
{
    if (!empty($result['busy']) || ($result['reason'] ?? '') === 'busy') {
        return 'busy';
    }
    if (!empty($result['blocked']) || ($result['ok'] ?? true) === false) {
        return 'failed';
    }
    if (!empty($result['skipped'])) {
        return 'skipped';
    }
    if (!empty($result['queued'])) {
        return 'running';
    }
    if (!empty($result['done']) || !empty($result['completed'])) {
        return 'completed';
    }
    return 'continued';
}

/**
 * Execute only the selected owner's bounded primitive and its safety checks.
 * @param string $id Stable supported task ID.
 * @param bool $force Bypass only the selected owner's timing threshold.
 * @param callable(string):mixed|null $loadDependencies Reviewed worker loader.
 * @return string Honest owner outcome; dispatch alone never reports completion.
 */
function request_scheduler_execute_owner(
    string $id, bool $force, ?callable $loadDependencies = null,
): string {
    if ($id === 'logs') {
        if ($loadDependencies !== null) {
            $loadDependencies('archive-maintenance-work');
        }
        $result = admin_log_archive_maintenance_run([
            'source' => 'request_scheduler', 'force' => $force,
            'deadline' => microtime(true) + site_maintenance_time_budget_seconds(),
        ]);
        if (!empty($result['ok']) && empty($result['busy'])
            && empty($result['has_more'])) {
            return 'completed';
        }
        return request_scheduler_outcome($result);
    }
    if ($id === 'updates') {
        if ($loadDependencies !== null) {
            $loadDependencies('updater-work');
        }
        $active = application_update_active_job();
        if ($active !== null) {
            application_autoupdate_maybe_run(
                application_autoupdate_interval_seconds(), 'GET', $loadDependencies);
            application_update_background_chain_start(
                static fn(string $ticket): bool =>
                    site_maintenance_fire_background_worker(false, '', $ticket));
            return 'continued';
        }
        $before = application_update_discovery_last_attempt_at();
        if (application_update_beta_active()) {
            application_autoupdate_dry_run($force);
        } else {
            application_autoupdate_run_installing_check($force);
        }
        $result = (string) app_setting('application_autoupdate_last_result', '');
        if (str_contains($result, 'fail')) {
            return 'failed';
        }
        if (application_update_active_job() !== null) {
            application_update_background_chain_start(
                static fn(string $ticket): bool =>
                    site_maintenance_fire_background_worker(false, '', $ticket));
            return 'running';
        }
        return application_update_discovery_last_attempt_at() > $before
            ? 'completed' : 'skipped';
    }
    if ($loadDependencies !== null) {
        $loadDependencies('site-maintenance-work');
    }
    switch ($id) {
        case 'site':
            site_maintenance_mark_request_trigger_attempt();
            return request_scheduler_outcome(site_maintenance_run([
                'source' => 'request_scheduler', 'force' => $force,
                'chain' => false, 'scheduler_task_only' => true,
            ]));
        case 'cache':
            $result = cache_lifecycle_visitor_run(true, true, $force);
            cache_lifecycle_chain_start();
            return request_scheduler_outcome($result);
        case 'update_cache':
            return request_scheduler_outcome(
                application_update_run_scheduled_cache_cleanup($force));
        case 'telemetry':
            return request_scheduler_outcome(site_maintenance_telemetry_worker_run($force));
        case 'attachments':
            return request_scheduler_outcome(gallery_attachment_usage_background_start(
                static fn(string $ticket): bool =>
                    site_maintenance_fire_background_worker(false, '', '', $ticket),
                $force,
            ));
        default:
            return 'skipped';
    }
}
