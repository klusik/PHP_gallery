<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/telemetry.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns telemetry persistence and reporting queries independently of HTTP and presentation.
 *
 * Responsibilities:
 *   - Persist normalized anonymous telemetry events, sessions, and hourly aggregates
 *   - Persist telemetry settings
 *   - Read bounded telemetry dashboard and report data
 *   - Roll hourly telemetry into daily aggregates and delete expired telemetry rows
 *   - Keep telemetry SQL out of service orchestration
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
 *   - Callers must normalize domain values and validate schema capability before writes.
 *   - Dynamic table/column/dimension identifiers are constrained by explicit allowlists.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use function Gallery\Core\db;

/**
 * Bound the compatibility retention helper when no batch size is passed.
 * Type: integer.
 * Units: rows per DELETE statement.
 * Scope: telemetry retention model default only.
 * Consumers: telemetry_model_delete_older_than compatibility callers.
 * Rationale: legacy callers must remain bounded; the scheduled service supplies its configured limit.
 */
const TELEMETRY_MODEL_DEFAULT_DELETE_BATCH = 2000;

/**
 * Return a constrained SQL predicate for telemetry traffic segmentation.
 *
 * Controllers/services pass only semantic segment names. SQL stays owned here
 * and the qualified device_type column is restricted to known report aliases.
 * @param string $trafficSegment Normalized technical traffic segment, not proof of a human visitor.
 * @param string $deviceColumn Model-owned validated device column identifier.
 * @return string Result of the documented operation.
 */
function telemetry_model_traffic_segment_condition(string $trafficSegment, string $deviceColumn = 'device_type'): string
{
    if (!in_array($deviceColumn, ['device_type', 'm.device_type'], true)) {
        throw new InvalidArgumentException('Unsupported telemetry traffic-segment column.');
    }
    return match ($trafficSegment) {
        'all' => '',
        'non_bot' => ' AND ' . $deviceColumn . " IN ('desktop', 'tablet', 'phone')",
        'bot' => ' AND ' . $deviceColumn . " = 'bot'",
        'unknown' => ' AND ' . $deviceColumn . " = 'unknown'",
        default => throw new InvalidArgumentException('Unsupported telemetry traffic segment.'),
    };
}

/**
 * Execute one telemetry-report statement while collecting request-local SQL runtime evidence.
 *
 * The profiler is intentionally in-memory only. It stores a bounded operation label and timing
 * counters, never SQL text, bound values, result rows, or database error messages. Production
 * exports can therefore identify expensive report query families without creating recursive
 * telemetry writes or increasing persistent telemetry cardinality.
 *
 * @param \PDOStatement $statement Prepared statement to execute.
 * @param array<array-key,mixed> $params Bound statement parameters.
 * @param string $profileKey Bounded source-owned report operation identifier.
 * @return void No return value; effects are recorded in the owned state.
 */
function telemetry_model_profiled_report_execute(\PDOStatement $statement, array $params, string $profileKey): void
{
    static $allowedKeys = [
        'metric_sum',
        'metric_events',
        'top_photos',
        'longest_photos',
        'cache_mix',
        'storage_sizes',
        'storage_table_scan',
        'hourly_metric_cardinality',
        'session_summary',
        'daily_trends',
        'daily_sessions',
        'top_galleries',
        'top_routes',
        'metric_distribution',
        'session_distribution',
        'performance_metrics',
        'client_errors',
        'recent_events',
        'database_summary',
        'database_totals',
        'database_fingerprints',
        'job_runs',
        'rollup_consistency_hourly',
        'rollup_consistency_daily',
        'photo_open_origins',
        'database_fingerprints_volume',
        'cache_phases',
    ];
    if (!in_array($profileKey, $allowedKeys, true)) {
        throw new InvalidArgumentException('Unsupported telemetry report query profile key.');
    }

    $startedNs = hrtime(true);
    $ok = false;
    try {
        $statement->execute($params);
        $ok = true;
    } finally {
        $elapsedMs = max(0.0, (hrtime(true) - $startedNs) / 1_000_000);
        if (!isset($GLOBALS['cms_telemetry_report_query_profile']) || !is_array($GLOBALS['cms_telemetry_report_query_profile'])) {
            $GLOBALS['cms_telemetry_report_query_profile'] = [];
        }
        if (!isset($GLOBALS['cms_telemetry_report_query_profile'][$profileKey])) {
            $GLOBALS['cms_telemetry_report_query_profile'][$profileKey] = [
                'operation' => $profileKey,
                'calls' => 0,
                'failed_calls' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
            ];
        }
        $row = &$GLOBALS['cms_telemetry_report_query_profile'][$profileKey];
        $row['calls'] = (int) $row['calls'] + 1;
        $row['failed_calls'] = (int) $row['failed_calls'] + ($ok ? 0 : 1);
        $row['total_ms'] = (float) $row['total_ms'] + $elapsedMs;
        $row['max_ms'] = max((float) $row['max_ms'], $elapsedMs);
        unset($row);
    }
}

/** Reset request-local telemetry report SQL timing evidence. */
function telemetry_model_reset_report_query_profile(): void
{
    $GLOBALS['cms_telemetry_report_query_profile'] = [];
}

/**
 * Return request-local telemetry report SQL timing evidence.
 *
 * @return array<int,array{operation:string,calls:int,failed_calls:int,total_ms:float,avg_ms:float,max_ms:float}>
 */
function telemetry_model_report_query_profile(): array
{
    $state = $GLOBALS['cms_telemetry_report_query_profile'] ?? [];
    if (!is_array($state)) {
        return [];
    }

    $rows = [];
    foreach ($state as $profileKey => $row) {
        if (!is_array($row)) {
            continue;
        }
        $calls = max(0, (int) ($row['calls'] ?? 0));
        $totalMs = max(0.0, (float) ($row['total_ms'] ?? 0.0));
        $rows[] = [
            'operation' => (string) $profileKey,
            'calls' => $calls,
            'failed_calls' => max(0, (int) ($row['failed_calls'] ?? 0)),
            'total_ms' => $totalMs,
            'avg_ms' => $calls > 0 ? $totalMs / $calls : 0.0,
            'max_ms' => max(0.0, (float) ($row['max_ms'] ?? 0.0)),
        ];
    }
    usort($rows, static function (array $left, array $right): int {
        $totalCompare = ((float) ($right['total_ms'] ?? 0.0)) <=> ((float) ($left['total_ms'] ?? 0.0));
        if ($totalCompare !== 0) {
            return $totalCompare;
        }
        return strcmp((string) ($left['operation'] ?? ''), (string) ($right['operation'] ?? ''));
    });
    return $rows;
}

/**
 * Persist one normalized short-retention telemetry event.
 *
 * @param array<int,mixed> $params Ordered telemetry_events insert parameters.
 */
function telemetry_model_insert_event(array $params): void
{
    $stmt = db()->prepare('INSERT INTO telemetry_events (
        occurred_at, received_at, event_name, source, session_hash, request_id, route_name, page_kind,
        gallery_id, image_id, referrer_category, browser_family, browser_major_bucket, os_family, device_type,
        viewport_class, locale_bucket, country_code, duration_ms_capped, value_count, value_bytes, value_ms,
        value_bucket, cache_result, media_variant, http_status, error_kind, sampled_rate, context_json
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute($params);
}

/**
 * Create or update one normalized anonymous telemetry session row.
 *
 * @param array<int,mixed> $params Ordered telemetry_sessions upsert parameters.
 */
function telemetry_model_upsert_session(array $params): void
{
    $stmt = db()->prepare('INSERT INTO telemetry_sessions (
        session_hash, started_at, last_seen_at, first_route_name, last_route_name, first_gallery_id, last_gallery_id,
        first_image_id, last_image_id, entry_referrer_category, browser_family, browser_major_bucket, os_family,
        device_type, viewport_class, locale_bucket, country_code, page_view_count, photo_view_count,
        duration_seconds_capped, bounced, exit_route_name, created_at, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        last_seen_at = VALUES(last_seen_at),
        last_route_name = VALUES(last_route_name),
        last_gallery_id = COALESCE(VALUES(last_gallery_id), last_gallery_id),
        last_image_id = COALESCE(VALUES(last_image_id), last_image_id),
        page_view_count = page_view_count + VALUES(page_view_count),
        photo_view_count = photo_view_count + VALUES(photo_view_count),
        duration_seconds_capped = duration_seconds_capped + VALUES(duration_seconds_capped),
        bounced = IF(page_view_count + VALUES(page_view_count) <= 1, 1, 0),
        exit_route_name = VALUES(exit_route_name),
        updated_at = VALUES(updated_at)');
    $stmt->execute($params);
}

/**
 * Add one normalized sample to an hourly telemetry aggregate row.
 *
 * @param array<int,mixed> $params Ordered telemetry_hourly_metrics upsert parameters.
 */
function telemetry_model_upsert_hourly_metric(array $params): void
{
    $stmt = db()->prepare('INSERT INTO telemetry_hourly_metrics (
        bucket_start, metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family,
        device_type, viewport_class, country_code, referrer_category, media_variant, cache_result,
        sample_count, event_count, value_sum, value_min, value_max, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        sample_count = sample_count + VALUES(sample_count),
        event_count = event_count + VALUES(event_count),
        value_sum = value_sum + VALUES(value_sum),
        value_min = IF(value_min IS NULL, VALUES(value_min), LEAST(value_min, VALUES(value_min))),
        value_max = IF(value_max IS NULL, VALUES(value_max), GREATEST(value_max, VALUES(value_max))),
        updated_at = VALUES(updated_at)');
    $stmt->execute($params);
}

/**
 * Read one telemetry setting value.
 *
 * @param string $key Setting key.
 * @return string|false Raw database value or false when missing.
 */
function telemetry_model_setting(string $key): string|false
{
    $stmt = db()->prepare('SELECT setting_value FROM telemetry_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? false : (string) $value;
}

/**
 * Persist one telemetry setting value.
 *
 * @param string $key Setting key.
 * @param string $value Setting value.
 * @param string $now SQL timestamp.
 */
function telemetry_model_set_setting(string $key, string $value, string $now): void
{
    $stmt = db()->prepare('INSERT INTO telemetry_settings (setting_key, setting_value, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)');
    $stmt->execute([$key, $value, $now]);
}

/**
 * Return all telemetry setting rows.
 *
 * @return array<int,array<string,mixed>> Setting rows.
 */
function telemetry_model_all_settings(): array
{
    return db()->query('SELECT setting_key, setting_value FROM telemetry_settings ORDER BY setting_key')->fetchAll();
}

/**
 * Return the aggregate value sum for one metric in a day window.
 */
function telemetry_model_metric_sum(string $metricName, int $days, string $trafficSegment = 'all'): float
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT COALESCE(SUM(value_sum), 0) FROM telemetry_hourly_metrics WHERE metric_name = ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql);
    telemetry_model_profiled_report_execute($stmt, [$metricName, $days], 'metric_sum');
    return (float) $stmt->fetchColumn();
}

/**
 * Return the aggregate event count for one metric in a day window.
 */
function telemetry_model_metric_events(string $metricName, int $days, string $trafficSegment = 'all'): int
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT COALESCE(SUM(event_count), 0) FROM telemetry_hourly_metrics WHERE metric_name = ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql);
    telemetry_model_profiled_report_execute($stmt, [$metricName, $days], 'metric_events');
    return (int) $stmt->fetchColumn();
}

/**
 * Return top viewed photo rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_top_photos(int $days, int $limit, string $trafficSegment = 'all'): array
{
    $limit = max(1, min(50, $limit));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment, 'm.device_type');
    $stmt = db()->prepare('SELECT i.id, i.filename, g.title AS gallery_title, SUM(m.event_count) AS photo_views
        FROM telemetry_hourly_metrics m
        JOIN images i ON i.id = m.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE m.metric_name = ? AND m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.image_id > 0' . $segmentSql . '
        GROUP BY i.id, i.filename, g.title
        ORDER BY photo_views DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, ['photo.views', $days], 'top_photos');
    return $stmt->fetchAll();
}

/**
 * Return longest viewed photo rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_longest_viewed_photos(int $days, int $limit, string $trafficSegment = 'all'): array
{
    $limit = max(1, min(50, $limit));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment, 'm.device_type');
    $stmt = db()->prepare('SELECT i.id, i.filename, g.title AS gallery_title, SUM(m.value_sum) / NULLIF(SUM(m.event_count), 0) AS avg_view_seconds, SUM(m.event_count) AS view_count
        FROM telemetry_hourly_metrics m
        JOIN images i ON i.id = m.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE m.metric_name = ? AND m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.image_id > 0' . $segmentSql . '
        GROUP BY i.id, i.filename, g.title
        HAVING view_count > 0
        ORDER BY avg_view_seconds DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, ['photo.view_seconds', $days], 'longest_photos');
    return $stmt->fetchAll();
}


/**
 * Return a bounded distribution of semantic photo opens per anonymous session.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_photo_open_session_buckets(int $days, string $trafficSegment = 'all'): array
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare("SELECT
        CASE
            WHEN photo_view_count = 0 THEN '0'
            WHEN photo_view_count = 1 THEN '1'
            WHEN photo_view_count BETWEEN 2 AND 5 THEN '2_5'
            WHEN photo_view_count BETWEEN 6 AND 10 THEN '6_10'
            WHEN photo_view_count BETWEEN 11 AND 25 THEN '11_25'
            WHEN photo_view_count BETWEEN 26 AND 50 THEN '26_50'
            WHEN photo_view_count BETWEEN 51 AND 100 THEN '51_100'
            ELSE '101_plus'
        END AS bucket,
        COUNT(*) AS sessions,
        COALESCE(SUM(photo_view_count), 0) AS photo_opens,
        COALESCE(AVG(photo_view_count), 0) AS avg_opens,
        COALESCE(MAX(photo_view_count), 0) AS max_opens,
        CASE
            WHEN photo_view_count = 0 THEN 0
            WHEN photo_view_count = 1 THEN 1
            WHEN photo_view_count BETWEEN 2 AND 5 THEN 2
            WHEN photo_view_count BETWEEN 6 AND 10 THEN 3
            WHEN photo_view_count BETWEEN 11 AND 25 THEN 4
            WHEN photo_view_count BETWEEN 26 AND 50 THEN 5
            WHEN photo_view_count BETWEEN 51 AND 100 THEN 6
            ELSE 7
        END AS bucket_order
        FROM telemetry_sessions
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)" . $segmentSql . "
        GROUP BY bucket, bucket_order
        ORDER BY bucket_order ASC");
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return aggregate outlier-oriented photo-open session counters.
 *
 * @return array<string,mixed>
 */
function telemetry_model_report_photo_open_anomaly_summary(int $days, int $threshold, string $trafficSegment = 'all'): array
{
    $threshold = max(1, min(10000, $threshold));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare("SELECT
        COUNT(*) AS sessions,
        COALESCE(SUM(photo_view_count), 0) AS photo_opens,
        COALESCE(AVG(photo_view_count), 0) AS avg_opens_per_session,
        COALESCE(MAX(photo_view_count), 0) AS max_opens_per_session,
        SUM(CASE WHEN photo_view_count > ? THEN 1 ELSE 0 END) AS sessions_above_threshold
        FROM telemetry_sessions
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)" . $segmentSql);
    $stmt->execute([$threshold, $days]);
    return $stmt->fetch() ?: [];
}

/**
 * Return galleries with unusually high raw-event photo opens per anonymous session.
 *
 * This query intentionally exposes only aggregate counts. Session hashes stay inside
 * the grouping subquery and are never returned to the presentation layer.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_gallery_photo_opens_per_session(int $days, int $minSessions, int $limit, string $trafficSegment = 'all'): array
{
    $minSessions = max(1, min(1000, $minSessions));
    $limit = max(1, min(100, $limit));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare("SELECT g.id AS gallery_id, g.title,
        COUNT(*) AS sessions,
        SUM(session_opens.photo_opens) AS photo_opens,
        AVG(session_opens.photo_opens) AS avg_opens_per_session,
        MAX(session_opens.photo_opens) AS max_opens_per_session
        FROM (
            SELECT session_hash, gallery_id, COUNT(*) AS photo_opens
            FROM telemetry_events
            WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
              AND event_name = 'public.photo.opened'
              AND session_hash IS NOT NULL
              AND gallery_id IS NOT NULL" . $segmentSql . "
            GROUP BY session_hash, gallery_id
        ) session_opens
        JOIN galleries g ON g.id = session_opens.gallery_id
        GROUP BY g.id, g.title
        HAVING COUNT(*) >= ?
        ORDER BY avg_opens_per_session DESC, photo_opens DESC
        LIMIT " . $limit);
    $stmt->execute([$days, $minSessions]);
    return $stmt->fetchAll();
}

/**
 * Return privacy-safe photo-open activation-origin counts from retained raw events.
 *
 * @return array<string,mixed>
 * @param int $days Report lookback or configured retention age in days.
 * @param string $trafficSegment Normalized technical traffic segment, not proof of a human visitor.
 */
function telemetry_model_report_photo_open_origins(int $days, string $trafficSegment = 'all'): array
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $originSql = "CASE
        WHEN context_json IS NULL OR JSON_VALID(context_json) = 0 THEN 'legacy_unclassified'
        ELSE COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(context_json, '$.trigger')), ''), 'legacy_unclassified')
    END";
    $stmt = db()->prepare('SELECT ' . $originSql . " AS label, COUNT(*) AS events
        FROM telemetry_events
        WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND event_name = 'public.photo.opened'" . $segmentSql . "\n        GROUP BY " . $originSql . "\n        ORDER BY events DESC, label ASC");
    telemetry_model_profiled_report_execute($stmt, [$days], 'photo_open_origins');
    return $stmt->fetchAll();
}

/**
 * Return browser-family mix rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_browser_mix(int $days, string $trafficSegment = 'all'): array
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT browser_family, COUNT(*) AS sessions FROM telemetry_sessions WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql . ' GROUP BY browser_family ORDER BY sessions DESC');
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return cache-result distribution rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_cache_mix(int $days, string $trafficSegment = 'all'): array
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT cache_result, SUM(event_count) AS events FROM telemetry_hourly_metrics WHERE metric_name LIKE ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql . ' GROUP BY cache_result ORDER BY events DESC');
    telemetry_model_profiled_report_execute($stmt, ['cache.%', $days], 'cache_mix');
    return $stmt->fetchAll();
}

/**
 * Return a telemetry table row count from the constrained report allowlist.
 */
function telemetry_model_report_table_count(string $tableName): int
{
    $allowedTables = [
        'telemetry_events',
        'telemetry_sessions',
        'telemetry_hourly_metrics',
        'telemetry_daily_metrics',
        'telemetry_db_query_metrics',
        'telemetry_job_runs',
    ];
    if (!in_array($tableName, $allowedTables, true)) {
        throw new InvalidArgumentException('Unsupported telemetry report table.');
    }
    return (int) db()->query('SELECT COUNT(*) FROM ' . $tableName)->fetchColumn();
}

/**
 * Return bounded storage/growth diagnostics for the persisted telemetry tables.
 *
 * Table and timestamp identifiers are owned by this model and never come from
 * request input. Exact row counts intentionally replace the separate COUNT(*)
 * queries already used by the export so the diagnostic snapshot does not add
 * duplicate table scans merely to show storage metadata.
 *
 * @param int $recentDays Recent growth window in days.
 * @return array<string,mixed> Storage diagnostics keyed as rows for presentation.
 * @param array<string,int> $retentionByTable Configured retention days keyed by the bounded telemetry table names.
 */
function telemetry_model_report_storage_diagnostics(int $recentDays = 7, array $retentionByTable = []): array
{
    $recentDays = max(1, min(30, $recentDays));
    $tableColumns = [
        'telemetry_events' => 'occurred_at',
        'telemetry_sessions' => 'last_seen_at',
        'telemetry_hourly_metrics' => 'bucket_start',
        'telemetry_daily_metrics' => 'bucket_date',
        'telemetry_db_query_metrics' => 'bucket_start',
        'telemetry_job_runs' => 'started_at',
    ];

    $sizeStmt = db()->prepare(
        'SELECT table_name, COALESCE(data_length, 0) AS data_bytes, COALESCE(index_length, 0) AS index_bytes'
        . ' FROM information_schema.tables'
        . ' WHERE table_schema = DATABASE() AND table_name IN (' . implode(',', array_fill(0, count($tableColumns), '?')) . ')'
    );
    telemetry_model_profiled_report_execute($sizeStmt, array_keys($tableColumns), 'storage_sizes');
    $sizes = [];
    foreach ($sizeStmt->fetchAll() as $row) {
        $tableName = (string) ($row['table_name'] ?? '');
        if (!isset($tableColumns[$tableName])) {
            continue;
        }
        $sizes[$tableName] = [
            'data_bytes' => max(0, (int) ($row['data_bytes'] ?? 0)),
            'index_bytes' => max(0, (int) ($row['index_bytes'] ?? 0)),
        ];
    }

    $rows = [];
    foreach ($tableColumns as $tableName => $timestampColumn) {
        $retentionDays = max(1, min(3650, (int) ($retentionByTable[$tableName] ?? 3650)));
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS exact_rows,'
            . ' MIN(' . $timestampColumn . ') AS oldest_row,'
            . ' MAX(' . $timestampColumn . ') AS newest_row,'
            . ' COALESCE(SUM(CASE WHEN ' . $timestampColumn . ' >= DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) AS recent_rows'
            . ', COALESCE(SUM(CASE WHEN ' . $timestampColumn . ' < DATE_SUB(NOW(), INTERVAL ? DAY) THEN 1 ELSE 0 END), 0) AS overdue_rows'
            . ' FROM ' . $tableName
        );
        telemetry_model_profiled_report_execute($stmt, [$recentDays, $retentionDays], 'storage_table_scan');
        $row = $stmt->fetch() ?: [];
        $dataBytes = (int) ($sizes[$tableName]['data_bytes'] ?? 0);
        $indexBytes = (int) ($sizes[$tableName]['index_bytes'] ?? 0);
        $rows[] = [
            'table_name' => $tableName,
            'exact_rows' => max(0, (int) ($row['exact_rows'] ?? 0)),
            'recent_rows' => max(0, (int) ($row['recent_rows'] ?? 0)),
            'overdue_rows' => max(0, (int) ($row['overdue_rows'] ?? 0)),
            'oldest_row' => $row['oldest_row'] ?? null,
            'newest_row' => $row['newest_row'] ?? null,
            'data_bytes' => max(0, $dataBytes),
            'index_bytes' => max(0, $indexBytes),
            'total_bytes' => max(0, $dataBytes + $indexBytes),
        ];
    }
    return $rows;
}

/**
 * Return bounded per-metric row and dimension cardinality for the hourly table.
 *
 * The query is intentionally restricted to the selected report window so an
 * administrator can measure whether Stage 6 dimension normalization is reducing
 * new aggregate growth without scanning historical retention unnecessarily.
 *
 * @param int $days Report window in days.
 * @param int $limit Maximum metric rows returned.
 * @return array<int,array<string,mixed>> Metric cardinality diagnostics.
 */
function telemetry_model_report_hourly_metric_cardinality(int $days, int $limit = 30): array
{
    $days = max(1, min(3650, $days));
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare('SELECT metric_name,
        COUNT(*) AS row_count,
        COUNT(DISTINCT route_name) AS route_cardinality,
        COUNT(DISTINCT page_kind) AS page_kind_cardinality,
        COUNT(DISTINCT gallery_id) AS gallery_cardinality,
        COUNT(DISTINCT image_id) AS image_cardinality,
        COUNT(DISTINCT browser_family) AS browser_cardinality,
        COUNT(DISTINCT os_family) AS os_cardinality,
        COUNT(DISTINCT device_type) AS device_cardinality,
        COUNT(DISTINCT viewport_class) AS viewport_cardinality,
        COUNT(DISTINCT referrer_category) AS referrer_cardinality,
        COUNT(DISTINCT media_variant) AS media_variant_cardinality,
        COUNT(DISTINCT cache_result) AS cache_cardinality
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY metric_name
        ORDER BY row_count DESC, metric_name ASC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days], 'hourly_metric_cardinality');
    return $stmt->fetchAll();
}


/**
 * Return hourly and daily aggregate totals for completed-day rollup validation.
 *
 * The comparison deliberately excludes the current partial day so a normal daily
 * rollup schedule is not reported as inconsistent merely because today's hourly
 * buckets have not yet been condensed. Traffic segmentation is applied to both
 * aggregate tables using the same bounded device_type contract.
 *
 * @param int $completedDays Number of completed calendar days to compare.
 * @param string $trafficSegment Traffic segment selector.
 * @return array{hourly:array<int,array<string,mixed>>,daily:array<int,array<string,mixed>>}
 */
function telemetry_model_report_daily_rollup_consistency(int $completedDays, string $trafficSegment = 'all'): array
{
    $completedDays = max(1, min(30, $completedDays));
    $hourlySegmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $dailySegmentSql = telemetry_model_traffic_segment_condition($trafficSegment);

    $hourlyStmt = db()->prepare('SELECT metric_name,
        SUM(sample_count) AS sample_count,
        SUM(event_count) AS event_count,
        SUM(value_sum) AS value_sum
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
          AND bucket_start < CURDATE()' . $hourlySegmentSql . '
        GROUP BY metric_name
        ORDER BY metric_name ASC');
    telemetry_model_profiled_report_execute($hourlyStmt, [$completedDays], 'rollup_consistency_hourly');

    $dailyStmt = db()->prepare('SELECT metric_name,
        SUM(sample_count) AS sample_count,
        SUM(event_count) AS event_count,
        SUM(value_sum) AS value_sum
        FROM telemetry_daily_metrics
        WHERE bucket_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
          AND bucket_date < CURDATE()' . $dailySegmentSql . '
        GROUP BY metric_name
        ORDER BY metric_name ASC');
    telemetry_model_profiled_report_execute($dailyStmt, [$completedDays], 'rollup_consistency_daily');

    return [
        'hourly' => $hourlyStmt->fetchAll(),
        'daily' => $dailyStmt->fetchAll(),
    ];
}

/**
 * Return sanitized EXPLAIN plans for a fixed set of telemetry report queries.
 *
 * This operator diagnostic never returns SQL text, bound values, database error
 * messages, or result data. Query definitions and profile names are source-owned,
 * while the returned plan fields are restricted to ordinary optimizer metadata.
 *
 * @return array<int,array<string,mixed>> Flattened safe optimizer-plan rows.
 */
function telemetry_model_report_query_plans(int $days, string $trafficSegment = 'all'): array
{
    $days = max(1, min(3650, $days));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $gallerySegmentSql = telemetry_model_traffic_segment_condition($trafficSegment, 'm.device_type');
    $plans = [
        'session_summary' => [
            'sql' => 'EXPLAIN SELECT COUNT(*) AS sessions, COALESCE(SUM(page_view_count), 0) AS page_views FROM telemetry_sessions WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql,
            'params' => [$days],
        ],
        'daily_trends' => [
            'sql' => 'EXPLAIN SELECT DATE(bucket_start) AS report_date, SUM(event_count) AS event_count FROM telemetry_hourly_metrics WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql . ' GROUP BY DATE(bucket_start)',
            'params' => [$days],
        ],
        'top_galleries' => [
            'sql' => 'EXPLAIN SELECT g.id, SUM(m.event_count) AS event_count FROM telemetry_hourly_metrics m JOIN galleries g ON g.id = m.gallery_id WHERE m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.gallery_id > 0' . $gallerySegmentSql . ' GROUP BY g.id',
            'params' => [$days],
        ],
        'page_kind_distribution' => [
            'sql' => 'EXPLAIN SELECT page_kind, SUM(event_count) AS event_count FROM telemetry_hourly_metrics WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND metric_name = ?' . $segmentSql . ' GROUP BY page_kind',
            'params' => [$days, 'public.page_views'],
        ],
    ];

    $rows = [];
    foreach ($plans as $profileKey => $plan) {
        try {
            $stmt = db()->prepare((string) $plan['sql']);
            $stmt->execute((array) $plan['params']);
            $planRows = $stmt->fetchAll();
            if ($planRows === []) {
                $rows[] = [
                    'operation' => $profileKey,
                    'status' => 'unavailable',
                    'select_id' => null,
                    'select_type' => '',
                    'table_name' => '',
                    'access_type' => '',
                    'possible_keys' => '',
                    'key_used' => '',
                    'key_length' => '',
                    'ref' => '',
                    'rows_estimate' => null,
                    'filtered_percent' => null,
                    'extra' => '',
                ];
                continue;
            }
            foreach ($planRows as $planRow) {
                if (!is_array($planRow)) {
                    continue;
                }
                $rows[] = [
                    'operation' => $profileKey,
                    'status' => 'available',
                    'select_id' => isset($planRow['id']) ? (int) $planRow['id'] : null,
                    'select_type' => substr((string) ($planRow['select_type'] ?? ''), 0, 40),
                    'table_name' => substr((string) ($planRow['table'] ?? ''), 0, 80),
                    'access_type' => substr((string) ($planRow['type'] ?? ''), 0, 40),
                    'possible_keys' => substr((string) ($planRow['possible_keys'] ?? ''), 0, 255),
                    'key_used' => substr((string) ($planRow['key'] ?? ''), 0, 120),
                    'key_length' => substr((string) ($planRow['key_len'] ?? ''), 0, 40),
                    'ref' => substr((string) ($planRow['ref'] ?? ''), 0, 120),
                    'rows_estimate' => isset($planRow['rows']) ? max(0, (int) $planRow['rows']) : null,
                    'filtered_percent' => isset($planRow['filtered']) ? max(0.0, min(100.0, (float) $planRow['filtered'])) : null,
                    'extra' => substr((string) ($planRow['Extra'] ?? $planRow['extra'] ?? ''), 0, 255),
                ];
            }
        } catch (\Throwable) {
            $rows[] = [
                'operation' => $profileKey,
                'status' => 'unavailable',
                'select_id' => null,
                'select_type' => '',
                'table_name' => '',
                'access_type' => '',
                'possible_keys' => '',
                'key_used' => '',
                'key_length' => '',
                'ref' => '',
                'rows_estimate' => null,
                'filtered_percent' => null,
                'extra' => '',
            ];
        }
    }
    return $rows;
}


/**
 * Return session quality summary data.
 *
 * @return array<string,mixed>
 */
function telemetry_model_report_session_summary(int $days, string $trafficSegment = 'all'): array
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT
        COUNT(*) AS sessions,
        COALESCE(SUM(page_view_count), 0) AS page_views,
        COALESCE(SUM(photo_view_count), 0) AS photo_views,
        COALESCE(SUM(duration_seconds_capped), 0) AS duration_seconds,
        COALESCE(AVG(page_view_count), 0) AS avg_pages_per_session,
        COALESCE(AVG(photo_view_count), 0) AS avg_photos_per_session,
        COALESCE(AVG(duration_seconds_capped), 0) AS avg_duration_seconds,
        COALESCE(SUM(CASE WHEN page_view_count <= 1 THEN 1 ELSE 0 END), 0) AS bounced_sessions,
        COALESCE(SUM(CASE WHEN started_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS previous_sessions,
        COALESCE(SUM(CASE WHEN started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END), 0) AS recent_sessions
        FROM telemetry_sessions
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql);
    telemetry_model_profiled_report_execute($stmt, [$days], 'session_summary');
    return $stmt->fetch() ?: [];
}

/**
 * Return daily trend report rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_daily_trends(int $days, string $trafficSegment = 'all'): array
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT DATE(bucket_start) AS report_date,
        SUM(CASE WHEN metric_name = \'public.page_views\' THEN event_count ELSE 0 END) AS page_views,
        SUM(CASE WHEN metric_name = \'photo.views\' THEN event_count ELSE 0 END) AS photo_views,
        SUM(CASE WHEN metric_name = \'photo.view_seconds\' THEN value_sum ELSE 0 END) AS photo_seconds,
        SUM(CASE WHEN metric_name = \'client.errors\' THEN event_count ELSE 0 END) AS client_errors,
        SUM(CASE WHEN metric_name IN (\'media.image.bytes\', \'media.thumbnail.bytes\', \'media.download.bytes\') THEN value_sum ELSE 0 END) AS media_bytes
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql . '
        GROUP BY DATE(bucket_start)
        ORDER BY report_date ASC');
    telemetry_model_profiled_report_execute($stmt, [$days], 'daily_trends');
    return $stmt->fetchAll();
}

/**
 * Return canonical daily unique-session counts from telemetry_sessions.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_daily_sessions(int $days, string $trafficSegment = 'all'): array
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT DATE(started_at) AS report_date, COUNT(*) AS sessions
        FROM telemetry_sessions
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql . '
        GROUP BY DATE(started_at)
        ORDER BY report_date ASC');
    telemetry_model_profiled_report_execute($stmt, [$days], 'daily_sessions');
    return $stmt->fetchAll();
}

/**
 * Return top gallery engagement rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_top_galleries(int $days, int $limit, string $trafficSegment = 'all'): array
{
    $limit = max(1, min(100, $limit));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment, 'm.device_type');
    $stmt = db()->prepare('SELECT g.id, g.title, g.slug,
        SUM(CASE WHEN m.metric_name = \'public.page_views\' THEN m.event_count ELSE 0 END) AS page_views,
        SUM(CASE WHEN m.metric_name = \'photo.views\' THEN m.event_count ELSE 0 END) AS photo_views,
        SUM(CASE WHEN m.metric_name = \'photo.view_seconds\' THEN m.value_sum ELSE 0 END) AS photo_seconds,
        SUM(CASE WHEN m.metric_name IN (\'media.image.bytes\', \'media.thumbnail.bytes\', \'media.download.bytes\') THEN m.value_sum ELSE 0 END) AS media_bytes
        FROM telemetry_hourly_metrics m
        JOIN galleries g ON g.id = m.gallery_id
        WHERE m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.gallery_id > 0' . $segmentSql . '
        GROUP BY g.id, g.title, g.slug
        ORDER BY page_views DESC, photo_views DESC, media_bytes DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days], 'top_galleries');
    return $stmt->fetchAll();
}

/**
 * Return top route rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_top_routes(int $days, int $limit, string $trafficSegment = 'all'): array
{
    $limit = max(1, min(100, $limit));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT route_name,
        SUM(CASE WHEN metric_name = \'public.page_views\' THEN event_count ELSE 0 END) AS page_views,
        SUM(CASE WHEN metric_name = \'photo.views\' THEN event_count ELSE 0 END) AS photo_views,
        SUM(CASE WHEN metric_name = \'client.errors\' THEN event_count ELSE 0 END) AS client_errors,
        SUM(CASE WHEN metric_name IN (\'media.image.bytes\', \'media.thumbnail.bytes\', \'media.download.bytes\') THEN value_sum ELSE 0 END) AS media_bytes
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND route_name <> \'\'' . $segmentSql . '
        GROUP BY route_name
        ORDER BY page_views DESC, photo_views DESC, client_errors DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days], 'top_routes');
    return $stmt->fetchAll();
}

/**
 * Return a constrained hourly metric dimension distribution.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_metric_distribution(string $dimension, int $days, string $metricName, int $limit, string $trafficSegment = 'all'): array
{
    $allowed = ['page_kind', 'browser_family', 'os_family', 'device_type', 'viewport_class', 'referrer_category', 'media_variant', 'cache_result'];
    if (!in_array($dimension, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported telemetry metric dimension.');
    }
    $limit = max(1, min(100, $limit));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT ' . $dimension . ' AS label, SUM(event_count) AS events, SUM(value_sum) AS value_sum
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND metric_name = ?' . $segmentSql . '
        GROUP BY ' . $dimension . '
        ORDER BY events DESC, value_sum DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days, $metricName], 'metric_distribution');
    return $stmt->fetchAll();
}

/**
 * Return a constrained session dimension distribution.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_session_distribution(string $dimension, int $days, int $limit, string $trafficSegment = 'all'): array
{
    $allowed = ['entry_referrer_category', 'browser_family', 'os_family', 'device_type', 'viewport_class', 'first_route_name', 'last_route_name', 'exit_route_name'];
    if (!in_array($dimension, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported telemetry session dimension.');
    }
    $limit = max(1, min(100, $limit));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT COALESCE(NULLIF(' . $dimension . ', \'\'), \'unknown\') AS label,
        COUNT(*) AS sessions,
        COALESCE(SUM(page_view_count), 0) AS page_views,
        COALESCE(SUM(photo_view_count), 0) AS photo_views,
        COALESCE(AVG(duration_seconds_capped), 0) AS avg_duration_seconds
        FROM telemetry_sessions
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql . '
        GROUP BY COALESCE(NULLIF(' . $dimension . ', \'\'), \'unknown\')
        ORDER BY sessions DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days], 'session_distribution');
    return $stmt->fetchAll();
}

/**
 * Return performance metric report rows.
 *
 * @return array<string,mixed>
 * @param int $days Report lookback or configured retention age in days.
 * @param string $trafficSegment Normalized technical traffic segment, not proof of a human visitor.
 */
function telemetry_model_report_performance_metrics(int $days, string $trafficSegment = 'all'): array
{
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT metric_name,
        SUM(event_count) AS samples,
        SUM(value_sum) / NULLIF(SUM(event_count), 0) AS avg_value,
        MIN(value_min) AS min_value,
        MAX(value_max) AS max_value
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (metric_name LIKE \'web_vital.%\' OR metric_name IN (\'client.page_load_ms\', \'client.image_decode_ms\', \'client.image_display_ms\'))
          AND (metric_name <> \'client.page_load_ms\' OR value_min > 0)' . $segmentSql . '
        GROUP BY metric_name
        ORDER BY metric_name ASC');
    telemetry_model_profiled_report_execute($stmt, [$days], 'performance_metrics');
    return $stmt->fetchAll();
}

/**
 * Return client error report rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_client_errors(int $days, int $limit, string $trafficSegment = 'all'): array
{
    $limit = max(1, min(100, $limit));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT COALESCE(NULLIF(error_kind, \'\'), \'unknown\') AS error_kind,
        COALESCE(NULLIF(route_name, \'\'), \'unknown\') AS route_name,
        COUNT(*) AS events,
        MAX(occurred_at) AS last_seen
        FROM telemetry_events
        WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND event_name = \'client.error.javascript\'' . $segmentSql . '
        GROUP BY COALESCE(NULLIF(error_kind, \'\'), \'unknown\'), COALESCE(NULLIF(route_name, \'\'), \'unknown\')
        ORDER BY events DESC, last_seen DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days], 'client_errors');
    return $stmt->fetchAll();
}

/**
 * Return recent anonymized event rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_recent_events(int $days, int $limit, string $trafficSegment = 'all'): array
{
    $limit = max(1, min(200, $limit));
    $segmentSql = telemetry_model_traffic_segment_condition($trafficSegment);
    $stmt = db()->prepare('SELECT occurred_at, event_name, source, route_name, page_kind, gallery_id, image_id,
        referrer_category, browser_family, os_family, device_type, viewport_class, media_variant,
        cache_result, http_status, error_kind, value_bytes, value_ms, duration_ms_capped
        FROM telemetry_events
        WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)' . $segmentSql . '
        ORDER BY occurred_at DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days], 'recent_events');
    return $stmt->fetchAll();
}

/**
 * Return database telemetry summary rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_database_summary(int $days, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT route_name, operation, table_name,
        SUM(query_count) AS query_count,
        SUM(failed_count) AS failed_count,
        SUM(slow_count) AS slow_count,
        SUM(latency_ms_sum) AS latency_ms_sum,
        MAX(latency_ms_max) AS latency_ms_max,
        SUM(rows_returned_sum) AS rows_returned_sum,
        SUM(rows_affected_sum) AS rows_affected_sum
        FROM telemetry_db_query_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY route_name, operation, table_name
        ORDER BY latency_ms_sum DESC, query_count DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days], 'database_summary');
    return $stmt->fetchAll();
}

/**
 * Return total database telemetry counters for a report window.
 *
 * @return array{query_count:float,slow_count:float,failed_count:float,first_bucket:?string,last_bucket:?string}
 * @param int $days Report lookback or configured retention age in days.
 */
function telemetry_model_report_database_totals(int $days): array
{
    $stmt = db()->prepare('SELECT
        COALESCE(SUM(query_count), 0) AS query_count,
        COALESCE(SUM(slow_count), 0) AS slow_count,
        COALESCE(SUM(failed_count), 0) AS failed_count,
        MIN(bucket_start) AS first_bucket, MAX(bucket_start) AS last_bucket
        FROM telemetry_db_query_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    telemetry_model_profiled_report_execute($stmt, [$days], 'database_totals');
    $row = $stmt->fetch() ?: [];
    return [
        'query_count' => (float) ($row['query_count'] ?? 0),
        'slow_count' => (float) ($row['slow_count'] ?? 0),
        'failed_count' => (float) ($row['failed_count'] ?? 0),
        'first_bucket' => $row['first_bucket'] ?? null,
        'last_bucket' => $row['last_bucket'] ?? null,
    ];
}

/**
 * Return database query-fingerprint hot spots.
 *
 * @return list<array<string,mixed>>
 * @param int $days Report lookback or configured retention age in days.
 * @param int $limit Maximum rows or items processed by this call.
 * @param string $ranking One of slow, volume or failed; unsupported values are rejected.
 */
function telemetry_model_report_database_fingerprints(int $days, int $limit, string $ranking = 'slow'): array
{
    $limit = max(1, min(100, $limit));
    $order = match ($ranking) {
        'slow' => 'slow_count DESC, avg_latency_ms DESC, query_count DESC',
        'volume' => 'query_count DESC, total_latency_ms DESC',
        'failed' => 'failed_count DESC, query_count DESC',
        default => throw new InvalidArgumentException('Unsupported fingerprint ranking.'),
    };
    $having = $ranking === 'failed' ? ' HAVING SUM(failed_count) > 0' : '';
    $stmt = db()->prepare('SELECT query_fingerprint, route_name, operation, table_name,
        SUM(query_count) AS query_count,
        SUM(failed_count) AS failed_count,
        SUM(slow_count) AS slow_count,
        SUM(latency_ms_sum) AS total_latency_ms,
        SUM(latency_ms_sum) / NULLIF(SUM(query_count), 0) AS avg_latency_ms,
        MAX(latency_ms_max) AS max_latency_ms
        FROM telemetry_db_query_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY query_fingerprint, route_name, operation, table_name' . $having . '
        ORDER BY ' . $order . ', query_fingerprint ASC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days], $ranking === 'volume' ? 'database_fingerprints_volume' : 'database_fingerprints');
    return $stmt->fetchAll();
}

/**
 * Return recent telemetry job runs.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_job_runs(int $days, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT job_name, status, started_at, finished_at, duration_ms, gallery_id, image_id, item_count, retry_count, error_kind
        FROM telemetry_job_runs
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY started_at DESC
        LIMIT ' . $limit);
    telemetry_model_profiled_report_execute($stmt, [$days], 'job_runs');
    return $stmt->fetchAll();
}

/**
 * Roll hourly metrics into daily telemetry aggregates.
 */
function telemetry_model_rollup_daily(string $fromDate, string $toDate, string $now): int
{
    $stmt = db()->prepare('INSERT INTO telemetry_daily_metrics (
        bucket_date, metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family,
        device_type, viewport_class, country_code, referrer_category, media_variant, cache_result,
        sample_count, event_count, value_sum, value_min, value_max, updated_at
    )
    SELECT
        DATE(bucket_start), metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family,
        device_type, viewport_class, country_code, referrer_category, media_variant, cache_result,
        SUM(sample_count), SUM(event_count), SUM(value_sum), MIN(value_min), MAX(value_max), ?
    FROM telemetry_hourly_metrics
    WHERE bucket_start >= ? AND bucket_start < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY DATE(bucket_start), metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family,
        device_type, viewport_class, country_code, referrer_category, media_variant, cache_result
    ON DUPLICATE KEY UPDATE
        sample_count = VALUES(sample_count),
        event_count = VALUES(event_count),
        value_sum = VALUES(value_sum),
        value_min = VALUES(value_min),
        value_max = VALUES(value_max),
        updated_at = VALUES(updated_at)');
    $stmt->execute([$now, $fromDate . ' 00:00:00', $toDate]);
    return $stmt->rowCount();
}

/**
 * Delete telemetry rows older than the supplied day count from a constrained table/column pair.
 * @param string $tableName Allowlisted telemetry table.
 * @param string $columnName Allowlisted timestamp column for that table.
 * @param int $days Report lookback or configured retention age in days.
 * @param int $limit Maximum rows or items processed by this call.
 * @param ?string $before Exclusive date boundary already covered by durable daily rollup.
 * @return int Result of the documented operation.
 * The default batch bounds one DELETE when a compatibility caller omits a limit.
 * Type: integer.
 * Units: rows per statement.
 * Scope: one allowlisted telemetry retention target.
 * Consumers: bounded maintenance and the compatibility purge helper.
 * Rationale: cap row-lock work while allowing catch-up over repeated slices.
 */
function telemetry_model_delete_older_than(string $tableName, string $columnName, int $days, int $limit = TELEMETRY_MODEL_DEFAULT_DELETE_BATCH, ?string $before = null): int
{
    $safeTables = [
        'telemetry_events' => ['occurred_at'],
        'telemetry_sessions' => ['last_seen_at'],
        'telemetry_hourly_metrics' => ['bucket_start'],
        'telemetry_daily_metrics' => ['bucket_date'],
        'telemetry_db_query_metrics' => ['bucket_start'],
        'telemetry_job_runs' => ['started_at'],
    ];
    if (!isset($safeTables[$tableName]) || !in_array($columnName, $safeTables[$tableName], true)) {
        throw new InvalidArgumentException('Unsupported telemetry retention target.');
    }
    $limit = max(1, min(10000, $limit));
    // Never delete an hourly bucket unless a durable completed-day checkpoint
    // proves it was rolled up. Partial deletion must not be re-aggregated later.
    if ($tableName === 'telemetry_hourly_metrics' && $before === null) {
        return 0;
    }
    $params = [$days];
    $where = '';
    if ($before !== null) {
        $where = ' AND ' . $columnName . ' < ?';
        $params[] = $before;
    }
    $stmt = db()->prepare('DELETE FROM ' . $tableName . ' WHERE ' . $columnName
        . ' < DATE_SUB(NOW(), INTERVAL ? DAY)' . $where
        . ' ORDER BY ' . $columnName . ' ASC LIMIT ' . $limit);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Upsert one privacy-safe hourly database query metric aggregate.
 *
 * The database observer buffers equivalent executions in memory and persists one
 * aggregate row per request. Counts therefore arrive pre-aggregated and must be
 * added as supplied instead of being converted back into one logical execution.
 *
 * @param array<int,mixed> $params Ordered telemetry_db_query_metrics parameters.
 */
function telemetry_model_upsert_db_query_metric_aggregate(array $params): void
{
    $stmt = db()->prepare('INSERT INTO telemetry_db_query_metrics (
        bucket_start, route_name, operation, table_name, query_fingerprint, query_count, failed_count,
        slow_count, latency_ms_sum, latency_ms_max, rows_returned_sum, rows_affected_sum, updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        query_count = query_count + VALUES(query_count),
        failed_count = failed_count + VALUES(failed_count),
        slow_count = slow_count + VALUES(slow_count),
        latency_ms_sum = latency_ms_sum + VALUES(latency_ms_sum),
        latency_ms_max = GREATEST(latency_ms_max, VALUES(latency_ms_max)),
        rows_returned_sum = rows_returned_sum + VALUES(rows_returned_sum),
        rows_affected_sum = rows_affected_sum + VALUES(rows_affected_sum),
        updated_at = VALUES(updated_at)');
    $stmt->execute($params);
}

/**
 * Backward-compatible single-execution database query metric writer.
 *
 * @param array<int,mixed> $params Historical ordered parameter list without query_count.
 */
function telemetry_model_upsert_db_query_metric(array $params): void
{
    if (count($params) !== 12) {
        throw new InvalidArgumentException('Unexpected database telemetry parameter count.');
    }

    $aggregateParams = array_merge(array_slice($params, 0, 5), [1], array_slice($params, 5));
    telemetry_model_upsert_db_query_metric_aggregate($aggregateParams);
}
