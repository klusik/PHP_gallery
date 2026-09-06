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
 * @param array<string,mixed> $payload Structured JSON-safe payload.
 */
function admin_test_run_write_json(string $path, array $payload): void
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        throw new RuntimeException('Could not encode Admin test-run JSON.');
    }
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('Could not write Admin test-run JSON.');
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Could not publish Admin test-run JSON.');
    }
}

/**
 * Read one JSON file as an associative array.
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
 */
function admin_test_run_delete_tree(string $path): void
{
    $deadline = microtime(true) + (ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS / 1000.0);
    admin_test_run_delete_tree_bounded($path, $deadline, ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP);
}

/**
 * Remove a bounded number of entries from one application-owned diagnostics tree.
 *
 * @return array{entries:int,complete:bool}
 */
function admin_test_run_delete_tree_bounded(string $path, float $deadline, int $entryBudget): array
{
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
    while ($stack !== [] && $entries < $entryBudget && microtime(true) < $deadline) {
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
        foreach (['report.json', 'report.zip', 'meta.json', 'browser.json'] as $file) {
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
    usort($runs, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
    $retainedBytes = 0;
    foreach ($runs as $index => $run) {
        $retainedBytes += (int) $run['size_hint'];
        $overCount = $index >= ADMIN_TEST_RUN_MAX_REPORTS;
        $overBytes = $retainedBytes > ADMIN_TEST_RUN_MAX_REPORT_STORAGE_BYTES;
        if ((!$overCount && !$overBytes) || empty($run['finalized'])) {
            continue;
        }
        if (microtime(true) >= $deadline || (int) $result['deleted_entries'] >= ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP) {
            $result['truncated'] = true;
            break;
        }
        $deleted = admin_test_run_delete_tree_bounded((string) $run['path'], $deadline, ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP - (int) $result['deleted_entries']);
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
 * @return array<string,mixed>
 */
function admin_test_run_cleanup_intermediates(string $token): array
{
    $started = microtime(true);
    $deadline = $started + (ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS / 1000.0);
    $result = ['attempted' => true, 'entries_deleted' => 0, 'complete' => true, 'elapsed_ms' => 0.0];
    foreach ([
        admin_test_run_directory($token) . DIRECTORY_SEPARATOR . 'browser.json',
        admin_test_run_requests_directory($token, false),
    ] as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $deleted = admin_test_run_delete_tree_bounded($path, $deadline, ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP - (int) $result['entries_deleted']);
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
 */
function admin_test_run_build_zip(string $token, array $report): void
{
    $zipPath = admin_test_run_zip_path($token);
    @unlink($zipPath);
    if (!class_exists(ZipArchive::class)) {
        return;
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return;
    }
    try {
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($json)) {
            $zip->addFromString(admin_test_run_download_filename($report, false), $json . "\n");
        }
    } finally {
        $zip->close();
    }
}

/**
 * Return a filesystem-safe download name for one test-run report.
 */
function admin_test_run_download_filename(array $report, bool $zip = true): string
{
    $page = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($report['target']['page'] ?? 'gallery')) ?: 'gallery';
    $created = (string) ($report['created_at'] ?? gmdate('c'));
    $stamp = preg_replace('/[^0-9]/', '', $created) ?: gmdate('YmdHis');
    $stamp = substr($stamp, 0, 14);
    $token = preg_replace('/[^a-z0-9]+/i', '', (string) ($report['run_id'] ?? '')) ?: 'run';
    $token = substr($token, 0, 8);
    return 'php-gallery-test-run-' . $page . '-' . $stamp . '-' . $token . ($zip ? '.zip' : '.json');
}

/**
 * Read every completed and active request sidecar for a run.
 *
 * @return array{completed:array<int,array<string,mixed>>,active:array<int,array<string,mixed>>}
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
        $payload = admin_test_run_read_json($directory . DIRECTORY_SEPARATOR . $name);
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
    usort($completed, static fn (array $a, array $b): int => ((float) ($a['request_time_unix'] ?? 0)) <=> ((float) ($b['request_time_unix'] ?? 0)));
    return ['completed' => $completed, 'active' => $active];
}

/**
 * Return recent finalized reports for the Admin diagnostics panel.
 *
 * @return array<int,array<string,mixed>>
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
        $report = admin_test_run_read_json($root . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'report.json');
        if (!$report || !admin_test_run_owned_by_current_admin($report)) {
            continue;
        }
        $reports[] = [
            'token' => $name,
            'created_at' => (string) ($report['created_at'] ?? ''),
            'finalized_at' => (string) ($report['finalized_at'] ?? ''),
            'target_page' => (string) ($report['target']['page'] ?? ''),
            'target' => (string) ($report['target']['request_target'] ?? ''),
            'request_count' => (int) ($report['request_lifecycle']['completed_count'] ?? 0),
            'peak_concurrency' => (int) ($report['request_concurrency']['peak_concurrent_php_requests'] ?? 0),
            'all_closed' => !empty($report['request_lifecycle']['all_completed_cleanly']),
            'zip_available' => is_file($root . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR . 'report.zip'),
        ];
    }
    usort($reports, static fn (array $a, array $b): int => strcmp($b['finalized_at'], $a['finalized_at']));
    return array_slice($reports, 0, max(1, min(50, $limit)));
}

/**
 * Return the latest finalized run for the current target, if any.
 *
 * @return array<string,mixed>|null
 */
function admin_test_run_latest_for_target(string $target): ?array
{
    $target = admin_test_run_normalize_target($target);
    foreach (admin_test_run_recent_reports(20) as $report) {
        if (admin_test_run_normalize_target((string) ($report['target'] ?? '')) === $target) {
            return $report;
        }
    }
    return null;
}
