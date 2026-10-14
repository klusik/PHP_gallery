<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_cleanup_diagnostics.php
 * Module Type: Service
 * Purpose: Correlate bounded cache-worker lifecycle evidence without exposing secrets.
 * Responsibilities: Reuse Admin logging, retain PHP error-log fallback and classify safe failures.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use Throwable;

// These existing owners retain all log persistence and schema-specific SQL.
require_once dirname(__DIR__) . '/models/admin_logs.php';
require_once __DIR__ . '/logs.php';

/**
 * Retain at most twenty-four safe lifecycle events for an authenticated UI response.
 * Type: int. Units: events. Scope: one request/run identifier.
 * Consumers: cache_cleanup_diagnostic_buffer().
 * Rationale: the bounded worker has fewer stages; preserve start and latest evidence without unbounded response growth.
 */
const CACHE_CLEANUP_DIAGNOSTIC_EVENT_LIMIT = 24;

/**
 * Preserve the installed Admin log request-ID schema while keeping full run IDs in context.
 * Type: int. Units: hexadecimal characters. Scope: admin_logs.request_id storage.
 * Consumers: cache-cleanup event metadata.
 * Rationale: migration 202605050001_admin_log_observability defines CHAR(26);
 * the full 32-character non-secret run ID remains searchable in context_json.
 */
const CACHE_CLEANUP_LOG_REQUEST_ID_LENGTH = 26;

/**
 * Retain or read the request-local trace without creating a competing persistent log.
 * @param string $runId Validated correlation identifier.
 * @param array<string,int|float|bool|string>|null $event Optional sanitized event to append.
 * @return list<array<string,int|float|bool|string>> Start and bounded recent events for this invocation.
 */
function cache_cleanup_diagnostic_buffer(string $runId, ?array $event = null): array
{
    static $events = [];
    if ($event !== null) {
        $events[] = $event;
        if (count($events) > CACHE_CLEANUP_DIAGNOSTIC_EVENT_LIMIT) {
            array_splice($events, 1, 1);
        }
    }
    return array_values(array_filter($events, static fn (array $item): bool => $item['run_id'] === $runId));
}

/**
 * Generate or validate a non-secret identifier for one manual cleanup request.
 * @param string $candidate Optional browser-supplied 32-character hexadecimal correlation value.
 * @return string Server-generated or validated correlation identifier, never an authorization token.
 */
function cache_cleanup_diagnostic_id(string $candidate = ''): string
{
    return preg_match('/^[a-f0-9]{32}$/D', $candidate) === 1 ? $candidate : bin2hex(random_bytes(16));
}

/**
 * Classify a cleanup exception without copying raw messages, SQL, paths or credentials.
 * @param Throwable $exception Failure observed at the current owner boundary.
 * @return array{exception_class:string,failure_code:string,exception_site:string,exception_line:int} Safe reason and repository-owned code location, or an external marker.
 */
function cache_cleanup_exception_diagnostic(Throwable $exception): array
{
    $known = [
        'Updater cache cleanup checkpoint is oversized.' => 'checkpoint_oversized',
        'Updater cache cleanup checkpoint is unreadable.' => 'checkpoint_unreadable',
        'Could not open update worker lock.' => 'lock_open_failed',
        'Could not persist update job state.' => 'checkpoint_write_failed',
        'Could not commit update job state.' => 'checkpoint_commit_failed',
        'Updater filesystem root is unavailable.' => 'installation_root_unavailable',
        'Updater filesystem target is not a safe relative path.' => 'unsafe_relative_path',
        'Updater refuses a symbolic link or redirected path in a managed target.' => 'unsafe_link',
        'Updater refuses a redirected path outside its canonical installation location.' => 'unsafe_redirect',
        'Updater target has a non-directory parent.' => 'invalid_parent',
        'Updater target or one of its parent directories is missing.' => 'missing_target',
        'Update cannot be cancelled after activation has begun. Resume or rollback instead.' => 'cancellation_after_activation_or_unsafe_stage',
        'Another application update job is active.' => 'another_updater_job_active',
        'Cache lifecycle path is outside its owner.' => 'cache_path_outside_owner',
        'Cache lifecycle state exceeded its bound.' => 'cache_state_oversized',
        'Cache lifecycle checkpoint could not be committed.' => 'cache_checkpoint_commit_failed',
        'Cache lifecycle lock is unavailable.' => 'cache_lock_unavailable',
        'Cache inventory catalog write failed.' => 'cache_catalog_write_failed',
        'Cache inventory catalog commit failed.' => 'cache_catalog_commit_failed',
        'Cache catalog seek failed.' => 'cache_catalog_seek_failed',
        'Cache catalog read failed.' => 'cache_catalog_read_failed',
        'Cache preview journal recovery failed.' => 'cache_plan_recovery_failed',
        'Cache preview journal lost committed records.' => 'cache_plan_truncated',
        'Cache cleanup plan is unavailable.' => 'cache_plan_unavailable',
        'Cache cleanup plan read failed.' => 'cache_plan_read_failed',
        'Cache cleanup plan seek failed.' => 'cache_plan_seek_failed',
        'Cache preview candidate is invalid.' => 'cache_candidate_invalid',
    ];
    $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
    $file = str_replace('\\', '/', $exception->getFile());
    $site = str_starts_with($file, $root) ? substr($file, strlen($root)) : 'external';
    return ['exception_class' => $exception::class,
        'failure_code' => $known[$exception->getMessage()] ?? 'owner_exception',
        'exception_site' => str_starts_with($site, 'app/') ? $site : 'external',
        'exception_line' => $exception->getLine()];
}

/**
 * Emit one bounded lifecycle event to existing Admin logging and PHP's error-log fallback.
 * @param string $runId Non-secret correlation identifier for this invocation.
 * @param string $stage Owner-controlled lifecycle stage such as started, lock_acquired or failed.
 * @param array<string,int|float|bool|string> $context Safe counters, reason codes and code locations; unsupported keys are discarded.
 * @param Throwable|null $exception Optional failure whose raw message is never logged.
 * @return array<string,int|float|bool|string> Exact sanitized event exposed to the authenticated diagnostic UI.
 */
function cache_cleanup_diagnostic_event(string $runId, string $stage, array $context = [], ?Throwable $exception = null): array
{
    $record = ['run_id' => cache_cleanup_diagnostic_id($runId),
        'stage' => preg_replace('/[^a-z0-9_]/', '_', substr($stage, 0, 48)) ?? 'unknown',
        'recorded_at_unix_ms' => (int) round(microtime(true) * 1000)];
    $allowed = ['source', 'forced', 'ok', 'done', 'skipped', 'blocked', 'reason', 'visited',
        'removed', 'bytes_removed', 'total_bytes_removed', 'elapsed_ms', 'next_due',
        'cursor_depth', 'entry_cap', 'time_cap_ms', 'lock', 'failure_code',
        'failure_operation', 'object_token', 'exception_class', 'exception_site',
        'exception_line', 'error_type', 'http_status', 'request_method', 'elapsed_total_ms', 'central_job_id', 'central_phase',
        'updater_job_id', 'updater_status', 'updater_stage', 'updater_updated_at', 'updater_pointer_state',
        'operation','phase','files','bytes','candidates','candidate_bytes','errors'];
    foreach ($allowed as $key) {
        if (isset($context[$key]) && is_scalar($context[$key])) {
            $record[$key] = is_string($context[$key]) ? substr($context[$key], 0, 160) : $context[$key];
        }
    }
    if ($exception !== null) {
        $record = array_replace($record, cache_cleanup_exception_diagnostic($exception));
    }
    $level = str_ends_with($stage, 'failed') || in_array($stage, ['fatal', 'blocked'], true) ? 'error'
        : ($stage === 'skipped' ? 'warning' : 'info');
    $record['admin_log_attempted'] = false;
    $record['admin_log_written'] = false;
    $record['log_failure_code'] = 'log_schema_unavailable';
    try {
        if (admin_log_schema_ready()) {
            $record['admin_log_attempted'] = true;
            // Only the returned receipt can confirm persistence after insertion.
            // Stored rows must never contain provisional failure defaults.
            $storedContext = array_diff_key($record, array_fill_keys(
                ['admin_log_attempted', 'admin_log_written', 'log_failure_code'], true));
            $receipt = admin_log_event_result($level, 'cache.cleanup.' . $record['stage'],
                (str_starts_with($record['stage'], 'lifecycle') ? 'Cache lifecycle: ' : 'Updater cache cleanup: ') . $record['stage'] . '.', $storedContext,
                ['category' => 'system', 'severity' => $level, 'subject_type' => 'cache_cleanup',
                    'request_id' => substr($record['run_id'], 0, CACHE_CLEANUP_LOG_REQUEST_ID_LENGTH), 'route_name' => 'admin_site_maintenance_settings']);
            $record['admin_log_written'] = $receipt['ok'];
            unset($record['log_failure_code']);
            foreach (['failure_code', 'exception_class', 'sqlstate', 'driver_code', 'field'] as $field) {
                if (isset($receipt[$field])) {
                    $record['log_' . $field] = $receipt[$field];
                }
            }
        }
    } catch (Throwable $exception) {
        $record['log_failure_code'] = 'log_service_exception';
        $record['log_exception_class'] = $exception::class;
    }
    // Always retain a second diagnostic channel: the legacy Admin logger may
    // intentionally suppress storage errors. Neither channel authorizes deletion.
    error_log('PHP Gallery cache cleanup ' . json_encode($record, JSON_UNESCAPED_SLASHES));
    cache_cleanup_diagnostic_buffer($record['run_id'], $record);
    return $record;
}
