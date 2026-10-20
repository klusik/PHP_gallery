<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/runtime/storage_observer.php
 * Module Type: Core Utility
 * Purpose: Observe explicit storage-refresh work without additional I/O.
 * Responsibilities: Collect bounded numeric counters and monotonic call timings.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core;

/**
 * Bound numeric diagnostic cardinality in one observed request.
 * Type: int. Units: metric keys. Scope: explicit storage-refresh observations.
 * Consumers: storage_observer_add().
 * Rationale: 256 accommodates fixed call-site counters without unbounded logs.
 */
const STORAGE_OBSERVER_MAX_METRICS = 256;

/**
 * Own passive request-local observation state.
 * @return array{active:bool,metrics:array<string,int|float>} Mutable numeric state;
 *     inactive by default, never persisted or enabled by public media readers.
 */
function &storage_observer_state(): array
{
    static $state = ['active' => false, 'metrics' => []];
    return $state;
}

/**
 * Start an empty observation window for one explicit workflow request.
 * @return void Enable numeric observation until storage_observer_finish().
 */
function storage_observer_start(): void
{
    $state = &storage_observer_state();
    $state = [
        'active' => true,
        'metrics' => ['observer.metric_updates_dropped' => 0],
    ];
}

/**
 * Finish observation and restore passive behavior even after a failed callback.
 * @return array<string,int|float> Fixed-key numeric observations from this window.
 */
function storage_observer_finish(): array
{
    $state = &storage_observer_state();
    $metrics = $state['metrics'];
    $state = ['active' => false, 'metrics' => []];
    return $metrics;
}

/**
 * Add a code-owned numeric observation without saving paths or payloads.
 * @param string $key Fixed metric identity supplied by an instrumented call site.
 * @param int|float $value Nonnegative count, bytes or seconds; defaults to one call.
 * @return void Accumulate when enabled, otherwise perform no work or I/O.
 */
function storage_observer_add(string $key, int|float $value = 1): void
{
    $state = &storage_observer_state();
    if (!$state['active'])
        return;
    if (
        !isset($state['metrics'][$key])
        && count($state['metrics']) >= STORAGE_OBSERVER_MAX_METRICS
    ) {
        $state['metrics']['observer.metric_updates_dropped']++;
        return;
    }
    $state['metrics'][$key] = ($state['metrics'][$key] ?? 0) + max(0, $value);
}

/**
 * Measure an existing operation exactly once and preserve its result or failure.
 * @template T
 * @param string $key Fixed code-owned operation identity; never a path or SQL text.
 * @param callable():T $callback Original operation, invoked exactly once.
 * @return T Original callback result, including false/null; exceptions propagate.
 */
function storage_observer_call(string $key, callable $callback): mixed
{
    $state = &storage_observer_state();
    if (!$state['active'])
        return $callback();
    $started = hrtime(true);
    storage_observer_add($key . '.calls');
    try {
        $result = $callback();
        if ($result === false)
            storage_observer_add($key . '.false_results');
        if (str_starts_with($key, 'fs.read.') && is_string($result)) {
            storage_observer_add($key . '.bytes', strlen($result));
        }
        return $result;
    } catch (\Throwable $exception) {
        storage_observer_add($key . '.exceptions');
        throw $exception;
    } finally {
        // hrtime uses nanoseconds; division converts this unit, not a policy.
        $seconds = max(0.0, (hrtime(true) - $started) / 1e9);
        storage_observer_add($key . '.seconds', $seconds);
        if (
            isset($state['metrics'][$key . '.max_seconds'])
            || count($state['metrics']) < STORAGE_OBSERVER_MAX_METRICS
        ) {
            $state['metrics'][$key . '.max_seconds'] = max(
                (float) ($state['metrics'][$key . '.max_seconds'] ?? 0),
                $seconds,
            );
        } else {
            $state['metrics']['observer.metric_updates_dropped']++;
        }
    }
}
