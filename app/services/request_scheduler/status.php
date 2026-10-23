<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/request_scheduler/status.php
 * Module Type: Service Part
 * Purpose: Project domain-owned schedule evidence into a small passive snapshot.
 * Responsibilities: Preserve unknown evidence, effective cadence and due semantics.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Read a small existing owner receipt without creating paths or repairing pointers.
 * @param string $relative Validated installation-relative private cache path.
 * @return array<string,int|string|bool|null> Allowlisted owner observation fields and a boolean result_ok projection; payloads, tickets, credentials and exception text are excluded.
 */
function request_scheduler_read_scalars(string $relative): array
{
    $root = defined('CACHE_LIFECYCLE_TEST_ROOT')
        ? (string) constant('CACHE_LIFECYCLE_TEST_ROOT')
        : dirname(__DIR__, 3);
    if (!str_starts_with($relative, 'cache/')) {
        throw new \InvalidArgumentException('Invalid owner receipt.');
    }
    application_update_assert_safe_target($root, $relative, true);
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        return [];
    }
    // Owner receipts share the existing protected-cache checkpoint ceiling.
    // Retain only explicitly consumed scalar evidence after bounded decoding.
    $json = @file_get_contents($path, false, null, 0,
        CACHE_LIFECYCLE_STATE_BYTES + 1);
    if (!is_string($json)
        || strlen($json) > CACHE_LIFECYCLE_STATE_BYTES) {
        throw new \RuntimeException('Owner receipt unavailable.');
    }
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new \RuntimeException('Invalid owner receipt.');
    }
    $allowed = array_flip([
        'job_id', 'id', 'status', 'stage', 'queued_until', 'updated_at',
        'started_at', 'finished_at', 'next_run_at', 'last_attempt_at',
        'last_success_at', 'last_progress_at', 'running', 'next_due', 'blocked',
    ]);
    $scalars = array_intersect_key(array_filter($data,
        static fn(mixed $value): bool => is_scalar($value) || $value === null), $allowed);
    if (is_array($data['last_result'] ?? null)
        && is_bool($data['last_result']['ok'] ?? null)) {
        $scalars['result_ok'] = $data['last_result']['ok'];
    }
    if (is_string($data['last_error'] ?? null) && $data['last_error'] !== '') {
        $scalars['has_error'] = true;
    }
    return $scalars;
}

/**
 * Convert persisted owner-clock/offset timestamps without inventing an execution time.
 * @param int|string|null $value Epoch, SQL application-clock timestamp or offset-bearing timestamp.
 * @return int|null Positive Unix seconds, or null for absent/invalid evidence.
 */
function request_scheduler_timestamp(int|string|null $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_int($value) || ctype_digit($value)) {
        return (int) $value > 0 ? (int) $value : null;
    }
    try {
        return (new \DateTimeImmutable($value, new \DateTimeZone(date_default_timezone_get())))
            ->getTimestamp();
    } catch (\Throwable) {
        return null;
    }
}

/**
 * List stable automatic tasks; manual Maintenance Center operations are excluded.
 * @return array<string,string> Stable task IDs mapped to English fallback names.
 */
function request_scheduler_task_names(): array
{
    return [
        'updates' => 'Update check / continuation',
        'logs' => 'Log archival',
        'site' => 'Site maintenance',
        'cache' => 'Cache maintenance',
        'update_cache' => 'Updater cache cleanup',
        'telemetry' => 'Telemetry retention',
        'attachments' => 'Attachment counts',
    ];
}

/**
 * Observe one authoritative owner without workers, network discovery or scans.
 * @param string $id Stable task ID from request_scheduler_task_names.
 * @param int $now Snapshot epoch in Unix seconds.
 * @return array{enabled:bool,cadence:int,next_at:?int,last_at:?int,state:string,detail:string} Effective schedule and actual owner evidence; zero cadence denotes a one-shot task.
 */
function request_scheduler_observe_task(string $id, int $now): array
{
    $row = ['enabled' => true, 'cadence' => 0, 'next_at' => 0,
        'last_at' => null, 'state' => 'never', 'detail' => ''];
    switch ($id) {
        case 'updates':
            $row['enabled'] = feature_capability_effective_enabled(
                'built_in_update_installer');
            $row['cadence'] = application_autoupdate_interval_seconds();
            $last = application_update_discovery_last_attempt_at();
            $row['last_at'] = $last > 0 ? $last : null;
            $row['next_at'] = $last > 0 ? $last + $row['cadence'] : 0;
            $row['enabled'] = $row['enabled'] && application_autoupdate_enabled();
            $result = (string) app_setting('application_autoupdate_last_result', '');
            $row['state'] = str_contains($result, 'fail') ? 'failed'
                : ($last > 0 ? 'idle' : 'never');
            $pointer = request_scheduler_read_scalars('cache/updates/active-job.json');
            $jobId = (string) ($pointer['job_id'] ?? '');
            if ($jobId !== '') {
                if (preg_match('/^[0-9]{14}-[a-f0-9]{12}$/D', $jobId) !== 1) {
                    throw new \RuntimeException('Invalid updater observation.');
                }
                $job = request_scheduler_read_scalars(
                    'cache/updates/jobs/' . $jobId . '/job.json');
                if (($job['id'] ?? '') !== $jobId) {
                    throw new \RuntimeException('Missing updater observation.');
                }
                if (!in_array($job['status'] ?? '', ['completed', 'cancelled'], true)) {
                    $row['enabled'] = feature_capability_effective_enabled(
                        'built_in_update_installer');
                    $row['state'] = ($job['status'] ?? '') === 'running'
                        ? 'running' : 'paused';
                    $chain = request_scheduler_read_scalars(
                        'cache/updates/background-chain.json');
                    $row['next_at'] = max($now,
                        (int) ($chain['queued_until'] ?? 0));
                    $row['detail'] = (string) ($job['stage'] ?? '');
                    $row['last_at'] = max($row['last_at'] ?? 0,
                        request_scheduler_timestamp($job['updated_at']
                            ?? $job['started_at'] ?? null) ?? 0) ?: null;
                    $row['cadence'] = 0;
                }
            }
            $row['next_at'] = max($row['next_at'],
                (int) app_setting('application_autoupdate_lock_until', '0'));
            break;
        case 'logs':
            $state = request_scheduler_read_scalars(
                'cache/admin-log-archive-maintenance-state.json');
            $row['enabled'] = admin_log_archive_retention_days() !== 0
                && class_exists(\ZipArchive::class);
            $row['cadence'] = ADMIN_LOG_ARCHIVE_INTERVAL_SECONDS;
            $row['next_at'] = (int) ($state['next_run_at'] ?? 0);
            $row['last_at'] = request_scheduler_timestamp($state['last_attempt_at'] ?? null);
            $success = request_scheduler_timestamp($state['last_success_at'] ?? null);
            $row['state'] = ($state['result_ok'] ?? null) === false ? 'failed'
                : ($row['last_at'] === null ? 'never'
                    : (($state['result_ok'] ?? null) === true
                        || $success !== null && $success >= $row['last_at'] ? 'idle' : 'unknown'));
            break;
        case 'site':
            $row['enabled'] = site_maintenance_enabled()
                && site_maintenance_request_trigger_enabled();
            $schedule = site_maintenance_schedule_due_state($now);
            $state = site_maintenance_state();
            $row['cadence'] = SITE_MAINTENANCE_DAY_SECONDS;
            $row['next_at'] = $now + $schedule['seconds_until'];
            $completed = (string) app_setting(SITE_MAINTENANCE_LAST_COMPLETED_DATE_SETTING, '');
            if ($schedule['within_window'] && $completed === $schedule['date']) {
                $row['next_at'] = (int) strtotime(
                    $schedule['scheduled_at'] . ' UTC') + $row['cadence'];
            }
            $root = defined('CACHE_LIFECYCLE_TEST_ROOT')
                ? (string) constant('CACHE_LIFECYCLE_TEST_ROOT')
                : dirname(__DIR__, 3);
            $relative = 'cache/site-maintenance/request-trigger.touch';
            application_update_assert_safe_target($root, $relative, true);
            $path = $root . '/' . $relative;
            if (is_file($path)) {
                $row['next_at'] = max($row['next_at'],
                    (int) filemtime($path) + site_maintenance_request_trigger_interval_seconds());
            }
            $row['last_at'] = request_scheduler_timestamp(
                $state['finished_at'] ?? $state['started_at'] ?? null);
            $row['state'] = match ($state['status'] ?? '') {
                'running' => 'running', 'failed' => 'failed',
                'complete', 'completed' => 'idle', default => 'never',
            };
            $row['detail'] = site_maintenance_utc_time() . ' UTC / '
                . site_maintenance_window_minutes() . ' min';
            break;
        case 'cache':
            $row['enabled'] = feature_capability_effective_enabled('automatic_cache_cleanup');
            $state = request_scheduler_read_scalars('cache/cache-lifecycle/scheduler.json');
            $row['cadence'] = CACHE_LIFECYCLE_REFRESH_SECONDS;
            $row['last_at'] = request_scheduler_timestamp($state['last_attempt_at'] ?? null);
            $row['next_at'] = $row['last_at'] === null ? 0
                : $row['last_at'] + $row['cadence'];
            $row['state'] = !empty($state['running']) ? 'running'
                : ($row['last_at'] === null ? 'never' : 'idle');
            if (!empty($state['has_error'])) {
                $row['state'] = 'failed';
            }
            break;
        case 'update_cache':
            $row['enabled'] = feature_capability_effective_enabled('automatic_cache_cleanup');
            $state = request_scheduler_read_scalars('cache/updates/cache-gc.json');
            $row['last_at'] = request_scheduler_timestamp($state['updated_at'] ?? null);
            $row['next_at'] = (int) ($state['next_due'] ?? 0);
            $row['cadence'] = $row['last_at'] === null ? 0
                : max(0, $row['next_at'] - $row['last_at']);
            $row['state'] = !empty($state['blocked']) ? 'failed'
                : ($row['last_at'] === null ? 'never' : 'idle');
            $root = defined('CACHE_LIFECYCLE_TEST_ROOT')
                ? (string) constant('CACHE_LIFECYCLE_TEST_ROOT')
                : dirname(__DIR__, 3);
            application_update_assert_safe_target($root, 'cache/updates/prune-trash', true);
            if (!is_dir($root . '/cache/updates/prune-trash')) {
                $row['next_at'] = null;
                $row['state'] = $row['state'] === 'failed' ? 'failed' : 'idle';
                $row['detail'] = t('admin.cron.detail.no_pending',
                    'There are no deferred updater files waiting for cleanup.');
            }
            break;
        case 'telemetry':
            $settingsStatus = presentation_telemetry_settings_schema_status();
            if (!schema_inspection_is_available($settingsStatus)) {
                throw new \RuntimeException('Telemetry settings unavailable.');
            }
            $row['enabled'] = feature_capability_effective_enabled('telemetry')
                && telemetry_setting_enabled('telemetry_enabled', '0');
            $row['cadence'] = (int) \Gallery\Core\cms_runtime_limit(
                'telemetry.maintenance_interval_seconds');
            $row['next_at'] = (int) telemetry_setting('telemetry_maintenance_next_at', '0');
            if ($row['enabled']) {
                $latest = \Gallery\Models\telemetry_maintenance_model_latest_run();
                $row['last_at'] = request_scheduler_timestamp(
                    $latest['finished_at'] ?? $latest['started_at'] ?? null);
                $row['state'] = match ($latest['status'] ?? '') {
                    'started' => 'running', 'failed' => 'failed',
                    'completed' => 'idle', default => 'never',
                };
            }
            break;
        case 'attachments':
            $raw = (string) app_setting('gallery_attachment_usage.background_state', '');
            $state = $raw === '' ? [] : json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new \RuntimeException('Attachment state unavailable.');
            }
            $complete = ($state['status'] ?? '') === 'complete';
            $row['next_at'] = $complete ? null : max(
                (int) ($state['next_attempt_at'] ?? 0),
                (int) ($state['queued_until'] ?? 0));
            $row['last_at'] = request_scheduler_timestamp($state['completed_at'] ?? null);
            $row['state'] = $complete ? 'completed'
                : ((int) ($state['queued_until'] ?? 0) > $now ? 'running' : 'never');
            $row['detail'] = (string) ($state['scanned'] ?? 0) . ' / '
                . (string) ($state['ready'] ?? 0) . ' / '
                . (string) ($state['failed'] ?? 0);
            if ($complete) {
                $row['detail'] = t('admin.cron.detail.one_shot_complete',
                    'This one-shot reconciliation is complete; no recurring deadline is needed.')
                    . ' ' . $row['detail'];
            }
            break;
        default:
            throw new \InvalidArgumentException('Unknown scheduler task.');
    }
    if (!$row['enabled']) {
        $row['state'] = 'disabled';
        $row['next_at'] = null;
        // Preserve the site's window text because the cadence column consumes
        // it; the final projection adds a separate disabled-state explanation.
    }
    return $row;
}

/**
 * Build a bounded read-only snapshot from current domain receipts.
 * @param callable(string):mixed|null $loadDependencies Loader for existing lightweight policy owners only.
 * @return list<array{id:string,name:string,cadence:string,cadence_seconds:int,next_at:?int,last_at:?int,state:string,state_label:string,can_run:bool,detail:string}> Safe localized rows without paths, tickets or privileged payloads.
 */
function request_scheduler_snapshot(?callable $loadDependencies = null): array
{
    if ($loadDependencies !== null) {
        $loadDependencies('request-maintenance');
        $loadDependencies('archive-request-trigger');
        $loadDependencies('site-request-trigger');
    }
    require_once dirname(__DIR__, 3) . '/app/services/cache_lifecycle_storage.php';
    $now = time();
    $coordinationAvailable = true;
    try {
        $dispatch = request_scheduler_read_state();
    } catch (\Throwable) {
        $coordinationAvailable = false;
        $dispatch = [];
    }
    $rows = [];
    foreach (request_scheduler_task_names() as $id => $fallback) {
        try {
            $observation = request_scheduler_observe_task($id, $now);
        } catch (\Throwable) {
            $observation = ['enabled' => false, 'cadence' => 0,
                'next_at' => null, 'last_at' => null, 'state' => 'unknown',
                'detail' => t('admin.cron.detail.unavailable',
                    'Saved schedule evidence is unavailable; check the task settings and diagnostics.')];
        }
        $queued = ($dispatch['task'] ?? '') === $id
            && (int) ($dispatch['expires_at'] ?? 0) > $now;
        $state = $queued
            ? (isset($dispatch['ticket']) ? 'queued' : 'running')
            : $observation['state'];
        if (!$queued && ($observation['enabled'] ?? false)
            && $observation['next_at'] !== null && $observation['next_at'] <= $now
            && !in_array($state, ['failed', 'paused'], true)) {
            $state = 'due';
        }
        $receipt = $dispatch['receipts'][$id] ?? null;
        if ($observation['state'] !== 'unknown'
            && is_array($receipt) && ($receipt['outcome'] ?? '') === 'failed'
            && (int) $receipt['attempt_at'] > ($observation['last_at'] ?? 0)) {
            $state = 'failed';
            if ($observation['enabled'] && $observation['next_at'] !== null) {
                $observation['next_at'] = max($observation['next_at'],
                    (int) $receipt['attempt_at'] + REQUEST_SCHEDULER_RETRY_SECONDS);
            }
        }
        $detail = $observation['detail'];
        if (!$coordinationAvailable) {
            $state = 'unknown';
            $detail = t('admin.cron.detail.coordination',
                'Scheduler coordination state is unavailable; execution is not offered.');
        }
        $rows[] = [
            'id' => $id, 'name' => t('admin.cron.task.' . $id, $fallback),
            'cadence_seconds' => $observation['cadence'],
            'cadence' => $observation['state'] === 'unknown'
                ? t('admin.cron.unknown', 'Unknown')
                : ($id === 'site' ? $observation['detail']
                : ($observation['cadence'] > 0 && $observation['cadence'] < 60
                    ? t('admin.cron.every_seconds', 'Every {seconds} s',
                        ['seconds' => (string) $observation['cadence']])
                    : ($observation['cadence'] > 0
                    ? t('admin.cron.every_minutes', 'Every {minutes} min',
                        ['minutes' => (string) ($observation['cadence'] / 60)])
                    : t('admin.cron.one_shot', 'One-shot / continuation')))),
            'next_at' => $observation['next_at'],
            'last_at' => $observation['last_at'], 'state' => $state,
            'state_label' => t('admin.cron.state.' . $state, ucfirst($state)),
            'can_run' => $observation['enabled'] && !$queued
                && !in_array($state, ['paused', 'completed', 'unknown'], true),
            'detail' => $state === 'disabled'
                ? t('admin.cron.detail.disabled', 'Disabled by the task owner settings.')
                : $detail,
        ];
    }
    return $rows;
}
