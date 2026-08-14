<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/database_maintenance.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides explicit, administrator-triggered database inspection, safe cleanup,
 *   legacy schema repair planning, and physical table maintenance.
 *
 * Responsibilities:
 *   - Build a complete information_schema inventory without touching gallery files
 *   - Correlate current schema objects with migration history and production code
 *   - Classify only explainable orphan, duplicate, and expired cleanup candidates
 *   - Execute bounded, resumable, idempotent database-only cleanup batches
 *   - Keep ANALYZE TABLE and OPTIMIZE TABLE separate and explicitly confirmed
 *   - Protect content, accounts, logs, telemetry, and unknown tables by default
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
 *   - This service never deletes filesystem media or thumbnail files.
 *
 * Last Updated:
 *   2026-07-25
 */

declare(strict_types=1);

namespace Gallery\Services;

use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use function Gallery\Core\pending_migration_files;
use function Gallery\Core\discover_migration_files;
use function Gallery\Core\run_migrations;

const DATABASE_MAINTENANCE_REPORT_FILE = 'admin-database-maintenance-report.json';
const DATABASE_MAINTENANCE_STATE_SETTING = 'database_maintenance_cleanup_state';
const DATABASE_MAINTENANCE_DEFAULT_BATCH_SIZE = 250;
const DATABASE_MAINTENANCE_MAX_BATCH_SIZE = 1000;
const DATABASE_MAINTENANCE_REPAIR_VERSION = '202607250001_database_maintenance_schema_repair';

/**
 * Return the project root directory.
 */
function database_maintenance_project_root(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Return the report cache path.
 */
function database_maintenance_report_path(): string
{
    return database_maintenance_project_root() . '/cache/' . DATABASE_MAINTENANCE_REPORT_FILE;
}

/**
 * Return explicit ownership and cleanup policy for known application tables.
 *
 * Unknown tables are deliberately protected. A large table is not considered
 * stale merely because of its row count or allocated storage.
 *
 * @return array<string, array<string, mixed>> Policies keyed by table name.
 */
function database_maintenance_table_policies(): array
{
    $protected = static fn (string $category, string $owner, string $reason, string $optimize = 'manual'): array => [
        'category' => $category,
        'owner' => $owner,
        'orphan_rule' => 'Disabled. No generic deletion semantics are assumed.',
        'retention_rule' => 'No automatic retention deletion.',
        'duplicate_rule' => 'Report only unless a deterministic logical identity is explicitly defined.',
        'protected_rule' => $reason,
        'cleanup_mode' => 'disabled',
        'physical_optimization' => $optimize,
    ];
    $derived = static fn (string $category, string $owner, string $orphan, string $retention = 'No age-based cleanup.', string $duplicate = 'Unique constraints define logical identity.', string $mode = 'manual'): array => [
        'category' => $category,
        'owner' => $owner,
        'orphan_rule' => $orphan,
        'retention_rule' => $retention,
        'duplicate_rule' => $duplicate,
        'protected_rule' => 'Only rows matching a listed high-confidence rule may be removed.',
        'cleanup_mode' => $mode,
        'physical_optimization' => 'manual',
    ];

    return [
        'galleries' => $protected('gallery/content data', 'Filesystem gallery folder and gallery metadata', 'Valid gallery content is always protected.'),
        'images' => $protected('gallery/content data', 'Gallery and source image', 'Valid image content is always protected.'),
        'duplicate_photo_ledger_pairs' => $protected('administrator workflow state', 'Per-administrator reviewed duplicate image relationships', 'Ledger decisions are removed only by the Duplicate Photo Detector controls or foreign-key cascades.'),
        'duplicate_photo_ledger_galleries' => $protected('administrator workflow state', 'Per-administrator exact-gallery duplicate suppression rules', 'Ledger decisions are removed only by the Duplicate Photo Detector controls or foreign-key cascades.'),
        'tags' => $protected('gallery/content data', 'Administrator-defined taxonomy', 'Unused tags may be intentional and are not removed automatically.'),
        'gallery_tags' => $derived('gallery/content data', 'Gallery and tag link', 'Remove only links whose gallery or tag parent is missing.', 'No retention rule.', 'Composite primary key defines one gallery/tag link.', 'automatic'),
        'image_tags' => $derived('gallery/content data', 'Image and tag link', 'Remove only links whose image or tag parent is missing.', 'No retention rule.', 'Composite primary key defines one image/tag link.', 'automatic'),
        'image_votes' => $derived('gallery/content data', 'Image vote', 'Remove only votes whose image parent is missing.', 'No retention rule.', 'Existing user/visitor unique keys define identity.', 'automatic'),
        'picture_game_votes' => $derived('gallery/content data', 'Gallery picture-game vote', 'Remove only votes whose gallery or referenced image is missing.', 'No retention rule.', 'Gallery, voter, and image-pair unique key defines identity.', 'automatic'),
        'zip_archives' => $derived('caches and temporary state', 'Generated ZIP metadata', 'Remove only gallery-scoped rows whose gallery parent is missing. Never delete ZIP files from this workflow.', 'No age-based deletion.', 'Content signature participates in lookup identity.', 'automatic'),
        'gallery_upload_tokens' => $derived('authentication and accounts', 'Gallery upload credential', 'Remove only tokens whose gallery parent is missing.', 'Revoked tokens are preserved unless a separate credential policy removes them.', 'Token hash is unique.', 'automatic'),
        'gallery_flight_maps' => $derived('external integration data', 'Gallery flight-map metadata', 'Remove only rows whose gallery parent is missing.', 'No retention rule.', 'One row per gallery primary key.', 'automatic'),
        'image_ai_metadata' => $derived('gallery/content data', 'Image AI-derived metadata', 'Remove only rows whose image parent is missing.', 'No age-based deletion.', 'Image, model name, and model version define identity.', 'automatic'),
        'image_ai_analysis_jobs' => $derived('caches and temporary state', 'Gallery image analysis queue', 'Remove only jobs whose gallery or image parent is missing.', 'Completed jobs are not deleted by age in this workflow.', 'Job key is unique.', 'automatic'),
        'image_thumbnail_variants' => $derived('caches and temporary state', 'Generated thumbnail metadata only, never image bytes', 'Remove only rows whose image parent is missing.', 'Unsupported sizes and formats are reported, not deleted automatically.', 'Image, size, and format define identity; deterministic duplicate survivor is the lowest id.', 'automatic'),
        'flight_map_nav_points' => $protected('external integration data', 'Imported navigation dataset', 'Data lifecycle is owned by the navigation-data refresh workflow.'),
        'navigation_data_accounts' => $derived('external integration data', 'User navigation provider credential', 'Remove only rows whose user parent is missing.', 'Token expiry does not imply account deletion.', 'User and provider define identity.', 'automatic'),
        'navigation_data_cache' => $derived('caches and temporary state', 'Navigation lookup cache', 'No parent relationship.', 'Rows are eligible only when expires_at is explicitly in the past.', 'cache_key is unique; deterministic duplicate survivor is the lowest id.', 'automatic'),
        'users' => $protected('authentication and accounts', 'Administrator account', 'Accounts are never removed by database maintenance.'),
        'admin_remember_tokens' => $derived('authentication and accounts', 'Administrator persistent login token', 'Remove only rows whose user parent is missing.', 'Expired or explicitly revoked credentials are eligible.', 'Selector is unique.', 'automatic'),
        'password_reset_tokens' => $derived('authentication and accounts', 'One-time password reset credential', 'Remove only rows whose user parent is missing.', 'Expired or already-used credentials are eligible.', 'Selector is unique.', 'automatic'),
        'auth_rate_limits' => $derived('authentication and accounts', 'Authentication throttle state', 'No parent relationship.', 'Use the application policy: inactive rows older than 24 hours and expired locks older than 24 hours.', 'Bucket and subject hash define identity.', 'automatic'),
        'mobile_webdav_upload_tokens' => $derived('authentication and accounts', 'User and gallery WebDAV credential', 'Remove only rows whose user or gallery parent is missing.', 'Disabled credentials are preserved.', 'Path token is unique.', 'automatic'),
        'user_google_accounts' => $derived('authentication and accounts', 'User Google account link', 'Remove only rows whose user parent is missing.', 'No retention rule.', 'User id and Google subject are unique identities.', 'automatic'),
        'user_openai_text_settings' => $derived('settings', 'User OpenAI text profile', 'Remove only rows whose user parent is missing.', 'No retention rule.', 'One row per user.', 'automatic'),
        'app_settings' => $protected('settings', 'Application configuration', 'Unknown and compatibility settings are retained. Dedicated migrations may remove proven obsolete keys.'),
        'admin_logs' => $protected('audit logs', 'Administrator audit and diagnostics history', 'Generic database cleanup never deletes Admin logs. Admin Logs archival maintenance removes historical rows only after a verified daily filesystem ZIP has been created.'),
        'database_maintenance_audit_log' => $protected('audit logs', 'Transactional database cleanup audit trail', 'Immutable row-identifier audit records are never deleted by generic database cleanup.'),
        'telemetry_settings' => $protected('telemetry/analytics', 'Telemetry configuration', 'Never deleted by generic database cleanup.'),
        'telemetry_sessions' => $protected('telemetry/analytics', 'Anonymous telemetry session data', 'Retention is owned by the separate telemetry maintenance workflow.'),
        'telemetry_events' => $protected('telemetry/analytics', 'Raw telemetry events', 'Retention is owned by the separate telemetry maintenance workflow.'),
        'telemetry_hourly_metrics' => $protected('telemetry/analytics', 'Hourly telemetry aggregates', 'Retention is owned by the separate telemetry maintenance workflow.'),
        'telemetry_daily_metrics' => $protected('telemetry/analytics', 'Daily telemetry aggregates', 'Retention is owned by the separate telemetry maintenance workflow.'),
        'telemetry_db_query_metrics' => $protected('telemetry/analytics', 'Database query aggregates', 'Retention is owned by the separate telemetry maintenance workflow.'),
        'telemetry_job_runs' => $protected('telemetry/analytics', 'Telemetry maintenance job history', 'Never deleted by generic database cleanup.'),
        'schema_migrations' => $protected('migration/system tables', 'Current migration audit trail', 'Migration audit rows are immutable.'),
        'migrations' => $protected('migration/system tables', 'Legacy migration audit trail when present', 'Legacy audit rows are preserved for old installations.'),
    ];
}

/**
 * Return a protected fallback policy for a dynamically discovered table.
 */
function database_maintenance_unknown_policy(string $tableName): array
{
    return [
        'category' => 'unknown/unclassified',
        'owner' => 'Unknown table: ' . $tableName,
        'orphan_rule' => 'Disabled because ownership is unclear.',
        'retention_rule' => 'Disabled because retention semantics are unclear.',
        'duplicate_rule' => 'Disabled because logical identity is unclear.',
        'protected_rule' => 'Unknown tables are always protected from automatic deletion.',
        'cleanup_mode' => 'disabled',
        'physical_optimization' => 'manual',
    ];
}

/**
 * Read and normalize the complete current database schema inventory.
 *
 * @return array<string, mixed> Structured inventory.
 */
function database_maintenance_schema_inventory(): array
{
    $databaseName = admin_database_usage_current_database_name();
    if ($databaseName === '') {
        throw new RuntimeException('Current database name could not be detected.');
    }

    $tableRows = database_maintenance_query_schema(
        'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, AUTO_INCREMENT, CREATE_TIME, UPDATE_TIME, TABLE_COMMENT
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME',
        [$databaseName]
    );
    $columnRows = database_maintenance_query_schema(
        'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_KEY, EXTRA, COLUMN_COMMENT
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, ORDINAL_POSITION',
        [$databaseName]
    );
    $indexRows = database_maintenance_query_schema(
        'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION, CARDINALITY, INDEX_COMMENT
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = ?
          ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        [$databaseName]
    );
    $constraintRows = database_maintenance_query_schema(
        'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,
                r.UPDATE_RULE, r.DELETE_RULE
           FROM information_schema.KEY_COLUMN_USAGE k
           LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r
             ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
            AND r.TABLE_NAME = k.TABLE_NAME
            AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
          WHERE k.TABLE_SCHEMA = ?
          ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
        [$databaseName]
    );

    return database_maintenance_normalize_inventory($databaseName, $tableRows, $columnRows, $indexRows, $constraintRows);
}

/**
 * Execute one information_schema query.
 *
 * @param string $sql SQL statement.
 * @param array<int, mixed> $parameters Bound parameters.
 * @return array<int, array<string, mixed>> Rows.
 */
function database_maintenance_query_schema(string $sql, array $parameters): array
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Normalize raw information_schema rows into table-centered records.
 *
 * This helper is intentionally pure so legacy and compact schema fixtures can
 * be tested without a database server.
 *
 * @param string $databaseName Database name.
 * @param array<int, array<string, mixed>> $tableRows Table metadata rows.
 * @param array<int, array<string, mixed>> $columnRows Column metadata rows.
 * @param array<int, array<string, mixed>> $indexRows Index metadata rows.
 * @param array<int, array<string, mixed>> $constraintRows Constraint metadata rows.
 * @return array<string, mixed> Normalized inventory.
 */
function database_maintenance_normalize_inventory(string $databaseName, array $tableRows, array $columnRows, array $indexRows, array $constraintRows): array
{
    $policies = database_maintenance_table_policies();
    $tables = [];

    foreach ($tableRows as $row) {
        $tableName = trim((string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? ''));
        if ($tableName === '') {
            continue;
        }
        $dataBytes = max(0, (int) ($row['DATA_LENGTH'] ?? $row['data_length'] ?? 0));
        $indexBytes = max(0, (int) ($row['INDEX_LENGTH'] ?? $row['index_length'] ?? 0));
        $policy = $policies[strtolower($tableName)] ?? database_maintenance_unknown_policy($tableName);
        $tables[$tableName] = [
            'table_name' => $tableName,
            'category' => (string) $policy['category'],
            'policy' => $policy,
            'engine' => (string) ($row['ENGINE'] ?? $row['engine'] ?? ''),
            'collation' => (string) ($row['TABLE_COLLATION'] ?? $row['table_collation'] ?? ''),
            'charset' => database_maintenance_charset_from_collation((string) ($row['TABLE_COLLATION'] ?? $row['table_collation'] ?? '')),
            'estimated_rows' => max(0, (int) ($row['TABLE_ROWS'] ?? $row['table_rows'] ?? 0)),
            'data_bytes' => $dataBytes,
            'index_bytes' => $indexBytes,
            'total_bytes' => $dataBytes + $indexBytes,
            'reclaimable_bytes_estimate' => max(0, (int) ($row['DATA_FREE'] ?? $row['data_free'] ?? 0)),
            'auto_increment' => isset($row['AUTO_INCREMENT']) ? (int) $row['AUTO_INCREMENT'] : null,
            'created_at' => (string) ($row['CREATE_TIME'] ?? $row['create_time'] ?? ''),
            'updated_at' => (string) ($row['UPDATE_TIME'] ?? $row['update_time'] ?? ''),
            'comment' => (string) ($row['TABLE_COMMENT'] ?? $row['table_comment'] ?? ''),
            'columns' => [],
            'primary_key' => [],
            'unique_keys' => [],
            'secondary_indexes' => [],
            'foreign_keys' => [],
            'text_blob_json_columns' => [],
            'enum_set_columns' => [],
        ];
    }

    foreach ($columnRows as $row) {
        $tableName = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
        $columnName = (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? '');
        if (!isset($tables[$tableName]) || $columnName === '') {
            continue;
        }
        $dataType = strtolower((string) ($row['DATA_TYPE'] ?? $row['data_type'] ?? ''));
        $column = [
            'name' => $columnName,
            'position' => (int) ($row['ORDINAL_POSITION'] ?? $row['ordinal_position'] ?? 0),
            'data_type' => $dataType,
            'column_type' => (string) ($row['COLUMN_TYPE'] ?? $row['column_type'] ?? ''),
            'nullable' => strtoupper((string) ($row['IS_NULLABLE'] ?? $row['is_nullable'] ?? 'NO')) === 'YES',
            'default' => $row['COLUMN_DEFAULT'] ?? $row['column_default'] ?? null,
            'character_set' => (string) ($row['CHARACTER_SET_NAME'] ?? $row['character_set_name'] ?? ''),
            'collation' => (string) ($row['COLLATION_NAME'] ?? $row['collation_name'] ?? ''),
            'key' => (string) ($row['COLUMN_KEY'] ?? $row['column_key'] ?? ''),
            'extra' => (string) ($row['EXTRA'] ?? $row['extra'] ?? ''),
            'comment' => (string) ($row['COLUMN_COMMENT'] ?? $row['column_comment'] ?? ''),
        ];
        $tables[$tableName]['columns'][$columnName] = $column;
        if (in_array($dataType, ['tinytext', 'text', 'mediumtext', 'longtext', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'json'], true)) {
            $tables[$tableName]['text_blob_json_columns'][] = $columnName;
        }
        if (in_array($dataType, ['enum', 'set'], true)) {
            $tables[$tableName]['enum_set_columns'][$columnName] = $column['column_type'];
        }
    }

    $indexes = [];
    foreach ($indexRows as $row) {
        $tableName = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
        $indexName = (string) ($row['INDEX_NAME'] ?? $row['index_name'] ?? '');
        if (!isset($tables[$tableName]) || $indexName === '') {
            continue;
        }
        $indexes[$tableName][$indexName]['name'] = $indexName;
        $indexes[$tableName][$indexName]['unique'] = (int) ($row['NON_UNIQUE'] ?? $row['non_unique'] ?? 1) === 0;
        $indexes[$tableName][$indexName]['type'] = (string) ($row['INDEX_TYPE'] ?? $row['index_type'] ?? '');
        $indexes[$tableName][$indexName]['columns'][] = [
            'name' => (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? ''),
            'position' => (int) ($row['SEQ_IN_INDEX'] ?? $row['seq_in_index'] ?? 0),
            'prefix_length' => isset($row['SUB_PART']) ? (int) $row['SUB_PART'] : null,
            'cardinality' => isset($row['CARDINALITY']) ? (int) $row['CARDINALITY'] : null,
        ];
    }
    foreach ($indexes as $tableName => $tableIndexes) {
        foreach ($tableIndexes as $indexName => $index) {
            if ($indexName === 'PRIMARY') {
                $tables[$tableName]['primary_key'] = $index['columns'];
            } elseif (!empty($index['unique'])) {
                $tables[$tableName]['unique_keys'][$indexName] = $index;
            } else {
                $tables[$tableName]['secondary_indexes'][$indexName] = $index;
            }
        }
    }

    foreach ($constraintRows as $row) {
        $tableName = (string) ($row['TABLE_NAME'] ?? $row['table_name'] ?? '');
        $constraintName = (string) ($row['CONSTRAINT_NAME'] ?? $row['constraint_name'] ?? '');
        $referencedTable = (string) ($row['REFERENCED_TABLE_NAME'] ?? $row['referenced_table_name'] ?? '');
        if (!isset($tables[$tableName]) || $constraintName === '' || $referencedTable === '') {
            continue;
        }
        $tables[$tableName]['foreign_keys'][$constraintName]['name'] = $constraintName;
        $tables[$tableName]['foreign_keys'][$constraintName]['referenced_table'] = $referencedTable;
        $tables[$tableName]['foreign_keys'][$constraintName]['update_rule'] = (string) ($row['UPDATE_RULE'] ?? $row['update_rule'] ?? '');
        $tables[$tableName]['foreign_keys'][$constraintName]['delete_rule'] = (string) ($row['DELETE_RULE'] ?? $row['delete_rule'] ?? '');
        $tables[$tableName]['foreign_keys'][$constraintName]['columns'][] = [
            'column' => (string) ($row['COLUMN_NAME'] ?? $row['column_name'] ?? ''),
            'referenced_column' => (string) ($row['REFERENCED_COLUMN_NAME'] ?? $row['referenced_column_name'] ?? ''),
            'position' => (int) ($row['ORDINAL_POSITION'] ?? $row['ordinal_position'] ?? 0),
        ];
    }

    ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);
    return [
        'database_name' => $databaseName,
        'generated_at_utc' => gmdate('c'),
        'table_count' => count($tables),
        'tables' => $tables,
    ];
}

/**
 * Derive a character set name from a collation.
 */
function database_maintenance_charset_from_collation(string $collation): string
{
    $position = strpos($collation, '_');
    return $position === false ? $collation : substr($collation, 0, $position);
}

/**
 * Return whether a normalized inventory contains a table and optional columns.
 *
 * @param array<string, mixed> $inventory Inventory.
 * @param string $tableName Table name.
 * @param array<int, string> $columns Required columns.
 */
function database_maintenance_inventory_has(array $inventory, string $tableName, array $columns = []): bool
{
    $table = $inventory['tables'][$tableName] ?? null;
    if (!is_array($table)) {
        return false;
    }
    foreach ($columns as $column) {
        if (!isset($table['columns'][$column])) {
            return false;
        }
    }
    return true;
}

/**
 * Parse migration source files without executing them.
 *
 * @return array<string, mixed> Migration audit data.
 */
function database_maintenance_migration_audit(): array
{
    $migrationDirectory = database_maintenance_project_root() . '/database/migrations';
    $files = discover_migration_files($migrationDirectory);
    $tables = [];
    $versions = [];

    $touchTable = static function (array &$audit, string $tableName, string $version): string {
        $tableName = strtolower(trim($tableName));
        if ($tableName !== '') {
            $audit[$tableName]['versions'][] = $version;
        }
        return $tableName;
    };
    $appendObject = static function (array &$audit, string $tableName, string $section, string $objectName, string $version) use ($touchTable): void {
        $tableName = $touchTable($audit, $tableName, $version);
        $objectName = trim($objectName);
        if ($tableName !== '' && $objectName !== '') {
            $audit[$tableName][$section][$objectName][] = $version;
        }
    };

    foreach ($files as $file) {
        $version = basename($file, '.php');
        $source = (string) file_get_contents($file);
        $versions[] = $version;

        preg_match_all('/\b(?:CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?|ALTER\s+TABLE\s+|REFERENCES\s+)`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $source, $tableMatches);
        foreach (array_unique($tableMatches[1] ?? []) as $tableName) {
            $touchTable($tables, (string) $tableName, $version);
        }

        preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z_][A-Za-z0-9_]*)`?\s*\(([\s\S]*?)\)\s*ENGINE\s*=/i', $source, $createMatches, PREG_SET_ORDER);
        foreach ($createMatches as $match) {
            $tableName = $touchTable($tables, (string) $match[1], $version);
            $body = (string) $match[2];
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                $line = trim(rtrim(trim((string) $line), ','));
                if ($line === '') {
                    continue;
                }
                if (preg_match('/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s+/', $line, $columnMatch) === 1) {
                    $candidate = strtoupper((string) $columnMatch[1]);
                    if (!in_array($candidate, ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK'], true)) {
                        $appendObject($tables, $tableName, 'added_columns', (string) $columnMatch[1], $version);
                    }
                }
                if (preg_match('/^PRIMARY\s+KEY\b/i', $line) === 1) {
                    $appendObject($tables, $tableName, 'added_indexes', 'PRIMARY', $version);
                }
                if (preg_match('/^(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $line, $indexMatch) === 1) {
                    $appendObject($tables, $tableName, 'added_indexes', (string) $indexMatch[1], $version);
                }
                if (preg_match('/^CONSTRAINT\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s+FOREIGN\s+KEY\b/i', $line, $foreignMatch) === 1) {
                    $appendObject($tables, $tableName, 'added_foreign_keys', (string) $foreignMatch[1], $version);
                }
            }
        }

        $alterMatches = [];
        preg_match_all('/"ALTER\s+TABLE\s+`?([A-Za-z_][A-Za-z0-9_]*)`?([\s\S]*?)"\s*[,;)]/i', $source, $doubleQuotedAlters, PREG_SET_ORDER);
        preg_match_all("/'ALTER\\s+TABLE\\s+`?([A-Za-z_][A-Za-z0-9_]*)`?([\\s\\S]*?)'\\s*[,;)]/i", $source, $singleQuotedAlters, PREG_SET_ORDER);
        $alterMatches = array_merge($doubleQuotedAlters, $singleQuotedAlters);
        foreach ($alterMatches as $match) {
            $tableName = $touchTable($tables, (string) $match[1], $version);
            $body = (string) $match[2];
            preg_match_all('/\bADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $body, $addedColumns);
            foreach ($addedColumns[1] ?? [] as $columnName) {
                if (!in_array(strtoupper((string) $columnName), ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK'], true)) {
                    $appendObject($tables, $tableName, 'added_columns', (string) $columnName, $version);
                }
            }
            preg_match_all('/\bDROP\s+COLUMN\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $body, $droppedColumns);
            foreach ($droppedColumns[1] ?? [] as $columnName) {
                $appendObject($tables, $tableName, 'dropped_columns', (string) $columnName, $version);
            }
            preg_match_all('/\b(?:CHANGE\s+COLUMN\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s+`?([A-Za-z_][A-Za-z0-9_]*)`?|RENAME\s+COLUMN\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s+TO\s+`?([A-Za-z_][A-Za-z0-9_]*)`?)/i', $body, $renamedColumns, PREG_SET_ORDER);
            foreach ($renamedColumns as $renameMatch) {
                $from = (string) ($renameMatch[1] !== '' ? $renameMatch[1] : $renameMatch[3]);
                $to = (string) ($renameMatch[2] !== '' ? $renameMatch[2] : $renameMatch[4]);
                if ($from !== '' && $to !== '') {
                    $tables[$tableName]['renamed_columns'][$from][$to][] = $version;
                }
            }
            preg_match_all('/\bADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $body, $addedIndexes);
            foreach ($addedIndexes[1] ?? [] as $indexName) {
                $appendObject($tables, $tableName, 'added_indexes', (string) $indexName, $version);
            }
            preg_match_all('/\bDROP\s+(?:KEY|INDEX)\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $body, $droppedIndexes);
            foreach ($droppedIndexes[1] ?? [] as $indexName) {
                $appendObject($tables, $tableName, 'dropped_indexes', (string) $indexName, $version);
            }
            preg_match_all('/\bADD\s+CONSTRAINT\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s+FOREIGN\s+KEY\b/i', $body, $addedForeignKeys);
            foreach ($addedForeignKeys[1] ?? [] as $constraintName) {
                $appendObject($tables, $tableName, 'added_foreign_keys', (string) $constraintName, $version);
            }
            preg_match_all('/\bDROP\s+FOREIGN\s+KEY\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $body, $droppedForeignKeys);
            foreach ($droppedForeignKeys[1] ?? [] as $constraintName) {
                $appendObject($tables, $tableName, 'dropped_foreign_keys', (string) $constraintName, $version);
            }
        }

        preg_match_all('/CREATE\s+(?:UNIQUE\s+)?INDEX\s+`?([A-Za-z_][A-Za-z0-9_]*)`?\s+ON\s+`?([A-Za-z_][A-Za-z0-9_]*)`?/i', $source, $createIndexMatches, PREG_SET_ORDER);
        foreach ($createIndexMatches as $match) {
            $appendObject($tables, (string) $match[2], 'added_indexes', (string) $match[1], $version);
        }

        if (str_contains($source, 'database_maintenance_schema_repair_migration_definition')) {
            foreach (['images', 'image_thumbnail_variants', 'database_maintenance_audit_log'] as $repairTable) {
                $repairTable = $touchTable($tables, $repairTable, $version);
                $tables[$repairTable]['repair_callbacks']['run_database_maintenance_schema_repair'][] = $version;
            }
            foreach (['id', 'operation_id', 'rule_key', 'table_name', 'category', 'reason', 'identifier_columns_json', 'removed_identifiers_json', 'deleted_count', 'created_at'] as $auditColumn) {
                $appendObject($tables, 'database_maintenance_audit_log', 'added_columns', $auditColumn, $version);
            }
            foreach (['PRIMARY', 'database_maintenance_audit_operation_index', 'database_maintenance_audit_table_created_index'] as $auditIndex) {
                $appendObject($tables, 'database_maintenance_audit_log', 'added_indexes', $auditIndex, $version);
            }
        }
    }

    foreach ($tables as &$table) {
        foreach ($table as &$value) {
            if (is_array($value)) {
                $value = database_maintenance_recursive_unique($value);
            }
        }
    }
    unset($table, $value);
    ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);

    return [
        'migration_count' => count($versions),
        'versions' => $versions,
        'tables' => $tables,
    ];
}

/**
 * Recursively unique scalar lists in parsed audit data.
 *
 * @param array<mixed> $value Input array.
 * @return array<mixed> Normalized array.
 */
function database_maintenance_recursive_unique(array $value): array
{
    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = database_maintenance_recursive_unique($item);
        }
    }
    if ($value !== [] && array_keys($value) === range(0, count($value) - 1)) {
        return array_values(array_unique($value, SORT_REGULAR));
    }
    return $value;
}

/**
 * Extract complete PHP string literals that look like SQL statements.
 *
 * Tokenization avoids treating comments, variable names, and unrelated prose as
 * SQL evidence. Dynamic statements are still represented by their literal
 * fragments, so this remains supporting audit evidence rather than a parser.
 *
 * @param string $source PHP source code.
 * @return array<int, string> SQL-like literal fragments.
 */
function database_maintenance_sql_literals(string $source): array
{
    $literals = [];
    $heredoc = '';
    $insideHeredoc = false;
    foreach (token_get_all($source) as $token) {
        if (!is_array($token)) {
            continue;
        }
        [$type, $text] = $token;
        if ($type === T_START_HEREDOC) {
            $heredoc = '';
            $insideHeredoc = true;
            continue;
        }
        if ($type === T_END_HEREDOC) {
            if ($insideHeredoc && preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|ANALYZE|OPTIMIZE)\b/i', $heredoc) === 1) {
                $literals[] = $heredoc;
            }
            $heredoc = '';
            $insideHeredoc = false;
            continue;
        }
        if ($insideHeredoc) {
            if ($type === T_ENCAPSED_AND_WHITESPACE) {
                $heredoc .= $text;
            }
            continue;
        }
        if ($type !== T_CONSTANT_ENCAPSED_STRING || strlen($text) < 2) {
            continue;
        }
        $quote = $text[0];
        $literal = substr($text, 1, -1);
        if ($quote === "'") {
            $literal = str_replace(["\\\\", "\\'"], ["\\", "'"], $literal);
        } else {
            $literal = stripcslashes($literal);
        }
        if (preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|ANALYZE|OPTIMIZE)\b/i', $literal) === 1) {
            $literals[] = $literal;
        }
    }
    return array_values(array_unique($literals));
}

/**
 * Count current code and SQL references for discovered tables and columns.
 *
 * The audit scans production, tooling, and tests as required, but records SQL
 * evidence separately from broad lexical references. Destructive schema
 * decisions may use the production SQL evidence, never an unscoped word match.
 * External scripts and admin exports may exist outside this repository, so the
 * result remains supporting evidence rather than sole authority.
 *
 * @param array<string, mixed> $inventory Inventory.
 * @param array<string, mixed> $migrationAudit Parsed migration audit.
 * @return array<string, mixed> Reference counts and files.
 */
function database_maintenance_code_audit(array $inventory, array $migrationAudit = []): array
{
    $root = database_maintenance_project_root();
    $scanRoots = ['app', 'public', 'scripts', 'tests'];
    $scanExtensions = ['php', 'inc', 'phtml', 'sql'];
    $files = [];
    foreach ($scanRoots as $relativeRoot) {
        $directory = $root . '/' . $relativeRoot;
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), $scanExtensions, true) && $file->getPathname() !== __FILE__) {
                $files[] = $file->getPathname();
            }
        }
    }
    foreach (['install.php', 'reset.php'] as $topLevelFile) {
        $path = $root . '/' . $topLevelFile;
        if (is_file($path)) {
            $files[] = $path;
        }
    }
    $files = array_values(array_unique($files));

    $references = [];
    foreach ($files as $file) {
        $source = (string) file_get_contents($file);
        $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
        $scope = str_starts_with($relative, 'tests/') ? 'test' : 'production';
        $sqlLiterals = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'sql'
            ? [$source]
            : database_maintenance_sql_literals($source);

        foreach ((array) ($inventory['tables'] ?? []) as $tableName => $table) {
            $tablePattern = '/\b' . preg_quote((string) $tableName, '/') . '\b/i';
            $tableMentioned = preg_match($tablePattern, $source) === 1;
            if ($tableMentioned) {
                $references[$tableName]['files'][] = $relative;
                $references[$tableName][$scope . '_files'][] = $relative;
            }

            $tableSqlLiterals = array_values(array_filter($sqlLiterals, static fn (string $literal): bool => preg_match($tablePattern, $literal) === 1));
            if ($tableSqlLiterals !== []) {
                $references[$tableName]['sql_files'][] = $relative;
                $references[$tableName][$scope . '_sql_files'][] = $relative;
            }

            $migrationTable = (array) ($migrationAudit['tables'][strtolower((string) $tableName)] ?? []);
            $columnNames = array_values(array_unique(array_merge(
                array_keys((array) ($table['columns'] ?? [])),
                array_keys((array) ($migrationTable['added_columns'] ?? [])),
                array_keys((array) ($migrationTable['dropped_columns'] ?? [])),
                array_keys((array) ($migrationTable['renamed_columns'] ?? []))
            )));
            foreach ($columnNames as $columnName) {
                $columnPattern = '/\b' . preg_quote((string) $columnName, '/') . '\b/i';
                if (preg_match($columnPattern, $source) === 1) {
                    $references[$tableName]['columns'][$columnName][] = $relative;
                    if ($tableMentioned) {
                        $references[$tableName]['column_table_context_files'][$columnName][] = $relative;
                    }
                }
                foreach ($tableSqlLiterals as $literal) {
                    if (preg_match($columnPattern, $literal) !== 1) {
                        continue;
                    }
                    $references[$tableName]['column_sql_references'][$columnName]['files'][] = $relative;
                    $references[$tableName]['column_sql_references'][$columnName][$scope . '_files'][] = $relative;
                    break;
                }
            }
        }
    }

    foreach ($references as &$reference) {
        foreach (['files', 'production_files', 'test_files', 'sql_files', 'production_sql_files', 'test_sql_files'] as $listKey) {
            $reference[$listKey] = array_values(array_unique((array) ($reference[$listKey] ?? [])));
        }
        foreach ((array) ($reference['columns'] ?? []) as $columnName => $columnFiles) {
            $reference['columns'][$columnName] = array_values(array_unique($columnFiles));
        }
        foreach ((array) ($reference['column_table_context_files'] ?? []) as $columnName => $columnFiles) {
            $reference['column_table_context_files'][$columnName] = array_values(array_unique($columnFiles));
        }
        foreach ((array) ($reference['column_sql_references'] ?? []) as $columnName => $details) {
            foreach (['files', 'production_files', 'test_files'] as $listKey) {
                $reference['column_sql_references'][$columnName][$listKey] = array_values(array_unique((array) ($details[$listKey] ?? [])));
            }
        }
    }
    unset($reference);

    return [
        'scan_roots' => $scanRoots,
        'scan_extensions' => $scanExtensions,
        'file_count' => count($files),
        'reference_model' => 'Lexical references are reported broadly. SQL references require the table and column in the same SQL-like literal and are separated into production and test scopes.',
        'references' => $references,
    ];
}

/**
 * Return proven legacy schema objects and their repair confidence.
 *
 * @param array<string, mixed> $inventory Inventory.
 * @param array<string, mixed> $codeAudit Code audit.
 * @return array<int, array<string, mixed>> Findings.
 */
function database_maintenance_legacy_schema_findings(array $inventory, array $codeAudit): array
{
    $findings = [];
    $legacyThumbnailColumns = [
        'gallery_id', 'thumbnail_rel_path', 'source_width', 'source_height',
        'source_mime_type', 'source_file_size', 'source_modified_at',
        'source_checksum_sha256', 'source_exif_orientation', 'source_exif_json',
    ];
    foreach ($legacyThumbnailColumns as $columnName) {
        if (!database_maintenance_inventory_has($inventory, 'image_thumbnail_variants', [$columnName])) {
            continue;
        }
        $files = (array) ($codeAudit['references']['image_thumbnail_variants']['columns'][$columnName] ?? []);
        $sqlReference = (array) ($codeAudit['references']['image_thumbnail_variants']['column_sql_references'][$columnName] ?? []);
        $productionSqlFiles = (array) ($sqlReference['production_files'] ?? []);
        if ($sqlReference === [] && !isset($codeAudit['reference_model'])) {
            $productionSqlFiles = array_values(array_filter($files, static fn (string $file): bool => !str_starts_with($file, 'tests/')));
        }
        $knownCompatibilityFiles = [
            'app/services/thumbnail_metadata.php',
            'app/services/gallery_mutations.php',
            'app/services/site_maintenance.php',
            'app/migration_repairs.php',
        ];
        $compatibilityFiles = array_values(array_filter($files, static fn (string $file): bool => in_array($file, $knownCompatibilityFiles, true)));
        $nonCompatibilitySqlFiles = array_values(array_diff($productionSqlFiles, $knownCompatibilityFiles));
        $findings[] = [
            'object_type' => 'column',
            'table_name' => 'image_thumbnail_variants',
            'object_name' => $columnName,
            'status' => $nonCompatibilitySqlFiles === [] ? 'obsolete_compatibility_only' : 'review',
            'confidence' => $nonCompatibilitySqlFiles === [] ? 'high' : 'medium',
            'reason' => 'The 202606130001 compaction migration moved source metadata to images and attempted to drop this duplicated derivative payload column. Production SQL references were checked separately from broad word matches; remaining known uses are compatibility gates or the conditional data-copy repair.',
            'code_reference_files' => $files,
            'production_sql_reference_files' => $productionSqlFiles,
            'compatibility_reference_files' => $compatibilityFiles,
            'non_compatibility_sql_reference_files' => $nonCompatibilitySqlFiles,
            'repair_version' => DATABASE_MAINTENANCE_REPAIR_VERSION,
        ];
    }

    $table = $inventory['tables']['image_thumbnail_variants'] ?? [];
    foreach (['image_thumbnail_variants_gallery_index'] as $indexName) {
        if (!isset($table['secondary_indexes'][$indexName])) {
            continue;
        }
        $findings[] = [
            'object_type' => 'index',
            'table_name' => 'image_thumbnail_variants',
            'object_name' => $indexName,
            'status' => 'obsolete',
            'confidence' => 'high',
            'reason' => 'The compact table no longer owns gallery_id. The historical compaction migration attempted to remove this index.',
            'code_reference_files' => [],
            'repair_version' => DATABASE_MAINTENANCE_REPAIR_VERSION,
        ];
    }
    foreach (['image_thumbnail_variants_gallery_id_foreign'] as $foreignKeyName) {
        if (!isset($table['foreign_keys'][$foreignKeyName])) {
            continue;
        }
        $findings[] = [
            'object_type' => 'foreign_key',
            'table_name' => 'image_thumbnail_variants',
            'object_name' => $foreignKeyName,
            'status' => 'obsolete',
            'confidence' => 'high',
            'reason' => 'The compact table derives gallery ownership through images and no longer requires a duplicated gallery foreign key.',
            'code_reference_files' => [],
            'repair_version' => DATABASE_MAINTENANCE_REPAIR_VERSION,
        ];
    }
    return $findings;
}

/**
 * Return a deterministic row identity for bounded cleanup and audit logging.
 *
 * Primary-key columns are preferred. The conventional id column is accepted as
 * a compatibility fallback for partially reported schemas. Without a stable
 * identity, the table is report-only and receives no automatic cleanup rule.
 *
 * @param array<string, mixed> $inventory Current schema inventory.
 * @return array<int, string> Identity column names.
 */
function database_maintenance_table_identity_columns(array $inventory, string $tableName): array
{
    $table = (array) ($inventory['tables'][$tableName] ?? []);
    $columns = [];
    foreach ((array) ($table['primary_key'] ?? []) as $column) {
        $name = trim((string) ($column['name'] ?? $column['column'] ?? ''));
        if ($name !== '') {
            $columns[] = $name;
        }
    }
    if ($columns !== []) {
        return array_values(array_unique($columns));
    }
    if (isset($table['columns']['id'])) {
        return ['id'];
    }
    return [];
}

/**
 * Define bounded high-confidence cleanup rules supported by current code.
 *
 * @param array<string, mixed> $inventory Inventory.
 * @return array<int, array<string, mixed>> Rules.
 */
function database_maintenance_cleanup_rules(array $inventory): array
{
    $rules = [];
    $addOrphan = static function (string $key, string $table, string $column, string $parent, string $parentColumn, string $reason) use (&$rules, $inventory): void {
        $identityColumns = database_maintenance_table_identity_columns($inventory, $table);
        if ($identityColumns === [] || !database_maintenance_inventory_has($inventory, $table, [$column]) || !database_maintenance_inventory_has($inventory, $parent, [$parentColumn])) {
            return;
        }
        $quotedTable = admin_database_usage_quote_identifier($table);
        $quotedColumn = admin_database_usage_quote_identifier($column);
        $quotedParent = admin_database_usage_quote_identifier($parent);
        $quotedParentColumn = admin_database_usage_quote_identifier($parentColumn);
        $predicate = $quotedTable . '.' . $quotedColumn . ' IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ' . $quotedParent . ' p WHERE p.' . $quotedParentColumn . ' = ' . $quotedTable . '.' . $quotedColumn . ')';
        $rules[] = database_maintenance_make_rule($key, $table, 'orphaned_rows', 'high', $reason, $predicate, $identityColumns);
    };

    $addOrphan('gallery_tags_missing_gallery', 'gallery_tags', 'gallery_id', 'galleries', 'id', 'Gallery/tag link references a gallery that does not exist.');
    $addOrphan('gallery_tags_missing_tag', 'gallery_tags', 'tag_id', 'tags', 'id', 'Gallery/tag link references a tag that does not exist.');
    $addOrphan('image_tags_missing_image', 'image_tags', 'image_id', 'images', 'id', 'Image/tag link references an image that does not exist.');
    $addOrphan('image_tags_missing_tag', 'image_tags', 'tag_id', 'tags', 'id', 'Image/tag link references a tag that does not exist.');
    $addOrphan('image_votes_missing_image', 'image_votes', 'image_id', 'images', 'id', 'Vote references an image that does not exist.');
    $addOrphan('picture_game_votes_missing_gallery', 'picture_game_votes', 'gallery_id', 'galleries', 'id', 'Picture-game vote references a gallery that does not exist.');
    foreach (['image_a_id', 'image_b_id', 'winner_image_id'] as $column) {
        $addOrphan('picture_game_votes_missing_' . $column, 'picture_game_votes', $column, 'images', 'id', 'Picture-game vote references an image that does not exist.');
    }
    $addOrphan('zip_archives_missing_gallery', 'zip_archives', 'gallery_id', 'galleries', 'id', 'Gallery-scoped ZIP metadata references a deleted gallery. Filesystem ZIPs are not deleted.');
    $addOrphan('gallery_upload_tokens_missing_gallery', 'gallery_upload_tokens', 'gallery_id', 'galleries', 'id', 'Upload credential references a gallery that does not exist.');
    $addOrphan('gallery_flight_maps_missing_gallery', 'gallery_flight_maps', 'gallery_id', 'galleries', 'id', 'Flight-map metadata references a gallery that does not exist.');
    $addOrphan('image_ai_metadata_missing_image', 'image_ai_metadata', 'image_id', 'images', 'id', 'AI metadata references an image that does not exist.');
    $addOrphan('image_ai_jobs_missing_gallery', 'image_ai_analysis_jobs', 'gallery_id', 'galleries', 'id', 'AI job references a gallery that does not exist.');
    $addOrphan('image_ai_jobs_missing_image', 'image_ai_analysis_jobs', 'image_id', 'images', 'id', 'AI job references an image that does not exist.');
    $addOrphan('thumbnail_variants_missing_image', 'image_thumbnail_variants', 'image_id', 'images', 'id', 'Thumbnail metadata references an image that does not exist. No thumbnail file is deleted.');
    $addOrphan('remember_tokens_missing_user', 'admin_remember_tokens', 'user_id', 'users', 'id', 'Persistent login token references a user that does not exist.');
    $addOrphan('password_reset_missing_user', 'password_reset_tokens', 'user_id', 'users', 'id', 'Password reset token references a user that does not exist.');
    $addOrphan('google_accounts_missing_user', 'user_google_accounts', 'user_id', 'users', 'id', 'Google account link references a user that does not exist.');
    $addOrphan('openai_settings_missing_user', 'user_openai_text_settings', 'user_id', 'users', 'id', 'OpenAI profile references a user that does not exist.');
    $addOrphan('navigation_accounts_missing_user', 'navigation_data_accounts', 'user_id', 'users', 'id', 'Navigation provider account references a user that does not exist.');
    $addOrphan('webdav_tokens_missing_user', 'mobile_webdav_upload_tokens', 'user_id', 'users', 'id', 'WebDAV credential references a user that does not exist.');
    $addOrphan('webdav_tokens_missing_gallery', 'mobile_webdav_upload_tokens', 'gallery_id', 'galleries', 'id', 'WebDAV credential references a gallery that does not exist.');

    if (database_maintenance_inventory_has($inventory, 'admin_remember_tokens', ['expires_at', 'revoked_at'])) {
        $rules[] = database_maintenance_make_rule('expired_admin_remember_tokens', 'admin_remember_tokens', 'expired_temporary_state', 'high', 'Persistent login token is expired or explicitly revoked.', '(expires_at < NOW() OR revoked_at IS NOT NULL)', database_maintenance_table_identity_columns($inventory, 'admin_remember_tokens'));
    }
    if (database_maintenance_inventory_has($inventory, 'password_reset_tokens', ['expires_at', 'used_at'])) {
        $rules[] = database_maintenance_make_rule('expired_password_reset_tokens', 'password_reset_tokens', 'expired_temporary_state', 'high', 'Password reset token is expired or already used.', '(expires_at < NOW() OR used_at IS NOT NULL)', database_maintenance_table_identity_columns($inventory, 'password_reset_tokens'));
    }
    if (database_maintenance_inventory_has($inventory, 'navigation_data_cache', ['expires_at'])) {
        $rules[] = database_maintenance_make_rule('expired_navigation_cache', 'navigation_data_cache', 'expired_temporary_state', 'high', 'Navigation cache row has an explicit expires_at value in the past.', 'expires_at IS NOT NULL AND expires_at < NOW()', database_maintenance_table_identity_columns($inventory, 'navigation_data_cache'));
    }
    if (database_maintenance_inventory_has($inventory, 'auth_rate_limits', ['last_attempt_at', 'locked_until'])) {
        $rules[] = database_maintenance_make_rule('expired_auth_rate_limits', 'auth_rate_limits', 'expired_temporary_state', 'high', 'Authentication throttle state is outside the application 24-hour cleanup policy.', 'last_attempt_at < DATE_SUB(NOW(), INTERVAL 1 DAY) AND (locked_until IS NULL OR locked_until < NOW())', database_maintenance_table_identity_columns($inventory, 'auth_rate_limits'));
    }

    $duplicateDefinitions = [
        ['thumbnail_variant_duplicates', 'image_thumbnail_variants', 'id', ['image_id', 'size_px', 'format'], 'Duplicate thumbnail metadata logical identity; keep the lowest id.'],
        ['image_ai_metadata_duplicates', 'image_ai_metadata', 'id', ['image_id', 'model_name', 'model_version'], 'Duplicate AI metadata logical identity; keep the lowest id.'],
        ['navigation_cache_duplicates', 'navigation_data_cache', 'id', ['cache_key'], 'Duplicate navigation cache key; keep the lowest id.'],
    ];
    foreach ($duplicateDefinitions as [$key, $table, $idColumn, $identityColumns, $reason]) {
        if (!database_maintenance_inventory_has($inventory, $table, array_merge([$idColumn], $identityColumns))) {
            continue;
        }
        $rules[] = database_maintenance_make_duplicate_rule($key, $table, $idColumn, $identityColumns, $reason);
    }

    return $rules;
}

/**
 * Build one predicate-based cleanup rule.
 */
function database_maintenance_make_rule(string $key, string $table, string $category, string $confidence, string $reason, string $predicate, array $identityColumns): array
{
    $quotedTable = admin_database_usage_quote_identifier($table);
    $quotedIdentity = array_map('Gallery\\Services\\admin_database_usage_quote_identifier', $identityColumns);
    $identitySelect = implode(', ', $quotedIdentity);
    $identityOrder = implode(', ', $quotedIdentity);
    $automatic = $confidence === 'high' && $identityColumns !== [];
    return [
        'key' => $key,
        'table_name' => $table,
        'category' => $category,
        'confidence' => $automatic ? $confidence : 'report_only',
        'reason' => $reason,
        'count_sql' => 'SELECT COUNT(*) FROM ' . $quotedTable . ' WHERE ' . $predicate,
        'identifiers_sql' => $automatic
            ? 'SELECT ' . $identitySelect . ' FROM ' . $quotedTable . ' WHERE ' . $predicate . ' ORDER BY ' . $identityOrder . ' LIMIT :batch_size'
            : '',
        'delete_sql' => $automatic
            ? 'DELETE FROM ' . $quotedTable . ' WHERE ' . $predicate . ' ORDER BY ' . $identityOrder . ' LIMIT :batch_size'
            : '',
        'identifier_columns' => $identityColumns,
        'parameters' => [],
        'automatic' => $automatic,
        'filesystem_effects' => false,
    ];
}

/**
 * Build one deterministic duplicate cleanup rule.
 *
 * The lowest numeric id survives. The nested derived table avoids MySQL's
 * restriction on modifying a table selected directly by the same statement.
 *
 * @param array<int, string> $identityColumns Logical identity columns.
 */
function database_maintenance_make_duplicate_rule(string $key, string $table, string $idColumn, array $identityColumns, string $reason): array
{
    $quotedTable = admin_database_usage_quote_identifier($table);
    $quotedId = admin_database_usage_quote_identifier($idColumn);
    $identity = implode(', ', array_map('Gallery\\Services\\admin_database_usage_quote_identifier', $identityColumns));
    $join = implode(' AND ', array_map(static fn (string $column): string => 'candidate.' . admin_database_usage_quote_identifier($column) . ' <=> duplicates.' . admin_database_usage_quote_identifier($column), $identityColumns));
    $groupCount = 'SELECT COALESCE(SUM(duplicate_count - 1), 0) FROM (SELECT COUNT(*) AS duplicate_count FROM ' . $quotedTable . ' GROUP BY ' . $identity . ' HAVING COUNT(*) > 1) duplicate_groups';
    $duplicateJoin = ' FROM ' . $quotedTable . ' candidate JOIN (SELECT ' . $identity . ', MIN(' . $quotedId . ') AS survivor_id FROM ' . $quotedTable . ' GROUP BY ' . $identity . ' HAVING COUNT(*) > 1) duplicates ON ' . $join . ' WHERE candidate.' . $quotedId . ' <> duplicates.survivor_id ORDER BY candidate.' . $quotedId . ' LIMIT :batch_size';
    $duplicateIds = 'SELECT candidate.' . $quotedId . $duplicateJoin;
    $duplicateIdsForDelete = 'SELECT candidate.' . $quotedId . ' AS duplicate_id' . $duplicateJoin;
    return [
        'key' => $key,
        'table_name' => $table,
        'category' => 'duplicate_logical_rows',
        'confidence' => 'high',
        'reason' => $reason,
        'count_sql' => $groupCount,
        'identifiers_sql' => $duplicateIds,
        'delete_sql' => 'DELETE FROM ' . $quotedTable . ' WHERE ' . $quotedId . ' IN (SELECT duplicate_id FROM (' . $duplicateIdsForDelete . ') bounded_duplicates)',
        'identifier_columns' => [$idColumn],
        'parameters' => [],
        'automatic' => true,
        'filesystem_effects' => false,
        'survivor_rule' => 'Keep the lowest ' . $idColumn . '.',
    ];
}

/**
 * Count cleanup candidates without modifying data.
 *
 * @param array<int, array<string, mixed>> $rules Rules.
 * @return array<int, array<string, mixed>> Candidate reports.
 */
function database_maintenance_inspect_cleanup_candidates(array $rules): array
{
    $candidates = [];
    foreach ($rules as $rule) {
        try {
            $statement = db()->prepare((string) $rule['count_sql']);
            $statement->execute((array) ($rule['parameters'] ?? []));
            $count = max(0, (int) $statement->fetchColumn());
            $candidates[] = $rule + ['candidate_count' => $count, 'inspection_error' => ''];
        } catch (Throwable $exception) {
            $candidates[] = $rule + ['candidate_count' => 0, 'inspection_error' => $exception->getMessage()];
        }
    }
    return $candidates;
}

/**
 * Normalize the thumbnail metadata distribution and flag unsupported variants.
 *
 * Unsupported rows are inspection findings only. They are deliberately excluded
 * from generic cleanup because a custom deployment may intentionally use an
 * additional derivative size or format.
 *
 * @param array<int, array<string, mixed>> $rows Grouped distribution rows.
 * @param array<int, int> $configuredSizes Active thumbnail sizes.
 * @param array<int, string> $supportedFormats Active thumbnail formats.
 * @return array<string, mixed> Structured thumbnail-specific audit.
 */
function database_maintenance_normalize_thumbnail_distribution(array $rows, array $configuredSizes, array $supportedFormats): array
{
    $configuredSizes = array_values(array_unique(array_filter(array_map('intval', $configuredSizes), static fn (int $size): bool => $size > 0)));
    sort($configuredSizes, SORT_NUMERIC);
    $supportedFormats = array_values(array_unique(array_filter(array_map(static fn (mixed $format): string => strtolower(trim((string) $format)), $supportedFormats))));
    sort($supportedFormats, SORT_STRING);

    $distribution = [];
    $unsupported = [];
    $statusTotals = [];
    $formatTotals = [];
    $sizeTotals = [];
    $totalRows = 0;
    foreach ($rows as $row) {
        $size = (int) ($row['size_px'] ?? $row['SIZE_PX'] ?? 0);
        $format = strtolower(trim((string) ($row['format'] ?? $row['FORMAT'] ?? '')));
        $status = strtolower(trim((string) ($row['status'] ?? $row['STATUS'] ?? 'unknown')));
        $count = max(0, (int) ($row['row_count'] ?? $row['ROW_COUNT'] ?? $row['variant_count'] ?? 0));
        $supported = in_array($size, $configuredSizes, true) && in_array($format, $supportedFormats, true);
        $entry = [
            'size_px' => $size,
            'format' => $format,
            'status' => $status !== '' ? $status : 'unknown',
            'row_count' => $count,
            'supported_by_current_policy' => $supported,
        ];
        $distribution[] = $entry;
        $totalRows += $count;
        $statusTotals[$entry['status']] = (int) ($statusTotals[$entry['status']] ?? 0) + $count;
        $formatTotals[$format !== '' ? $format : 'unknown'] = (int) ($formatTotals[$format !== '' ? $format : 'unknown'] ?? 0) + $count;
        $sizeTotals[(string) $size] = (int) ($sizeTotals[(string) $size] ?? 0) + $count;
        if (!$supported) {
            $unsupported[] = $entry;
        }
    }

    return [
        'metadata_only' => true,
        'stores_image_bytes' => false,
        'configured_sizes' => $configuredSizes,
        'supported_formats' => $supportedFormats,
        'total_rows' => $totalRows,
        'distribution' => $distribution,
        'status_totals' => $statusTotals,
        'format_totals' => $formatTotals,
        'size_totals' => $sizeTotals,
        'unsupported_distribution' => $unsupported,
        'unsupported_row_count' => array_sum(array_map(static fn (array $entry): int => (int) $entry['row_count'], $unsupported)),
        'unsupported_cleanup_mode' => 'report_only',
        'note' => 'Unsupported variants are reported for review and are never removed automatically.',
    ];
}

/**
 * Inspect the thumbnail metadata size, format, and status distribution.
 *
 * @param array<string, mixed> $inventory Current schema inventory.
 * @return array<string, mixed> Thumbnail-specific audit, or an unavailable result.
 */
function database_maintenance_thumbnail_distribution(array $inventory): array
{
    if (!database_maintenance_inventory_has($inventory, 'image_thumbnail_variants', ['size_px', 'format', 'status'])) {
        return [
            'available' => false,
            'metadata_only' => true,
            'stores_image_bytes' => false,
            'reason' => 'The thumbnail metadata table or required distribution columns are absent.',
        ];
    }

    $configuredSizes = function_exists('Gallery\\Services\\thumbnail_sizes')
        ? array_values(array_map('intval', thumbnail_sizes()))
        : [300, 600, 800, 960, 1280, 1600];
    $supportedFormats = ['jpg', 'webp'];
    $rows = db()->query(
        'SELECT size_px, format, status, COUNT(*) AS row_count '
        . 'FROM ' . admin_database_usage_quote_identifier('image_thumbnail_variants') . ' '
        . 'GROUP BY size_px, format, status ORDER BY size_px, format, status'
    )->fetchAll(PDO::FETCH_ASSOC);

    return ['available' => true] + database_maintenance_normalize_thumbnail_distribution($rows, $configuredSizes, $supportedFormats);
}

/**
 * Build and persist a complete explicit Admin audit report.
 *
 * @return array<string, mixed> Report.
 */
function database_maintenance_inspect(): array
{
    $startedAt = microtime(true);
    $inventory = database_maintenance_schema_inventory();
    $migrationAudit = database_maintenance_migration_audit();
    $codeAudit = database_maintenance_code_audit($inventory, $migrationAudit);
    $rules = database_maintenance_cleanup_rules($inventory);
    $candidates = database_maintenance_inspect_cleanup_candidates($rules);
    $legacyFindings = database_maintenance_legacy_schema_findings($inventory, $codeAudit);

    foreach ($inventory['tables'] as $tableName => &$table) {
        $tableMigrationAudit = (array) ($migrationAudit['tables'][strtolower((string) $tableName)] ?? []);
        $table['migration_audit'] = $tableMigrationAudit;
        $table['code_reference_files'] = $codeAudit['references'][$tableName]['files'] ?? [];
        $table['code_sql_reference_files'] = $codeAudit['references'][$tableName]['sql_files'] ?? [];
        $table['production_sql_reference_files'] = $codeAudit['references'][$tableName]['production_sql_files'] ?? [];
        $table['test_sql_reference_files'] = $codeAudit['references'][$tableName]['test_sql_files'] ?? [];
        $currentColumnNames = array_keys((array) ($table['columns'] ?? []));
        $historicalColumnNames = array_values(array_unique(array_merge(
            array_keys((array) ($tableMigrationAudit['added_columns'] ?? [])),
            array_keys((array) ($tableMigrationAudit['dropped_columns'] ?? []))
        )));
        $table['historical_columns_absent_from_current_schema'] = [];
        foreach (array_values(array_diff($historicalColumnNames, $currentColumnNames)) as $historicalColumnName) {
            $table['historical_columns_absent_from_current_schema'][$historicalColumnName] = [
                'added_versions' => (array) ($tableMigrationAudit['added_columns'][$historicalColumnName] ?? []),
                'dropped_versions' => (array) ($tableMigrationAudit['dropped_columns'][$historicalColumnName] ?? []),
                'current_code_reference_files' => (array) ($codeAudit['references'][$tableName]['columns'][$historicalColumnName] ?? []),
                'production_sql_reference_files' => (array) ($codeAudit['references'][$tableName]['column_sql_references'][$historicalColumnName]['production_files'] ?? []),
                'test_sql_reference_files' => (array) ($codeAudit['references'][$tableName]['column_sql_references'][$historicalColumnName]['test_files'] ?? []),
            ];
        }
        foreach ((array) ($table['columns'] ?? []) as $columnName => &$column) {
            $column['migration_audit'] = [
                'added_versions' => (array) ($tableMigrationAudit['added_columns'][$columnName] ?? []),
                'dropped_versions' => (array) ($tableMigrationAudit['dropped_columns'][$columnName] ?? []),
                'rename_history' => (array) ($tableMigrationAudit['renamed_columns'][$columnName] ?? []),
            ];
            $column['code_reference_files'] = (array) ($codeAudit['references'][$tableName]['columns'][$columnName] ?? []);
            $column['production_sql_reference_files'] = (array) ($codeAudit['references'][$tableName]['column_sql_references'][$columnName]['production_files'] ?? []);
            $column['test_sql_reference_files'] = (array) ($codeAudit['references'][$tableName]['column_sql_references'][$columnName]['test_files'] ?? []);
        }
        unset($column);
    }
    unset($table);

    $report = [
        'ok' => true,
        'generated_at_utc' => gmdate('c'),
        'duration_seconds' => round(microtime(true) - $startedAt, 4),
        'inventory' => $inventory,
        'migration_audit' => $migrationAudit,
        'code_audit' => $codeAudit,
        'cleanup_candidates' => $candidates,
        'legacy_schema_findings' => $legacyFindings,
        'table_specific_audit' => [
            'image_thumbnail_variants' => database_maintenance_thumbnail_distribution($inventory),
        ],
        'protected_tables' => database_maintenance_protected_tables($inventory),
        'physical_space_reclaimed' => false,
        'physical_optimization_note' => 'Inspection and logical cleanup do not reclaim table files. OPTIMIZE TABLE remains a separate manually selected action.',
    ];

    database_maintenance_save_report($report);
    if (function_exists('Gallery\\Services\\admin_log_event')) {
        admin_log_event('info', 'database_maintenance.inspected', 'Admin inspected the complete application database.', [
            'table_count' => (int) ($inventory['table_count'] ?? 0),
            'cleanup_candidate_count' => array_sum(array_map(static fn (array $candidate): int => (int) ($candidate['candidate_count'] ?? 0), $candidates)),
            'legacy_schema_finding_count' => count($legacyFindings),
            'duration_seconds' => $report['duration_seconds'],
        ], ['category' => 'database', 'severity' => 'notice']);
    }
    return $report;
}

/**
 * Return tables protected from generic automatic deletion.
 *
 * @param array<string, mixed> $inventory Inventory.
 * @return array<int, string> Table names.
 */
function database_maintenance_protected_tables(array $inventory): array
{
    $protected = [];
    foreach ((array) ($inventory['tables'] ?? []) as $tableName => $table) {
        if ((string) ($table['policy']['cleanup_mode'] ?? 'disabled') !== 'automatic') {
            $protected[] = (string) $tableName;
        }
    }
    sort($protected, SORT_NATURAL | SORT_FLAG_CASE);
    return $protected;
}

/**
 * Save the latest report atomically when the cache directory is writable.
 */
function database_maintenance_save_report(array $report): void
{
    $path = database_maintenance_report_path();
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Database maintenance report directory could not be created.');
    }
    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $temporaryPath = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || !@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Database maintenance report could not be written.');
    }
}

/**
 * Load the latest cached report without starting a new audit.
 */
function database_maintenance_load_report(): ?array
{
    $path = database_maintenance_report_path();
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    try {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : null;
    } catch (Throwable) {
        return null;
    }
}

/**
 * Return normalized persisted cleanup state.
 *
 * @return array<string, mixed> State.
 */
function database_maintenance_cleanup_state(): array
{
    $decoded = json_decode((string) app_setting(DATABASE_MAINTENANCE_STATE_SETTING, '{}'), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Persist cleanup state.
 */
function database_maintenance_set_cleanup_state(array $state): void
{
    set_app_setting(DATABASE_MAINTENANCE_STATE_SETTING, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

/**
 * Aggregate one committed live cleanup batch into bounded resumable state.
 *
 * State keeps one summary row per rule rather than one row per batch. This
 * prevents large cleanup operations from overflowing app_settings while still
 * retaining initial, deleted, remaining, and batch counts.
 *
 * @param array<string, mixed> $state Current operation state.
 * @param array<string, mixed> $rule Cleanup rule.
 * @return array<string, mixed> Updated state.
 */
function database_maintenance_record_live_progress(array $state, array $rule, int $beforeCount, int $deletedCount, int $remainingCount): array
{
    $key = (string) ($rule['key'] ?? '');
    $processedRules = array_values((array) ($state['processed_rules'] ?? []));
    $matchedIndex = null;
    foreach ($processedRules as $index => $processedRule) {
        if (empty($processedRule['dry_run']) && (string) ($processedRule['key'] ?? '') === $key) {
            $matchedIndex = $index;
            break;
        }
    }

    if ($matchedIndex === null) {
        $processedRules[] = [
            'key' => $key,
            'table_name' => (string) ($rule['table_name'] ?? ''),
            'category' => (string) ($rule['category'] ?? ''),
            'reason' => (string) ($rule['reason'] ?? ''),
            'candidate_count' => $beforeCount,
            'last_before_count' => $beforeCount,
            'deleted_count' => $deletedCount,
            'removed_identifier_count' => $deletedCount,
            'remaining_count' => $remainingCount,
            'batch_count' => 1,
            'dry_run' => false,
            'error' => '',
        ];
        $state['candidate_rows'] = (int) ($state['candidate_rows'] ?? 0) + $beforeCount;
    } else {
        $processedRules[$matchedIndex]['last_before_count'] = $beforeCount;
        $processedRules[$matchedIndex]['deleted_count'] = (int) ($processedRules[$matchedIndex]['deleted_count'] ?? 0) + $deletedCount;
        $processedRules[$matchedIndex]['removed_identifier_count'] = (int) ($processedRules[$matchedIndex]['removed_identifier_count'] ?? 0) + $deletedCount;
        $processedRules[$matchedIndex]['remaining_count'] = $remainingCount;
        $processedRules[$matchedIndex]['batch_count'] = (int) ($processedRules[$matchedIndex]['batch_count'] ?? 0) + 1;
        $processedRules[$matchedIndex]['error'] = '';
    }

    $state['processed_rules'] = $processedRules;
    $state['deleted_rows'] = (int) ($state['deleted_rows'] ?? 0) + $deletedCount;
    return $state;
}

/**
 * Start or continue a bounded cleanup operation.
 *
 * Dry-run executes all candidate counts but no DELETE statement. Live execution
 * processes one bounded batch per rule and persists the next rule index, making
 * retries safe after timeouts or shared-hosting request limits.
 *
 * @return array<string, mixed> Operation state.
 */
function database_maintenance_cleanup_step(bool $dryRun, int $batchSize = DATABASE_MAINTENANCE_DEFAULT_BATCH_SIZE, bool $restart = false): array
{
    mutation_schema_assert_known(
        database_maintenance_mutation_schema_status(),
        $dryRun ? 'database_maintenance.cleanup_dry_run' : 'database_maintenance.cleanup_live',
        'Database cleanup is temporarily unavailable because migration schema state could not be verified. No cleanup step was started.'
    );
    $batchSize = max(1, min(DATABASE_MAINTENANCE_MAX_BATCH_SIZE, $batchSize));
    $inventory = database_maintenance_schema_inventory();
    $rules = array_values(array_filter(database_maintenance_cleanup_rules($inventory), static fn (array $rule): bool => !empty($rule['automatic']) && (string) ($rule['confidence'] ?? '') === 'high'));
    if (!$dryRun && !database_maintenance_inventory_has($inventory, 'database_maintenance_audit_log', ['operation_id', 'rule_key', 'table_name', 'reason', 'removed_identifiers_json', 'deleted_count'])) {
        throw new RuntimeException('Live cleanup requires the database_maintenance_audit_log table. Apply the database maintenance schema migration first.');
    }
    $state = database_maintenance_cleanup_state();
    if ($restart || $state === [] || (bool) ($state['dry_run'] ?? false) !== $dryRun || !empty($state['completed'])) {
        $state = [
            'operation_id' => bin2hex(random_bytes(8)),
            'started_at_utc' => gmdate('c'),
            'updated_at_utc' => gmdate('c'),
            'dry_run' => $dryRun,
            'batch_size' => $batchSize,
            'rule_index' => 0,
            'processed_rules' => [],
            'deleted_rows' => 0,
            'candidate_rows' => 0,
            'completed' => false,
            'failed' => false,
            'error' => '',
            'filesystem_deletions' => 0,
        ];
    }
    if (!empty($state['failed'])) {
        $state['failed'] = false;
        $state['error'] = '';
        $state['updated_at_utc'] = gmdate('c');
    }

    if ($dryRun) {
        $candidates = database_maintenance_inspect_cleanup_candidates($rules);
        $state['processed_rules'] = array_map(static fn (array $candidate): array => [
            'key' => (string) $candidate['key'],
            'table_name' => (string) $candidate['table_name'],
            'category' => (string) $candidate['category'],
            'reason' => (string) $candidate['reason'],
            'candidate_count' => (int) ($candidate['candidate_count'] ?? 0),
            'deleted_count' => 0,
            'dry_run' => true,
            'error' => (string) ($candidate['inspection_error'] ?? ''),
        ], $candidates);
        $state['candidate_rows'] = array_sum(array_column($state['processed_rules'], 'candidate_count'));
        $state['rule_index'] = count($rules);
        $state['completed'] = true;
        $state['updated_at_utc'] = gmdate('c');
        database_maintenance_set_cleanup_state($state);
        database_maintenance_log_cleanup($state);
        return $state;
    }

    $ruleIndex = max(0, (int) ($state['rule_index'] ?? 0));
    if (!isset($rules[$ruleIndex])) {
        $state['completed'] = true;
        $state['updated_at_utc'] = gmdate('c');
        database_maintenance_set_cleanup_state($state);
        database_maintenance_log_cleanup($state);
        return $state;
    }

    $rule = $rules[$ruleIndex];
    $pdo = db();
    try {
        $countStatement = $pdo->prepare((string) $rule['count_sql']);
        $countStatement->execute((array) ($rule['parameters'] ?? []));
        $beforeCount = max(0, (int) $countStatement->fetchColumn());
        $deleted = 0;
        $removedIdentifiers = [];
        if ($beforeCount > 0) {
            $ownsTransaction = !$pdo->inTransaction();
            if ($ownsTransaction) {
                $pdo->beginTransaction();
            }
            try {
                $identifierStatement = $pdo->prepare((string) $rule['identifiers_sql']);
                foreach ((array) ($rule['parameters'] ?? []) as $name => $value) {
                    $identifierStatement->bindValue(is_int($name) ? $name + 1 : (string) $name, $value);
                }
                $identifierStatement->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
                $identifierStatement->execute();
                $removedIdentifiers = $identifierStatement->fetchAll(PDO::FETCH_ASSOC);
                if ($removedIdentifiers === []) {
                    throw new RuntimeException('Cleanup count reported candidates, but no deterministic identifier batch could be selected. The transaction was rolled back.');
                }

                if ($removedIdentifiers !== []) {
                    $deleteStatement = $pdo->prepare((string) $rule['delete_sql']);
                    foreach ((array) ($rule['parameters'] ?? []) as $name => $value) {
                        $deleteStatement->bindValue(is_int($name) ? $name + 1 : (string) $name, $value);
                    }
                    $deleteStatement->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
                    $deleteStatement->execute();
                    $deleted = max(0, $deleteStatement->rowCount());
                    if ($deleted !== count($removedIdentifiers)) {
                        throw new RuntimeException('Cleanup batch identifier count did not match the deleted row count. The transaction was rolled back.');
                    }
                    database_maintenance_write_cleanup_audit($pdo, $rule, $removedIdentifiers, $deleted, (string) ($state['operation_id'] ?? ''));
                }
                if ($ownsTransaction) {
                    $pdo->commit();
                }
            } catch (Throwable $exception) {
                if ($ownsTransaction && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            if ($deleted > 0) {
                database_maintenance_log_cleanup_batch($rule, $deleted, (string) ($state['operation_id'] ?? ''));
            }
        }
        $remainingStatement = $pdo->prepare((string) $rule['count_sql']);
        $remainingStatement->execute((array) ($rule['parameters'] ?? []));
        $remaining = max(0, (int) $remainingStatement->fetchColumn());
        $state = database_maintenance_record_live_progress($state, $rule, $beforeCount, $deleted, $remaining);
        if ($remaining === 0) {
            $state['rule_index'] = $ruleIndex + 1;
        }
        $state['completed'] = (int) $state['rule_index'] >= count($rules);
        $state['updated_at_utc'] = gmdate('c');
        database_maintenance_set_cleanup_state($state);
        if (!empty($state['completed'])) {
            database_maintenance_log_cleanup($state);
        }
        return $state;
    } catch (Throwable $exception) {
        $state['failed'] = true;
        $state['error'] = $exception->getMessage();
        $state['updated_at_utc'] = gmdate('c');
        database_maintenance_set_cleanup_state($state);
        database_maintenance_log_cleanup($state);
        return $state;
    }
}

/**
 * Write one cleanup batch audit row inside the deletion transaction.
 *
 * An audit insertion failure is intentionally fatal. The caller rolls back the
 * matching deletion, which guarantees that no committed cleanup batch exists
 * without its exact row identifiers and reason.
 *
 * @param array<string, mixed> $rule Cleanup rule.
 * @param array<int, array<string, mixed>> $identifiers Removed row identities.
 */
function database_maintenance_write_cleanup_audit(PDO $pdo, array $rule, array $identifiers, int $deletedCount, string $operationId): void
{
    $statement = $pdo->prepare(
        'INSERT INTO database_maintenance_audit_log '
        . '(operation_id, rule_key, table_name, category, reason, identifier_columns_json, removed_identifiers_json, deleted_count, created_at) '
        . 'VALUES (:operation_id, :rule_key, :table_name, :category, :reason, :identifier_columns_json, :removed_identifiers_json, :deleted_count, NOW())'
    );
    $statement->execute([
        ':operation_id' => $operationId,
        ':rule_key' => (string) ($rule['key'] ?? ''),
        ':table_name' => (string) ($rule['table_name'] ?? ''),
        ':category' => (string) ($rule['category'] ?? ''),
        ':reason' => (string) ($rule['reason'] ?? ''),
        ':identifier_columns_json' => json_encode((array) ($rule['identifier_columns'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ':removed_identifiers_json' => json_encode($identifiers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ':deleted_count' => $deletedCount,
    ]);
}

/**
 * Record a non-authoritative Admin log summary for one committed batch.
 *
 * The exact identifiers are stored transactionally in
 * database_maintenance_audit_log. This secondary event supports the existing
 * Admin log UI but is not relied upon for audit integrity.
 *
 * @param array<string, mixed> $rule Cleanup rule.
 */
function database_maintenance_log_cleanup_batch(array $rule, int $deletedCount, string $operationId): void
{
    if (!function_exists('Gallery\\Services\\admin_log_event')) {
        return;
    }
    admin_log_event('info', 'database_maintenance.cleanup_batch', 'Admin committed one bounded batch of safe database cleanup.', [
        'operation_id' => $operationId,
        'table_name' => (string) ($rule['table_name'] ?? ''),
        'category' => (string) ($rule['category'] ?? ''),
        'reason' => (string) ($rule['reason'] ?? ''),
        'deleted_count' => $deletedCount,
        'transactional_audit_table' => 'database_maintenance_audit_log',
        'filesystem_deletions' => 0,
    ], ['category' => 'database', 'severity' => 'notice']);
}

/**
 * Record cleanup summary without logging secrets or row payloads.
 */
function database_maintenance_log_cleanup(array $state): void
{
    if (!function_exists('Gallery\\Services\\admin_log_event')) {
        return;
    }
    admin_log_event(!empty($state['failed']) ? 'error' : 'info', 'database_maintenance.cleanup_' . (!empty($state['dry_run']) ? 'dry_run' : 'completed'), !empty($state['dry_run']) ? 'Admin ran a database cleanup dry-run.' : 'Admin ran bounded safe database cleanup.', $state, ['category' => 'database', 'severity' => !empty($state['failed']) ? 'error' : 'notice']);
}

/**
 * Build a non-mutating schema-repair plan from the current database.
 *
 * This is the dry-run counterpart to the migration action. It repeats the
 * current schema, migration, and production SQL evidence checks and returns the
 * exact known objects that the dedicated repair migration would alter.
 *
 * @return array<string, mixed> Schema-repair dry-run report.
 */
function database_maintenance_schema_repair_plan(): array
{
    $inventory = database_maintenance_schema_inventory();
    $migrationAudit = database_maintenance_migration_audit();
    $codeAudit = database_maintenance_code_audit($inventory, $migrationAudit);
    $findings = database_maintenance_legacy_schema_findings($inventory, $codeAudit);
    return [
        'ok' => true,
        'dry_run' => true,
        'migration_version' => DATABASE_MAINTENANCE_REPAIR_VERSION,
        'findings' => $findings,
        'finding_count' => count($findings),
        'readiness' => database_maintenance_schema_repair_readiness(),
        'ddl_executed' => false,
        'data_deleted' => false,
        'generated_at_utc' => gmdate('c'),
    ];
}

/**
 * Return whether the dedicated repair migration is the only pending migration.
 *
 * @return array<string, mixed> Readiness result.
 */
function database_maintenance_schema_repair_readiness(): array
{
    $schemaStatus = database_maintenance_mutation_schema_status();
    mutation_schema_assert_known(
        $schemaStatus,
        'database_maintenance.schema_repair_readiness',
        'Database schema repair is temporarily unavailable because migration schema state could not be verified. No migration was started.'
    );
    $files = discover_migration_files(database_maintenance_project_root() . '/database/migrations');
    $applied = [];
    if (schema_inspection_is_available($schemaStatus)) {
        $applied = db()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    }
    $pendingFiles = pending_migration_files($files, array_map('strval', $applied));
    $pendingVersions = array_map(static fn (string $file): string => basename($file, '.php'), $pendingFiles);
    return [
        'repair_version' => DATABASE_MAINTENANCE_REPAIR_VERSION,
        'pending_versions' => $pendingVersions,
        'ready' => $pendingVersions === [DATABASE_MAINTENANCE_REPAIR_VERSION],
        'already_applied' => !in_array(DATABASE_MAINTENANCE_REPAIR_VERSION, $pendingVersions, true),
        'blocked_by_other_migrations' => array_values(array_diff($pendingVersions, [DATABASE_MAINTENANCE_REPAIR_VERSION])),
    ];
}

/**
 * Apply only the dedicated repair migration.
 *
 * The action refuses to call the general runner while any other migration is
 * pending. This prevents an explicit schema-repair confirmation from silently
 * applying unrelated application changes.
 *
 * @return array<string, mixed> Result.
 */
function database_maintenance_apply_schema_repair(): array
{
    $readiness = database_maintenance_schema_repair_readiness();
    if (!empty($readiness['already_applied'])) {
        return ['ok' => true, 'applied' => [], 'message' => 'The database maintenance schema repair is already applied.'] + $readiness;
    }
    if (empty($readiness['ready'])) {
        throw new RuntimeException('Schema repair is blocked because other migrations are pending: ' . implode(', ', (array) $readiness['blocked_by_other_migrations']) . '. Apply normal migrations first, then inspect again.');
    }
    $applied = run_migrations();
    if ($applied !== [DATABASE_MAINTENANCE_REPAIR_VERSION]) {
        throw new RuntimeException('Unexpected migration set was applied during schema repair.');
    }
    return ['ok' => true, 'applied' => $applied, 'message' => 'Legacy schema repair migration applied.'] + $readiness;
}

/**
 * Normalize selected table names against the current inventory.
 *
 * @param array<int, string> $selectedTables Requested names.
 * @param array<string, mixed> $inventory Inventory.
 * @return array<int, string> Existing table names.
 */
function database_maintenance_selected_tables(array $selectedTables, array $inventory): array
{
    $existing = array_fill_keys(array_keys((array) ($inventory['tables'] ?? [])), true);
    $selected = [];
    foreach ($selectedTables as $tableName) {
        $tableName = trim((string) $tableName);
        if ($tableName !== '' && isset($existing[$tableName])) {
            $selected[] = $tableName;
        }
    }
    return array_values(array_unique($selected));
}

/**
 * Build a non-mutating selected-table operation plan.
 *
 * @param string $operation ANALYZE or OPTIMIZE.
 * @param array<int, string> $selectedTables Requested tables.
 * @param array<string, mixed>|null $inventory Optional current inventory fixture.
 * @return array<string, mixed> Dry-run report.
 */
function database_maintenance_table_operation_plan(string $operation, array $selectedTables, ?array $inventory = null): array
{
    $operation = strtoupper($operation);
    if (!in_array($operation, ['ANALYZE', 'OPTIMIZE'], true)) {
        throw new RuntimeException('Unsupported database table maintenance operation.');
    }
    $inventory = $inventory ?? database_maintenance_schema_inventory();
    $tables = database_maintenance_selected_tables($selectedTables, $inventory);
    if ($tables === []) {
        throw new RuntimeException('Select at least one current database table.');
    }
    $tablePlans = [];
    foreach ($tables as $tableName) {
        $table = (array) ($inventory['tables'][$tableName] ?? []);
        $tablePlans[] = [
            'table_name' => $tableName,
            'engine' => (string) ($table['engine'] ?? ''),
            'estimated_rows' => (int) ($table['estimated_rows'] ?? 0),
            'data_bytes' => (int) ($table['data_bytes'] ?? 0),
            'index_bytes' => (int) ($table['index_bytes'] ?? 0),
            'total_bytes' => (int) ($table['total_bytes'] ?? 0),
            'reclaimable_bytes_estimate' => (int) ($table['reclaimable_bytes_estimate'] ?? 0),
        ];
    }
    return [
        'ok' => true,
        'dry_run' => true,
        'operation' => $operation,
        'table_count' => count($tablePlans),
        'allocated_bytes' => array_sum(array_column($tablePlans, 'total_bytes')),
        'reclaimable_bytes_estimate' => array_sum(array_column($tablePlans, 'reclaimable_bytes_estimate')),
        'table_plans' => $tablePlans,
        'operation_executed' => false,
        'generated_at_utc' => gmdate('c'),
    ];
}

/**
 * Preview selected-table OPTIMIZE without executing it.
 *
 * @param array<int, string> $selectedTables Selected table names.
 * @return array<string, mixed> Dry-run report.
 */
function database_maintenance_preview_optimize_tables(array $selectedTables): array
{
    return database_maintenance_table_operation_plan('OPTIMIZE', $selectedTables);
}

/**
 * Run ANALYZE TABLE for explicitly selected tables.
 *
 * @param array<int, string> $selectedTables Selected table names.
 * @return array<string, mixed> Operation report.
 */
function database_maintenance_analyze_tables(array $selectedTables): array
{
    return database_maintenance_run_table_operation('ANALYZE', $selectedTables);
}

/**
 * Run OPTIMIZE TABLE for explicitly selected tables.
 *
 * @param array<int, string> $selectedTables Selected table names.
 * @return array<string, mixed> Operation report.
 */
function database_maintenance_optimize_tables(array $selectedTables): array
{
    return database_maintenance_run_table_operation('OPTIMIZE', $selectedTables);
}

/**
 * Run one explicit physical/statistics table operation.
 *
 * @param string $operation ANALYZE or OPTIMIZE.
 * @param array<int, string> $selectedTables Selected tables.
 * @return array<string, mixed> Report.
 */
function database_maintenance_run_table_operation(string $operation, array $selectedTables): array
{
    $operation = strtoupper($operation);
    if (!in_array($operation, ['ANALYZE', 'OPTIMIZE'], true)) {
        throw new RuntimeException('Unsupported database table maintenance operation.');
    }
    $inventory = database_maintenance_schema_inventory();
    $tables = database_maintenance_selected_tables($selectedTables, $inventory);
    if ($tables === []) {
        throw new RuntimeException('Select at least one current database table.');
    }
    $reports = [];
    foreach ($tables as $tableName) {
        try {
            $statement = db()->query($operation . ' TABLE ' . admin_database_usage_quote_identifier($tableName));
            $reports[] = [
                'table_name' => $tableName,
                'status' => 'ok',
                'messages' => admin_database_usage_normalize_analyze_messages($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : []),
            ];
        } catch (Throwable $exception) {
            $reports[] = ['table_name' => $tableName, 'status' => 'failed', 'messages' => [], 'error' => $exception->getMessage()];
        }
    }
    $failed = count(array_filter($reports, static fn (array $report): bool => (string) ($report['status'] ?? '') !== 'ok'));
    $result = [
        'ok' => $failed === 0,
        'operation' => $operation,
        'table_count' => count($tables),
        'failed_table_count' => $failed,
        'table_reports' => $reports,
        'physical_optimization_executed' => $operation === 'OPTIMIZE' && $failed === 0,
        'physical_space_reclaimed' => null,
        'physical_space_note' => $operation === 'OPTIMIZE'
            ? 'OPTIMIZE TABLE completed for the successful selections, but actual filesystem reduction depends on the storage engine and hosting environment.'
            : 'ANALYZE TABLE refreshes optimizer statistics and does not reclaim table files.',
        'finished_at_utc' => gmdate('c'),
    ];
    if (function_exists('Gallery\\Services\\admin_log_event')) {
        admin_log_event($failed === 0 ? 'info' : 'warning', 'database_maintenance.' . strtolower($operation), 'Admin ran ' . $operation . ' TABLE for selected tables.', $result, ['category' => 'database', 'severity' => $failed === 0 ? 'notice' : 'warning']);
    }
    return $result;
}
