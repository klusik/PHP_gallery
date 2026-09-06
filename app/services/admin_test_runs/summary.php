<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs/summary.php
 * Module Type: Service
 *
 * Purpose:
 *   Derives concurrency and database summaries from recorded requests.
 *
 * Responsibilities:
 *   - Summarize overlapping requests and session lock pressure
 *   - Aggregate recorded database events into per-run totals
 *   - Keep summaries derived only from already-sanitized records
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
 *   - Loaded by app/services/admin_test_runs.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_runs.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\url_for;

/**
 * Build request concurrency statistics from completed request intervals.
 *
 * @param array<int,array<string,mixed>> $requests Completed request records.
 * @return array<string,mixed>
 */
function admin_test_run_concurrency_summary(array $requests): array
{
    $events = [];
    $pids = [];
    $duration = 0.0;
    foreach ($requests as $request) {
        $start = (float) ($request['request_time_unix'] ?? 0.0);
        $end = (float) ($request['finished_at_unix'] ?? 0.0);
        if ($start > 0.0 && $end >= $start) {
            $events[] = ['at' => $start, 'delta' => 1, 'type' => 'start', 'id' => (string) ($request['request_id'] ?? '')];
            $events[] = ['at' => $end, 'delta' => -1, 'type' => 'end', 'id' => (string) ($request['request_id'] ?? '')];
            $duration += max(0.0, ($end - $start) * 1000);
        }
        $pid = (int) ($request['process']['pid'] ?? 0);
        if ($pid > 0) {
            $pids[$pid] = ($pids[$pid] ?? 0) + 1;
        }
    }
    usort($events, static function (array $a, array $b): int {
        if ($a['at'] === $b['at']) {
            return $a['delta'] <=> $b['delta'];
        }
        return $a['at'] <=> $b['at'];
    });
    $active = 0;
    $peak = 0;
    $peakAt = null;
    foreach ($events as $event) {
        $active += (int) $event['delta'];
        if ($active > $peak) {
            $peak = $active;
            $peakAt = $event['at'];
        }
    }
    return [
        'completed_request_count' => count($requests),
        'peak_concurrent_php_requests' => $peak,
        'peak_at_unix' => $peakAt,
        'distinct_pids' => count($pids),
        'requests_by_pid' => $pids,
        'aggregate_php_request_wall_ms' => $duration,
        'diagnostic_runner_intended_probe_concurrency' => 1,
        'danger_threshold' => 32,
        'dangerous_peak_detected' => $peak >= 32,
    ];
}

/**
 * Summarize database activity across every traced PHP request.
 *
 * @param array<int,array<string,mixed>> $requests Completed request records.
 * @return array<string,mixed>
 */
function admin_test_run_db_summary(array $requests): array
{
    $queryCount = 0;
    $failed = 0;
    $totalMs = 0.0;
    $maxMs = 0.0;
    $byOperation = [];
    $byTable = [];
    $slowest = [];
    $prepareCount = 0;
    $prepareFailedCount = 0;
    $prepareTotalMs = 0.0;
    $prepareMaxMs = 0.0;
    $transactionEvents = [];
    $transactionFailedCount = 0;
    $transactionBalances = [];
    foreach ($requests as $request) {
        $dbState = is_array($request['db'] ?? null) ? $request['db'] : [];
        $queryCount += (int) ($dbState['query_count_total'] ?? 0);
        $failed += (int) ($dbState['failed_count'] ?? 0);
        $totalMs += (float) ($dbState['query_total_ms'] ?? 0.0);
        $maxMs = max($maxMs, (float) ($dbState['query_max_ms'] ?? 0.0));
        foreach ((array) ($dbState['prepare_events'] ?? []) as $prepare) {
            if (!is_array($prepare)) continue;
            $prepareCount++;
            if (empty($prepare['ok'])) $prepareFailedCount++;
            $prepareTotalMs += (float) ($prepare['elapsed_ms'] ?? 0.0);
            $prepareMaxMs = max($prepareMaxMs, (float) ($prepare['elapsed_ms'] ?? 0.0));
        }
        $requestId = (string) ($request['request_id'] ?? '');
        $transactionBalance = 0;
        foreach ((array) ($dbState['transaction_events'] ?? []) as $transaction) {
            if (!is_array($transaction)) continue;
            $transactionEvents[] = array_merge(['request_id' => $requestId], $transaction);
            if (empty($transaction['ok'])) {
                $transactionFailedCount++;
                continue;
            }
            $operation = (string) ($transaction['operation'] ?? '');
            if ($operation === 'begin') $transactionBalance++;
            if (($operation === 'commit' || $operation === 'rollback') && $transactionBalance > 0) $transactionBalance--;
        }
        if ($transactionBalance !== 0) {
            $transactionBalances[$requestId] = $transactionBalance;
        }
        foreach ((array) ($dbState['queries'] ?? []) as $query) {
            if (!is_array($query)) {
                continue;
            }
            $operation = (string) ($query['operation'] ?? 'other');
            $table = (string) ($query['table'] ?? '');
            $byOperation[$operation] = ($byOperation[$operation] ?? 0) + 1;
            if ($table !== '') {
                $byTable[$table] = ($byTable[$table] ?? 0) + 1;
            }
            $slowest[] = [
                'elapsed_ms' => (float) ($query['elapsed_ms'] ?? 0.0),
                'operation' => $operation,
                'table' => $table,
                'fingerprint' => (string) ($query['fingerprint'] ?? ''),
                'shape' => (string) ($query['shape'] ?? ''),
                'callsite' => $query['callsite'] ?? [],
                'request_id' => (string) ($request['request_id'] ?? ''),
            ];
        }
    }
    arsort($byOperation);
    arsort($byTable);
    usort($slowest, static fn (array $a, array $b): int => $b['elapsed_ms'] <=> $a['elapsed_ms']);
    return [
        'query_count' => $queryCount,
        'failed_count' => $failed,
        'total_query_ms' => $totalMs,
        'max_query_ms' => $maxMs,
        'average_query_ms' => $queryCount > 0 ? $totalMs / $queryCount : 0.0,
        'prepare_count' => $prepareCount,
        'prepare_failed_count' => $prepareFailedCount,
        'prepare_total_ms' => $prepareTotalMs,
        'prepare_max_ms' => $prepareMaxMs,
        'transaction_events' => $transactionEvents,
        'transaction_failed_count' => $transactionFailedCount,
        'unclosed_transaction_balance_by_request' => $transactionBalances,
        'all_traced_transactions_closed' => $transactionBalances === [],
        'by_operation' => $byOperation,
        'by_table' => $byTable,
        'slowest_queries' => array_slice($slowest, 0, 100),
    ];
}
