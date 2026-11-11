<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/app_linking_passive_profile.php
 * Module Type: Service
 * Purpose: Passively measure authorized native API phases on one PHP request.
 * Responsibilities:
 *   - Default to off unless the host explicitly enables diagnostic sampling.
 *   - Keep capped numeric SQL, phase, hash-byte and process-memory counters.
 *   - Format authorized Server-Timing metrics without emitting HTTP responses.
 *   - Avoid persistent files, global locks, polling and sensitive request data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/**
 * Allow profiling only after an explicit server-side opt-in.
 * Type: string. Units: environment-variable name. Scope: PHP process.
 * Consumers: app_linking_passive_begin.
 * Rationale: No query parameter or request header may enable diagnostics.
 */
const APP_LINKING_PASSIVE_ENVIRONMENT = 'PHP_GALLERY_A2_PASSIVE_PROFILE';

/**
 * Keep only the native read routes currently instrumented by A2.4.
 * Type: list<string>. Units: fixed route labels. Scope: one request.
 * Consumers: app_linking_passive_begin. Rationale: Never store raw URLs.
 */
const APP_LINKING_PASSIVE_ROUTES = [
    'gallery_tree', 'gallery_page', 'media_page',
    'thumbnail_single', 'photo_single', 'revision',
];

/**
 * Keep numeric phase buckets constant and independent of request content.
 * Type: list<string>. Units: fixed phase labels. Scope: one request.
 * Consumers: app_linking_passive_begin and app_linking_passive_span_end.
 * Rationale: Prevent dynamic keys, user identifiers or unbounded cardinality.
 */
const APP_LINKING_PASSIVE_PHASES = [
    'auth', 'gallery_model', 'tree_json', 'tree_hash',
    'media_metadata', 'photo_open', 'file_hash',
];

/**
 * Cap executed database observations per PHP request.
 * Type: int. Units: executed operations. Scope: sampled request.
 * Consumers: app_linking_passive_sql_observe. Rationale: Bounded counters,
 * including explicit truncation when the cap has been reached.
 */
const APP_LINKING_PASSIVE_MAX_SQL = 512;

/**
 * Refuse invalid, negative or extremely long diagnostic intervals.
 * Type: float. Units: milliseconds. Scope: one phase or SQL operation.
 * Consumers: app_linking_passive_span_end and app_linking_passive_sql_observe.
 * Rationale: Ten minutes is above expected work without retaining infinity.
 */
const APP_LINKING_PASSIVE_MAX_MS = 600000.0;

/**
 * Cap accounted hash bytes without changing the actual file read policy.
 * Type: int. Units: bytes. Scope: one request.
 * Consumers: app_linking_passive_span_end. Rationale: 64 MiB exceeds one
 * accepted binary response plus bounded catalogue hashing.
 */
const APP_LINKING_PASSIVE_MAX_BYTES = 67108864;

/**
 * Start an identity-free profiler only on an allowlisted server-side opt-in.
 *
 * @param string $route Fixed caller-owned native route label, not URL data.
 * @return bool True only when this PHP request has an active numeric sampler.
 */
function app_linking_passive_begin(string $route): bool
{
    $GLOBALS['app_linking_passive_profile'] = null;
    $GLOBALS['app_linking_passive_sql_observer'] = null;
    if (getenv(APP_LINKING_PASSIVE_ENVIRONMENT) !== '1'
        || !in_array($route, APP_LINKING_PASSIVE_ROUTES, true)) {
        return false;
    }
    $phases = [];
    foreach (APP_LINKING_PASSIVE_PHASES as $phase) {
        $phases[$phase] = ['count' => 0, 'ms' => 0.0, 'bytes' => 0];
    }
    $GLOBALS['app_linking_passive_profile'] = [
        'route' => $route,
        'start_ns' => hrtime(true),
        'authorized' => false,
        'sql_count' => 0,
        'sql_failed' => 0,
        'sql_total_ms' => 0.0,
        'sql_max_ms' => 0.0,
        'sql_truncated' => false,
        'phases' => $phases,
    ];
    // Only the opted-in native route installs this numeric-only PDO hook.
    // Core invokes it without depending on optional app-linking Services.
    $GLOBALS['app_linking_passive_sql_observer'] =
        static function (float $elapsedMs, bool $ok): void {
            app_linking_passive_sql_observe($elapsedMs, $ok);
        };
    return true;
}

/**
 * Check request-local state without consulting a client-controlled value.
 *
 * @return bool Whether the current request has an active numeric sampler.
 */
function app_linking_passive_active(): bool
{
    return is_array($GLOBALS['app_linking_passive_profile'] ?? null);
}

/**
 * Return a monotonic start only when passive sampling is already active.
 *
 * @return int|null Monotonic nanoseconds, or null without sampling.
 */
function app_linking_passive_span_start(): ?int
{
    return app_linking_passive_active() ? hrtime(true) : null;
}

/**
 * Sum an allowlisted stage without retaining object names or raw data.
 *
 * @param string $phase Fixed known phase bucket.
 * @param int|null $startedNs Optional monotonic start from the same request.
 * @param int $bytes Actual already read or hashed bytes, never the contents.
 * @return void Invalid metrics and inactive requests are ignored.
 */
function app_linking_passive_span_end(
    string $phase,
    ?int $startedNs,
    int $bytes = 0,
): void {
    if ($startedNs === null || !app_linking_passive_active()
        || !in_array($phase, APP_LINKING_PASSIVE_PHASES, true)) {
        return;
    }
    $elapsed = (hrtime(true) - $startedNs) / 1_000_000;
    if (!is_finite($elapsed) || $elapsed < 0
        || $elapsed > APP_LINKING_PASSIVE_MAX_MS || $bytes < 0) {
        return;
    }
    $state = &$GLOBALS['app_linking_passive_profile'];
    $bucket = &$state['phases'][$phase];
    // The phase count is bounded even if a caller repeatedly requests files.
    if ($bucket['count'] >= APP_LINKING_PASSIVE_MAX_SQL) {
        return;
    }
    ++$bucket['count'];
    $bucket['ms'] += $elapsed;
    $bucket['bytes'] = min(
        APP_LINKING_PASSIVE_MAX_BYTES, $bucket['bytes'] + $bytes,
    );
}

/**
 * Observe an executed PDO operation from the existing telemetry dispatcher.
 *
 * @param float $elapsedMs Existing PDO execution duration in milliseconds.
 * @param bool $ok Whether the original PDO operation succeeded.
 * @return void A capped numeric aggregate; no SQL text or parameters stored.
 */
function app_linking_passive_sql_observe(float $elapsedMs, bool $ok): void
{
    if (!app_linking_passive_active()
        || !is_finite($elapsedMs) || $elapsedMs < 0
        || $elapsedMs > APP_LINKING_PASSIVE_MAX_MS) {
        return;
    }
    $state = &$GLOBALS['app_linking_passive_profile'];
    if ($state['sql_count'] >= APP_LINKING_PASSIVE_MAX_SQL) {
        $state['sql_truncated'] = true;
        return;
    }
    ++$state['sql_count'];
    $state['sql_failed'] += $ok ? 0 : 1;
    $state['sql_total_ms'] += $elapsedMs;
    $state['sql_max_ms'] = max($state['sql_max_ms'], $elapsedMs);
}

/**
 * Mark the current request as successfully authorized for this exact route.
 *
 * This must be called only after all current bearer, scope, gallery and
 * visibility checks succeed. A sampled unauthorized request never emits a
 * diagnostic header, even if it performed some database work.
 *
 * @return void Allows an explicitly opt-in numeric export only after authority.
 */
function app_linking_passive_authorized(): void
{
    if (app_linking_passive_active()) {
        $GLOBALS['app_linking_passive_profile']['authorized'] = true;
    }
}

/**
 * Read the already authorized numeric sample, without request identifiers.
 *
 * @return array{route:string,total_ms:float,sql_count:int,sql_failed:int,sql_total_ms:float,sql_max_ms:float,sql_truncated:bool,peak_process_memory_bytes:int,phases:array<string,array{count:int,ms:float,bytes:int}>}|null
 *     Fixed request-local measurements, or null when disabled/unauthorized.
 */
function app_linking_passive_snapshot(): ?array
{
    if (!app_linking_passive_active()) {
        return null;
    }
    $state = $GLOBALS['app_linking_passive_profile'];
    if (!$state['authorized']) {
        return null;
    }
    return [
        'route' => $state['route'],
        'total_ms' => max(0.0, (hrtime(true) - $state['start_ns']) / 1_000_000),
        'sql_count' => $state['sql_count'],
        'sql_failed' => $state['sql_failed'],
        'sql_total_ms' => $state['sql_total_ms'],
        'sql_max_ms' => $state['sql_max_ms'],
        'sql_truncated' => $state['sql_truncated'],
        'peak_process_memory_bytes' => memory_get_peak_usage(true),
        'phases' => $state['phases'],
    ];
}

/**
 * Build one bounded standard header with only allowlisted numeric metrics.
 *
 * @return string|null Server-Timing content or null without live authority.
 */
function app_linking_passive_header(): ?string
{
    $sample = app_linking_passive_snapshot();
    if ($sample === null) {
        return null;
    }
    $format = static fn(float $ms): string => number_format(
        min(APP_LINKING_PASSIVE_MAX_MS, max(0.0, $ms)),
        3, '.', '',
    );
    $sqlDescription = 'n=' . $sample['sql_count']
        . ';max_ms=' . $format($sample['sql_max_ms'])
        . ';failed=' . $sample['sql_failed']
        . ';cap=' . (int) $sample['sql_truncated'];
    $hash = $sample['phases']['file_hash'];
    return 'gallery-php;dur=' . $format($sample['total_ms'])
        . ', gallery-sql;dur=' . $format($sample['sql_total_ms'])
        . ';desc="' . $sqlDescription . '"'
        . ', gallery-auth;dur=' . $format($sample['phases']['auth']['ms'])
        . ', gallery-tree;dur='
        . $format($sample['phases']['gallery_model']['ms']
            + $sample['phases']['tree_json']['ms']
            + $sample['phases']['tree_hash']['ms'])
        . ', gallery-hash;dur=' . $format($hash['ms'])
        . ';desc="bytes=' . $hash['bytes'] . '"'
        . ', gallery-media;dur=' . $format(
            $sample['phases']['media_metadata']['ms']
            + $sample['phases']['photo_open']['ms'],
        );
}

/**
 * Erase request-local state for isolated tests and explicit teardown.
 *
 * @return void No diagnostic state survives the explicit reset.
 */
function app_linking_passive_reset(): void
{
    $GLOBALS['app_linking_passive_profile'] = null;
    $GLOBALS['app_linking_passive_sql_observer'] = null;
}
