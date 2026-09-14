<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/database_maintenance.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database inspection and explicitly bounded maintenance persistence.
 *
 * Responsibilities:
 *   - Read information_schema inventory rows
 *   - Compile deterministic cleanup SQL from semantic maintenance rules
 *   - Inspect and execute bounded cleanup batches transactionally
 *   - Persist exact cleanup audit rows inside the matching delete transaction
 *   - Read migration state and execute explicit ANALYZE/OPTIMIZE operations
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
 *   - Filesystem operations, policy classification, and Admin logging stay in services.
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/** Quote one validated database identifier. */
function database_maintenance_model_quote_identifier(string $identifier): string
{
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
        throw new RuntimeException('Invalid database maintenance identifier.');
    }
    return '`' . $identifier . '`';
}

/** Return raw information_schema rows needed to normalize the current schema inventory. */
function database_maintenance_model_schema_inventory_rows(string $databaseName): array
{
    $queries = [
        'tables' => 'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH, DATA_FREE, AUTO_INCREMENT, CREATE_TIME, UPDATE_TIME, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
        'columns' => 'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_KEY, EXTRA, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, ORDINAL_POSITION',
        'indexes' => 'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE, COLLATION, CARDINALITY, INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
        'constraints' => 'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = ? ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
    ];
    $result = [];
    foreach ($queries as $key => $sql) {
        $stmt = db()->prepare($sql);
        $stmt->execute([$databaseName]);
        $result[$key] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $result;
}

/** Compile one deterministic orphan cleanup rule. */
function database_maintenance_model_orphan_rule(string $key, string $table, string $column, string $parent, string $parentColumn, string $reason, array $identityColumns): array
{
    $quotedTable = database_maintenance_model_quote_identifier($table);
    $quotedColumn = database_maintenance_model_quote_identifier($column);
    $quotedParent = database_maintenance_model_quote_identifier($parent);
    $quotedParentColumn = database_maintenance_model_quote_identifier($parentColumn);
    $predicate = $quotedTable . '.' . $quotedColumn . ' IS NOT NULL AND NOT EXISTS (SELECT 1 FROM ' . $quotedParent . ' p WHERE p.' . $quotedParentColumn . ' = ' . $quotedTable . '.' . $quotedColumn . ')';
    return database_maintenance_model_predicate_rule($key, $table, 'orphaned_rows', 'high', $reason, $predicate, $identityColumns);
}

/** Compile one known expiry cleanup rule without accepting raw SQL from services. */
function database_maintenance_model_expiry_rule(string $key, string $table, string $reason, array $identityColumns): array
{
    $predicates = [
        'expired_admin_remember_tokens' => '(expires_at < NOW() OR revoked_at IS NOT NULL)',
        'expired_password_reset_tokens' => '(expires_at < NOW() OR used_at IS NOT NULL)',
        'expired_navigation_cache' => 'expires_at IS NOT NULL AND expires_at < NOW()',
        'expired_auth_rate_limits' => 'last_attempt_at < DATE_SUB(NOW(), INTERVAL 1 DAY) AND (locked_until IS NULL OR locked_until < NOW())',
    ];
    if (!isset($predicates[$key])) {
        throw new RuntimeException('Unsupported database maintenance expiry rule.');
    }
    return database_maintenance_model_predicate_rule($key, $table, 'expired_temporary_state', 'high', $reason, $predicates[$key], $identityColumns);
}

/** Compile one predicate-based cleanup rule inside the persistence layer. */
function database_maintenance_model_predicate_rule(string $key, string $table, string $category, string $confidence, string $reason, string $predicate, array $identityColumns): array
{
    $quotedTable = database_maintenance_model_quote_identifier($table);
    $quotedIdentity = array_map(__NAMESPACE__ . '\\database_maintenance_model_quote_identifier', $identityColumns);
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
        'identifiers_sql' => $automatic ? 'SELECT ' . $identitySelect . ' FROM ' . $quotedTable . ' WHERE ' . $predicate . ' ORDER BY ' . $identityOrder . ' LIMIT :batch_size' : '',
        'delete_sql' => $automatic ? 'DELETE FROM ' . $quotedTable . ' WHERE ' . $predicate . ' ORDER BY ' . $identityOrder . ' LIMIT :batch_size' : '',
        'identifier_columns' => array_values($identityColumns),
        'parameters' => [],
        'automatic' => $automatic,
        'filesystem_effects' => false,
    ];
}

/** Compile one deterministic duplicate cleanup rule. */
function database_maintenance_model_duplicate_rule(string $key, string $table, string $idColumn, array $identityColumns, string $reason): array
{
    $quotedTable = database_maintenance_model_quote_identifier($table);
    $quotedId = database_maintenance_model_quote_identifier($idColumn);
    $identity = implode(', ', array_map(__NAMESPACE__ . '\\database_maintenance_model_quote_identifier', $identityColumns));
    $join = implode(' AND ', array_map(static fn (string $column): string => 'candidate.' . database_maintenance_model_quote_identifier($column) . ' <=> duplicates.' . database_maintenance_model_quote_identifier($column), $identityColumns));
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

/** Count candidate rows for compiled cleanup rules. */
function database_maintenance_model_inspect_cleanup_candidates(array $rules): array
{
    $candidates = [];
    foreach ($rules as $rule) {
        try {
            $statement = db()->prepare((string) ($rule['count_sql'] ?? ''));
            $statement->execute((array) ($rule['parameters'] ?? []));
            $count = max(0, (int) $statement->fetchColumn());
            $candidates[] = $rule + ['candidate_count' => $count, 'inspection_error' => ''];
        } catch (Throwable $exception) {
            $candidates[] = $rule + ['candidate_count' => 0, 'inspection_error' => $exception->getMessage()];
        }
    }
    return $candidates;
}

/** Return grouped thumbnail metadata distribution rows. */
function database_maintenance_model_thumbnail_distribution_rows(): array
{
    return db()->query('SELECT size_px, format, status, COUNT(*) AS row_count FROM image_thumbnail_variants GROUP BY size_px, format, status ORDER BY size_px, format, status')->fetchAll(PDO::FETCH_ASSOC);
}

/** Execute one bounded cleanup rule and persist the matching exact audit row transactionally. */
function database_maintenance_model_execute_cleanup_rule(array $rule, int $batchSize, string $operationId): array
{
    $batchSize = max(1, $batchSize);
    $pdo = db();
    $countStatement = $pdo->prepare((string) ($rule['count_sql'] ?? ''));
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
            $identifierStatement = $pdo->prepare((string) ($rule['identifiers_sql'] ?? ''));
            foreach ((array) ($rule['parameters'] ?? []) as $name => $value) {
                $identifierStatement->bindValue(is_int($name) ? $name + 1 : (string) $name, $value);
            }
            $identifierStatement->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            $identifierStatement->execute();
            $removedIdentifiers = $identifierStatement->fetchAll(PDO::FETCH_ASSOC);
            if ($removedIdentifiers === []) {
                throw new RuntimeException('Cleanup count reported candidates, but no deterministic identifier batch could be selected. The transaction was rolled back.');
            }

            $deleteStatement = $pdo->prepare((string) ($rule['delete_sql'] ?? ''));
            foreach ((array) ($rule['parameters'] ?? []) as $name => $value) {
                $deleteStatement->bindValue(is_int($name) ? $name + 1 : (string) $name, $value);
            }
            $deleteStatement->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            $deleteStatement->execute();
            $deleted = max(0, $deleteStatement->rowCount());
            if ($deleted !== count($removedIdentifiers)) {
                throw new RuntimeException('Cleanup batch identifier count did not match the deleted row count. The transaction was rolled back.');
            }
            database_maintenance_model_write_cleanup_audit($pdo, $rule, $removedIdentifiers, $deleted, $operationId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    $remainingStatement = $pdo->prepare((string) ($rule['count_sql'] ?? ''));
    $remainingStatement->execute((array) ($rule['parameters'] ?? []));
    $remaining = max(0, (int) $remainingStatement->fetchColumn());
    return [
        'before_count' => $beforeCount,
        'deleted_count' => $deleted,
        'remaining_count' => $remaining,
        'removed_identifiers' => $removedIdentifiers,
    ];
}

/** Write one exact cleanup audit row inside an existing transaction. */
function database_maintenance_model_write_cleanup_audit(PDO $pdo, array $rule, array $identifiers, int $deletedCount, string $operationId): void
{
    $statement = $pdo->prepare('INSERT INTO database_maintenance_audit_log (operation_id, rule_key, table_name, category, reason, identifier_columns_json, removed_identifiers_json, deleted_count, created_at) VALUES (:operation_id, :rule_key, :table_name, :category, :reason, :identifier_columns_json, :removed_identifiers_json, :deleted_count, NOW())');
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

/** Return applied migration versions from the schema ledger. */
function database_maintenance_model_applied_migration_versions(): array
{
    return array_map('strval', db()->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
}

/** Run one explicitly allowed physical/statistics table operation. */
function database_maintenance_model_run_table_operation(string $operation, string $tableName): array
{
    $operation = strtoupper($operation);
    if (!in_array($operation, ['ANALYZE', 'OPTIMIZE'], true)) {
        throw new RuntimeException('Unsupported database table maintenance operation.');
    }
    $statement = db()->query($operation . ' TABLE ' . database_maintenance_model_quote_identifier($tableName));
    return $statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
}
