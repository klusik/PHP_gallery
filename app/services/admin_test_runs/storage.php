<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs/storage.php
 * Module Type: Service
 *
 * Purpose:
 *   Persists, prunes, and packages stored test-run artifacts.
 *
 * Responsibilities:
 *   - Read and write run JSON payloads without leaking partial writes
 *   - Delete stale run trees under explicit entry and time budgets
 *   - Package a finished report into a downloadable archive
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
 * Atomically write one JSON diagnostics file.
 *
 * @phpstan-type AdminRunJsonValue null|bool|int|float|string|array<array-key,AdminRunJsonValue>
 * @param array<string,AdminRunJsonValue> $payload JSON object with recursively JSON-safe values.
 * @param string $path Destination JSON file path.
 * @return void Atomically replaces the destination with the encoded payload.
 */
function admin_test_run_write_json(string $path, array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT
            | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE,
    );
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode Admin test-run JSON.');
    }
    $isReport = basename($path) === 'report.json';
    if ($isReport && is_link($path . '.publish.lock')) {
        throw new RuntimeException('Invalid Admin report publication lock.');
    }
    $lock = $isReport ? @fopen($path . '.publish.lock', 'c') : null;
    if ($isReport && (!is_resource($lock) || !flock($lock, LOCK_EX))) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('Could not lock Admin report publication.');
    }
    try {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException('Could not write Admin test-run JSON.');
        }
        // Remove the prior projection before replacement, including same-size writes.
        $summaryPath = dirname($path) . DIRECTORY_SEPARATOR . 'report-summary.json';
        if ($isReport && is_file($summaryPath) && !@unlink($summaryPath)) {
            @unlink($temporary);
            throw new RuntimeException('Could not invalidate Admin report summary.');
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish Admin test-run JSON.');
        }
        if ($isReport) {
            // The authoritative report is already durable; projection failure must
            // preserve it. Missing summaries use the permanent legacy read path.
            try {
                admin_test_run_write_json($summaryPath, [
                    'version' => 1, // Structural sidecar format, not a policy limit.
                    'source' => admin_test_run_report_identity($path),
                    'report' => admin_test_run_report_projection($payload),
                ]);
            } catch (Throwable) {
                @unlink($summaryPath);
            }
        }
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Bound one compact projection read before JSON decoding.
 * Type: int. Units: bytes. Scope: one finalized report summary.
 * Consumers: admin_test_run_listing_report().
 * Rationale: 64 KiB accommodates metadata while excluding multi-MiB traces;
 * oversized metadata retains the authoritative legacy path without truncation.
 */
const ADMIN_TEST_RUN_SUMMARY_MAX_BYTES = 65536;

/**
 * Identify the published report without reading its diagnostic body.
 * @param string $path Authoritative report path.
 * @return array{size:int,mtime:int,ctime:int,ino:int}|null File identity, or null when absent.
 */
function admin_test_run_report_identity(string $path): ?array
{
    clearstatcache(true, $path);
    $stat = @stat($path);
    if (!is_array($stat) || !is_file($path)) {
        return null;
    }
    return [
        'size' => (int) $stat['size'],
        'mtime' => (int) $stat['mtime'],
        'ctime' => (int) $stat['ctime'],
        'ino' => (int) $stat['ino'],
    ];
}

/**
 * Project the fields consumed by the listing without retaining client traces.
 * @param array{admin?:array{id?:int},created_at?:string,finalized_at?:string,target?:array{page?:string,request_target?:string},request_lifecycle?:array{completed_count?:int,all_completed_cleanly?:bool},request_concurrency?:array{peak_concurrent_php_requests?:int}} $report Stored report; other diagnostic fields are ignored.
 * @return array{admin:array{id:int},created_at:string,finalized_at:string,target:array{page:string,request_target:string},request_lifecycle:array{completed_count:int,all_completed_cleanly:bool},request_concurrency:array{peak_concurrent_php_requests:int}} Compact ownership and display metadata.
 */
function admin_test_run_report_projection(array $report): array
{
    return [
        'admin' => ['id' => (int) ($report['admin']['id'] ?? 0)],
        'created_at' => (string) ($report['created_at'] ?? ''),
        'finalized_at' => (string) ($report['finalized_at'] ?? ''),
        'target' => [
            'page' => (string) ($report['target']['page'] ?? ''),
            'request_target' => (string) ($report['target']['request_target'] ?? ''),
        ],
        'request_lifecycle' => [
            'completed_count' => (int) ($report['request_lifecycle']['completed_count'] ?? 0),
            'all_completed_cleanly' => !empty($report['request_lifecycle']['all_completed_cleanly']),
        ],
        'request_concurrency' => [
            'peak_concurrent_php_requests' => (int) ($report['request_concurrency']['peak_concurrent_php_requests'] ?? 0),
        ],
    ];
}

/**
 * Read validated compact metadata, retaining old reports when no sidecar works.
 * @param string $directory Validated application-owned run directory.
 * @return array{admin:array{id:int},created_at:string,finalized_at:string,target:array{page:string,request_target:string},request_lifecycle:array{completed_count:int,all_completed_cleanly:bool},request_concurrency:array{peak_concurrent_php_requests:int}}|array{} Report projection, or empty when no readable authoritative report exists.
 */
function admin_test_run_listing_report(string $directory): array
{
    $path = $directory . DIRECTORY_SEPARATOR . 'report.json';
    $lockPath = $path . '.publish.lock';
    $lock = !is_link($lockPath) && is_file($lockPath) ? @fopen($lockPath, 'r') : null;
    if (is_resource($lock) && !flock($lock, LOCK_SH)) {
        fclose($lock);
        $lock = null;
    }
    try {
        $identity = admin_test_run_report_identity($path);
        if (is_link($path) || $identity === null) {
            return [];
        }
        $summaryPath = $directory . DIRECTORY_SEPARATOR . 'report-summary.json';
        // One sentinel byte distinguishes an oversized sidecar from an admitted one.
        $raw = !is_resource($lock) || is_link($summaryPath) ? false : @file_get_contents(
            $summaryPath, false, null, 0, ADMIN_TEST_RUN_SUMMARY_MAX_BYTES + 1,
        );
        $summary = is_string($raw) && strlen($raw) <= ADMIN_TEST_RUN_SUMMARY_MAX_BYTES
            ? json_decode($raw, true) : null;
        if (
            is_array($summary)
            && ($summary['version'] ?? null) === 1
            && ($summary['source'] ?? null) === $identity
            && is_array($summary['report'] ?? null)
            && is_int($summary['report']['admin']['id'] ?? null)
            && is_string($summary['report']['created_at'] ?? null)
            && is_string($summary['report']['finalized_at'] ?? null)
            && is_string($summary['report']['target']['page'] ?? null)
            && is_string($summary['report']['target']['request_target'] ?? null)
            && is_int($summary['report']['request_lifecycle']['completed_count'] ?? null)
            && is_bool($summary['report']['request_lifecycle']['all_completed_cleanly'] ?? null)
            && is_int($summary['report']['request_concurrency']['peak_concurrent_php_requests'] ?? null)
            && $summary['report'] === admin_test_run_report_projection($summary['report'])
            && admin_test_run_report_identity($path) === $identity
        ) {
            return $summary['report'];
        }
        // Permanent compatibility: stored/downloaded historical reports have no
        // sidecar. Storage owns this fallback; retirement requires proven complete
        // migration of retained runs and owner approval (see report-summary.md).
        $report = admin_test_run_read_json($path);
        return $report ? admin_test_run_report_projection($report) : [];
    } finally {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Read one JSON file as an associative array.
 *
 * @param string $path JSON file path.
 *
 * @return array<string,mixed>
 */
function admin_test_run_read_json(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Recursively remove one application-owned test-run directory.
 *
 * @param string $path Application-owned path to remove.
 * @return void Attempts bounded recursive cleanup using the configured budget.
 */
function admin_test_run_delete_tree(string $path): void
{
    $deadline =
        microtime(true) + (ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS / 1000.0);
    admin_test_run_delete_tree_bounded(
        $path,
        $deadline,
        ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP,
    );
}

/**
 * Remove a bounded number of entries from one application-owned diagnostics tree.
 *
 * @param string $path Application-owned file or directory path.
 * @param float $deadline Absolute microtime deadline for this cleanup attempt.
 * @param int $entryBudget Maximum number of entries to unlink or remove.
 * @return array{entries:int,complete:bool} Number of entries removed and whether the requested tree is fully deleted.
 */
function admin_test_run_delete_tree_bounded(
    string $path,
    float $deadline,
    int $entryBudget,
): array {
    $entries = 0;
    $entryBudget = max(1, $entryBudget);
    if (!file_exists($path) && !is_link($path)) {
        return ['entries' => 0, 'complete' => true];
    }
    if (!is_dir($path) || is_link($path)) {
        $ok = @unlink($path);
        return ['entries' => $ok ? 1 : 0, 'complete' => $ok];
    }
    $stack = [[$path, false]];
    while (
        $stack !== []
        && $entries < $entryBudget
        && microtime(true) < $deadline
    ) {
        [$current, $visited] = array_pop($stack);
        if (!is_dir($current) || is_link($current)) {
            if (@unlink($current)) {
                $entries++;
            }
            continue;
        }
        if ($visited) {
            if (@rmdir($current)) {
                $entries++;
            }
            continue;
        }
        $stack[] = [$current, true];
        $items = @scandir($current);
        if (!is_array($items)) {
            continue;
        }
        foreach (array_reverse($items) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $stack[] = [$current . DIRECTORY_SEPARATOR . $name, false];
        }
    }
    return ['entries' => $entries, 'complete' => !file_exists($path)];
}

/**
 * Remove old completed test-run directories so diagnostics cannot grow without bound.
 *
 * @return array{retention_max_count:int,retention_max_bytes:int,deleted_runs:int,deleted_entries:int,bytes_before:int,truncated:bool,elapsed_ms:float} Retention limits, removed run/entry totals, prior size estimate and bounded-cleanup status.
 */
function admin_test_run_cleanup_old_reports(): array
{
    $root = admin_test_run_root();
    $started = microtime(true);
    $deadline = $started + (ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS / 1000.0);
    $items = @scandir($root);
    $result = [
        'retention_max_count' => ADMIN_TEST_RUN_MAX_REPORTS,
        'retention_max_bytes' => ADMIN_TEST_RUN_MAX_REPORT_STORAGE_BYTES,
        'deleted_runs' => 0,
        'deleted_entries' => 0,
        'bytes_before' => 0,
        'truncated' => false,
        'elapsed_ms' => 0.0,
    ];
    if (!is_array($items)) {
        return $result;
    }
    $runs = [];
    foreach ($items as $name) {
        if (!admin_test_run_token_valid((string) $name)) {
            continue;
        }
        $path = $root . DIRECTORY_SEPARATOR . $name;
        if (!is_dir($path)) {
            continue;
        }
        $size = 0;
        foreach ([
            'report.json',
            'report-summary.json',
            'report.json.publish.lock',
            'report.zip',
            'meta.json',
            'browser.json',
        ] as $file) {
            $filePath = $path . DIRECTORY_SEPARATOR . $file;
            if (is_file($filePath)) {
                $size += max(0, (int) (@filesize($filePath) ?: 0));
            }
        }
        $runs[] = [
            'name' => $name,
            'path' => $path,
            'mtime' => (int) (@filemtime($path) ?: 0),
            'size_hint' => $size,
            'finalized' => is_file($path . DIRECTORY_SEPARATOR . 'report.json'),
        ];
        $result['bytes_before'] += $size;
    }
    usort(
        $runs,
        static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime'],
    );
    $retainedBytes = 0;
    foreach ($runs as $index => $run) {
        $retainedBytes += (int) $run['size_hint'];
        $overCount = $index >= ADMIN_TEST_RUN_MAX_REPORTS;
        $overBytes = $retainedBytes > ADMIN_TEST_RUN_MAX_REPORT_STORAGE_BYTES;
        if ((!$overCount && !$overBytes) || empty($run['finalized'])) {
            continue;
        }
        if (
            microtime(true) >= $deadline
            || (int) $result['deleted_entries']
                >= ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP
        ) {
            $result['truncated'] = true;
            break;
        }
        $deleted = admin_test_run_delete_tree_bounded(
            (string) $run['path'],
            $deadline,
            ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP - (int) $result['deleted_entries'],
        );
        $result['deleted_entries'] += (int) ($deleted['entries'] ?? 0);
        if (!is_dir((string) $run['path'])) {
            $result['deleted_runs']++;
        } else {
            $result['truncated'] = true;
            break;
        }
    }
    $result['elapsed_ms'] = (microtime(true) - $started) * 1000;
    return $result;
}

/**
 * Remove intermediate request/browser sidecars after final artifacts exist.
 *
 * @param string $token Test-run token whose intermediate sidecars are removed.
 * @return array{attempted:bool,entries_deleted:int,complete:bool,elapsed_ms:float} Cleanup outcome and elapsed duration.
 */
function admin_test_run_cleanup_intermediates(string $token): array
{
    $started = microtime(true);
    $deadline = $started + (ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS / 1000.0);
    $result = [
        'attempted' => true,
        'entries_deleted' => 0,
        'complete' => true,
        'elapsed_ms' => 0.0,
    ];
    foreach ([
        admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'browser.json',
        admin_test_run_requests_directory($token, false),
    ] as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $deleted = admin_test_run_delete_tree_bounded(
            $path,
            $deadline,
            ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP - (int) $result['entries_deleted'],
        );
        $result['entries_deleted'] += (int) ($deleted['entries'] ?? 0);
        if (empty($deleted['complete'])) {
            $result['complete'] = false;
            break;
        }
    }
    $result['elapsed_ms'] = (microtime(true) - $started) * 1000;
    return $result;
}

/**
 * Build an optional ZIP containing the final JSON report.
 *
 * @param array<string,mixed> $report Final report payload.
 * @param string $token Test-run token determining the ZIP destination.
 * @return void Creates a ZIP when the extension and filesystem allow it.
 */
function admin_test_run_build_zip(string $token, array $report): void
{
    $zipPath = admin_test_run_zip_path($token);
    @unlink($zipPath);
    if (!class_exists(ZipArchive::class)) {
        return;
    }
    $zip = new ZipArchive();
    if (
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)
        !== true
    ) {
        return;
    }
    try {
        $json = json_encode(
            $report,
            JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if (is_string($json)) {
            $zip->addFromString(
                admin_test_run_download_filename($report, false),
                $json . "\n",
            );
        }
    } finally {
        $zip->close();
    }
}

/**
 * Return a filesystem-safe download name for one test-run report.
 *
 * @param array<string,mixed> $report Final report metadata.
 * @param bool $zip Whether the filename should use the ZIP extension.
 * @return string Sanitized download filename ending in `.zip` or `.json`.
 */
function admin_test_run_download_filename(
    array $report,
    bool $zip = true,
): string {
    $page = preg_replace(
        '/[^a-z0-9_-]+/i',
        '-',
        (string) ($report['target']['page'] ?? 'gallery'),
    ) ?: 'gallery';
    $created = (string) ($report['created_at'] ?? gmdate('c'));
    $stamp = preg_replace('/[^0-9]/', '', $created) ?: gmdate('YmdHis');
    $stamp = substr($stamp, 0, 14);
    $token = preg_replace(
        '/[^a-z0-9]+/i',
        '',
        (string) ($report['run_id'] ?? ''),
    ) ?: 'run';
    $token = substr($token, 0, 8);
    return (
        'php-gallery-test-run-'
            . $page
            . '-'
            . $stamp
            . '-'
            . $token
            . ($zip ? '.zip' : '.json')
    );
}

/**
 * Read every completed and active request sidecar for a run.
 *
 * @param string $token Test-run token whose request sidecars are read.
 * @return array{completed:list<array<string,mixed>>,active:list<array<string,mixed>>} Completed and active request records, with token fields removed.
 */
function admin_test_run_request_records(string $token): array
{
    $directory = admin_test_run_requests_directory($token, false);
    $completed = [];
    $active = [];
    if (!is_dir($directory)) {
        return ['completed' => [], 'active' => []];
    }
    $items = @scandir($directory);
    if (!is_array($items)) {
        return ['completed' => [], 'active' => []];
    }
    foreach ($items as $name) {
        if (!str_ends_with($name, '.json')) {
            continue;
        }
        $payload = admin_test_run_read_json(
            $directory . DIRECTORY_SEPARATOR . $name,
        );
        if (!$payload) {
            continue;
        }
        unset($payload['token']);
        if (str_ends_with($name, '.active.json')) {
            $active[] = $payload;
        } else {
            $completed[] = $payload;
        }
    }
    usort(
        $completed,
        static fn(array $a, array $b): int => (
            (float) ($a['request_time_unix'] ?? 0)
            <=> (float) ($b['request_time_unix'] ?? 0)
        ),
    );
    return ['completed' => $completed, 'active' => $active];
}

/**
 * Return recent finalized reports for the Admin diagnostics panel.
 *
 * @param int $limit Maximum number of reports to return, defaulting to ten.
 * @return list<array{token:string,created_at:string,finalized_at:string,target_page:string,target:string,request_count:int,peak_concurrency:int,all_closed:bool,zip_available:bool}> Current Admin's newest finalized reports.
 */
function admin_test_run_recent_reports(int $limit = 10): array
{
    $root = admin_test_run_root();
    $items = @scandir($root);
    if (!is_array($items)) {
        return [];
    }
    $reports = [];
    foreach ($items as $name) {
        if (!admin_test_run_token_valid((string) $name)) {
            continue;
        }
        $directory = $root . DIRECTORY_SEPARATOR . $name;
        if (is_link($directory) || !is_dir($directory)) {
            continue;
        }
        $report = admin_test_run_listing_report($directory);
        if (!$report || !admin_test_run_owned_by_current_admin($report)) {
            continue;
        }
        $reports[] = [
            'token' => $name,
            'created_at' => (string) ($report['created_at'] ?? ''),
            'finalized_at' => (string) ($report['finalized_at'] ?? ''),
            'target_page' => (string) ($report['target']['page'] ?? ''),
            'target' => (string) ($report['target']['request_target'] ?? ''),
            'request_count' => (int) (
                $report['request_lifecycle']['completed_count'] ?? 0
            ),
            'peak_concurrency' => (int) (
                $report['request_concurrency']['peak_concurrent_php_requests']
                ?? 0
            ),
            'all_closed' =>
                !empty($report['request_lifecycle']['all_completed_cleanly']),
            'zip_available' => is_file(
                $root
                    . DIRECTORY_SEPARATOR
                    . $name
                    . DIRECTORY_SEPARATOR
                    . 'report.zip',
            ),
        ];
    }
    usort($reports, static fn(array $a, array $b): int => strcmp(
        $b['finalized_at'],
        $a['finalized_at'],
    ));
    return array_slice($reports, 0, max(1, min(50, $limit)));
}

/**
 * Return the latest finalized run for the current target, if any.
 *
 * @param string $target Request target to normalize and match.
 * @return array{token:string,created_at:string,finalized_at:string,target_page:string,target:string,request_count:int,peak_concurrency:int,all_closed:bool,zip_available:bool}|null Newest matching report among the current Admin's latest twenty, or null.
 */
function admin_test_run_latest_for_target(string $target): ?array
{
    $target = admin_test_run_normalize_target($target);
    foreach (admin_test_run_recent_reports(20) as $report) {
        if (
            admin_test_run_normalize_target((string) ($report['target'] ?? ''))
            === $target
        ) {
            return $report;
        }
    }
    return null;
}
