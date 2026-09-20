<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_maintenance_recovery_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercises telemetry repairs using isolated deterministic fixtures.
 *
 * Responsibilities:
 *   - Execute production behavior with isolated inputs
 *   - Fail on privacy, recovery or reporting regressions
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply deterministic bounded limits without application configuration.
     * @param string $key Canonical fixture configuration or setting key.
     * @return int Result of the documented operation.
     */
    function cms_runtime_limit(string $key): int {
        return ['telemetry.maintenance_time_budget_seconds' => 3, 'telemetry.maintenance_delete_batch_size' => 2,
            'telemetry.maintenance_delete_batches' => 2, 'telemetry.maintenance_rollup_days' => 2,
            'telemetry.maintenance_retry_seconds' => 300, 'telemetry.maintenance_interval_seconds' => 3600][$key];
    }
    /**
     * Supply the current local SQL timestamp.
     * @return string Result of the documented operation.
     */
    function now_sql(): string { return date('Y-m-d H:i:s'); }
}
namespace Gallery\Services {
    /**
     * Simulate effective policy before any schema or mutation work.
     * @param string $key Canonical fixture configuration or setting key.
     * @return bool Result of the documented operation.
     */
    function feature_capability_effective_enabled(string $key): bool { return $GLOBALS['tm']['enabled']; }
    /**
     * Count schema observations for the disabled-path contract.
     * @return array{state:string} Result of the documented operation.
     */
    function presentation_telemetry_schema_status(): array { $GLOBALS['tm']['schema_reads']++; return ['state' => $GLOBALS['tm']['schema']]; }
    /**
     * Preserve fail-closed unknown schema semantics in the fixture.
     * @param array<string,mixed> $state Prepared state consumed by the maintenance or schema operation.
     * @param string $operation Bounded diagnostic operation name.
     * @param string $message Caller-provided safe diagnostic message.
     * @return void No return value; effects are recorded in the owned state.
     */
    function presentation_schema_assert_known(array $state, string $operation, string $message): void {
        if ($state['state'] === 'unknown') { throw new \RuntimeException('unknown schema'); }
    }
    /**
     * Identify confirmed missing schema.
     * @param array<string,mixed> $state Prepared state consumed by the maintenance or schema operation.
     * @return bool Result of the documented operation.
     */
    function schema_inspection_is_missing(array $state): bool { return $state['state'] === 'missing'; }
    /**
     * Read fixture settings, including the durable checkpoint.
     * @param string $key Canonical fixture configuration or setting key.
     * @param string $fallback Value used when no explicit fixture setting exists.
     * @return string Result of the documented operation.
     */
    function telemetry_setting(string $key, string $fallback): string { return $GLOBALS['tm']['settings'][$key] ?? $fallback; }
    /**
     * Persist a fixture checkpoint at the production service's real write boundary.
     * @param string $key Canonical fixture configuration or setting key.
     * @param string $value Candidate value; unsupported shapes are ignored or normalized.
     * @return void No return value; effects are recorded in the owned state.
     */
    function telemetry_set_setting(string $key, string $value): void { $GLOBALS['tm']['settings'][$key] = $value; }
    /**
     * Retain production defaults for age tests.
     * @param string $key Canonical fixture configuration or setting key.
     * @param int $fallback Value used when no explicit fixture setting exists.
     * @param int $min Minimum accepted configuration value.
     * @param int $max Maximum accepted configuration value.
     * @return int Result of the documented operation.
     */
    function telemetry_retention_days(string $key, int $fallback, int $min, int $max): int { return $fallback; }
    /**
     * Accept operational logging without a live database.
     * @param scalar|array<array-key,mixed>|object|resource|null $args Arguments accepted for interface compatibility; unused by this fixture.
     * @return void No return value; effects are recorded in the owned state.
     */
    function admin_log_event(mixed ...$args): void {}
}
namespace Gallery\Models {
    /**
     * Read durable state without the production reporting getter's fail-open default.
     * @param string $key Canonical setting key requested by maintenance.
     * @return string|false Stored setting or confirmed absent row.
     */
    function telemetry_model_setting(string $key): string|false {
        if (!empty($GLOBALS['tm']['checkpoint_read_failure']) && $key === 'telemetry_rollup_next_date') {
            throw new \RuntimeException('fixture read failure');
        }
        if (!empty($GLOBALS['tm']['retention_read_failure']) && $key === 'telemetry_raw_retention_days') {
            throw new \RuntimeException('fixture retention read failure');
        }
        return $GLOBALS['tm']['settings'][$key] ?? false;
    }
    /**
     * Simulate nonblocking admission and prove every admitted slice releases it.
     * @return bool Result of the documented operation.
     */
    function telemetry_maintenance_model_acquire_lock(): bool {
        $GLOBALS['tm']['lock_calls']++;
        if ($GLOBALS['tm']['busy']) { return false; }
        $GLOBALS['tm']['locked'] = true; return true;
    }
    /**
     * Release the fixture's independently owned telemetry lock.
     * @return void No return value; effects are recorded in the owned state.
     */
    function telemetry_maintenance_model_release_lock(): void { $GLOBALS['tm']['locked'] = false; }
    /**
     * Find the next stable populated date using the same exclusive checkpoint semantics.
     * @param ?string $from Inclusive checkpoint date; null starts at the oldest stored hourly day.
     * @param string $before Exclusive date boundary already covered by durable daily rollup.
     * @return ?string Result of the documented operation.
     */
    function telemetry_maintenance_model_next_rollup_day(?string $from, string $before): ?string {
        foreach ($GLOBALS['tm']['hours'] as $day => $count) {
            if ($count > 0 && $day < $before && ($from === null || $day >= $from)) { return $day; }
        }
        return null;
    }
    /**
     * Create durable start evidence only after lock admission.
     * @param string $now Local SQL timestamp supplied by the service.
     * @return int Result of the documented operation.
     */
    function telemetry_maintenance_model_start_job(string $now): int {
        $GLOBALS['tm']['jobs'][] = ['status' => 'started']; return count($GLOBALS['tm']['jobs']);
    }
    /**
     * Persist a terminal slice outcome in the fixture.
     * @param int $id Job identifier allocated after lock acquisition.
     * @param string $status Bounded terminal job status.
     * @param string $now Local SQL timestamp supplied by the service.
     * @param int $ms Elapsed slice duration in milliseconds.
     * @param int $items Aggregate processed item count.
     * @param ?string $error Bounded error kind, never an exception message.
     * @return void No return value; effects are recorded in the owned state.
     */
    function telemetry_maintenance_model_finish_job(int $id, string $status, string $now, int $ms, int $items, ?string $error): void {
        $GLOBALS['tm']['jobs'][$id - 1] = ['status' => $status, 'error' => $error];
    }
    /**
     * Simulate replace-style daily rollup so partial-hour replay would be observable.
     * @param string $from Inclusive checkpoint date; null starts at the oldest stored hourly day.
     * @param string $to Inclusive end date.
     * @param string $now Local SQL timestamp supplied by the service.
     * @return int Result of the documented operation.
     */
    function telemetry_model_rollup_daily(string $from, string $to, string $now): int {
        $GLOBALS['tm']['calls'][] = 'rollup:' . $from;
        if (($GLOBALS['tm']['fail_day'] ?? null) === $from) { throw new \RuntimeException('fixture failure'); }
        $GLOBALS['tm']['daily'][$from] = $GLOBALS['tm']['hours'][$from] ?? 0;
        return 1;
    }
    /**
     * Delete a bounded batch and refuse hourly deletion ahead of archival evidence.
     * @param string $table Allowlisted telemetry table.
     * @param string $column Timestamp column belonging to that table.
     * @param int $days Report lookback or configured retention age in days.
     * @param int $limit Maximum rows or items processed by this call.
     * @param ?string $before Exclusive date boundary already covered by durable daily rollup.
     * @return int Result of the documented operation.
     */
    function telemetry_model_delete_older_than(string $table, string $column, int $days, int $limit = 2000, ?string $before = null): int {
        $GLOBALS['tm']['calls'][] = 'delete:' . $table;
        if ($table !== 'telemetry_hourly_metrics') {
            $count = min($limit, $GLOBALS['tm']['backlog'][$table] ?? 0);
            $GLOBALS['tm']['backlog'][$table] = ($GLOBALS['tm']['backlog'][$table] ?? 0) - $count;
            return $count;
        }
        if ($before === null) { throw new \RuntimeException('hourly deletion without checkpoint'); }
        $count = 0;
        foreach ($GLOBALS['tm']['hours'] as $day => &$remaining) {
            if ($day >= $before || $day >= date('Y-m-d', strtotime('-' . $days . ' days'))) { continue; }
            if (!array_key_exists($day, $GLOBALS['tm']['daily'])) { throw new \RuntimeException('hourly deletion before rollup'); }
            $take = min($remaining, $limit - $count); $remaining -= $take; $count += $take;
            if ($count === $limit) { break; }
        }
        unset($remaining); return $count;
    }
}
namespace {
    require_once dirname(__DIR__) . '/app/services/telemetry_rollup.php';
    use function Gallery\Services\telemetry_run_maintenance;
    use function Gallery\Services\telemetry_run_scheduled_maintenance;
    use function Gallery\Services\telemetry_rollup_checkpoint;
    use const Gallery\Services\TELEMETRY_ROLLUP_CHECKPOINT_KEY;
    /**
     * Reject a violated recovery invariant.
     * @param bool $ok Whether the asserted invariant holds.
     * @param string $why Diagnostic message emitted if the invariant fails.
     * @return void No return value; effects are recorded in the owned state.
     */
    function tm_assert(bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } }
    $old = date('Y-m-d', strtotime('-100 days'));
    $next = date('Y-m-d', strtotime('-99 days'));
    $third = date('Y-m-d', strtotime('-98 days'));
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $GLOBALS['tm'] = ['enabled' => true, 'schema' => 'available', 'schema_reads' => 0,
        'busy' => false, 'locked' => false, 'lock_calls' => 0, 'settings' => [], 'jobs' => [], 'calls' => [],
        'backlog' => ['telemetry_events' => 9], 'hours' => [$old => 3, $next => 3, $third => 3, $yesterday => 6], 'daily' => []];
    $first = telemetry_run_maintenance();
    tm_assert($first['ok'] && $first['has_more'], 'First slice must remain resumable, not claim all history cleaned.');
    tm_assert($first['deleted']['telemetry_events'] === 4 && $GLOBALS['tm']['backlog']['telemetry_events'] === 5, 'Per-table batch cap must bound cleanup.');
    tm_assert($GLOBALS['tm']['calls'][0] === 'delete:telemetry_events', 'Raw retention must run before rollup and thumbnail maintenance.');
    tm_assert(telemetry_rollup_checkpoint() === $third, 'Checkpoint must advance only over successfully archived stable days.');
    tm_assert($GLOBALS['tm']['daily'][$next] === 3 && $GLOBALS['tm']['hours'][$next] === 2, 'Fixture must exercise a partially purged hourly day.');
    tm_assert(!$GLOBALS['tm']['locked'], 'Successful slice must release its own lock.');
    $savedState = $GLOBALS['tm'];
    $GLOBALS['tm']['checkpoint_read_failure'] = true;
    $readFailure = telemetry_run_maintenance();
    tm_assert(!$readFailure['ok'] && $GLOBALS['tm']['daily'][$next] === 3 && $GLOBALS['tm']['hours'][$next] === 2,
        'Failed checkpoint read cannot restart archival from a partially deleted day.');
    tm_assert($GLOBALS['tm']['backlog']['telemetry_events'] < $savedState['backlog']['telemetry_events'],
        'Raw privacy cleanup remains independent of unreadable archival state.');
    $GLOBALS['tm'] = $savedState;
    $GLOBALS['tm']['settings'][TELEMETRY_ROLLUP_CHECKPOINT_KEY] = '2026-02-31';
    $invalidState = telemetry_run_maintenance();
    tm_assert(!$invalidState['ok'] && $GLOBALS['tm']['daily'][$next] === 3 && $GLOBALS['tm']['hours'][$next] === 2,
        'Malformed checkpoint must refuse replay and hourly deletion.');
    $GLOBALS['tm'] = $savedState;
    $GLOBALS['tm']['retention_read_failure'] = true;
    $retentionFailure = telemetry_run_maintenance();
    tm_assert(!$retentionFailure['ok'] && $GLOBALS['tm']['backlog'] === $savedState['backlog'],
        'A retention read error must not silently use a shorter destructive default.');
    $GLOBALS['tm'] = $savedState;
    $GLOBALS['tm']['fail_day'] = $third;
    $failed = telemetry_run_maintenance();
    tm_assert(!$failed['ok'] && $failed['error'] === 'maintenance_failed', 'Rollup failure must be explicit.');
    tm_assert($GLOBALS['tm']['backlog']['telemetry_events'] === 1, 'Raw cleanup must progress even when rollup fails.');
    tm_assert(telemetry_rollup_checkpoint() === $third && $GLOBALS['tm']['hours'][$third] === 3, 'Failed rollup cannot authorize deletion or advance checkpoint.');
    tm_assert(!$GLOBALS['tm']['locked'] && end($GLOBALS['tm']['jobs'])['status'] === 'failed', 'Failure must release the lock and persist terminal evidence.');
    unset($GLOBALS['tm']['fail_day']);
    $GLOBALS['tm']['hours'][$yesterday] = 9; // Delayed beacons inside the admitted 24h horizon.
    $last = telemetry_run_maintenance();
    tm_assert($last['ok'] && $last['has_more'], 'Another slice is required when hourly deletion reaches its batch cap.');
    $last = telemetry_run_maintenance();
    tm_assert($last['ok'] && !$last['has_more'], 'Repeated bounded slices must eventually finish the backlog.');
    tm_assert($GLOBALS['tm']['daily'][$next] === 3, 'Partial hourly deletion must NEVER overwrite a full daily total on resume.');
    tm_assert($GLOBALS['tm']['daily'][$yesterday] === 9, 'Yesterday must be refreshed for late accepted events.');
    tm_assert(telemetry_rollup_checkpoint() === $yesterday, 'Yesterday is refreshable, not permanently closed.');
    $jobs = count($GLOBALS['tm']['jobs']);
    tm_assert(telemetry_run_scheduled_maintenance()['reason'] === 'not_due' && count($GLOBALS['tm']['jobs']) === $jobs, 'Scheduled runs must recheck the independent throttle under lock.');
    tm_assert(!$GLOBALS['tm']['locked'], 'Throttled invocation must release the lock.');
    $GLOBALS['tm']['busy'] = true;
    tm_assert(telemetry_run_maintenance()['reason'] === 'busy' && count($GLOBALS['tm']['jobs']) === $jobs, 'Busy lock must not execute a second job.');
    $GLOBALS['tm']['enabled'] = false; $reads = $GLOBALS['tm']['schema_reads'];
    tm_assert(telemetry_run_maintenance()['reason'] === 'disabled' && $GLOBALS['tm']['schema_reads'] === $reads, 'Master OFF must be non-destructive and schema-lazy.');
    $GLOBALS['tm']['enabled'] = true; $GLOBALS['tm']['schema'] = 'unknown';
    tm_assert(!telemetry_run_scheduled_maintenance()['ok'], 'Unknown schema must be safely reported by the scheduled wrapper.');
    $GLOBALS['tm']['settings'][TELEMETRY_ROLLUP_CHECKPOINT_KEY] = '2026-02-31';
    $refused = false;
    try { telemetry_rollup_checkpoint(); } catch (RuntimeException) { $refused = true; }
    tm_assert($refused, 'Invalid dates cannot authorize replay or hourly purge.');
    echo "Telemetry maintenance recovery fixtures passed.\n";
}
