<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/request_scheduler_test.php
 * Module Type: Regression Test
 * Purpose: Exercise real central admission, passive reads and private ticket recovery.
 * Responsibilities: Isolate settings/transport while retaining production locks and scheduler code.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /** Read only fixture settings. @param string $key Semantic key. @param string $default Absent-key fallback. @return string Saved fixture scalar or fallback. */
    function app_setting(string $key, string $default = ''): string
    {
        return $GLOBALS['scheduler_settings'][$key] ?? $default;
    }
    /** Persist one fixture setting. @param string $key Semantic key. @param string $value Scalar value. @return void Records the write in isolated memory. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['scheduler_settings'][$key] = $value;
        $GLOBALS['scheduler_setting_writes']++;
    }
    /** Enable only the updater in this transport fixture. @param string $key Capability ID. @return bool True only for the installer. */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key === 'built_in_update_installer';
    }
    /** Resolve safe English fixture text. @param string $key Catalog key, unused because this isolated fixture selects fallback text. @param string $fallback English fallback. @param array<string,string> $parameters Placeholder values. @return string Expanded fallback. */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        foreach ($parameters as $name => $value) {
            $fallback = str_replace('{' . $name . '}', $value, $fallback);
        }
        return $fallback;
    }
    /** Keep unrelated automatic archival disabled. @return int Zero retention. */
    function admin_log_archive_retention_days(): int { return 0; }
    /** Keep unrelated thumbnails disabled. @return bool False. */
    function site_maintenance_enabled(): bool { return false; }
    /** Preserve a separately enabled request preference. @return bool True. */
    function site_maintenance_request_trigger_enabled(): bool { return true; }
    /** Supply a passive daily-window projection. @param int $now Explicit snapshot epoch. @return array{seconds_until:int,within_window:bool,date:string,scheduled_at:string} Fixed future window metadata. */
    function site_maintenance_schedule_due_state(int $now): array
    {
        return ['seconds_until' => SITE_MAINTENANCE_DAY_SECONDS,
            'within_window' => false, 'date' => gmdate('Y-m-d', $now),
            'scheduled_at' => gmdate('Y-m-d 23:00:00', $now)];
    }
    /** Supply no thumbnail attempt evidence. @return array{status?:string} Empty receipt. */
    function site_maintenance_state(): array { return []; }
    /** Supply the fixture's daily UTC start. @return string Native UTC HH:MM. */
    function site_maintenance_utc_time(): string { return '23:00'; }
    /** Supply a structural whole-day fixture window. @return int Minutes in one hour. */
    function site_maintenance_window_minutes(): int { return 60; }
    /** Supply the production retry interval to the unused disabled owner. @return int Seconds between attempts. */
    function site_maintenance_request_trigger_interval_seconds(): int { return REQUEST_SCHEDULER_RETRY_SECONDS; }
    /** Model confirmed missing telemetry storage. @return array{state:string} Missing schema projection. */
    function presentation_telemetry_settings_schema_status(): array { return ['state' => 'missing']; }
    /** Preserve explicit missing/unknown schema policy. @param array{state:string} $state Observed fixture schema. @return bool True only for available. */
    function schema_inspection_is_available(array $state): bool { return $state['state'] === 'available'; }
    /** Observe an absent updater job without creating directories. @return array{id:string}|null Always null for isolated discovery tests. */
    function application_update_active_job(): ?array { return null; }
    /** Perform a real fixture discovery observation, with no network. @param bool $force Explicit time override. @param int|null $checkedAt Optional attempt clock. @return array{ok:bool} Completed fixture check. */
    function application_autoupdate_run_installing_check(bool $force = false, ?int $checkedAt = null): array
    {
        $GLOBALS['scheduler_owner_runs']++;
        $GLOBALS['scheduler_last_force'] = $force;
        set_app_setting('application_autoupdate_last_checked_at', (string) ($checkedAt ?? time()));
        set_app_setting('application_autoupdate_last_result', 'no_update');
        return ['ok' => true];
    }
    /** Capture the existing private transport's scheduler mode. @param bool $telemetryOnly Telemetry mode, false in this fixture. @param string $ticket Cache ticket. @param string $updateTicket Updater ticket. @param string $mediaTicket Media ticket. @param string $schedulerTicket Central one-use ticket. @return bool Fixture-controlled transport acceptance. */
    function site_maintenance_fire_background_worker(bool $telemetryOnly, string $ticket = '', string $updateTicket = '', string $mediaTicket = '', string $schedulerTicket = ''): bool
    {
        $GLOBALS['scheduler_dispatched'][] = $schedulerTicket;
        return $GLOBALS['scheduler_transport_ok'];
    }
    /** Preserve the disabled log owner's daily cadence. Type: int. Units: seconds. Scope: isolated owner projection. Consumers: scheduler snapshot. Rationale: 86400 seconds matches the production UTC day without wall-clock waits. */
    const ADMIN_LOG_ARCHIVE_INTERVAL_SECONDS = 86400;
    /** Preserve UTC-day arithmetic in the disabled site fixture. Type: int. Units: seconds. Scope: isolated daily-window projection. Consumers: scheduler snapshot. Rationale: 86400 seconds matches the production owner's fixed day independent of local DST. */
    const SITE_MAINTENANCE_DAY_SECONDS = 86400;
    /** Mirror the persisted daily-cycle key. Type: string. Units: setting key. Scope: isolated disabled-site owner. Consumers: scheduler observation. Rationale: retain the production key without loading the heavy maintenance worker. */
    const SITE_MAINTENANCE_LAST_COMPLETED_DATE_SETTING = 'site_maintenance_last_completed_date';
}
namespace Gallery\Models {
    /** Observe a strict fixture maintenance claim. @param bool $requireKnown True requires storage evidence. @return array{id:int}|null Busy owner or null; unknown claim throws. */
    function maintenance_center_model_mutation_owner(bool $requireKnown = false): ?array
    {
        if ($requireKnown && $GLOBALS['scheduler_claim_unknown']) {
            throw new \RuntimeException('Fixture ownership unavailable.');
        }
        return $GLOBALS['scheduler_claim_busy'] ? ['id' => 1] : null;
    }
}
namespace {
    require_once __DIR__ . '/../app/early_runtime.php';
    require_once __DIR__ . '/../app/release_file_policy.php';
    require_once __DIR__ . '/../app/services/updates_path_safety.php';
    require_once __DIR__ . '/../app/services/updates_request.php';
    require_once __DIR__ . '/../app/services/request_scheduler.php';
    require_once __DIR__ . '/../app/controllers/admin_cron.php';

    /** Assert the scheduler invariant. @param bool $condition Expected invariant. @param string $message Safe failure context. @return void Throws on a regression. */
    function scheduler_check(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    /** Remove only this test's independently created private directory. @param string $directory Owned unique fixture path. @return void Removes children and the fixture directory. */
    function scheduler_remove_fixture(string $directory): void
    {
        if (!str_starts_with(basename($directory), 'gallery-scheduler-test-')) {
            throw new RuntimeException('Fixture cleanup path rejected.');
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
    $directory = sys_get_temp_dir() . '/gallery-scheduler-test-' . bin2hex(random_bytes(8));
    mkdir($directory);
    /** Confine scheduler files to this disposable fixture. Type: string. Units: absolute filesystem path. Scope: this child process. Consumers: scheduler/cache path guards. Rationale: isolate locks and atomic receipts from the real installation. */
    define('CACHE_LIFECYCLE_TEST_ROOT', $directory);
    $GLOBALS['scheduler_settings'] = [
        'gallery_attachment_usage.background_state' => '{"status":"complete","completed_at":1}',
    ];
    $GLOBALS['scheduler_setting_writes'] = 0;
    $GLOBALS['scheduler_owner_runs'] = 0;
    $GLOBALS['scheduler_dispatched'] = [];
    $GLOBALS['scheduler_transport_ok'] = true;
    $GLOBALS['scheduler_claim_busy'] = false;
    $GLOBALS['scheduler_claim_unknown'] = false;
    try {
        foreach (['GET', 'HEAD'] as $method) {
            scheduler_check(\Gallery\Services\request_scheduler_visit_allowed('gallery', $method), 'Crawler-compatible HTML visit rejected.');
            scheduler_check(\Gallery\Services\request_scheduler_visit_allowed('admin_update', $method)
                && !\Gallery\Services\request_scheduler_visit_allowed('admin_update', $method, true),
                'Updater page recovery or passive checkpoint isolation regressed.');
        }
        foreach (['media', 'thumb', 'site_maintenance_cron', 'request_scheduler_cron', 'admin_cron_status'] as $page) {
            scheduler_check(!\Gallery\Services\request_scheduler_visit_allowed($page, 'GET'), 'Passive/private route admitted.');
        }
        scheduler_check(!\Gallery\Services\request_scheduler_visit_allowed('home', 'POST')
            && !\Gallery\Services\request_scheduler_visit_allowed('home', 'GET', true), 'POST or passive read admitted.');
        $rows = \Gallery\Services\request_scheduler_snapshot();
        scheduler_check(count($rows) === count(\Gallery\Services\request_scheduler_task_names()), 'Automatic inventory incomplete.');
        scheduler_check(!is_dir($directory . '/cache') && $GLOBALS['scheduler_setting_writes'] === 0, 'Passive status created state.');
        scheduler_check(\Gallery\Services\request_scheduler_host_token() === ''
            && $GLOBALS['scheduler_setting_writes'] === 0, 'Absent-token read generated a credential.');
        $token = \Gallery\Services\request_scheduler_host_token('rotate');
        scheduler_check(preg_match('/^[a-f0-9]{64}$/D', $token) === 1, 'Hosting token lacks required entropy format.');
        \Gallery\Services\request_scheduler_host_token('revoke');
        scheduler_check(\Gallery\Services\request_scheduler_host_token() === '', 'Revocation retained hosting access.');
        mkdir($directory . '/cache');
        file_put_contents($directory . '/cache/archive-projection.json', json_encode([
            'last_attempt_at' => '2030-01-01 00:00:00',
            'last_success_at' => '2030-01-01 00:00:00',
            'last_result' => ['ok' => false, 'error' => 'PRIVATE EXCEPTION'],
            'token' => 'PRIVATE CREDENTIAL',
        ]));
        $projection = \Gallery\Services\request_scheduler_read_scalars(
            'cache/archive-projection.json');
        scheduler_check($projection['result_ok'] === false
            && !isset($projection['token']) && !isset($projection['last_result']),
            'Passive projection lost latest failure or exposed private payload.');
        scheduler_check(\Gallery\Services\request_scheduler_claim_host_call(),
            'First authenticated hosting window was rejected.');
        scheduler_check(!\Gallery\Services\request_scheduler_claim_host_call()
            && $GLOBALS['scheduler_owner_runs'] === 0,
            'Repeated hosting calls bypassed their independent rate limit.');
        $hostState = \Gallery\Services\request_scheduler_read_state();
        $hostState['host_next_at'] = 0;
        \Gallery\Services\request_scheduler_write_state($hostState);
        scheduler_check(\Gallery\Services\request_scheduler_claim_host_call(),
            'Expired hosting window could not be admitted again.');
        // Exact boundary vectors describe one minute and a multi-unit duration;
        // display arithmetic must not alter the epoch used for task sorting.
        scheduler_check(\Gallery\Controllers\admin_cron_relative_label(1060, 1000) === 'in 1 min'
            && \Gallery\Controllers\admin_cron_relative_label(4661, 1000) === 'in 1 h 1 min'
            && \Gallery\Controllers\admin_cron_relative_label(1000, 1000) === ''
            && \Gallery\Controllers\admin_cron_relative_label(null, 1000) === '',
            'Relative display lost future precision or invented an unknown/due countdown.');
        scheduler_check(\Gallery\Controllers\admin_cron_next_label(null, 'attachments', 'completed', 1000) === 'Completed'
            && \Gallery\Controllers\admin_cron_next_label(null, 'updates', 'disabled', 1000) === 'Disabled'
            && \Gallery\Controllers\admin_cron_next_label(null, 'update_cache', 'idle', 1000) === 'Nothing pending cleanup'
            && \Gallery\Controllers\admin_cron_next_label(null, 'telemetry', 'unknown', 1000) === 'Unknown',
            'Intentional absent deadlines were confused with unavailable evidence.');
        $now = time();
        $candidates = [
            ['id' => 'a', 'can_run' => true, 'next_at' => $now],
            ['id' => 'b', 'can_run' => true, 'next_at' => $now],
            ['id' => 'off', 'can_run' => false, 'next_at' => 0],
        ];
        scheduler_check(\Gallery\Services\request_scheduler_select($candidates, ['a' => $now], $now) === 'b', 'Due fairness failed.');
        scheduler_check(\Gallery\Services\request_scheduler_select($candidates, [], $now, 'off') === null, 'Force bypassed enablement.');
        $first = \Gallery\Services\request_scheduler_tick();
        scheduler_check($first['outcome'] === 'started' && $first['task'] === 'updates', 'Due updater was not admitted.');
        $ticket = $GLOBALS['scheduler_dispatched'][0];
        scheduler_check(count($GLOBALS['scheduler_dispatched']) === 1, 'A tick dispatched multiple owners.');
        \Gallery\Services\request_scheduler_tick();
        scheduler_check(count($GLOBALS['scheduler_dispatched']) === 1, 'Concurrent visitor/cron duplicated admission.');
        $lock = \Gallery\Services\request_scheduler_lock();
        scheduler_check(is_resource($lock), 'Test failed to acquire real lock.');
        scheduler_check(\Gallery\Services\request_scheduler_lock() === false, 'Contending request bypassed flock.');
        \Gallery\Services\request_scheduler_unlock($lock);
        scheduler_check(\Gallery\Services\request_scheduler_work(str_repeat('0', 64))['outcome'] === 'skipped', 'Wrong ticket admitted work.');
        $done = \Gallery\Services\request_scheduler_work($ticket);
        scheduler_check($done['outcome'] === 'completed' && $GLOBALS['scheduler_owner_runs'] === 1, 'Accepted worker did not execute exactly one owner.');
        \Gallery\Services\request_scheduler_work($ticket);
        scheduler_check($GLOBALS['scheduler_owner_runs'] === 1, 'Consumed ticket replayed.');
        $before = count($GLOBALS['scheduler_dispatched']);
        scheduler_check(\Gallery\Services\request_scheduler_tick()['outcome'] === 'skipped', 'Configured updater cadence ignored.');
        scheduler_check(count($GLOBALS['scheduler_dispatched']) === $before, 'Non-due tick dispatched work.');
        scheduler_check(\Gallery\Services\request_scheduler_tick(null, '', true, 'updates')['outcome'] === 'skipped'
            && $GLOBALS['scheduler_owner_runs'] === 1,
            'Restricting an authenticated updater opportunity bypassed its due interval.');
        $forced = \Gallery\Services\request_scheduler_tick(null, 'updates');
        scheduler_check($forced['outcome'] === 'started', 'Explicit timing override rejected.');
        $ticket = end($GLOBALS['scheduler_dispatched']);
        $GLOBALS['scheduler_claim_busy'] = true;
        scheduler_check(\Gallery\Services\request_scheduler_work($ticket)['outcome'] === 'busy', 'Worker bypassed a new central maintenance claim.');
        scheduler_check($GLOBALS['scheduler_owner_runs'] === 1, 'Busy claim still executed owner.');
        $GLOBALS['scheduler_claim_busy'] = false;
        $GLOBALS['scheduler_transport_ok'] = false;
        \Gallery\Services\set_app_setting('application_autoupdate_last_checked_at', '0');
        scheduler_check(\Gallery\Services\request_scheduler_tick()['outcome'] === 'failed', 'Transport refusal reported success.');
        $before = count($GLOBALS['scheduler_dispatched']);
        \Gallery\Services\request_scheduler_tick();
        scheduler_check(count($GLOBALS['scheduler_dispatched']) === $before, 'Failed transport retried on every visit.');
        $state = \Gallery\Services\request_scheduler_read_state();
        $state['ticket'] = bin2hex(random_bytes(32));
        $state['expires_at'] = time() - 1; // Exact expired-lease boundary.
        $lock = \Gallery\Services\request_scheduler_lock();
        \Gallery\Services\request_scheduler_write_state($state);
        \Gallery\Services\request_scheduler_unlock($lock);
        scheduler_check(\Gallery\Services\request_scheduler_work($state['ticket'])['outcome'] === 'skipped', 'Expired ticket ran work.');
        $GLOBALS['scheduler_claim_unknown'] = true;
        $refused = false;
        try {
            \Gallery\Services\request_scheduler_tick(null, 'updates');
        } catch (RuntimeException) {
            $refused = true;
        }
        scheduler_check($refused && $GLOBALS['scheduler_owner_runs'] === 1,
            'Unknown ownership did not fail closed.');
        $GLOBALS['scheduler_claim_unknown'] = false;
        $GLOBALS['scheduler_transport_ok'] = false;
        \Gallery\Services\set_app_setting('application_autoupdate_last_checked_at', '0');
        $before = count($GLOBALS['scheduler_dispatched']);
        $direct = \Gallery\Services\request_scheduler_tick(null, 'updates', true);
        scheduler_check($direct['outcome'] === 'completed'
            && $GLOBALS['scheduler_owner_runs'] === 2
            && count($GLOBALS['scheduler_dispatched']) === $before,
            'Finished-response scheduling depends on a loopback transport or repeats work.');
        echo "PASS central scheduler passive state, fair admission, cadence, locks, tickets, retries, direct after-response execution and hosting token lifecycle\n";
        $jobId = '20300101000000-abcdef123456';
        mkdir($directory . '/cache/updates/jobs/' . $jobId, 0700, true);
        file_put_contents($directory . '/cache/updates/active-job.json',
            json_encode(['job_id' => $jobId], JSON_THROW_ON_ERROR));
        $failure = ['id' => $jobId, 'status' => 'failed', 'stage' => 'migrate',
            'trigger' => 'admin', 'recovery_not_before' => $now + 60];
        file_put_contents($directory . '/cache/updates/jobs/' . $jobId . '/job.json',
            json_encode($failure, JSON_THROW_ON_ERROR));
        $GLOBALS['scheduler_settings']['application_autoupdate_enabled'] = '0';
        $failedRow = \Gallery\Services\request_scheduler_observe_task('updates', $now);
        scheduler_check($failedRow['enabled'] && $failedRow['state'] === 'failed'
            && $failedRow['next_at'] === $now + 60,
            'A failed manual job was hidden as pending/disabled while safety recovery was due.');
        $failure['recovery_blocked'] = 'database_unverified';
        file_put_contents($directory . '/cache/updates/jobs/' . $jobId . '/job.json',
            json_encode($failure, JSON_THROW_ON_ERROR));
        $blockedRow = \Gallery\Services\request_scheduler_observe_task('updates', $now);
        scheduler_check($blockedRow['state'] === 'failed' && $blockedRow['next_at'] === null,
            'An unverified database invented a pending continuation deadline.');
    } finally {
        scheduler_remove_fixture($directory);
    }
}
