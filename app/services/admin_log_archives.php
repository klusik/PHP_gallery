<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_log_archives.php
 * Module Type: Service
 *
 * Purpose:
 *   Archives completed Admin log days into immutable filesystem ZIP files while
 *   keeping the live admin_logs table bounded by an administrator-selected window.
 *
 * Responsibilities:
 *   - Keep only the selected recent live-log window in MariaDB
 *   - Stream completed historical days to JSON and static HTML without large buffers
 *   - Verify each ZIP before deleting the represented database rows
 *   - Recover safely when a process stops after archive creation but before cleanup
 *   - Expose filesystem archive metadata for the Admin Logs archive browser
 *   - Run opportunistically after normal page loads on a lightweight 24-hour counter
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
 *   2026-08-10
 */

declare(strict_types=1);

namespace Gallery\Services;

use DateTimeImmutable;
use RuntimeException;
use Throwable;
use ZipArchive;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

const ADMIN_LOG_ARCHIVE_DEFAULT_RETENTION_DAYS = 30;
const ADMIN_LOG_ARCHIVE_RETENTION_OPTIONS = [2, 7, 30, 90, 0];
const ADMIN_LOG_ARCHIVE_INTERVAL_SECONDS = 86400;
const ADMIN_LOG_ARCHIVE_BACKLOG_RETRY_SECONDS = 60;
const ADMIN_LOG_ARCHIVE_FAILURE_RETRY_SECONDS = 3600;
const ADMIN_LOG_ARCHIVE_ROW_BATCH_SIZE = 200;
const ADMIN_LOG_ARCHIVE_DELETE_BATCH_SIZE = 2000;
const ADMIN_LOG_ARCHIVE_LIST_PAGE_SIZE = 50;
const ADMIN_LOG_ARCHIVE_LOCK_FILE = 'admin-log-archive-maintenance.lock';
const ADMIN_LOG_ARCHIVE_STATE_FILE = 'admin-log-archive-maintenance-state.json';
const ADMIN_LOG_ARCHIVE_SCHEMA = 'php-gallery-admin-log-archive-v1';

/**
 * Return the supported Admin log live-retention choices.
 *
 * Zero means that automatic archive maintenance is disabled and all logs remain live.
 *
 * @return array<int,int> Retention choices in their Admin UI order.
 */
function admin_log_archive_retention_options(): array
{
    return ADMIN_LOG_ARCHIVE_RETENTION_OPTIONS;
}

/**
 * Normalize the Admin log live-retention selection to a supported value.
 *
 * @param int $days Requested retention days.
 * @return int Supported retention days, or zero for Forever.
 */
function admin_log_archive_normalize_retention_days(int $days): int
{
    return in_array($days, ADMIN_LOG_ARCHIVE_RETENTION_OPTIONS, true)
        ? $days
        : ADMIN_LOG_ARCHIVE_DEFAULT_RETENTION_DAYS;
}

/**
 * Return the configured Admin log live-retention window.
 *
 * @return int Retention days, or zero when archival is disabled.
 */
function admin_log_archive_retention_days(): int
{
    return admin_log_archive_normalize_retention_days((int) app_setting(
        'admin_log_retention_days',
        (string) ADMIN_LOG_ARCHIVE_DEFAULT_RETENTION_DAYS
    ));
}

/**
 * Persist the Admin log live-retention choice and make the next archive check due.
 *
 * @param int $days Requested retention days.
 * @return int Persisted supported value.
 */
function admin_log_archive_set_retention_days(int $days): int
{
    $normalized = admin_log_archive_normalize_retention_days($days);
    set_app_setting('admin_log_retention_days', (string) $normalized);

    $state = admin_log_archive_state();
    $state['next_run_at'] = $normalized === 0 ? time() + ADMIN_LOG_ARCHIVE_INTERVAL_SECONDS : 0;
    $state['retention_days'] = $normalized;
    admin_log_archive_write_state($state);
    return $normalized;
}

/**
 * Return the persistent archive root directory.
 *
 * The directory is application data rather than cache because archived logs are
 * intentionally permanent until an administrator explicitly deletes a ZIP.
 *
 * @return string Absolute filesystem path.
 */
function admin_log_archive_root_dir(): string
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'admin-log-archives';
}

/**
 * Ensure the archive root exists and carries an Apache deny rule as defense in depth.
 *
 * @return string Absolute archive root path.
 */
function admin_log_archive_ensure_root_dir(): string
{
    $root = admin_log_archive_root_dir();
    if (!is_dir($root) && !@mkdir($root, 0775, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to create the Admin log archive directory.');
    }

    $denyPath = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($denyPath)) {
        @file_put_contents($denyPath, "Require all denied\n", LOCK_EX);
    }
    return $root;
}

/**
 * Return the cache directory used for the tiny maintenance counter and lock files.
 *
 * @return string Absolute cache directory path.
 */
function admin_log_archive_cache_dir(): string
{
    $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'cache';
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the Admin log archive maintenance cache directory.');
    }
    return $directory;
}

/**
 * Return the archive-maintenance state file path.
 *
 * @return string Absolute filesystem path.
 */
function admin_log_archive_state_path(): string
{
    return admin_log_archive_cache_dir() . DIRECTORY_SEPARATOR . ADMIN_LOG_ARCHIVE_STATE_FILE;
}

/**
 * Return the current lightweight archive-maintenance state.
 *
 * @return array<string,mixed> State data.
 */
function admin_log_archive_state(): array
{
    try {
        $path = admin_log_archive_state_path();
    } catch (Throwable) {
        return [];
    }
    if (!is_file($path)) {
        return [];
    }

    $json = @file_get_contents($path);
    if (!is_string($json) || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Atomically persist the tiny archive-maintenance state file.
 *
 * @param array<string,mixed> $state State data.
 */
function admin_log_archive_write_state(array $state): void
{
    $path = admin_log_archive_state_path();
    $directory = dirname($path);
    $temporary = tempnam($directory, 'admin-log-archive-state-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to allocate the Admin log archive maintenance state file.');
    }

    try {
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the Admin log archive maintenance state.');
        }
        if (!@rename($temporary, $path)) {
            throw new RuntimeException('Unable to finalize the Admin log archive maintenance state.');
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

/**
 * Return whether one archive date uses the canonical YYYY-MM-DD form.
 *
 * @param string $date Date value.
 * @return bool True for a valid canonical calendar date.
 */
function admin_log_archive_valid_date(string $date): bool
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

/**
 * Return the canonical immutable ZIP filename for one archive day.
 *
 * @param string $date Canonical archive date.
 * @return string Filename only.
 */
function admin_log_archive_file_name(string $date): string
{
    if (!admin_log_archive_valid_date($date)) {
        throw new RuntimeException('Invalid Admin log archive date.');
    }
    return 'admin-logs-' . $date . '.zip';
}

/**
 * Return the canonical archive ZIP path for one day.
 *
 * @param string $date Canonical archive date.
 * @param bool $ensureDirectory Whether to create the year/month directory.
 * @return string Absolute filesystem path.
 */
function admin_log_archive_path(string $date, bool $ensureDirectory = false): string
{
    if (!admin_log_archive_valid_date($date)) {
        throw new RuntimeException('Invalid Admin log archive date.');
    }
    $root = $ensureDirectory ? admin_log_archive_ensure_root_dir() : admin_log_archive_root_dir();
    $directory = $root . DIRECTORY_SEPARATOR . substr($date, 0, 4) . DIRECTORY_SEPARATOR . substr($date, 5, 2);
    if ($ensureDirectory && !is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the Admin log archive month directory.');
    }
    return $directory . DIRECTORY_SEPARATOR . admin_log_archive_file_name($date);
}

/**
 * Return the start and end timestamps for one local calendar day.
 *
 * @param string $date Canonical archive date.
 * @return array{start:string,end:string}
 */
function admin_log_archive_day_bounds(string $date): array
{
    if (!admin_log_archive_valid_date($date)) {
        throw new RuntimeException('Invalid Admin log archive date.');
    }
    $start = new DateTimeImmutable($date . ' 00:00:00');
    return [
        'start' => $start->format('Y-m-d H:i:s'),
        'end' => $start->modify('+1 day')->format('Y-m-d H:i:s'),
    ];
}

/**
 * Return the midnight boundary before which complete days are archive-eligible.
 *
 * A rolling N-day live window is honored without creating partial-day archives:
 * the cutoff is rounded down to local midnight, so the database may retain up to
 * almost one additional day while every ZIP remains one complete immutable day.
 *
 * @param int $retentionDays Retention days.
 * @param ?int $now Optional epoch used by tests and deterministic callers.
 * @return string SQL timestamp boundary, or an empty string when disabled.
 */
function admin_log_archive_eligible_before(int $retentionDays, ?int $now = null): string
{
    $days = admin_log_archive_normalize_retention_days($retentionDays);
    if ($days === 0) {
        return '';
    }
    $reference = $now === null
        ? new DateTimeImmutable('now')
        : (new DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    return $reference->modify('-' . $days . ' days')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
}

/**
 * Return the oldest completed day currently eligible for archival.
 *
 * @param string $eligibleBefore Exclusive upper boundary for eligible rows.
 * @return ?string Canonical date or null when there is no backlog.
 */
function admin_log_archive_oldest_eligible_date(string $eligibleBefore): ?string
{
    if ($eligibleBefore === '' || !admin_log_schema_ready()) {
        return null;
    }
    $stmt = db()->prepare('SELECT MIN(created_at) FROM admin_logs WHERE created_at < ?');
    $stmt->execute([$eligibleBefore]);
    $value = $stmt->fetchColumn();
    if (!is_string($value) || $value === '') {
        return null;
    }
    $date = substr($value, 0, 10);
    return admin_log_archive_valid_date($date) ? $date : null;
}

/**
 * Capture stable scalar metadata for one archive day before streaming starts.
 *
 * @param string $date Canonical archive date.
 * @return array<string,mixed> Snapshot metadata.
 */
function admin_log_archive_day_snapshot(string $date): array
{
    $bounds = admin_log_archive_day_bounds($date);
    $stmt = db()->prepare(
        'SELECT COUNT(*) AS row_count, MIN(id) AS first_log_id, MAX(id) AS last_log_id,'
        . ' MIN(created_at) AS first_created_at, MAX(created_at) AS last_created_at'
        . ' FROM admin_logs WHERE created_at >= ? AND created_at < ?'
    );
    $stmt->execute([$bounds['start'], $bounds['end']]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        $row = [];
    }
    return [
        'date' => $date,
        'period_start' => $bounds['start'],
        'period_end' => $bounds['end'],
        'row_count' => max(0, (int) ($row['row_count'] ?? 0)),
        'first_log_id' => max(0, (int) ($row['first_log_id'] ?? 0)),
        'last_log_id' => max(0, (int) ($row['last_log_id'] ?? 0)),
        'first_created_at' => (string) ($row['first_created_at'] ?? ''),
        'last_created_at' => (string) ($row['last_created_at'] ?? ''),
    ];
}

/**
 * Return one bounded keyset page for a captured archive day.
 *
 * @param array<string,mixed> $snapshot Captured day metadata.
 * @param int $afterId Last exported log id.
 * @param int $limit Maximum rows held in memory.
 * @return array<int,array<string,mixed>> Database rows.
 */
function admin_log_archive_row_batch(array $snapshot, int $afterId, int $limit = ADMIN_LOG_ARCHIVE_ROW_BATCH_SIZE): array
{
    $safeLimit = max(25, min(1000, $limit));
    $stmt = db()->prepare(
        'SELECT l.*, u.username FROM admin_logs l LEFT JOIN users u ON u.id = l.user_id'
        . ' WHERE l.created_at >= ? AND l.created_at < ? AND l.id > ? AND l.id <= ?'
        . ' ORDER BY l.id ASC LIMIT ' . $safeLimit
    );
    $stmt->execute([
        (string) ($snapshot['period_start'] ?? ''),
        (string) ($snapshot['period_end'] ?? ''),
        max(0, $afterId),
        max(0, (int) ($snapshot['last_log_id'] ?? 0)),
    ]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

/**
 * Escape one value for the static archive HTML report.
 *
 * @param mixed $value Value to display.
 * @return string HTML-safe text.
 */
function admin_log_archive_html_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Write the static Admin log archive HTML header.
 *
 * @param resource $handle Writable HTML stream.
 * @param array<string,mixed> $snapshot Archive snapshot.
 */
function admin_log_archive_write_html_header($handle, array $snapshot): void
{
    $date = admin_log_archive_html_escape((string) ($snapshot['date'] ?? ''));
    $rowCount = admin_log_archive_html_escape((string) ($snapshot['row_count'] ?? 0));
    $language = function_exists(__NAMESPACE__ . '\\translation_active_language') ? translation_active_language() : 'en';
    $title = t('admin.logs.archive.html.title', 'PHP Gallery Admin Logs {date}', ['date' => (string) ($snapshot['date'] ?? '')]);
    fwrite($handle, '<!doctype html><html lang="' . admin_log_archive_html_escape($language) . '"><head><meta charset="utf-8">');
    fwrite($handle, '<meta name="viewport" content="width=device-width,initial-scale=1">');
    fwrite($handle, '<title>' . admin_log_archive_html_escape($title) . '</title>');
    fwrite($handle, '<style>body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;margin:0;background:#f4f5f7;color:#17191c}main{max-width:1400px;margin:0 auto;padding:24px}header{margin-bottom:24px}.meta{color:#5b616b}.log{background:#fff;border:1px solid #d9dde3;border-radius:10px;padding:16px;margin:0 0 14px}.log h2{font-size:1rem;margin:0 0 10px;overflow-wrap:anywhere}.fields{display:grid;grid-template-columns:minmax(150px,220px) minmax(0,1fr);gap:6px 14px;margin:0}.fields dt{font-weight:700}.fields dd{margin:0;overflow-wrap:anywhere}.message,.context{white-space:pre-wrap;overflow-wrap:anywhere;background:#f7f8fa;border:1px solid #e2e5e9;border-radius:7px;padding:10px}.context{overflow:auto}code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}@media(max-width:700px){main{padding:12px}.fields{grid-template-columns:1fr}.fields dd{margin-bottom:8px}}</style>');
    fwrite($handle, '</head><body><main><header><h1>' . admin_log_archive_html_escape(t('admin.logs.archive.html.heading', 'PHP Gallery Admin Logs: {date}', ['date' => (string) ($snapshot['date'] ?? '')])) . '</h1>');
    fwrite($handle, '<p class="meta">' . admin_log_archive_html_escape(t('admin.logs.archive.html.description', 'Static archive. {count} log entries. Everything is expanded and requires no JavaScript or server-side code.', ['count' => (string) ($snapshot['row_count'] ?? 0)])) . '</p></header>');
}

/**
 * Write one fully expanded log entry to the static HTML archive.
 *
 * @param resource $handle Writable HTML stream.
 * @param array<string,mixed> $entry Normalized archive entry.
 * @param int $index One-based archive row index.
 */
function admin_log_archive_write_html_entry($handle, array $entry, int $index): void
{
    $eventKey = admin_log_archive_html_escape((string) ($entry['event_key'] ?? ''));
    $message = admin_log_archive_html_escape((string) ($entry['message'] ?? ''));
    $context = $entry['context'] ?? [];
    $contextJson = is_array($context)
        ? json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : '';
    if ($contextJson === false) {
        $contextJson = (string) ($entry['context_json'] ?? '');
    }

    fwrite($handle, '<article class="log"><h2>#' . $index . ' <code>' . $eventKey . '</code></h2><dl class="fields">');
    $fields = [
        t('admin.logs.archive.html.log_id', 'Log ID') => $entry['id'] ?? '',
        t('admin.logs.archive.html.created_at', 'Created at') => $entry['created_at'] ?? '',
        t('admin.logs.archive.html.status', 'Status') => $entry['status'] ?? '',
        t('admin.logs.archive.html.status_label', 'Status label') => $entry['status_label'] ?? '',
        t('admin.logs.archive.html.status_updated_at', 'Status updated at') => $entry['status_updated_at'] ?? '',
        t('admin.logs.archive.html.level', 'Level') => $entry['level'] ?? '',
        t('admin.logs.archive.html.severity', 'Severity') => $entry['severity'] ?? '',
        t('admin.logs.archive.html.category', 'Category') => $entry['category'] ?? '',
        t('admin.logs.archive.html.user_id', 'User ID') => $entry['user_id'] ?? '',
        t('admin.logs.archive.html.username', 'Username') => $entry['username'] ?? '',
        t('admin.logs.archive.html.subject_type', 'Subject type') => $entry['subject_type'] ?? '',
        t('admin.logs.archive.html.subject_id', 'Subject ID') => $entry['subject_id'] ?? '',
        t('admin.logs.archive.html.request_id', 'Request ID') => $entry['request_id'] ?? '',
        t('admin.logs.archive.html.route', 'Route') => $entry['route_name'] ?? '',
        t('admin.logs.archive.html.http_method', 'HTTP method') => $entry['http_method'] ?? '',
        'AJAX' => !empty($entry['is_ajax']) ? t('admin.logs.archive.html.yes', 'yes') : t('admin.logs.archive.html.no', 'no'),
        t('admin.logs.archive.html.fingerprint', 'Fingerprint') => $entry['fingerprint'] ?? '',
        t('admin.logs.archive.html.resolved_at', 'Resolved at') => $entry['resolved_at'] ?? '',
        t('admin.logs.archive.html.resolution_note', 'Resolution note') => $entry['resolution_note'] ?? '',
    ];
    foreach ($fields as $label => $value) {
        fwrite($handle, '<dt>' . admin_log_archive_html_escape($label) . '</dt><dd>' . admin_log_archive_html_escape($value) . '</dd>');
    }
    fwrite($handle, '</dl><h3>' . admin_log_archive_html_escape(t('admin.logs.archive.html.message', 'Message')) . '</h3><div class="message">' . $message . '</div><h3>' . admin_log_archive_html_escape(t('admin.logs.archive.html.context', 'Context')) . '</h3><pre class="context">' . admin_log_archive_html_escape($contextJson) . '</pre></article>');
}

/**
 * Write one complete day to temporary JSON and HTML files using bounded row batches.
 *
 * @param array<string,mixed> $snapshot Captured day metadata.
 * @param string $jsonPath Temporary JSON path.
 * @param string $htmlPath Temporary HTML path.
 * @return int Number of rows written.
 */
function admin_log_archive_write_day_files(array $snapshot, string $jsonPath, string $htmlPath): int
{
    $jsonHandle = fopen($jsonPath, 'wb');
    $htmlHandle = fopen($htmlPath, 'wb');
    if ($jsonHandle === false || $htmlHandle === false) {
        if (is_resource($jsonHandle)) {
            fclose($jsonHandle);
        }
        if (is_resource($htmlHandle)) {
            fclose($htmlHandle);
        }
        throw new RuntimeException('Unable to open temporary Admin log archive files.');
    }

    $written = 0;
    try {
        $metadata = [
            'schema' => ADMIN_LOG_ARCHIVE_SCHEMA,
            'date' => (string) ($snapshot['date'] ?? ''),
            'period_start' => (string) ($snapshot['period_start'] ?? ''),
            'period_end' => (string) ($snapshot['period_end'] ?? ''),
            'created_at' => now_sql(),
            'row_count' => max(0, (int) ($snapshot['row_count'] ?? 0)),
            'first_log_id' => max(0, (int) ($snapshot['first_log_id'] ?? 0)),
            'last_log_id' => max(0, (int) ($snapshot['last_log_id'] ?? 0)),
            'columns' => admin_log_export_columns(),
        ];
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metadataJson === false) {
            throw new RuntimeException('Unable to encode Admin log archive metadata.');
        }
        fwrite($jsonHandle, "{\n  \"archive\": " . $metadataJson . ",\n  \"logs\": [");
        admin_log_archive_write_html_header($htmlHandle, $snapshot);

        $afterId = 0;
        $firstJsonRow = true;
        while (true) {
            $batch = admin_log_archive_row_batch($snapshot, $afterId, ADMIN_LOG_ARCHIVE_ROW_BATCH_SIZE);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                $normalized = admin_log_export_normalize_entry($row);
                $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($encoded === false) {
                    throw new RuntimeException('Unable to encode Admin log archive row: ' . json_last_error_msg());
                }
                fwrite($jsonHandle, ($firstJsonRow ? "\n" : ",\n") . '    ' . $encoded);
                $firstJsonRow = false;
                $written++;
                admin_log_archive_write_html_entry($htmlHandle, $normalized, $written);
                $afterId = max($afterId, (int) ($row['id'] ?? 0));
            }
            if (count($batch) < ADMIN_LOG_ARCHIVE_ROW_BATCH_SIZE) {
                break;
            }
        }

        fwrite($jsonHandle, ($firstJsonRow ? '' : "\n") . "  ]\n}\n");
        fwrite($htmlHandle, '</main></body></html>');
    } finally {
        fclose($jsonHandle);
        fclose($htmlHandle);
    }

    if ($written !== (int) ($snapshot['row_count'] ?? 0)) {
        throw new RuntimeException('Admin log archive row count changed during export. No database rows were deleted.');
    }
    return $written;
}

/**
 * Return the application version string stored in archive manifests.
 *
 * @return string Version label.
 */
function admin_log_archive_application_version(): string
{
    $constant = 'Gallery\\Core\\CMS_VERSION';
    return defined($constant) ? (string) constant($constant) : 'unknown';
}

/**
 * Build the self-describing manifest for one completed day archive.
 *
 * @param array<string,mixed> $snapshot Captured day metadata.
 * @param string $jsonPath Generated JSON file.
 * @param string $htmlPath Generated HTML file.
 * @return array<string,mixed> Manifest data.
 */
function admin_log_archive_manifest(array $snapshot, string $jsonPath, string $htmlPath): array
{
    return [
        'schema' => ADMIN_LOG_ARCHIVE_SCHEMA,
        'archive_version' => 1,
        'application_version' => admin_log_archive_application_version(),
        'date' => (string) ($snapshot['date'] ?? ''),
        'period_start' => (string) ($snapshot['period_start'] ?? ''),
        'period_end' => (string) ($snapshot['period_end'] ?? ''),
        'created_at' => now_sql(),
        'row_count' => max(0, (int) ($snapshot['row_count'] ?? 0)),
        'first_log_id' => max(0, (int) ($snapshot['first_log_id'] ?? 0)),
        'last_log_id' => max(0, (int) ($snapshot['last_log_id'] ?? 0)),
        'first_created_at' => (string) ($snapshot['first_created_at'] ?? ''),
        'last_created_at' => (string) ($snapshot['last_created_at'] ?? ''),
        'json_file' => 'admin-logs-' . (string) ($snapshot['date'] ?? '') . '.json',
        'html_file' => 'admin-logs-' . (string) ($snapshot['date'] ?? '') . '.html',
        'json_bytes' => is_file($jsonPath) ? max(0, (int) filesize($jsonPath)) : 0,
        'html_bytes' => is_file($htmlPath) ? max(0, (int) filesize($htmlPath)) : 0,
        'json_sha256' => is_file($jsonPath) ? hash_file('sha256', $jsonPath) : '',
        'html_sha256' => is_file($htmlPath) ? hash_file('sha256', $htmlPath) : '',
    ];
}

/**
 * Create and finalize one immutable daily Admin log ZIP archive.
 *
 * @param array<string,mixed> $snapshot Captured day metadata.
 * @return array<string,mixed> Created archive metadata.
 */
function admin_log_archive_create_day_zip(array $snapshot): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive is not available. Admin logs cannot be archived safely.');
    }
    $date = (string) ($snapshot['date'] ?? '');
    $finalPath = admin_log_archive_path($date, true);
    if (is_file($finalPath)) {
        throw new RuntimeException('The Admin log archive already exists and must be reconciled instead of overwritten.');
    }

    $directory = dirname($finalPath);
    $jsonPath = tempnam($directory, '.admin-log-json-');
    $htmlPath = tempnam($directory, '.admin-log-html-');
    $zipPath = tempnam($directory, '.admin-log-zip-');
    if ($jsonPath === false || $htmlPath === false || $zipPath === false) {
        foreach ([$jsonPath, $htmlPath, $zipPath] as $temporary) {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
        }
        throw new RuntimeException('Unable to allocate temporary Admin log archive files.');
    }

    $zip = null;
    try {
        $written = admin_log_archive_write_day_files($snapshot, $jsonPath, $htmlPath);
        $manifest = admin_log_archive_manifest($snapshot, $jsonPath, $htmlPath);
        if ($written !== (int) ($manifest['row_count'] ?? -1)) {
            throw new RuntimeException('Admin log archive manifest row count does not match the exported data.');
        }
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($manifestJson === false) {
            throw new RuntimeException('Unable to encode the Admin log archive manifest.');
        }

        // tempnam() creates an empty file. Remove that placeholder so ZipArchive creates
        // a fresh archive instead of relying on platform-specific empty-file handling.
        if (is_file($zipPath) && !@unlink($zipPath)) {
            throw new RuntimeException('Unable to prepare the temporary Admin log ZIP archive.');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the Admin log daily ZIP archive.');
        }
        $jsonName = (string) $manifest['json_file'];
        $htmlName = (string) $manifest['html_file'];
        if (!$zip->addFile($jsonPath, $jsonName)
            || !$zip->addFile($htmlPath, $htmlName)
            || !$zip->addFromString('manifest.json', $manifestJson . "\n")) {
            throw new RuntimeException('Unable to add files to the Admin log daily ZIP archive.');
        }
        if (!$zip->close()) {
            throw new RuntimeException('Unable to finalize the Admin log daily ZIP archive.');
        }
        $zip = null;

        $verification = admin_log_archive_verify_file($zipPath, $date);
        if (empty($verification['ok'])) {
            throw new RuntimeException('Admin log archive verification failed: ' . (string) ($verification['error'] ?? 'unknown error'));
        }
        if (!@rename($zipPath, $finalPath)) {
            throw new RuntimeException('Unable to atomically publish the verified Admin log archive.');
        }
        $zipPath = '';

        return [
            'date' => $date,
            'path' => $finalPath,
            'file_name' => basename($finalPath),
            'row_count' => (int) $manifest['row_count'],
            'zip_bytes' => max(0, (int) filesize($finalPath)),
            'manifest' => $manifest,
        ];
    } finally {
        if ($zip instanceof ZipArchive) {
            $zip->close();
        }
        foreach ([$jsonPath, $htmlPath, $zipPath] as $temporary) {
            if (is_string($temporary) && $temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}

/**
 * Read only the small manifest member from one archive ZIP.
 *
 * @param string $path Archive path.
 * @return array<string,mixed> Manifest data or an empty array.
 */
function admin_log_archive_read_manifest(string $path): array
{
    if (!class_exists(ZipArchive::class) || !is_file($path)) {
        return [];
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        return [];
    }
    try {
        $json = $zip->getFromName('manifest.json');
        if (!is_string($json) || $json === '') {
            return [];
        }
        $manifest = json_decode($json, true);
        return is_array($manifest) ? $manifest : [];
    } finally {
        $zip->close();
    }
}

/**
 * Hash one ZIP member without loading the member into PHP memory.
 *
 * @param ZipArchive $zip Open ZIP archive.
 * @param string $entryName Member name.
 * @return string SHA-256 hash or an empty string on failure.
 */
function admin_log_archive_zip_entry_sha256(ZipArchive $zip, string $entryName): string
{
    $stream = $zip->getStream($entryName);
    if (!is_resource($stream)) {
        return '';
    }
    $context = hash_init('sha256');
    hash_update_stream($context, $stream);
    fclose($stream);
    return hash_final($context);
}

/**
 * Fully verify one archive ZIP before database cleanup relies on it.
 *
 * @param string $path Archive path.
 * @param string $expectedDate Optional expected archive date.
 * @return array<string,mixed> Verification result and manifest.
 */
function admin_log_archive_verify_file(string $path, string $expectedDate = ''): array
{
    if (!class_exists(ZipArchive::class)) {
        return ['ok' => false, 'error' => 'ZipArchive is unavailable.', 'manifest' => []];
    }
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'Archive file does not exist.', 'manifest' => []];
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        return ['ok' => false, 'error' => 'Archive file cannot be opened.', 'manifest' => []];
    }
    try {
        $manifestJson = $zip->getFromName('manifest.json');
        $manifest = is_string($manifestJson) ? json_decode($manifestJson, true) : null;
        if (!is_array($manifest)) {
            return ['ok' => false, 'error' => 'manifest.json is missing or invalid.', 'manifest' => []];
        }
        $date = (string) ($manifest['date'] ?? '');
        if (!admin_log_archive_valid_date($date) || ($expectedDate !== '' && $date !== $expectedDate)) {
            return ['ok' => false, 'error' => 'Archive date does not match the expected day.', 'manifest' => $manifest];
        }
        if ((string) ($manifest['schema'] ?? '') !== ADMIN_LOG_ARCHIVE_SCHEMA) {
            return ['ok' => false, 'error' => 'Archive schema is unsupported.', 'manifest' => $manifest];
        }
        $jsonName = (string) ($manifest['json_file'] ?? '');
        $htmlName = (string) ($manifest['html_file'] ?? '');
        if ($jsonName === '' || $htmlName === '' || $zip->locateName($jsonName) === false || $zip->locateName($htmlName) === false) {
            return ['ok' => false, 'error' => 'Archive JSON or HTML member is missing.', 'manifest' => $manifest];
        }
        $jsonExpectedHash = strtolower((string) ($manifest['json_sha256'] ?? ''));
        $htmlExpectedHash = strtolower((string) ($manifest['html_sha256'] ?? ''));
        if (!preg_match('/^[a-f0-9]{64}$/', $jsonExpectedHash) || !preg_match('/^[a-f0-9]{64}$/', $htmlExpectedHash)) {
            return ['ok' => false, 'error' => 'Archive member checksums are missing or invalid.', 'manifest' => $manifest];
        }
        $jsonActualHash = admin_log_archive_zip_entry_sha256($zip, $jsonName);
        $htmlActualHash = admin_log_archive_zip_entry_sha256($zip, $htmlName);
        if (!preg_match('/^[a-f0-9]{64}$/', $jsonActualHash) || !hash_equals($jsonExpectedHash, $jsonActualHash)) {
            return ['ok' => false, 'error' => 'Archive JSON checksum does not match.', 'manifest' => $manifest];
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $htmlActualHash) || !hash_equals($htmlExpectedHash, $htmlActualHash)) {
            return ['ok' => false, 'error' => 'Archive HTML checksum does not match.', 'manifest' => $manifest];
        }
        return ['ok' => true, 'error' => '', 'manifest' => $manifest];
    } finally {
        $zip->close();
    }
}

/**
 * Count database rows still represented by one verified archive manifest.
 *
 * @param array<string,mixed> $manifest Verified archive manifest.
 * @return int Remaining represented rows.
 */
function admin_log_archive_remaining_database_rows(array $manifest): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM admin_logs WHERE created_at >= ? AND created_at < ? AND id >= ? AND id <= ?'
    );
    $stmt->execute([
        (string) ($manifest['period_start'] ?? ''),
        (string) ($manifest['period_end'] ?? ''),
        max(0, (int) ($manifest['first_log_id'] ?? 0)),
        max(0, (int) ($manifest['last_log_id'] ?? 0)),
    ]);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Delete only rows already preserved by a verified immutable archive.
 *
 * Cleanup is intentionally chunked. If a request stops between batches, the next
 * maintenance call verifies the same ZIP and safely continues deleting the remainder.
 *
 * @param array<string,mixed> $manifest Verified archive manifest.
 * @param ?float $deadline Optional runtime deadline.
 * @return array<string,mixed> Cleanup result.
 */
function admin_log_archive_delete_verified_rows(array $manifest, ?float $deadline = null): array
{
    $expectedRows = max(0, (int) ($manifest['row_count'] ?? 0));
    $remainingBefore = admin_log_archive_remaining_database_rows($manifest);
    if ($remainingBefore > $expectedRows) {
        throw new RuntimeException('Database rows no longer match the verified Admin log archive. Cleanup was stopped.');
    }

    $deleted = 0;
    while (true) {
        if ($deadline !== null && microtime(true) >= ($deadline - 0.25)) {
            break;
        }
        $stmt = db()->prepare(
            'DELETE FROM admin_logs WHERE created_at >= ? AND created_at < ? AND id >= ? AND id <= ?'
            . ' ORDER BY id ASC LIMIT ' . ADMIN_LOG_ARCHIVE_DELETE_BATCH_SIZE
        );
        $stmt->execute([
            (string) ($manifest['period_start'] ?? ''),
            (string) ($manifest['period_end'] ?? ''),
            max(0, (int) ($manifest['first_log_id'] ?? 0)),
            max(0, (int) ($manifest['last_log_id'] ?? 0)),
        ]);
        $batchDeleted = max(0, $stmt->rowCount());
        $deleted += $batchDeleted;
        if ($batchDeleted < ADMIN_LOG_ARCHIVE_DELETE_BATCH_SIZE) {
            break;
        }
    }

    $remaining = admin_log_archive_remaining_database_rows($manifest);
    return [
        'deleted_rows' => $deleted,
        'remaining_rows' => $remaining,
        'complete' => $remaining === 0,
    ];
}

/**
 * Reconcile an already-created daily ZIP after an interrupted prior maintenance call.
 *
 * @param string $date Canonical archive date.
 * @param ?float $deadline Optional runtime deadline.
 * @return array<string,mixed> Reconciliation result.
 */
function admin_log_archive_reconcile_existing(string $date, ?float $deadline = null): array
{
    $path = admin_log_archive_path($date);
    $verification = admin_log_archive_verify_file($path, $date);
    if (empty($verification['ok'])) {
        throw new RuntimeException('Existing Admin log archive failed verification: ' . (string) ($verification['error'] ?? 'unknown error'));
    }
    $manifest = (array) ($verification['manifest'] ?? []);
    $cleanup = admin_log_archive_delete_verified_rows($manifest, $deadline);
    return [
        'archive_created' => false,
        'archive_reused' => true,
        'archive_date' => $date,
        'archived_rows' => max(0, (int) ($manifest['row_count'] ?? 0)),
        'deleted_rows' => (int) ($cleanup['deleted_rows'] ?? 0),
        'cleanup_remaining_rows' => (int) ($cleanup['remaining_rows'] ?? 0),
        'cleanup_complete' => !empty($cleanup['complete']),
        'archive_path' => $path,
    ];
}

/**
 * Acquire the non-blocking cross-request archive-maintenance lock.
 *
 * @return resource|false Open locked handle, or false when another invocation owns it.
 */
function admin_log_archive_acquire_lock()
{
    $path = admin_log_archive_cache_dir() . DIRECTORY_SEPARATOR . ADMIN_LOG_ARCHIVE_LOCK_FILE;
    $handle = @fopen($path, 'c+');
    if (!is_resource($handle)) {
        throw new RuntimeException('Unable to open the Admin log archive maintenance lock.');
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return false;
    }
    ftruncate($handle, 0);
    fwrite($handle, json_encode(['pid' => getmypid(), 'started_at' => now_sql()], JSON_UNESCAPED_SLASHES) . "\n");
    fflush($handle);
    return $handle;
}

/**
 * Release the cross-request archive-maintenance lock.
 *
 * @param resource|false $handle Lock handle.
 */
function admin_log_archive_release_lock($handle): void
{
    if (!is_resource($handle)) {
        return;
    }
    @flock($handle, LOCK_UN);
    fclose($handle);
}

/**
 * Persist the result and next lightweight request-counter deadline.
 *
 * @param array<string,mixed> $result Maintenance result.
 * @param int $retentionDays Current retention days.
 */
function admin_log_archive_schedule_after_result(array $result, int $retentionDays): void
{
    $now = time();
    if ($retentionDays === 0) {
        $nextRun = $now + ADMIN_LOG_ARCHIVE_INTERVAL_SECONDS;
    } elseif (!empty($result['busy']) || !empty($result['has_more'])) {
        $nextRun = $now + ADMIN_LOG_ARCHIVE_BACKLOG_RETRY_SECONDS;
    } elseif (empty($result['ok'])) {
        $nextRun = $now + ADMIN_LOG_ARCHIVE_FAILURE_RETRY_SECONDS;
    } else {
        $nextRun = $now + ADMIN_LOG_ARCHIVE_INTERVAL_SECONDS;
    }

    $state = admin_log_archive_state();
    $state['retention_days'] = $retentionDays;
    $state['last_attempt_at'] = now_sql();
    if (!empty($result['ok']) && empty($result['busy'])) {
        $state['last_success_at'] = now_sql();
    }
    $state['next_run_at'] = $nextRun;
    $state['last_result'] = $result;
    admin_log_archive_write_state($state);
}

/**
 * Run one safe Admin log archive maintenance cycle.
 *
 * One invocation handles at most one historical day. This is deliberate: normal
 * gallery requests only need to contribute a bounded amount of background work.
 * When a backlog remains, the lightweight counter becomes due again shortly.
 *
 * @param array<string,mixed> $options Optional force/deadline/source values.
 * @return array<string,mixed> Maintenance result.
 */
function admin_log_archive_maintenance_run(array $options = []): array
{
    $source = trim((string) ($options['source'] ?? 'automatic'));
    $force = !empty($options['force']);
    $deadline = isset($options['deadline']) ? (float) $options['deadline'] : null;
    $retentionDays = admin_log_archive_retention_days();

    if ($retentionDays === 0 && !$force) {
        $result = [
            'ok' => true,
            'busy' => false,
            'enabled' => false,
            'retention_days' => 0,
            'has_more' => false,
            'reason' => 'disabled',
            'source' => $source,
        ];
        admin_log_archive_schedule_after_result($result, $retentionDays);
        return $result;
    }
    if ($retentionDays === 0 && $force) {
        $result = [
            'ok' => true,
            'busy' => false,
            'enabled' => false,
            'retention_days' => 0,
            'has_more' => false,
            'reason' => 'retention_forever',
            'source' => $source,
        ];
        admin_log_archive_schedule_after_result($result, $retentionDays);
        return $result;
    }
    if (!admin_log_schema_ready()) {
        $result = [
            'ok' => false,
            'busy' => false,
            'enabled' => true,
            'retention_days' => $retentionDays,
            'has_more' => false,
            'reason' => 'schema_unavailable',
            'source' => $source,
        ];
        admin_log_archive_schedule_after_result($result, $retentionDays);
        return $result;
    }

    $lock = admin_log_archive_acquire_lock();
    if ($lock === false) {
        $result = [
            'ok' => true,
            'busy' => true,
            'enabled' => true,
            'retention_days' => $retentionDays,
            'has_more' => true,
            'reason' => 'already_running',
            'source' => $source,
        ];
        admin_log_archive_schedule_after_result($result, $retentionDays);
        return $result;
    }

    try {
        $eligibleBefore = admin_log_archive_eligible_before($retentionDays);
        $date = admin_log_archive_oldest_eligible_date($eligibleBefore);
        if ($date === null) {
            $result = [
                'ok' => true,
                'busy' => false,
                'enabled' => true,
                'retention_days' => $retentionDays,
                'eligible_before' => $eligibleBefore,
                'has_more' => false,
                'reason' => 'caught_up',
                'source' => $source,
            ];
            admin_log_archive_schedule_after_result($result, $retentionDays);
            return $result;
        }

        $path = admin_log_archive_path($date);
        if (is_file($path)) {
            $dayResult = admin_log_archive_reconcile_existing($date, $deadline);
        } else {
            $snapshot = admin_log_archive_day_snapshot($date);
            if ((int) ($snapshot['row_count'] ?? 0) <= 0) {
                throw new RuntimeException('The selected Admin log archive day contains no rows.');
            }
            $created = admin_log_archive_create_day_zip($snapshot);
            $verification = admin_log_archive_verify_file((string) $created['path'], $date);
            if (empty($verification['ok'])) {
                throw new RuntimeException('Published Admin log archive failed final verification.');
            }
            $cleanup = admin_log_archive_delete_verified_rows((array) $verification['manifest'], $deadline);
            $dayResult = [
                'archive_created' => true,
                'archive_reused' => false,
                'archive_date' => $date,
                'archived_rows' => (int) ($created['row_count'] ?? 0),
                'archive_bytes' => (int) ($created['zip_bytes'] ?? 0),
                'deleted_rows' => (int) ($cleanup['deleted_rows'] ?? 0),
                'cleanup_remaining_rows' => (int) ($cleanup['remaining_rows'] ?? 0),
                'cleanup_complete' => !empty($cleanup['complete']),
                'archive_path' => (string) ($created['path'] ?? ''),
            ];
        }

        $hasMore = empty($dayResult['cleanup_complete']);
        if (!$hasMore) {
            $hasMore = admin_log_archive_oldest_eligible_date($eligibleBefore) !== null;
        }
        $result = array_merge($dayResult, [
            'ok' => true,
            'busy' => false,
            'enabled' => true,
            'retention_days' => $retentionDays,
            'eligible_before' => $eligibleBefore,
            'has_more' => $hasMore,
            'reason' => $hasMore ? 'backlog_remaining' : 'caught_up',
            'source' => $source,
        ]);
        admin_log_archive_schedule_after_result($result, $retentionDays);

        admin_log_event('info', 'admin_log.archive_maintenance_completed', 'Admin log archive maintenance cycle completed.', [
            'source' => $source,
            'retention_days' => $retentionDays,
            'archive_date' => (string) ($result['archive_date'] ?? ''),
            'archive_created' => !empty($result['archive_created']),
            'archive_reused' => !empty($result['archive_reused']),
            'archived_rows' => (int) ($result['archived_rows'] ?? 0),
            'deleted_rows' => (int) ($result['deleted_rows'] ?? 0),
            'cleanup_remaining_rows' => (int) ($result['cleanup_remaining_rows'] ?? 0),
            'has_more' => $hasMore,
        ], [
            'severity' => 'info',
            'category' => 'admin',
        ]);
        return $result;
    } catch (Throwable $exception) {
        $result = [
            'ok' => false,
            'busy' => false,
            'enabled' => true,
            'retention_days' => $retentionDays,
            'has_more' => true,
            'reason' => 'failed',
            'source' => $source,
            'error' => $exception->getMessage(),
        ];
        try {
            admin_log_archive_schedule_after_result($result, $retentionDays);
        } catch (Throwable) {
        }
        try {
            admin_log_event('error', 'admin_log.archive_maintenance_failed', 'Admin log archive maintenance failed.', [
                'source' => $source,
                'retention_days' => $retentionDays,
                'error' => $exception->getMessage(),
            ], [
                'severity' => 'error',
                'category' => 'admin',
            ]);
        } catch (Throwable) {
        }
        return $result;
    } finally {
        admin_log_archive_release_lock($lock);
    }
}

/**
 * Return whether the tiny 24-hour request counter says archive maintenance is due.
 *
 * Most requests return after one small protected cache-file read. The DB-backed
 * retention setting is consulted only when that counter is actually due.
 *
 * @param ?int $now Optional epoch used by tests.
 * @return bool True when automatic archive maintenance should be registered.
 */
function admin_log_archive_request_trigger_due(?int $now = null): bool
{
    $now = $now ?? time();
    $state = admin_log_archive_state();
    $nextRunAt = max(0, (int) ($state['next_run_at'] ?? 0));
    if ($nextRunAt > $now) {
        return false;
    }

    $retentionDays = admin_log_archive_retention_days();
    if ($retentionDays === 0) {
        admin_log_archive_schedule_after_result([
            'ok' => true,
            'busy' => false,
            'enabled' => false,
            'retention_days' => 0,
            'has_more' => false,
            'reason' => 'disabled',
            'source' => 'request_counter',
        ], 0);
        return false;
    }
    return true;
}

/**
 * Return whether one routed request is a normal page load suitable for background archival.
 *
 * @param string $page Route key.
 * @return bool True for normal gallery/Admin page loads.
 */
function admin_log_archive_route_allows_request_trigger(string $page): bool
{
    return in_array($page, ['home', 'gallery', 'share', 'tag', 'admin', 'admin_logs'], true);
}

/**
 * Finish the visible response before archive work when the server supports it.
 */
function admin_log_archive_finish_response_before_background_work(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
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
 * Register one after-response archive maintenance cycle when the 24-hour counter is due.
 *
 * @param string $page Current route key.
 */
function admin_log_archive_register_request_trigger(string $page): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)
        || !admin_log_archive_route_allows_request_trigger($page)
        || !admin_log_archive_request_trigger_due()) {
        return;
    }

    register_shutdown_function(static function (): void {
        if (!admin_log_archive_request_trigger_due()) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        admin_log_archive_finish_response_before_background_work();
        admin_log_archive_maintenance_run([
            'source' => 'request_trigger',
            'force' => false,
        ]);
    });
}

/**
 * Return filesystem archive paths in newest-first order.
 *
 * @return array<int,string> Canonical ZIP paths.
 */
function admin_log_archive_paths(): array
{
    $root = admin_log_archive_root_dir();
    if (!is_dir($root)) {
        return [];
    }
    $paths = glob($root . DIRECTORY_SEPARATOR . '[0-9][0-9][0-9][0-9]' . DIRECTORY_SEPARATOR . '[0-9][0-9]' . DIRECTORY_SEPARATOR . 'admin-logs-????-??-??.zip') ?: [];
    $paths = array_values(array_filter($paths, static fn (string $path): bool => is_file($path)));
    rsort($paths, SORT_STRING);
    return $paths;
}

/**
 * Return archive inventory totals without opening every ZIP.
 *
 * @return array<string,mixed> Inventory summary.
 */
function admin_log_archive_inventory(): array
{
    $paths = admin_log_archive_paths();
    $totalBytes = 0;
    foreach ($paths as $path) {
        $size = filesize($path);
        if ($size !== false) {
            $totalBytes += max(0, (int) $size);
        }
    }
    $newestDate = $paths !== [] ? substr(basename($paths[0]), 11, 10) : '';
    $oldestDate = $paths !== [] ? substr(basename($paths[count($paths) - 1]), 11, 10) : '';
    return [
        'count' => count($paths),
        'total_bytes' => $totalBytes,
        'newest_date' => admin_log_archive_valid_date($newestDate) ? $newestDate : '',
        'oldest_date' => admin_log_archive_valid_date($oldestDate) ? $oldestDate : '',
    ];
}

/**
 * Return one paginated filesystem-backed archive listing.
 *
 * Only visible ZIP manifests are opened, keeping the archive browser inexpensive
 * even after years of daily files have accumulated.
 *
 * @param int $page One-based page number.
 * @param int $perPage Items per page.
 * @return array<string,mixed> Pagination and visible archive metadata.
 */
function admin_log_archive_list(int $page = 1, int $perPage = ADMIN_LOG_ARCHIVE_LIST_PAGE_SIZE): array
{
    $paths = admin_log_archive_paths();
    $safePerPage = max(10, min(100, $perPage));
    $total = count($paths);
    $pages = max(1, (int) ceil($total / $safePerPage));
    $safePage = max(1, min($pages, $page));
    $slice = array_slice($paths, ($safePage - 1) * $safePerPage, $safePerPage);
    $items = [];
    foreach ($slice as $path) {
        $fileName = basename($path);
        $date = substr($fileName, 11, 10);
        if (!admin_log_archive_valid_date($date)) {
            continue;
        }
        $manifest = admin_log_archive_read_manifest($path);
        $items[] = [
            'date' => $date,
            'file_name' => $fileName,
            'path' => $path,
            'bytes' => max(0, (int) (filesize($path) ?: 0)),
            'row_count' => max(0, (int) ($manifest['row_count'] ?? 0)),
            'created_at' => (string) ($manifest['created_at'] ?? ''),
            'manifest_available' => $manifest !== [],
        ];
    }
    return [
        'items' => $items,
        'page' => $safePage,
        'pages' => $pages,
        'per_page' => $safePerPage,
        'total' => $total,
    ];
}

/**
 * Stream one member of a filesystem archive without extracting it permanently.
 *
 * @param string $date Canonical archive date.
 * @param string $kind html or json.
 */
function admin_log_archive_stream_member(string $date, string $kind): void
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive is not available.');
    }
    $path = admin_log_archive_path($date);
    if (!is_file($path)) {
        throw new RuntimeException('Admin log archive not found.');
    }
    $manifest = admin_log_archive_read_manifest($path);
    $memberName = $kind === 'json'
        ? (string) ($manifest['json_file'] ?? '')
        : (string) ($manifest['html_file'] ?? '');
    if ($memberName === '') {
        throw new RuntimeException('Admin log archive member metadata is missing.');
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Unable to open Admin log archive.');
    }
    $stream = $zip->getStream($memberName);
    if (!is_resource($stream)) {
        $zip->close();
        throw new RuntimeException('Unable to read Admin log archive member.');
    }
    try {
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                throw new RuntimeException('Unable to stream Admin log archive member.');
            }
            echo $chunk;
            if (connection_aborted()) {
                break;
            }
        }
    } finally {
        fclose($stream);
        $zip->close();
    }
}

/**
 * Stream one immutable archive ZIP to the authenticated administrator.
 *
 * @param string $date Canonical archive date.
 */
function admin_log_archive_stream_zip(string $date): void
{
    $path = admin_log_archive_path($date);
    if (!is_file($path)) {
        throw new RuntimeException('Admin log archive not found.');
    }
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to open Admin log archive for download.');
    }
    try {
        fpassthru($handle);
    } finally {
        fclose($handle);
    }
}

/**
 * Permanently delete one selected archive ZIP from the filesystem.
 *
 * Automatic maintenance never calls this function. Archive destruction remains
 * an explicit authenticated Admin Logs action only.
 *
 * @param string $date Canonical archive date.
 * @return bool True when a file was deleted.
 */
function admin_log_archive_delete_file(string $date): bool
{
    $path = admin_log_archive_path($date);
    if (!is_file($path)) {
        return false;
    }
    if (!@unlink($path)) {
        throw new RuntimeException('Unable to delete the selected Admin log archive file.');
    }
    return true;
}

/**
 * Return Admin-log archive maintenance status for the Logs page.
 *
 * @return array<string,mixed> Status data.
 */
function admin_log_archive_status(): array
{
    $retentionDays = admin_log_archive_retention_days();
    $state = admin_log_archive_state();
    return [
        'retention_days' => $retentionDays,
        'enabled' => $retentionDays !== 0,
        'eligible_before' => admin_log_archive_eligible_before($retentionDays),
        'next_run_at' => max(0, (int) ($state['next_run_at'] ?? 0)),
        'last_attempt_at' => (string) ($state['last_attempt_at'] ?? ''),
        'last_success_at' => (string) ($state['last_success_at'] ?? ''),
        'last_result' => is_array($state['last_result'] ?? null) ? $state['last_result'] : [],
        'inventory' => admin_log_archive_inventory(),
        'zip_available' => class_exists(ZipArchive::class),
    ];
}
