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
function telemetry_model_metric_sum(string $metricName, int $days): float
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(value_sum), 0) FROM telemetry_hourly_metrics WHERE metric_name = ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$metricName, $days]);
    return (float) $stmt->fetchColumn();
}

/**
 * Return the aggregate event count for one metric in a day window.
 */
function telemetry_model_metric_events(string $metricName, int $days): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(event_count), 0) FROM telemetry_hourly_metrics WHERE metric_name = ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$metricName, $days]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return top viewed photo rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_top_photos(int $days, int $limit): array
{
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare('SELECT i.id, i.filename, g.title AS gallery_title, SUM(m.event_count) AS photo_views
        FROM telemetry_hourly_metrics m
        JOIN images i ON i.id = m.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE m.metric_name = ? AND m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.image_id > 0
        GROUP BY i.id, i.filename, g.title
        ORDER BY photo_views DESC
        LIMIT ' . $limit);
    $stmt->execute(['photo.views', $days]);
    return $stmt->fetchAll();
}

/**
 * Return longest viewed photo rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_longest_viewed_photos(int $days, int $limit): array
{
    $limit = max(1, min(50, $limit));
    $stmt = db()->prepare('SELECT i.id, i.filename, g.title AS gallery_title, SUM(m.value_sum) / NULLIF(SUM(m.event_count), 0) AS avg_view_seconds, SUM(m.event_count) AS view_count
        FROM telemetry_hourly_metrics m
        JOIN images i ON i.id = m.image_id
        JOIN galleries g ON g.id = i.gallery_id
        WHERE m.metric_name = ? AND m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.image_id > 0
        GROUP BY i.id, i.filename, g.title
        HAVING view_count > 0
        ORDER BY avg_view_seconds DESC
        LIMIT ' . $limit);
    $stmt->execute(['photo.view_seconds', $days]);
    return $stmt->fetchAll();
}

/**
 * Return browser-family mix rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_browser_mix(int $days): array
{
    $stmt = db()->prepare('SELECT browser_family, COUNT(*) AS sessions FROM telemetry_sessions WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY browser_family ORDER BY sessions DESC');
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return cache-result distribution rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_cache_mix(int $days): array
{
    $stmt = db()->prepare('SELECT cache_result, SUM(event_count) AS events FROM telemetry_hourly_metrics WHERE metric_name LIKE ? AND bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) GROUP BY cache_result ORDER BY events DESC');
    $stmt->execute(['cache.%', $days]);
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
 * Return session quality summary data.
 *
 * @return array<string,mixed>
 */
function telemetry_model_report_session_summary(int $days): array
{
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
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$days]);
    return $stmt->fetch() ?: [];
}

/**
 * Return daily trend report rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_daily_trends(int $days): array
{
    $stmt = db()->prepare('SELECT DATE(bucket_start) AS report_date,
        SUM(CASE WHEN metric_name = \'public.sessions\' THEN event_count ELSE 0 END) AS sessions,
        SUM(CASE WHEN metric_name = \'public.page_views\' THEN event_count ELSE 0 END) AS page_views,
        SUM(CASE WHEN metric_name = \'photo.views\' THEN event_count ELSE 0 END) AS photo_views,
        SUM(CASE WHEN metric_name = \'photo.view_seconds\' THEN value_sum ELSE 0 END) AS photo_seconds,
        SUM(CASE WHEN metric_name = \'client.errors\' THEN event_count ELSE 0 END) AS client_errors,
        SUM(CASE WHEN metric_name IN (\'media.image.bytes\', \'media.thumbnail.bytes\', \'media.download.bytes\') THEN value_sum ELSE 0 END) AS media_bytes
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY DATE(bucket_start)
        ORDER BY report_date ASC');
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return top gallery engagement rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_top_galleries(int $days, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT g.id, g.title, g.slug,
        SUM(CASE WHEN m.metric_name = \'public.page_views\' THEN m.event_count ELSE 0 END) AS page_views,
        SUM(CASE WHEN m.metric_name = \'photo.views\' THEN m.event_count ELSE 0 END) AS photo_views,
        SUM(CASE WHEN m.metric_name = \'photo.view_seconds\' THEN m.value_sum ELSE 0 END) AS photo_seconds,
        SUM(CASE WHEN m.metric_name IN (\'media.image.bytes\', \'media.thumbnail.bytes\', \'media.download.bytes\') THEN m.value_sum ELSE 0 END) AS media_bytes
        FROM telemetry_hourly_metrics m
        JOIN galleries g ON g.id = m.gallery_id
        WHERE m.bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND m.gallery_id > 0
        GROUP BY g.id, g.title, g.slug
        ORDER BY page_views DESC, photo_views DESC, media_bytes DESC
        LIMIT ' . $limit);
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return top route rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_top_routes(int $days, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT route_name,
        SUM(CASE WHEN metric_name = \'public.page_views\' THEN event_count ELSE 0 END) AS page_views,
        SUM(CASE WHEN metric_name = \'photo.views\' THEN event_count ELSE 0 END) AS photo_views,
        SUM(CASE WHEN metric_name = \'client.errors\' THEN event_count ELSE 0 END) AS client_errors,
        SUM(CASE WHEN metric_name IN (\'media.image.bytes\', \'media.thumbnail.bytes\', \'media.download.bytes\') THEN value_sum ELSE 0 END) AS media_bytes
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND route_name <> \'\'
        GROUP BY route_name
        ORDER BY page_views DESC, photo_views DESC, client_errors DESC
        LIMIT ' . $limit);
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return a constrained hourly metric dimension distribution.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_metric_distribution(string $dimension, int $days, string $metricName, int $limit): array
{
    $allowed = ['page_kind', 'browser_family', 'os_family', 'device_type', 'viewport_class', 'referrer_category', 'media_variant', 'cache_result'];
    if (!in_array($dimension, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported telemetry metric dimension.');
    }
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT ' . $dimension . ' AS label, SUM(event_count) AS events, SUM(value_sum) AS value_sum
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY) AND metric_name = ?
        GROUP BY ' . $dimension . '
        ORDER BY events DESC, value_sum DESC
        LIMIT ' . $limit);
    $stmt->execute([$days, $metricName]);
    return $stmt->fetchAll();
}

/**
 * Return a constrained session dimension distribution.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_session_distribution(string $dimension, int $days, int $limit): array
{
    $allowed = ['entry_referrer_category', 'browser_family', 'os_family', 'device_type', 'viewport_class', 'first_route_name', 'last_route_name', 'exit_route_name'];
    if (!in_array($dimension, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported telemetry session dimension.');
    }
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT COALESCE(NULLIF(' . $dimension . ', \'\'), \'unknown\') AS label,
        COUNT(*) AS sessions,
        COALESCE(SUM(page_view_count), 0) AS page_views,
        COALESCE(SUM(photo_view_count), 0) AS photo_views,
        COALESCE(AVG(duration_seconds_capped), 0) AS avg_duration_seconds
        FROM telemetry_sessions
        WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY COALESCE(NULLIF(' . $dimension . ', \'\'), \'unknown\')
        ORDER BY sessions DESC
        LIMIT ' . $limit);
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return performance metric report rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_performance_metrics(int $days): array
{
    $stmt = db()->prepare('SELECT metric_name,
        SUM(event_count) AS samples,
        SUM(value_sum) / NULLIF(SUM(event_count), 0) AS avg_value,
        MIN(value_min) AS min_value,
        MAX(value_max) AS max_value
        FROM telemetry_hourly_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
          AND (metric_name LIKE \'web_vital.%\' OR metric_name IN (\'client.image_decode_ms\', \'client.image_display_ms\'))
        GROUP BY metric_name
        ORDER BY metric_name ASC');
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return client error report rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_client_errors(int $days, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT COALESCE(NULLIF(error_kind, \'\'), \'unknown\') AS error_kind,
        COALESCE(NULLIF(route_name, \'\'), \'unknown\') AS route_name,
        COUNT(*) AS events,
        MAX(occurred_at) AS last_seen
        FROM telemetry_events
        WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND event_name = \'client.error.javascript\'
        GROUP BY COALESCE(NULLIF(error_kind, \'\'), \'unknown\'), COALESCE(NULLIF(route_name, \'\'), \'unknown\')
        ORDER BY events DESC, last_seen DESC
        LIMIT ' . $limit);
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return recent anonymized event rows.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_recent_events(int $days, int $limit): array
{
    $limit = max(1, min(200, $limit));
    $stmt = db()->prepare('SELECT occurred_at, event_name, source, route_name, page_kind, gallery_id, image_id,
        referrer_category, browser_family, os_family, device_type, viewport_class, media_variant,
        cache_result, http_status, error_kind, value_bytes, value_ms, duration_ms_capped
        FROM telemetry_events
        WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ORDER BY occurred_at DESC
        LIMIT ' . $limit);
    $stmt->execute([$days]);
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
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Return total database telemetry counters for a report window.
 *
 * @return array{query_count:float,slow_count:float,failed_count:float}
 */
function telemetry_model_report_database_totals(int $days): array
{
    $stmt = db()->prepare('SELECT
        COALESCE(SUM(query_count), 0) AS query_count,
        COALESCE(SUM(slow_count), 0) AS slow_count,
        COALESCE(SUM(failed_count), 0) AS failed_count
        FROM telemetry_db_query_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$days]);
    $row = $stmt->fetch() ?: [];
    return [
        'query_count' => (float) ($row['query_count'] ?? 0),
        'slow_count' => (float) ($row['slow_count'] ?? 0),
        'failed_count' => (float) ($row['failed_count'] ?? 0),
    ];
}

/**
 * Return database query-fingerprint hot spots.
 *
 * @return array<int,array<string,mixed>>
 */
function telemetry_model_report_database_fingerprints(int $days, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare('SELECT query_fingerprint, route_name, operation, table_name,
        SUM(query_count) AS query_count,
        SUM(failed_count) AS failed_count,
        SUM(slow_count) AS slow_count,
        SUM(latency_ms_sum) / NULLIF(SUM(query_count), 0) AS avg_latency_ms,
        MAX(latency_ms_max) AS max_latency_ms
        FROM telemetry_db_query_metrics
        WHERE bucket_start >= DATE_SUB(NOW(), INTERVAL ? DAY)
        GROUP BY query_fingerprint, route_name, operation, table_name
        ORDER BY slow_count DESC, avg_latency_ms DESC, query_count DESC
        LIMIT ' . $limit);
    $stmt->execute([$days]);
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
    $stmt->execute([$days]);
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
 */
function telemetry_model_delete_older_than(string $tableName, string $columnName, int $days): int
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
    $stmt = db()->prepare('DELETE FROM ' . $tableName . ' WHERE ' . $columnName . ' < DATE_SUB(NOW(), INTERVAL ? DAY)');
    $stmt->execute([$days]);
    return $stmt->rowCount();
}

/**
 * Upsert one privacy-safe hourly database query metric.
 *
 * @param array<int,mixed> $params Ordered telemetry_db_query_metrics parameters.
 */
function telemetry_model_upsert_db_query_metric(array $params): void
{
    $stmt = db()->prepare('INSERT INTO telemetry_db_query_metrics (
        bucket_start, route_name, operation, table_name, query_fingerprint, query_count, failed_count,
        slow_count, latency_ms_sum, latency_ms_max, rows_returned_sum, rows_affected_sum, updated_at
    ) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        query_count = query_count + 1,
        failed_count = failed_count + VALUES(failed_count),
        slow_count = slow_count + VALUES(slow_count),
        latency_ms_sum = latency_ms_sum + VALUES(latency_ms_sum),
        latency_ms_max = GREATEST(latency_ms_max, VALUES(latency_ms_max)),
        rows_returned_sum = rows_returned_sum + VALUES(rows_returned_sum),
        rows_affected_sum = rows_affected_sum + VALUES(rows_affected_sum),
        updated_at = VALUES(updated_at)');
    $stmt->execute($params);
}
