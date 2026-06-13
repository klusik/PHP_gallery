<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/site_maintenance.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides cron-safe site maintenance orchestration.
 *
 * Responsibilities:
 *   - Keep automatic maintenance configurable from the Admin dashboard
 *   - Run thumbnail generation through the existing thumbnail service layer
 *   - Persist progress between cron invocations so long jobs survive request limits
 *   - Protect the public cron endpoint with a dedicated secret token
 *   - Run lightweight database and cache cleanup after generated media checks finish
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

namespace Gallery\Services;

use PDO;
use Throwable;
use function Gallery\Controllers\cms_cleanup_password_reset_tokens;
use function Gallery\Core\absolute_public_url;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;
use function Gallery\Core\pending_migrations_exist;
use function Gallery\Core\url_for;

const SITE_MAINTENANCE_STATE_SETTING = 'site_maintenance_run_state';
const SITE_MAINTENANCE_LAST_RESULT_SETTING = 'site_maintenance_last_result';
const SITE_MAINTENANCE_LAST_COMPLETED_DATE_SETTING = 'site_maintenance_last_completed_date';
const SITE_MAINTENANCE_LAST_COMPLETED_AT_SETTING = 'site_maintenance_last_completed_at';
const SITE_MAINTENANCE_REQUEST_TRIGGER_TOUCH_FILE = 'request-trigger.touch';
const SITE_MAINTENANCE_RUNTIME_RESERVE_SECONDS = 8;
const SITE_MAINTENANCE_MIN_SECONDS_BEFORE_WEB_REPAIR = 6.0;
const SITE_MAINTENANCE_WEB_REPAIR_MAX_BYTES = 25165824;
const SITE_MAINTENANCE_WEB_REPAIR_MAX_PIXELS = 36000000;
const SITE_MAINTENANCE_DEFAULT_WINDOW_MINUTES = 180;
const SITE_MAINTENANCE_MIN_WINDOW_MINUTES = 15;
const SITE_MAINTENANCE_MAX_WINDOW_MINUTES = 1440;
const SITE_MAINTENANCE_MIN_CHAIN_SECONDS_LEFT = 10;



/**
 * Return whether scheduled automatic site maintenance is enabled.
 *
 * @return bool True when the condition matches.
 */
function site_maintenance_enabled(): bool
{
    return (string) app_setting('site_maintenance_enabled', '1') !== '0';
}

/**
 * Return a normalized HH:MM UTC maintenance start time.
 *
 * @return string Text result for the caller.
 */
function site_maintenance_utc_time(): string
{
    return site_maintenance_normalize_utc_time((string) app_setting('site_maintenance_utc_time', '00:00'));
}

/**
 * Normalize an admin-submitted UTC time into HH:MM format.
 *
 * @param string $value Value to process.
 * @return string Text result for the caller.
 */
function site_maintenance_normalize_utc_time(string $value): string
{
    $value = trim($value);
    if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $value, $matches) !== 1) {
        return '00:00';
    }

    return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
}

/**
 * Return the configured daily maintenance window length in minutes.
 *
 * @return int Integer result for the caller.
 */
function site_maintenance_window_minutes(): int
{
    return site_maintenance_normalize_window_minutes((int) app_setting('site_maintenance_window_minutes', (string) SITE_MAINTENANCE_DEFAULT_WINDOW_MINUTES));
}

/**
 * Clamp an Admin-submitted maintenance window length to safe bounds.
 *
 * @param int $minutes Minutes value.
 * @return int Integer result for the caller.
 */
function site_maintenance_normalize_window_minutes(int $minutes): int
{
    return max(SITE_MAINTENANCE_MIN_WINDOW_MINUTES, min(SITE_MAINTENANCE_MAX_WINDOW_MINUTES, $minutes));
}

/**
 * Convert an Admin-submitted hour value to bounded maintenance-window minutes.
 *
 * @param string $value Value to process.
 * @return int Integer result for the caller.
 */
function site_maintenance_window_hours_to_minutes(string $value): int
{
    $normalized = str_replace(',', '.', trim($value));
    if ($normalized === '' || !is_numeric($normalized)) {
        return SITE_MAINTENANCE_DEFAULT_WINDOW_MINUTES;
    }

    return site_maintenance_normalize_window_minutes((int) round(((float) $normalized) * 60));
}

/**
 * Return an HTML form value for the maintenance window expressed in hours.
 *
 * @param int $minutes Minutes value.
 * @return string Text result for the caller.
 */
function site_maintenance_window_hours_value(int $minutes): string
{
    $value = number_format(site_maintenance_normalize_window_minutes($minutes) / 60, 2, '.', '');
    return rtrim(rtrim($value, '0'), '.');
}

/**
 * Return the maximum number of images checked in one persisted thumbnail step.
 *
 * @return int Integer result for the caller.
 */
function site_maintenance_batch_size(): int
{
    return max(1, min(50, (int) app_setting('site_maintenance_batch_size', '20')));
}

/**
 * Return the per-invocation runtime budget in seconds.
 *
 * @return int Integer result for the caller.
 */
function site_maintenance_time_budget_seconds(): int
{
    return max(3, min(120, (int) app_setting('site_maintenance_time_budget_seconds', '20')));
}

/**
 * Return the PHP request execution limit in seconds, or zero when unlimited.
 *
 * @return int Integer result for the caller.
 */
function site_maintenance_php_max_execution_seconds(): int
{
    $limit = (int) ini_get('max_execution_time');
    return max(0, $limit);
}

/**
 * Return a time budget that leaves room before PHP can terminate the request.
 *
 * @param int $requestedSeconds Requested seconds value.
 * @return int Integer result for the caller.
 */
function site_maintenance_effective_time_budget_seconds(int $requestedSeconds): int
{
    $requestedSeconds = max(1, min(120, $requestedSeconds));
    if (PHP_SAPI === 'cli') {
        return $requestedSeconds;
    }

    $phpLimit = site_maintenance_php_max_execution_seconds();
    if ($phpLimit <= 0) {
        return $requestedSeconds;
    }

    $safeLimit = max(1, $phpLimit - SITE_MAINTENANCE_RUNTIME_RESERVE_SECONDS);
    return max(1, min($requestedSeconds, $safeLimit));
}

/**
 * Return a smaller budget for the Admin button, which runs inside the browser request.
 *
 * @return int Integer result for the caller.
 */
function site_maintenance_manual_time_budget_seconds(): int
{
    return min(12, site_maintenance_effective_time_budget_seconds(site_maintenance_time_budget_seconds()));
}

/**
 * Return true while there is enough runtime left to start another image check.
 *
 * @param float $deadline Deadline value.
 * @return bool True when the condition matches.
 */
function site_maintenance_has_runtime(float $deadline): bool
{
    return microtime(true) < ($deadline - 0.25);
}

/**
 * Return the approximate seconds left in this maintenance invocation.
 *
 * @param float $deadline Deadline value.
 * @return float Numeric result for the caller.
 */
function site_maintenance_runtime_remaining_seconds(float $deadline): float
{
    return max(0.0, $deadline - microtime(true));
}

/**
 * Return whether a web request still has enough room to start one thumbnail repair.
 *
 * @param float $deadline Deadline value.
 * @return bool True when the condition matches.
 */
function site_maintenance_has_web_repair_runtime(float $deadline): bool
{
    if (PHP_SAPI === 'cli') {
        return site_maintenance_runtime_remaining_seconds($deadline) > 1.0;
    }

    return site_maintenance_runtime_remaining_seconds($deadline) >= SITE_MAINTENANCE_MIN_SECONDS_BEFORE_WEB_REPAIR;
}


/**
 * Return whether normal page requests may trigger scheduled maintenance after the response.
 *
 * @return bool True when the condition matches.
 */
function site_maintenance_request_trigger_enabled(): bool
{
    return (string) app_setting('site_maintenance_request_trigger_enabled', '1') !== '0';
}

/**
 * Return the minimum delay between automatic request-trigger attempts.
 *
 * @return int Integer result for the caller.
 */
function site_maintenance_request_trigger_interval_seconds(): int
{
    return max(30, min(1800, (int) app_setting('site_maintenance_request_trigger_interval_seconds', '60')));
}

/**
 * Persist Admin-configurable maintenance settings.
 *
 * @param bool $enabled Enabled flag.
 * @param string $utcTime Utc time value.
 * @param int $batchSize Batch size value.
 * @param int $timeBudgetSeconds Time budget seconds value.
 * @param bool $requestTriggerEnabled Request trigger enabled value.
 * @param int $windowMinutes Window minutes value.
 */
function set_site_maintenance_settings(bool $enabled, string $utcTime, int $batchSize, int $timeBudgetSeconds, bool $requestTriggerEnabled = true, int $windowMinutes = SITE_MAINTENANCE_DEFAULT_WINDOW_MINUTES): void
{
    set_app_setting('site_maintenance_enabled', $enabled ? '1' : '0');
    set_app_setting('site_maintenance_utc_time', site_maintenance_normalize_utc_time($utcTime));
    set_app_setting('site_maintenance_batch_size', (string) max(1, min(50, $batchSize)));
    set_app_setting('site_maintenance_time_budget_seconds', (string) max(3, min(120, $timeBudgetSeconds)));
    set_app_setting('site_maintenance_request_trigger_enabled', $requestTriggerEnabled ? '1' : '0');
    set_app_setting('site_maintenance_window_minutes', (string) site_maintenance_normalize_window_minutes($windowMinutes));
}

/**
 * Return the persistent secret used by the public cron URL.
 *
 * @return string Text result for the caller.
 */
function site_maintenance_token(): string
{
    $token = trim((string) app_setting('site_maintenance_token', ''));
    if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token) === 1) {
        return $token;
    }

    $token = bin2hex(random_bytes(32));
    set_app_setting('site_maintenance_token', $token);
    return $token;
}

/**
 * Replace the public cron token and return the new token.
 *
 * @return string Text result for the caller.
 */
function site_maintenance_rotate_token(): string
{
    $token = bin2hex(random_bytes(32));
    set_app_setting('site_maintenance_token', $token);
    return $token;
}

/**
 * Return true when a submitted cron token matches the stored secret.
 *
 * @param string $token Token value.
 * @return bool True when the condition matches.
 */
function site_maintenance_token_is_valid(string $token): bool
{
    $token = trim($token);
    return $token !== '' && hash_equals(site_maintenance_token(), $token);
}

/**
 * Return the query-string cron endpoint URL displayed in Admin.
 *
 * @return string Text result for the caller.
 */
function site_maintenance_cron_url(): string
{
    return absolute_public_url(url_for('site_maintenance_cron', ['token' => site_maintenance_token()]));
}

/**
 * Return the cache directory used for the site-maintenance lock file.
 *
 * @return string Text result for the caller.
 */
function site_maintenance_cache_dir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'site-maintenance';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

/**
 * Return the non-waiting lock file path for site maintenance.
 *
 * @return string Text result for the caller.
 */
function site_maintenance_lock_path(): string
{
    return site_maintenance_cache_dir() . DIRECTORY_SEPARATOR . 'worker.lock';
}

/**
 * Read the persisted maintenance run state.
 *
 * @return array<string mixed>.
 */
function site_maintenance_state(): array
{
    $json = trim((string) app_setting(SITE_MAINTENANCE_STATE_SETTING, ''));
    if ($json === '') {
        return [];
    }

    $state = json_decode($json, true);
    return is_array($state) ? $state : [];
}

/**
 * Persist the current maintenance run state.
 *
 * @param array $state State value.
 */
function site_maintenance_save_state(array $state): void
{
    $state['updated_at'] = now_sql();
    set_app_setting(SITE_MAINTENANCE_STATE_SETTING, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Reset an interrupted maintenance run without changing the daily completion marker.
 */
function site_maintenance_reset_state(): void
{
    delete_app_settings([SITE_MAINTENANCE_STATE_SETTING]);
}

/**
 * Decode the last stored maintenance result for Admin display.
 *
 * @return array<string mixed>.
 */
function site_maintenance_last_result(): array
{
    $json = trim((string) app_setting(SITE_MAINTENANCE_LAST_RESULT_SETTING, ''));
    if ($json === '') {
        return [];
    }

    $result = json_decode($json, true);
    return is_array($result) ? $result : [];
}

/**
 * Store a compact result summary for Admin display.
 *
 * @param array $result Result value.
 */
function site_maintenance_store_last_result(array $result): void
{
    set_app_setting(SITE_MAINTENANCE_LAST_RESULT_SETTING, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

/**
 * Return the maintenance schedule state for the current UTC day.
 *
 * @param ?int $now Now value.
 * @return array{due:bool,within_window:bool,date:string,scheduled_at:string,window_ends_at:string,seconds_until:int,seconds_until_window_end:int} Structured result data for the caller.
 */
function site_maintenance_schedule_due_state(?int $now = null): array
{
    $now = $now ?? time();
    $time = site_maintenance_utc_time();
    $windowSeconds = site_maintenance_window_minutes() * 60;
    $today = gmdate('Y-m-d', $now);
    $todayStart = strtotime($today . ' ' . $time . ':00 UTC');
    if ($todayStart === false) {
        $todayStart = strtotime($today . ' 00:00:00 UTC') ?: $now;
    }

    $candidateStarts = [$todayStart, $todayStart - 86400];
    $activeStart = null;
    foreach ($candidateStarts as $candidateStart) {
        if ($now >= $candidateStart && $now < ($candidateStart + $windowSeconds)) {
            $activeStart = $activeStart === null ? $candidateStart : max($activeStart, $candidateStart);
        }
    }

    if ($activeStart !== null) {
        $windowEnd = $activeStart + $windowSeconds;
        return [
            'due' => true,
            'within_window' => true,
            'date' => gmdate('Y-m-d', $activeStart),
            'scheduled_at' => gmdate('Y-m-d H:i:s', $activeStart),
            'window_ends_at' => gmdate('Y-m-d H:i:s', $windowEnd),
            'seconds_until' => 0,
            'seconds_until_window_end' => max(0, $windowEnd - $now),
        ];
    }

    $nextStart = $now < $todayStart ? $todayStart : $todayStart + 86400;
    $referenceStart = $now >= $todayStart ? $todayStart : $nextStart;
    $referenceEnd = $referenceStart + $windowSeconds;

    return [
        'due' => $now >= $todayStart,
        'within_window' => false,
        'date' => gmdate('Y-m-d', $referenceStart),
        'scheduled_at' => gmdate('Y-m-d H:i:s', $referenceStart),
        'window_ends_at' => gmdate('Y-m-d H:i:s', $referenceEnd),
        'seconds_until' => max(0, $nextStart - $now),
        'seconds_until_window_end' => 0,
    ];
}

/**
 * Return a fresh running state for one scheduled or manually forced cycle.
 *
 * @param string $cycleDate Cycle date value.
 * @param string $source Source value.
 * @return array<string mixed>.
 */
function site_maintenance_new_state(string $cycleDate, string $source): array
{
    return [
        'version' => 2,
        'status' => 'running',
        'cycle_date' => $cycleDate,
        'source' => $source,
        'phase' => 'thumbnails',
        'window_paused_at' => null,
        'cursor_image_id' => 0,
        'current_image_id' => 0,
        'current_image_started_at' => null,
        'started_at' => now_sql(),
        'updated_at' => now_sql(),
        'finished_at' => null,
        'last_step_at' => null,
        'last_step_summary' => [],
        'totals' => site_maintenance_empty_totals(),
        'cleanup' => [],
        'thumbnail_metadata_start_snapshot' => function_exists('Gallery\\Services\\thumbnail_metadata_storage_snapshot') ? thumbnail_metadata_storage_snapshot() : [],
    ];
}

/**
 * Return zero counters for a maintenance run.
 *
 * @return array<string mixed>.
 */
function site_maintenance_empty_totals(): array
{
    return [
        'images_seen' => 0,
        'images_processed' => 0,
        'images_interrupted' => 0,
        'images_with_repairs_needed' => 0,
        'images_deferred' => 0,
        'thumbs_created' => 0,
        'thumbs_skipped' => 0,
        'variants_missing_or_invalid' => 0,
        'webp_skipped' => 0,
        'failed' => 0,
        'invalid_geometry_deleted' => 0,
        'metadata_rows_refreshed' => 0,
        'metadata_source_syncs' => 0,
        'errors' => [],
    ];
}


/**
 * Return the total number of original image rows in the gallery library.
 *
 * @return int Integer result for the caller.
 */
function site_maintenance_total_source_image_count(): int
{
    try {
        $stmt = db()->query("SELECT COUNT(*) FROM images WHERE relative_path NOT LIKE '%/%'");
        return max(0, (int) $stmt->fetchColumn());
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Add one error message to a bounded diagnostic list.
 *
 * @param array $errors Error messages for the caller.
 * @param string $error Error value.
 * @return array<int string>.
 */
function site_maintenance_append_error(array $errors, string $error): array
{
    $error = trim($error);
    if ($error === '') {
        return $errors;
    }

    $errors[] = mb_substr($error, 0, 300);
    $errors = array_values(array_unique($errors));
    return array_slice($errors, 0, 20);
}

/**
 * Run maintenance with a non-waiting filesystem lock.
 *
 * @param callable $callback Callback invoked by this workflow.
 * @return array<string mixed>.
 */
function site_maintenance_with_lock(callable $callback): array
{
    $handle = @fopen(site_maintenance_lock_path(), 'c');
    if (!$handle) {
        return ['ok' => false, 'busy' => false, 'error' => 'Could not open site maintenance lock file.'];
    }

    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return ['ok' => true, 'busy' => true, 'skipped' => true, 'reason' => 'maintenance_already_running'];
    }

    try {
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}


/**
 * Return the touch-file path used to throttle automatic request-trigger attempts.
 *
 * @return string Text result for the caller.
 */
function site_maintenance_request_trigger_touch_path(): string
{
    return site_maintenance_cache_dir() . DIRECTORY_SEPARATOR . SITE_MAINTENANCE_REQUEST_TRIGGER_TOUCH_FILE;
}

/**
 * Return whether the request trigger was attempted recently enough to skip it now.
 *
 * @param ?int $now Now value.
 * @return bool True when the condition matches.
 */
function site_maintenance_request_trigger_recently_attempted(?int $now = null): bool
{
    $now = $now ?? time();
    $path = site_maintenance_request_trigger_touch_path();
    if (!is_file($path)) {
        return false;
    }

    $mtime = filemtime($path) ?: 0;
    return $mtime > 0 && ($now - $mtime) < site_maintenance_request_trigger_interval_seconds();
}

/**
 * Mark that a request-triggered maintenance attempt was made.
 */
function site_maintenance_mark_request_trigger_attempt(): void
{
    @touch(site_maintenance_request_trigger_touch_path());
}

/**
 * Return whether a route is suitable for opportunistic maintenance after response.
 *
 * @param string $page Page number or page data.
 * @return bool True when the condition matches.
 */
function site_maintenance_route_allows_request_trigger(string $page): bool
{
    $excludedPrefixes = [
        'admin_',
        'gallery_migration_',
        'picture_manager_',
        'upload_automation_',
    ];
    $excludedPages = [
        'admin',
        'admin_login',
        'gallery',
        'home',
        'share',
        'tag',
    ];

    if (in_array($page, $excludedPages, true)) {
        return true;
    }

    foreach ($excludedPrefixes as $prefix) {
        if (str_starts_with($page, $prefix)) {
            return false;
        }
    }

    return false;
}

/**
 * Return whether automatic request-triggered maintenance is due right now.
 *
 * @param ?int $now Now value.
 * @return bool True when the condition matches.
 */
function site_maintenance_request_trigger_due(?int $now = null): bool
{
    if (!site_maintenance_enabled() || !site_maintenance_request_trigger_enabled()) {
        return false;
    }

    if (site_maintenance_request_trigger_recently_attempted($now)) {
        return false;
    }

    $schedule = site_maintenance_schedule_due_state($now);
    if (empty($schedule['within_window'])) {
        return false;
    }

    $state = site_maintenance_state();
    if ((string) ($state['status'] ?? '') === 'running') {
        return true;
    }

    $lastCompletedDate = trim((string) app_setting(SITE_MAINTENANCE_LAST_COMPLETED_DATE_SETTING, ''));
    return $lastCompletedDate !== $schedule['date'];
}


/**
 * Fire the hidden web cron endpoint without waiting for its JSON response.
 *
 * @param array $queryParams Query params value.
 * @return bool True when the condition matches.
 */
function site_maintenance_fire_web_cron_slice(array $queryParams = []): bool
{
    $queryParams = array_merge([
        'source' => 'request_trigger',
        'time_budget' => site_maintenance_time_budget_seconds(),
    ], $queryParams);
    $url = site_maintenance_cron_url();
    $separator = str_contains($url, '?') ? '&' : '?';
    $url .= $separator . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
        return false;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $host = (string) $parts['host'];
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $target = (string) $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $transport = $scheme === 'https' ? 'ssl://' . $host : $host;
    $handle = @fsockopen($transport, $port, $errno, $errstr, 2.0);
    if (!$handle) {
        return false;
    }

    stream_set_timeout($handle, 2);
    $hostHeader = $host . (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80) ? ':' . $port : '');
    $request = "GET " . $target . " HTTP/1.1
";
    $request .= "Host: " . $hostHeader . "
";
    $request .= "User-Agent: PHP-Gallery-Site-Maintenance
";
    $request .= "Connection: Close

";
    fwrite($handle, $request);
    fclose($handle);
    return true;
}

/**
 * Finish the visible HTTP response before doing opportunistic background work when the server supports it.
 */
function site_maintenance_finish_response_before_background_work(): void
{
    static $finished = false;
    if ($finished || PHP_SAPI === 'cli') {
        return;
    }

    $finished = true;
    if (function_exists('fastcgi_finish_request')) {
        @fastcgi_finish_request();
        return;
    }

    if (function_exists('litespeed_finish_request')) {
        @litespeed_finish_request();
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
}

/**
 * Return whether a maintenance result should immediately queue another safe web slice.
 *
 * @param array $result Result value.
 * @return bool True when the condition matches.
 */
function site_maintenance_should_chain_after_result(array $result): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    if (!site_maintenance_enabled() || !site_maintenance_request_trigger_enabled()) {
        return false;
    }

    if (empty($result['ok']) || !empty($result['busy']) || empty($result['has_more'])) {
        return false;
    }

    $schedule = site_maintenance_schedule_due_state();
    if (empty($schedule['within_window'])) {
        return false;
    }

    return (int) ($schedule['seconds_until_window_end'] ?? 0) >= SITE_MAINTENANCE_MIN_CHAIN_SECONDS_LEFT;
}

/**
 * Queue the next web maintenance slice so a daily window can keep moving without another visitor.
 *
 * @return bool True when the condition matches.
 */
function site_maintenance_queue_next_chained_slice(): bool
{
    return site_maintenance_fire_web_cron_slice([
        'source' => 'maintenance_chain',
        'time_budget' => site_maintenance_time_budget_seconds(),
        'chain' => 1,
    ]);
}

/**
 * Register an after-response maintenance slice for suitable normal page requests.
 *
 * @param string $page Page number or page data.
 */
function site_maintenance_register_request_trigger(string $page): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    if (!site_maintenance_route_allows_request_trigger($page) || !site_maintenance_request_trigger_due()) {
        return;
    }

    register_shutdown_function(static function (): void {
        if (!site_maintenance_request_trigger_due()) {
            return;
        }

        site_maintenance_mark_request_trigger_attempt();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ignore_user_abort(true);
        site_maintenance_finish_response_before_background_work();
        site_maintenance_run([
            'source' => 'request_trigger_inline',
            'force' => false,
            'time_budget_seconds' => site_maintenance_time_budget_seconds(),
            'chain' => true,
        ]);
    });
}

/**
 * Run one cron-safe maintenance invocation.
 *
 * The caller should invoke this often, for example every five minutes. The
 * configured UTC time only decides when a new daily cycle may start. Active work
 * continues across later calls until all phases complete.
 *
 * @param array $options Optional behavior flags.
 * @return array<string mixed>.
 */
function site_maintenance_run(array $options = []): array
{
    $force = !empty($options['force']);
    $chain = array_key_exists('chain', $options) ? !empty($options['chain']) : false;
    $source = trim((string) ($options['source'] ?? 'cron'));
    $requestedTimeBudgetSeconds = isset($options['time_budget_seconds'])
        ? max(1, min(120, (int) $options['time_budget_seconds']))
        : site_maintenance_time_budget_seconds();
    $timeBudgetSeconds = site_maintenance_effective_time_budget_seconds($requestedTimeBudgetSeconds);

    @set_time_limit(max(30, $timeBudgetSeconds + SITE_MAINTENANCE_RUNTIME_RESERVE_SECONDS));

    $result = site_maintenance_with_lock(static function () use ($force, $source, $timeBudgetSeconds): array {
        if (!site_maintenance_enabled() && !$force) {
            return [
                'ok' => true,
                'busy' => false,
                'skipped' => true,
                'reason' => 'maintenance_disabled',
                'enabled' => false,
            ];
        }

        $state = site_maintenance_state();
        $isRunning = (string) ($state['status'] ?? '') === 'running';
        $schedule = site_maintenance_schedule_due_state();
        $lastCompletedDate = trim((string) app_setting(SITE_MAINTENANCE_LAST_COMPLETED_DATE_SETTING, ''));

        if (!$force && empty($schedule['within_window'])) {
            if ($isRunning) {
                $state['window_paused_at'] = now_sql();
                site_maintenance_save_state($state);
            }

            return [
                'ok' => true,
                'busy' => false,
                'skipped' => true,
                'has_more' => $isRunning,
                'reason' => $isRunning ? 'outside_maintenance_window_active_cycle_paused' : 'outside_maintenance_window',
                'enabled' => site_maintenance_enabled(),
                'scheduled_at_utc' => $schedule['scheduled_at'],
                'window_ends_at_utc' => $schedule['window_ends_at'],
                'seconds_until' => $schedule['seconds_until'],
                'last_completed_date' => $lastCompletedDate,
                'state' => $state ? site_maintenance_public_state($state) : [],
            ];
        }

        if (!$isRunning) {
            if (!$force && (!$schedule['due'] || $lastCompletedDate === $schedule['date'])) {
                return [
                    'ok' => true,
                    'busy' => false,
                    'skipped' => true,
                    'reason' => $lastCompletedDate === $schedule['date'] ? 'already_completed_today' : 'not_due_yet',
                    'enabled' => site_maintenance_enabled(),
                    'scheduled_at_utc' => $schedule['scheduled_at'],
                    'window_ends_at_utc' => $schedule['window_ends_at'],
                    'seconds_until' => $schedule['seconds_until'],
                    'last_completed_date' => $lastCompletedDate,
                ];
            }

            $cycleDate = $force ? 'manual-' . gmdate('Y-m-d-His') : $schedule['date'];
            $state = site_maintenance_new_state($cycleDate, $source);
            site_maintenance_save_state($state);
            site_maintenance_log_event('info', 'site_maintenance.started', 'Site maintenance cycle started.', [
                'cycle_date' => $cycleDate,
                'source' => $source,
                'forced' => $force,
                'batch_size' => site_maintenance_batch_size(),
                'time_budget_seconds' => $timeBudgetSeconds,
                'window_minutes' => site_maintenance_window_minutes(),
                'window_ends_at_utc' => $schedule['window_ends_at'],
                'php_max_execution_time' => site_maintenance_php_max_execution_seconds(),
                'thumbnail_metadata_start_snapshot' => is_array($state['thumbnail_metadata_start_snapshot'] ?? null) ? $state['thumbnail_metadata_start_snapshot'] : [],
            ]);
        }

        try {
            $result = site_maintenance_run_active_state($state, $timeBudgetSeconds, $force);
            site_maintenance_store_last_result($result);
            return $result;
        } catch (Throwable $exception) {
            $state['status'] = 'failed';
            $state['finished_at'] = now_sql();
            $state['error'] = $exception->getMessage();
            site_maintenance_save_state($state);
            $result = [
                'ok' => false,
                'busy' => false,
                'done' => false,
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'state' => site_maintenance_public_state($state),
            ];
            site_maintenance_store_last_result($result);
            site_maintenance_log_event('error', 'site_maintenance.failed', 'Site maintenance cycle failed.', [
                'error' => $exception->getMessage(),
                'exception_class' => get_class($exception),
                'state' => site_maintenance_public_state($state),
            ], ['severity' => 'error']);
            return $result;
        }
    });

    if ($chain && site_maintenance_should_chain_after_result($result)) {
        $result['continuation_queued'] = site_maintenance_queue_next_chained_slice();
    }

    return $result;
}


/**
 * Continue the active maintenance state until the time budget is almost exhausted.
 *
 * @param array $state State value.
 * @param int $timeBudgetSeconds Time budget seconds value.
 * @param bool $forced Forced value.
 * @return array<string mixed>.
 */
function site_maintenance_run_active_state(array $state, int $timeBudgetSeconds, bool $forced): array
{
    $deadline = microtime(true) + max(1, $timeBudgetSeconds);
    $steps = 0;
    $batchResults = [];

    while (site_maintenance_has_runtime($deadline)) {
        $steps++;
        $stepResult = site_maintenance_run_one_step($state, $deadline);
        $batchResults[] = $stepResult;
        site_maintenance_save_state($state);

        if ((string) ($state['status'] ?? '') !== 'running') {
            break;
        }
        if (empty($stepResult['worked'])) {
            break;
        }
    }

    $done = (string) ($state['status'] ?? '') === 'complete';
    $result = [
        'ok' => true,
        'busy' => false,
        'skipped' => false,
        'done' => $done,
        'has_more' => !$done,
        'status' => (string) ($state['status'] ?? 'running'),
        'phase' => (string) ($state['phase'] ?? ''),
        'cycle_date' => (string) ($state['cycle_date'] ?? ''),
        'forced' => $forced,
        'steps' => $steps,
        'batch_results' => array_slice($batchResults, -5),
        'state' => site_maintenance_public_state($state),
        'schedule' => site_maintenance_schedule_due_state(),
    ];

    if ($done) {
        $cycleDate = (string) ($state['cycle_date'] ?? '');
        if ($cycleDate !== '' && !str_starts_with($cycleDate, 'manual-')) {
            $completionSchedule = site_maintenance_schedule_due_state();
            $completionDate = (string) ($completionSchedule['date'] ?? $cycleDate);
            set_app_setting(SITE_MAINTENANCE_LAST_COMPLETED_DATE_SETTING, $completionDate !== '' ? $completionDate : $cycleDate);
        }
        set_app_setting(SITE_MAINTENANCE_LAST_COMPLETED_AT_SETTING, now_sql());
        thumbnail_maintenance_summary_cache_clear();
        $state['thumbnail_metadata_end_snapshot'] = function_exists('Gallery\\Services\\thumbnail_metadata_storage_snapshot') ? thumbnail_metadata_storage_snapshot() : [];
        site_maintenance_save_state($state);
        site_maintenance_log_event('info', 'site_maintenance.completed', 'Site maintenance cycle completed.', [
            'cycle_date' => $cycleDate,
            'state' => site_maintenance_public_state($state),
            'thumbnail_metadata_start_snapshot' => is_array($state['thumbnail_metadata_start_snapshot'] ?? null) ? $state['thumbnail_metadata_start_snapshot'] : [],
            'thumbnail_metadata_end_snapshot' => is_array($state['thumbnail_metadata_end_snapshot'] ?? null) ? $state['thumbnail_metadata_end_snapshot'] : [],
            'duration_from_state_seconds' => site_maintenance_state_duration_seconds($state),
        ]);
    }

    return $result;
}

/**
 * Run one persisted maintenance step and mutate the supplied state.
 *
 * @param array $state State value.
 * @param float $deadline Deadline value.
 * @return array<string mixed>.
 */
function site_maintenance_run_one_step(array &$state, float $deadline): array
{
    $phase = (string) ($state['phase'] ?? 'thumbnails');
    if ($phase === 'thumbnails') {
        return site_maintenance_process_thumbnail_step($state, $deadline);
    }

    if ($phase === 'cleanups') {
        return site_maintenance_process_cleanup_step($state, $deadline);
    }

    $state['status'] = 'complete';
    $state['finished_at'] = now_sql();
    return ['worked' => false, 'phase' => 'complete'];
}

/**
 * Process a bounded thumbnail check and repair step.
 *
 * @param array $state State value.
 * @param float $deadline Deadline value.
 * @return array<string mixed>.
 */
function site_maintenance_process_thumbnail_step(array &$state, float $deadline): array
{
    site_maintenance_record_interrupted_thumbnail_attempt($state);

    $cursorImageId = max(0, (int) ($state['cursor_image_id'] ?? 0));
    $batchSize = site_maintenance_batch_size();
    $stmt = db()->prepare("SELECT i.* FROM images i WHERE i.relative_path NOT LIKE '%/%' AND i.id > ? ORDER BY i.id LIMIT ?");
    $stmt->bindValue(1, $cursorImageId, PDO::PARAM_INT);
    $stmt->bindValue(2, $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $images = $stmt->fetchAll();

    if (!$images) {
        $state['phase'] = 'cleanups';
        $state['cursor_image_id'] = 0;
        $state['current_image_id'] = 0;
        $state['current_image_started_at'] = null;
        site_maintenance_log_event('info', 'site_maintenance.thumbnails_checked', 'Site maintenance thumbnail scan finished.', [
            'cycle_date' => (string) ($state['cycle_date'] ?? ''),
            'totals' => is_array($state['totals'] ?? null) ? $state['totals'] : site_maintenance_empty_totals(),
            'thumbnail_metadata_snapshot' => function_exists('Gallery\\Services\\thumbnail_metadata_storage_snapshot') ? thumbnail_metadata_storage_snapshot() : [],
        ]);
        return ['worked' => true, 'phase' => 'thumbnails', 'processed_images' => 0, 'seen_images' => 0, 'next_phase' => 'cleanups'];
    }

    $totals = is_array($state['totals'] ?? null) ? $state['totals'] : site_maintenance_empty_totals();
    $galleryCache = [];
    $step = site_maintenance_empty_totals();
    $stepStartedAt = microtime(true);
    $stepImageIds = [];

    foreach ($images as $image) {
        if (!site_maintenance_has_runtime($deadline)) {
            break;
        }

        $imageId = (int) ($image['id'] ?? 0);
        if ($imageId <= 0) {
            continue;
        }
        $stepImageIds[] = $imageId;

        // These snapshots let runtime pauses retry the same image in the next slice.
        $previousCursorImageId = (int) ($state['cursor_image_id'] ?? 0);
        $totalsBeforeImage = $totals;
        $stepBeforeImage = $step;
        $galleryId = (int) ($image['gallery_id'] ?? 0);
        if (!array_key_exists($galleryId, $galleryCache)) {
            $galleryCache[$galleryId] = $galleryId > 0 ? find_gallery($galleryId) : null;
        }

        $state['cursor_image_id'] = max((int) ($state['cursor_image_id'] ?? 0), $imageId);
        $state['current_image_id'] = $imageId;
        $state['current_image_started_at'] = now_sql();
        $totals['images_seen'] = (int) ($totals['images_seen'] ?? 0) + 1;
        $step['images_seen'] = (int) ($step['images_seen'] ?? 0) + 1;
        $state['totals'] = $totals;
        site_maintenance_save_state($state);

        if (!$galleryCache[$galleryId]) {
            $message = 'Gallery row was not found for image id ' . $imageId . '.';
            $totals['failed'] = (int) ($totals['failed'] ?? 0) + 1;
            $step['failed'] = (int) ($step['failed'] ?? 0) + 1;
            $totals['errors'] = site_maintenance_append_error((array) ($totals['errors'] ?? []), $message);
            $step['errors'] = site_maintenance_append_error((array) ($step['errors'] ?? []), $message);
            site_maintenance_finish_current_image($state, $totals);
            continue;
        }

        $status = thumbnail_maintenance_status($image, $galleryCache[$galleryId]);
        $required = max(0, (int) ($status['required'] ?? 0));
        $missing = max(0, (int) ($status['missing'] ?? 0));
        $validVariants = max(0, $required - $missing);
        $invalidDeleted = max(0, (int) ($status['invalid_geometry_deleted'] ?? 0));

        $metadataRowsWritten = (int) ($status['metadata_rows_written'] ?? 0);
        $metadataSourceSyncs = (int) ($status['metadata_source_syncs'] ?? 0);
        $totals['webp_skipped'] = (int) ($totals['webp_skipped'] ?? 0) + (int) ($status['webp_skipped'] ?? 0);
        $totals['invalid_geometry_deleted'] = (int) ($totals['invalid_geometry_deleted'] ?? 0) + $invalidDeleted;
        $totals['metadata_rows_refreshed'] = (int) ($totals['metadata_rows_refreshed'] ?? 0) + $metadataRowsWritten;
        $totals['metadata_source_syncs'] = (int) ($totals['metadata_source_syncs'] ?? 0) + $metadataSourceSyncs;
        $step['webp_skipped'] = (int) ($step['webp_skipped'] ?? 0) + (int) ($status['webp_skipped'] ?? 0);
        $step['invalid_geometry_deleted'] = (int) ($step['invalid_geometry_deleted'] ?? 0) + $invalidDeleted;
        $step['metadata_rows_refreshed'] = (int) ($step['metadata_rows_refreshed'] ?? 0) + $metadataRowsWritten;
        $step['metadata_source_syncs'] = (int) ($step['metadata_source_syncs'] ?? 0) + $metadataSourceSyncs;

        if ($missing <= 0) {
            $totals['thumbs_skipped'] = (int) ($totals['thumbs_skipped'] ?? 0) + $validVariants;
            $step['thumbs_skipped'] = (int) ($step['thumbs_skipped'] ?? 0) + $validVariants;
            site_maintenance_finish_current_image($state, $totals);
            continue;
        }

        $totals['images_with_repairs_needed'] = (int) ($totals['images_with_repairs_needed'] ?? 0) + 1;
        $totals['variants_missing_or_invalid'] = (int) ($totals['variants_missing_or_invalid'] ?? 0) + $missing;
        $step['images_with_repairs_needed'] = (int) ($step['images_with_repairs_needed'] ?? 0) + 1;
        $step['variants_missing_or_invalid'] = (int) ($step['variants_missing_or_invalid'] ?? 0) + $missing;

        $repairDecision = site_maintenance_thumbnail_repair_decision($image, $galleryCache[$galleryId], $deadline, (string) ($state['source'] ?? ''));
        if (empty($repairDecision['allowed'])) {
            if ((string) ($repairDecision['reason'] ?? '') === 'not_enough_runtime_left') {
                $totals = $totalsBeforeImage;
                $step = $stepBeforeImage;
                array_pop($stepImageIds);
                $state['cursor_image_id'] = $previousCursorImageId;
                $state['current_image_id'] = 0;
                $state['current_image_started_at'] = null;
                $state['totals'] = $totals;
                site_maintenance_save_state($state);
                break;
            }
            $message = 'Deferred thumbnail repair for image id ' . $imageId . ': ' . (string) ($repairDecision['reason'] ?? 'not_allowed');
            $totals['images_deferred'] = (int) ($totals['images_deferred'] ?? 0) + 1;
            $totals['thumbs_skipped'] = (int) ($totals['thumbs_skipped'] ?? 0) + $validVariants;
            $step['images_deferred'] = (int) ($step['images_deferred'] ?? 0) + 1;
            $step['thumbs_skipped'] = (int) ($step['thumbs_skipped'] ?? 0) + $validVariants;
            $totals['errors'] = site_maintenance_append_error((array) ($totals['errors'] ?? []), $message);
            $step['errors'] = site_maintenance_append_error((array) ($step['errors'] ?? []), $message);
            site_maintenance_finish_current_image($state, $totals);
            continue;
        }

        $result = create_image_thumbnails_result($image, $galleryCache[$galleryId], null, [
            'prefer_imagick_webp_exif' => false,
        ]);

        $totals['images_processed'] = (int) ($totals['images_processed'] ?? 0) + 1;
        $totals['thumbs_created'] = (int) ($totals['thumbs_created'] ?? 0) + (int) ($result['created'] ?? 0);
        $totals['thumbs_skipped'] = (int) ($totals['thumbs_skipped'] ?? 0) + (int) ($result['skipped'] ?? 0);
        $totals['webp_skipped'] = (int) ($totals['webp_skipped'] ?? 0) + (int) ($result['webp_skipped'] ?? 0);
        $totals['failed'] = (int) ($totals['failed'] ?? 0) + (int) ($result['failed'] ?? 0);
        $totals['invalid_geometry_deleted'] = (int) ($totals['invalid_geometry_deleted'] ?? 0) + (int) ($result['invalid_geometry_deleted'] ?? 0);

        $step['images_processed'] = (int) ($step['images_processed'] ?? 0) + 1;
        $step['thumbs_created'] = (int) ($step['thumbs_created'] ?? 0) + (int) ($result['created'] ?? 0);
        $step['thumbs_skipped'] = (int) ($step['thumbs_skipped'] ?? 0) + (int) ($result['skipped'] ?? 0);
        $step['webp_skipped'] = (int) ($step['webp_skipped'] ?? 0) + (int) ($result['webp_skipped'] ?? 0);
        $step['failed'] = (int) ($step['failed'] ?? 0) + (int) ($result['failed'] ?? 0);
        $step['invalid_geometry_deleted'] = (int) ($step['invalid_geometry_deleted'] ?? 0) + (int) ($result['invalid_geometry_deleted'] ?? 0);

        foreach ((array) ($result['errors'] ?? []) as $error) {
            $totals['errors'] = site_maintenance_append_error((array) ($totals['errors'] ?? []), (string) $error);
            $step['errors'] = site_maintenance_append_error((array) ($step['errors'] ?? []), (string) $error);
        }

        site_maintenance_finish_current_image($state, $totals);
    }

    $step['duration_seconds'] = round(microtime(true) - $stepStartedAt, 4);
    $step['image_ids'] = $stepImageIds;
    $state['totals'] = $totals;
    $state['last_step_at'] = now_sql();
    $state['last_step_summary'] = site_maintenance_step_summary($step, (int) ($state['cursor_image_id'] ?? 0));
    site_maintenance_save_state($state);

    if ((int) ($step['images_seen'] ?? 0) > 0) {
        site_maintenance_log_event('info', 'site_maintenance.slice_progress', 'Site maintenance checked one thumbnail slice.', [
            'cycle_date' => (string) ($state['cycle_date'] ?? ''),
            'phase' => 'thumbnails',
            'cursor_image_id' => (int) ($state['cursor_image_id'] ?? 0),
            'step' => site_maintenance_step_summary($step, (int) ($state['cursor_image_id'] ?? 0)),
            'image_ids' => $stepImageIds,
            'duration_seconds' => (float) ($step['duration_seconds'] ?? 0.0),
            'thumbnail_metadata_snapshot' => function_exists('Gallery\\Services\\thumbnail_metadata_storage_snapshot') ? thumbnail_metadata_storage_snapshot() : [],
        ]);
    }

    $worked = (int) ($step['images_seen'] ?? 0) > 0;

    return [
        'worked' => $worked,
        'phase' => 'thumbnails',
        'processed_images' => (int) ($step['images_processed'] ?? 0),
        'seen_images' => (int) ($step['images_seen'] ?? 0),
        'created' => (int) ($step['thumbs_created'] ?? 0),
        'skipped' => (int) ($step['thumbs_skipped'] ?? 0),
        'failed' => (int) ($step['failed'] ?? 0),
        'deferred' => (int) ($step['images_deferred'] ?? 0),
        'interrupted' => (int) ($step['images_interrupted'] ?? 0),
        'metadata_rows_refreshed' => (int) ($step['metadata_rows_refreshed'] ?? 0),
        'metadata_source_syncs' => (int) ($step['metadata_source_syncs'] ?? 0),
        'duration_seconds' => (float) ($step['duration_seconds'] ?? 0.0),
        'cursor_image_id' => (int) ($state['cursor_image_id'] ?? 0),
    ];
}

/**
 * Clear the current image marker after one image was safely checked.
 *
 * @param array $state State value.
 * @param array $totals Totals value.
 */
function site_maintenance_finish_current_image(array &$state, array $totals): void
{
    $state['current_image_id'] = 0;
    $state['current_image_started_at'] = null;
    $state['totals'] = $totals;
    site_maintenance_save_state($state);
}

/**
 * Return a compact summary for the last thumbnail step.
 *
 * @param array $step Step value.
 * @param int $cursorImageId Cursor image id identifier.
 * @return array<string mixed>.
 */
function site_maintenance_step_summary(array $step, int $cursorImageId): array
{
    return [
        'images_checked' => (int) ($step['images_seen'] ?? 0),
        'repair_attempts' => (int) ($step['images_processed'] ?? 0),
        'repairs_needed' => (int) ($step['images_with_repairs_needed'] ?? 0),
        'variants_missing_or_invalid' => (int) ($step['variants_missing_or_invalid'] ?? 0),
        'thumbnails_created' => (int) ($step['thumbs_created'] ?? 0),
        'valid_thumbnails_reused' => (int) ($step['thumbs_skipped'] ?? 0),
        'invalid_thumbnails_removed' => (int) ($step['invalid_geometry_deleted'] ?? 0),
        'metadata_rows_refreshed' => (int) ($step['metadata_rows_refreshed'] ?? 0),
        'metadata_source_syncs' => (int) ($step['metadata_source_syncs'] ?? 0),
        'deferred_images' => (int) ($step['images_deferred'] ?? 0),
        'failed_images' => (int) ($step['failed'] ?? 0),
        'duration_seconds' => (float) ($step['duration_seconds'] ?? 0.0),
        'image_id_range' => !empty($step['image_ids']) ? [min($step['image_ids']), max($step['image_ids'])] : [],
        'cursor_image_id' => $cursorImageId,
    ];
}

/**
 * Return whether one maintenance source should attempt full thumbnail repairs.
 *
 * @param string $source Source value.
 * @return bool True when the condition matches.
 */
function site_maintenance_source_allows_full_thumbnail_repair(string $source): bool
{
    $source = strtolower(trim($source));
    return in_array($source, [
        'cli_cron',
        'web_cron',
        'request_trigger',
        'request_trigger_inline',
        'maintenance_chain',
    ], true);
}

/**
 * Decide whether a missing thumbnail repair may be attempted in this process.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @param float $deadline Deadline value.
 * @param string $source Source value.
 * @return array{allowed:bool,reason:string,source_bytes:int,pixels:int} Structured result data for the caller.
 */
function site_maintenance_thumbnail_repair_decision(array $image, array $gallery, float $deadline, string $source = ''): array
{
    if (!site_maintenance_has_web_repair_runtime($deadline)) {
        return ['allowed' => false, 'reason' => 'not_enough_runtime_left', 'source_bytes' => 0, 'pixels' => 0];
    }

    if (PHP_SAPI === 'cli') {
        return ['allowed' => true, 'reason' => 'cli_runner', 'source_bytes' => 0, 'pixels' => 0];
    }

    $fullRepairSource = site_maintenance_source_allows_full_thumbnail_repair($source);

    try {
        $sourcePath = image_abs_path($image, $gallery);
    } catch (Throwable) {
        return ['allowed' => false, 'reason' => 'source_path_unavailable', 'source_bytes' => 0, 'pixels' => 0];
    }

    if (!is_file($sourcePath)) {
        return ['allowed' => false, 'reason' => 'source_file_missing', 'source_bytes' => 0, 'pixels' => 0];
    }

    $sourceBytes = (int) (filesize($sourcePath) ?: 0);
    $info = @getimagesize($sourcePath);
    $sourcePixels = is_array($info) ? max(0, (int) ($info[0] ?? 0) * (int) ($info[1] ?? 0)) : 0;

    if (image_uses_dng_display_derivatives($image)) {
        if ($fullRepairSource) {
            return ['allowed' => true, 'reason' => 'scheduled_dng_repair_allowed', 'source_bytes' => $sourceBytes, 'pixels' => $sourcePixels];
        }
        return ['allowed' => false, 'reason' => 'dng_repair_requires_scheduled_or_cli_maintenance', 'source_bytes' => $sourceBytes, 'pixels' => $sourcePixels];
    }

    if ($fullRepairSource) {
        return ['allowed' => true, 'reason' => 'scheduled_repair_allowed', 'source_bytes' => $sourceBytes, 'pixels' => $sourcePixels];
    }

    if ($sourceBytes > SITE_MAINTENANCE_WEB_REPAIR_MAX_BYTES) {
        return ['allowed' => false, 'reason' => 'source_file_too_large_for_web_repair', 'source_bytes' => $sourceBytes, 'pixels' => $sourcePixels];
    }

    if ($sourcePixels > SITE_MAINTENANCE_WEB_REPAIR_MAX_PIXELS) {
        return ['allowed' => false, 'reason' => 'source_dimensions_too_large_for_web_repair', 'source_bytes' => $sourceBytes, 'pixels' => $sourcePixels];
    }

    return ['allowed' => true, 'reason' => 'web_repair_allowed', 'source_bytes' => $sourceBytes, 'pixels' => $sourcePixels];
}

/**
 * Convert a previously fatal thumbnail attempt into a recorded failure and move on.
 *
 * @param array $state State value.
 */
function site_maintenance_record_interrupted_thumbnail_attempt(array &$state): void
{
    $currentImageId = (int) ($state['current_image_id'] ?? 0);
    if ($currentImageId <= 0) {
        return;
    }

    $totals = is_array($state['totals'] ?? null) ? $state['totals'] : site_maintenance_empty_totals();
    $message = 'Previous thumbnail maintenance attempt was interrupted while processing image id ' . $currentImageId . '. The cursor was advanced so the daily cycle cannot get stuck on one hazardous file.';
    $totals['failed'] = (int) ($totals['failed'] ?? 0) + 1;
    $totals['images_interrupted'] = (int) ($totals['images_interrupted'] ?? 0) + 1;
    $totals['errors'] = site_maintenance_append_error((array) ($totals['errors'] ?? []), $message);

    $state['totals'] = $totals;
    $state['current_image_id'] = 0;
    $state['current_image_started_at'] = null;
    site_maintenance_save_state($state);
}

/**
 * Run lightweight cleanup tasks after thumbnail processing finishes.
 *
 * @param array $state State value.
 * @param float $deadline Deadline value.
 * @return array<string mixed>.
 */
function site_maintenance_process_cleanup_step(array &$state, float $deadline): array
{
    $cleanup = [];

    if (function_exists('cleanup_expired_zip_cache')) {
        $cleanup['zip_cache'] = cleanup_expired_zip_cache();
    }

    if (function_exists('Gallery\\Services\\auth_throttle_cleanup')) {
        auth_throttle_cleanup();
        $cleanup['auth_rate_limits'] = 'cleaned';
    }

    if (function_exists('Gallery\\Controllers\\cms_cleanup_password_reset_tokens')) {
        cms_cleanup_password_reset_tokens();
        $cleanup['password_reset_tokens'] = 'cleaned';
    }

    if (function_exists('telemetry_run_maintenance') && (!function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled('telemetry'))) {
        $cleanup['telemetry'] = telemetry_run_maintenance();
    }

    if (function_exists('Gallery\\Services\\thumbnail_metadata_schema_ready') && thumbnail_metadata_schema_ready()) {
        $cleanup['thumbnail_metadata_orphans_deleted'] = site_maintenance_delete_orphan_thumbnail_metadata();
    }

    $cleanup['pending_migrations'] = function_exists('Gallery\\Core\\pending_migrations_exist') ? pending_migrations_exist() : false;

    $state['cleanup'] = $cleanup;
    $state['phase'] = 'complete';
    $state['status'] = 'complete';
    $state['finished_at'] = now_sql();

    return ['worked' => true, 'phase' => 'cleanups', 'cleanup' => $cleanup];
}

/**
 * Remove thumbnail metadata rows that no longer have matching image or gallery rows.
 *
 * @return int Integer result for the caller.
 */
function site_maintenance_delete_orphan_thumbnail_metadata(): int
{
    if (!function_exists('Gallery\\Services\\db_table_exists') || !db_table_exists('image_thumbnail_variants')) {
        return 0;
    }

    $deleted = 0;
    try {
        $stmt = db()->prepare('DELETE v FROM image_thumbnail_variants v LEFT JOIN images i ON i.id = v.image_id WHERE i.id IS NULL');
        $stmt->execute();
        $deleted += $stmt->rowCount();
    } catch (Throwable) {
        return $deleted;
    }

    if (!function_exists('Gallery\\Services\\db_column_exists') || !db_column_exists('image_thumbnail_variants', 'gallery_id')) {
        return $deleted;
    }

    try {
        $stmt = db()->prepare('DELETE v FROM image_thumbnail_variants v LEFT JOIN galleries g ON g.id = v.gallery_id WHERE g.id IS NULL');
        $stmt->execute();
        $deleted += $stmt->rowCount();
    } catch (Throwable) {
        return $deleted;
    }

    return $deleted;
}

/**
 * Return a best-effort duration for a persisted maintenance state.
 *
 * @param array $state State value.
 * @return int Integer result for the caller.
 */
function site_maintenance_state_duration_seconds(array $state): int
{
    $startedAt = strtotime((string) ($state['started_at'] ?? ''));
    $finishedAt = strtotime((string) ($state['finished_at'] ?? ''));
    if (!$startedAt || !$finishedAt || $finishedAt < $startedAt) {
        return 0;
    }

    return max(0, $finishedAt - $startedAt);
}

/**
 * Return a compact state object safe to show in Admin or JSON responses.
 *
 * @param array $state State value.
 * @return array<string mixed>.
 */
function site_maintenance_public_state(array $state): array
{
    return [
        'status' => (string) ($state['status'] ?? ''),
        'cycle_date' => (string) ($state['cycle_date'] ?? ''),
        'source' => (string) ($state['source'] ?? ''),
        'phase' => (string) ($state['phase'] ?? ''),
        'window_paused_at' => (string) ($state['window_paused_at'] ?? ''),
        'cursor_image_id' => (int) ($state['cursor_image_id'] ?? 0),
        'current_image_id' => (int) ($state['current_image_id'] ?? 0),
        'current_image_started_at' => (string) ($state['current_image_started_at'] ?? ''),
        'started_at' => (string) ($state['started_at'] ?? ''),
        'updated_at' => (string) ($state['updated_at'] ?? ''),
        'finished_at' => (string) ($state['finished_at'] ?? ''),
        'last_step_at' => (string) ($state['last_step_at'] ?? ''),
        'last_step_summary' => is_array($state['last_step_summary'] ?? null) ? $state['last_step_summary'] : [],
        'totals' => is_array($state['totals'] ?? null) ? $state['totals'] : site_maintenance_empty_totals(),
        'cleanup' => is_array($state['cleanup'] ?? null) ? $state['cleanup'] : [],
        'thumbnail_metadata_start_snapshot' => is_array($state['thumbnail_metadata_start_snapshot'] ?? null) ? $state['thumbnail_metadata_start_snapshot'] : [],
        'thumbnail_metadata_end_snapshot' => is_array($state['thumbnail_metadata_end_snapshot'] ?? null) ? $state['thumbnail_metadata_end_snapshot'] : [],
    ];
}

/**
 * Return dashboard-safe site maintenance status.
 *
 * @return array<string mixed>.
 */
function site_maintenance_status(): array
{
    $schedule = site_maintenance_schedule_due_state();
    $state = site_maintenance_state();

    return [
        'enabled' => site_maintenance_enabled(),
        'utc_time' => site_maintenance_utc_time(),
        'batch_size' => site_maintenance_batch_size(),
        'time_budget_seconds' => site_maintenance_time_budget_seconds(),
        'window_minutes' => site_maintenance_window_minutes(),
        'window_hours_value' => site_maintenance_window_hours_value(site_maintenance_window_minutes()),
        'request_trigger_enabled' => site_maintenance_request_trigger_enabled(),
        'request_trigger_interval_seconds' => site_maintenance_request_trigger_interval_seconds(),
        'total_source_images' => site_maintenance_total_source_image_count(),
        'cron_url' => site_maintenance_cron_url(),
        'scheduled_at_utc' => $schedule['scheduled_at'],
        'window_ends_at_utc' => $schedule['window_ends_at'],
        'within_window' => !empty($schedule['within_window']),
        'seconds_until' => $schedule['seconds_until'],
        'seconds_until_window_end' => (int) ($schedule['seconds_until_window_end'] ?? 0),
        'last_completed_date' => trim((string) app_setting(SITE_MAINTENANCE_LAST_COMPLETED_DATE_SETTING, '')),
        'last_completed_at' => trim((string) app_setting(SITE_MAINTENANCE_LAST_COMPLETED_AT_SETTING, '')),
        'state' => $state ? site_maintenance_public_state($state) : [],
        'last_result' => site_maintenance_last_result(),
    ];
}

/**
 * Write an operational site-maintenance event when the admin log is available.
 *
 * @param string $level Level value.
 * @param string $eventKey Event key value.
 * @param string $message Message value.
 * @param array $context Context value.
 * @param array $options Optional behavior flags.
 */
function site_maintenance_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
{
    if (!function_exists('admin_log_event')) {
        return;
    }

    $options = array_merge([
        'category' => 'system',
        'severity' => $level === 'error' ? 'error' : 'info',
        'subject_type' => 'site_maintenance',
        'route_name' => 'site_maintenance_cron',
    ], $options);

    admin_log_event($level, $eventKey, $message, $context, $options);
}
