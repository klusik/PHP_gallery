<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_database_usage.php
 * Module Type: Service
 *
 * Purpose:
 *   Measures MySQL/MariaDB table storage used by the gallery database.
 *
 * Responsibilities:
 *   - Read database-size metadata from information_schema.TABLES
 *   - Separate gallery/content tables from operational tables
 *   - Return compact usage rows suitable for Admin dashboard rendering
 *   - Recompute database engine statistics on explicit admin request
 *   - Fail safely when table-size metadata is unavailable on shared hosting
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
 *   2026-06-13
 */

declare(strict_types=1);

/**
 * Return table names that represent gallery content or gallery-derived metadata.
 *
 * These tables are the database counterpart to the filesystem gallery storage:
 * folders, images, tags, votes, ZIP cache rows, upload tokens, flight-map rows,
 * AI metadata rows, analysis jobs, and tracked thumbnail variants. Runtime logs,
 * telemetry, settings, accounts, and update state remain outside this number.
 *
 * @return array<int string>.
 */
function admin_database_usage_gallery_table_names(): array
{
    return [
        'galleries',
        'images',
        'tags',
        'gallery_tags',
        'image_tags',
        'image_votes',
        'picture_game_votes',
        'zip_archives',
        'gallery_upload_tokens',
        'gallery_flight_maps',
        'image_ai_metadata',
        'image_ai_analysis_jobs',
        'image_thumbnail_variants',
    ];
}

/**
 * Return database usage measured from MySQL/MariaDB table metadata.
 *
 * The returned byte values are the database engine estimate for table data pages
 * plus secondary index pages. For InnoDB/MariaDB these numbers are usually close
 * enough for admin capacity planning, but they should not be treated as exact
 * byte-for-byte payload serialization sizes.
 *
 * @return array<string mixed>.
 */
function admin_database_usage_summary(): array
{
    try {
        $databaseName = admin_database_usage_current_database_name();
        if ($databaseName === '') {
            return admin_database_usage_unavailable('Current database name could not be detected.');
        }

        return admin_database_usage_build_summary_from_rows(
            $databaseName,
            admin_database_usage_table_rows($databaseName),
            admin_database_usage_gallery_table_names()
        );
    } catch (Throwable $exception) {
        return admin_database_usage_unavailable($exception->getMessage());
    }
}

/**
 * Recompute database table statistics through ANALYZE TABLE.
 *
 * The storage page normally reads information_schema directly, but some MySQL
 * and MariaDB installations keep row and page estimates stale after DDL. This
 * method deliberately asks the engine to refresh table statistics, then reads
 * the same information_schema summary again for comparison. It does not rebuild
 * tables, optimize storage files, or change gallery data.
 *
 * @return array<string mixed> Structured recompute report.
 */
function admin_database_usage_recompute_statistics(): array
{
    $startedAt = microtime(true);
    $databaseName = admin_database_usage_current_database_name();
    if ($databaseName === '') {
        return [
            'ok' => false,
            'database_name' => '',
            'table_count' => 0,
            'table_reports' => [],
            'duration_seconds' => 0.0,
            'before_summary' => admin_database_usage_unavailable('Current database name could not be detected.'),
            'after_summary' => admin_database_usage_unavailable('Current database name could not be detected.'),
            'error' => 'Current database name could not be detected.',
        ];
    }

    $beforeRows = admin_database_usage_table_rows($databaseName);
    $beforeSummary = admin_database_usage_build_summary_from_rows(
        $databaseName,
        $beforeRows,
        admin_database_usage_gallery_table_names()
    );
    $tableNames = admin_database_usage_recomputable_table_names($beforeRows);
    $tableReports = [];
    $failedTables = 0;

    foreach ($tableNames as $tableName) {
        $tableStartedAt = microtime(true);
        try {
            $stmt = db()->query('ANALYZE TABLE ' . admin_database_usage_quote_identifier($tableName));
            $messages = $stmt ? $stmt->fetchAll() : [];
            $tableReports[] = [
                'table_name' => $tableName,
                'status' => 'ok',
                'duration_seconds' => round(microtime(true) - $tableStartedAt, 4),
                'messages' => admin_database_usage_normalize_analyze_messages($messages),
            ];
        } catch (Throwable $exception) {
            $failedTables++;
            $tableReports[] = [
                'table_name' => $tableName,
                'status' => 'failed',
                'duration_seconds' => round(microtime(true) - $tableStartedAt, 4),
                'messages' => [],
                'error' => $exception->getMessage(),
            ];
        }
    }

    $afterRows = admin_database_usage_table_rows($databaseName);
    $afterSummary = admin_database_usage_build_summary_from_rows(
        $databaseName,
        $afterRows,
        admin_database_usage_gallery_table_names()
    );

    $report = [
        'ok' => $failedTables === 0,
        'database_name' => $databaseName,
        'table_count' => count($tableNames),
        'failed_table_count' => $failedTables,
        'table_reports' => $tableReports,
        'duration_seconds' => round(microtime(true) - $startedAt, 4),
        'started_at_utc' => gmdate('c', (int) $startedAt),
        'finished_at_utc' => gmdate('c'),
        'php_max_execution_time' => ini_get('max_execution_time'),
        'php_memory_limit' => ini_get('memory_limit'),
        'before_summary' => $beforeSummary,
        'after_summary' => $afterSummary,
        'total_bytes_delta' => (int) ($afterSummary['total_bytes'] ?? 0) - (int) ($beforeSummary['total_bytes'] ?? 0),
        'gallery_bytes_delta' => (int) ($afterSummary['gallery_bytes'] ?? 0) - (int) ($beforeSummary['gallery_bytes'] ?? 0),
        'row_estimate_delta' => (int) ($afterSummary['table_rows_estimate'] ?? 0) - (int) ($beforeSummary['table_rows_estimate'] ?? 0),
        'gallery_row_estimate_delta' => (int) ($afterSummary['gallery_rows_estimate'] ?? 0) - (int) ($beforeSummary['gallery_rows_estimate'] ?? 0),
        'error' => '',
    ];

    if (function_exists('admin_log_event')) {
        admin_log_event('info', 'database_usage.recomputed', 'Admin recomputed database table statistics.', $report, ['category' => 'database', 'severity' => $failedTables === 0 ? 'notice' : 'warning']);
    }

    return $report;
}

/**
 * Return table names that can be safely passed to ANALYZE TABLE.
 *
 * @param array $rows Table metadata rows.
 * @return array<int, string> Table names.
 */
function admin_database_usage_recomputable_table_names(array $rows): array
{
    $tableNames = [];
    foreach ($rows as $row) {
        $tableName = trim((string) ($row['table_name'] ?? $row['TABLE_NAME'] ?? ''));
        $engine = trim((string) ($row['engine'] ?? $row['ENGINE'] ?? ''));
        if ($tableName === '' || $engine === '') {
            continue;
        }
        $tableNames[] = $tableName;
    }
    return array_values(array_unique($tableNames));
}

/**
 * Quote a database identifier for a controlled maintenance statement.
 *
 * @param string $identifier Identifier value.
 * @return string Quoted identifier.
 */
function admin_database_usage_quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * Normalize ANALYZE TABLE rows for admin logs.
 *
 * @param array $messages Raw rows.
 * @return array<int, array<string, string>> Normalized rows.
 */
function admin_database_usage_normalize_analyze_messages(array $messages): array
{
    $normalized = [];
    foreach ($messages as $message) {
        $normalized[] = [
            'table' => (string) ($message['Table'] ?? $message['table'] ?? ''),
            'operation' => (string) ($message['Op'] ?? $message['op'] ?? ''),
            'message_type' => (string) ($message['Msg_type'] ?? $message['msg_type'] ?? ''),
            'message_text' => (string) ($message['Msg_text'] ?? $message['msg_text'] ?? ''),
        ];
    }
    return $normalized;
}

/**
 * Return the active database/schema name for the shared PDO connection.
 *
 * @return string Text result for the caller.
 */
function admin_database_usage_current_database_name(): string
{
    try {
        $row = db()->query('SELECT DATABASE() AS database_name')->fetch() ?: [];
        return trim((string) ($row['database_name'] ?? ''));
    } catch (Throwable) {
        $config = cms_config();
        return trim((string) ($config['database']['name'] ?? ''));
    }
}

/**
 * Return raw table-size rows from information_schema.TABLES.
 *
 * @param string $databaseName Database name value.
 * @return array<int array<string, mixed>>.
 */
function admin_database_usage_table_rows(string $databaseName): array
{
    $stmt = db()->prepare(
        'SELECT TABLE_NAME AS table_name,
                COALESCE(TABLE_ROWS, 0) AS table_rows,
                COALESCE(DATA_LENGTH, 0) AS data_bytes,
                COALESCE(INDEX_LENGTH, 0) AS index_bytes,
                COALESCE(ENGINE, "") AS engine
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = :database_name
          ORDER BY (COALESCE(DATA_LENGTH, 0) + COALESCE(INDEX_LENGTH, 0)) DESC, TABLE_NAME ASC'
    );
    $stmt->execute(['database_name' => $databaseName]);
    return $stmt->fetchAll();
}

/**
 * Build a safe unavailable-state payload.
 *
 * @param string $reason Reason value.
 * @return array<string mixed>.
 */
function admin_database_usage_unavailable(string $reason): array
{
    return [
        'available' => false,
        'method' => 'information_schema',
        'database_name' => '',
        'table_count' => 0,
        'total_bytes' => 0,
        'data_bytes' => 0,
        'index_bytes' => 0,
        'gallery_bytes' => 0,
        'gallery_data_bytes' => 0,
        'gallery_index_bytes' => 0,
        'gallery_table_count' => 0,
        'gallery_percent_of_database' => 0.0,
        'operational_bytes' => 0,
        'table_rows_estimate' => 0,
        'gallery_rows_estimate' => 0,
        'largest_table_name' => '',
        'largest_table_bytes' => 0,
        'table_rows' => [],
        'gallery_table_rows' => [],
        'error' => $reason,
    ];
}

/**
 * Build a normalized usage summary from information_schema rows.
 *
 * @param string $databaseName Database name value.
 * @param array $rows Rows to process.
 * @param array $galleryTableNames Gallery table names value.
 * @return array<string mixed>.
 */
function admin_database_usage_build_summary_from_rows(string $databaseName, array $rows, array $galleryTableNames): array
{
    $galleryTableLookup = array_fill_keys(array_map('strtolower', $galleryTableNames), true);
    $normalizedRows = [];
    $galleryRows = [];

    $totalBytes = 0;
    $dataBytes = 0;
    $indexBytes = 0;
    $galleryBytes = 0;
    $galleryDataBytes = 0;
    $galleryIndexBytes = 0;
    $rowEstimate = 0;
    $galleryRowEstimate = 0;
    $largestTableName = '';
    $largestTableBytes = 0;

    foreach ($rows as $row) {
        $normalized = admin_database_usage_normalize_table_row($row, $galleryTableLookup);
        if ((string) ($normalized['table_name'] ?? '') === '') {
            continue;
        }

        $bytes = (int) ($normalized['bytes'] ?? 0);
        $totalBytes += $bytes;
        $dataBytes += (int) ($normalized['data_bytes'] ?? 0);
        $indexBytes += (int) ($normalized['index_bytes'] ?? 0);
        $rowEstimate += (int) ($normalized['count'] ?? 0);

        if ($bytes > $largestTableBytes) {
            $largestTableBytes = $bytes;
            $largestTableName = (string) ($normalized['table_name'] ?? '');
        }

        if (!empty($normalized['is_gallery_table'])) {
            $galleryBytes += $bytes;
            $galleryDataBytes += (int) ($normalized['data_bytes'] ?? 0);
            $galleryIndexBytes += (int) ($normalized['index_bytes'] ?? 0);
            $galleryRowEstimate += (int) ($normalized['count'] ?? 0);
            $galleryRows[] = $normalized;
        }

        $normalizedRows[] = $normalized;
    }

    return [
        'available' => true,
        'method' => 'information_schema',
        'database_name' => $databaseName,
        'table_count' => count($normalizedRows),
        'total_bytes' => $totalBytes,
        'data_bytes' => $dataBytes,
        'index_bytes' => $indexBytes,
        'gallery_bytes' => $galleryBytes,
        'gallery_data_bytes' => $galleryDataBytes,
        'gallery_index_bytes' => $galleryIndexBytes,
        'gallery_table_count' => count($galleryRows),
        'gallery_percent_of_database' => $totalBytes > 0 ? round(($galleryBytes / $totalBytes) * 100, 1) : 0.0,
        'operational_bytes' => max(0, $totalBytes - $galleryBytes),
        'table_rows_estimate' => $rowEstimate,
        'gallery_rows_estimate' => $galleryRowEstimate,
        'largest_table_name' => $largestTableName,
        'largest_table_bytes' => $largestTableBytes,
        'table_rows' => admin_database_usage_finalize_table_rows($normalizedRows, $totalBytes, 10),
        'gallery_table_rows' => admin_database_usage_finalize_table_rows($galleryRows, $galleryBytes, 10),
        'error' => '',
    ];
}

/**
 * Normalize one information_schema row for display and aggregation.
 *
 * @param array $row Row data.
 * @param array $galleryTableLookup Gallery table lookup value.
 * @return array<string mixed>.
 */
function admin_database_usage_normalize_table_row(array $row, array $galleryTableLookup): array
{
    $tableName = trim((string) ($row['table_name'] ?? $row['TABLE_NAME'] ?? ''));
    $dataBytes = max(0, (int) ($row['data_bytes'] ?? $row['DATA_LENGTH'] ?? 0));
    $indexBytes = max(0, (int) ($row['index_bytes'] ?? $row['INDEX_LENGTH'] ?? 0));
    $rowCount = max(0, (int) ($row['table_rows'] ?? $row['TABLE_ROWS'] ?? 0));
    $engine = trim((string) ($row['engine'] ?? $row['ENGINE'] ?? ''));
    $isGalleryTable = isset($galleryTableLookup[strtolower($tableName)]);

    return [
        'key' => 'db-table-' . preg_replace('/[^a-z0-9_]+/i', '-', strtolower($tableName)),
        'label' => $tableName,
        'table_name' => $tableName,
        'count' => $rowCount,
        'bytes' => $dataBytes + $indexBytes,
        'data_bytes' => $dataBytes,
        'index_bytes' => $indexBytes,
        'engine' => $engine,
        'is_gallery_table' => $isGalleryTable,
    ];
}

/**
 * Sort table rows and add percentage values for chart rendering.
 *
 * @param array $rows Rows to process.
 * @param int $totalBytes Total bytes value.
 * @param int $limit Maximum number of items.
 * @return array<int array<string, mixed>>.
 */
function admin_database_usage_finalize_table_rows(array $rows, int $totalBytes, int $limit = 10): array
{
    usort($rows, static function (array $left, array $right): int {
        $bytesCompare = (int) ($right['bytes'] ?? 0) <=> (int) ($left['bytes'] ?? 0);
        if ($bytesCompare !== 0) {
            return $bytesCompare;
        }
        return strnatcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
    });

    if ($limit > 0 && count($rows) > $limit) {
        $rows = array_slice($rows, 0, $limit);
    }

    foreach ($rows as $index => $row) {
        $bytes = max(0, (int) ($row['bytes'] ?? 0));
        $rows[$index]['percent'] = $totalBytes > 0 ? round(($bytes / $totalBytes) * 100, 1) : 0.0;
    }

    return $rows;
}
