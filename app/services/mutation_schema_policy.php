<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/mutation_schema_policy.php
 * Module Type: Service
 *
 * Purpose:
 *   Applies fail-closed three-state schema policy to destructive, ingestion,
 *   maintenance, credential, migration, and updater mutations.
 *
 * Responsibilities:
 *   - Define reusable schema capability models for Phase 10 mutation workflows
 *   - Distinguish confirmed missing schema from metadata inspection failure
 *   - Refuse mutation when required schema state is unknown
 *   - Allow only explicit, proven compatibility behavior for confirmed absence
 *   - Keep mutation refusal logs bounded and free of SQL, credentials, and paths
 *   - Reuse request-local schema inspection caching across related operations
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
 *   - This service owns mutation policy, not database metadata discovery.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Never include raw database exceptions, SQL, tokens, or filesystem paths in logs.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

/**
 * Raised when a mutation cannot safely continue because required schema is
 * missing or could not be verified.
 */
class MutationSchemaUnavailableException extends RuntimeException
{
    /**
     * Create an exception describing a mutation refused by schema policy.
     *
     * @param string $feature Stable mutation capability identifier.
     * @param string $state Observed schema capability state.
     * @param string $operation Stable refused-operation identifier.
     * @param string $message Safe caller-facing exception message.
     */
    public function __construct(
        public readonly string $feature,
        public readonly string $state,
        public readonly string $operation,
        string $message
    ) {
        parent::__construct($message);
    }
}

/**
 * Inspect one required table and its required columns.
 *
 * A confirmed missing table is already a complete answer. Columns are inspected
 * only when the table exists, which keeps metadata query budgets bounded and
 * avoids producing misleading column failures for an absent table.
 *
 * @param string $feature Stable capability identifier.
 * @param string $table Required table.
 * @param array<int,string> $columns Required columns.
 * @return array{state:string,feature:string,requirements:array}
 */
function mutation_schema_table_columns_status(string $feature, string $table, array $columns): array
{
    $requirements = [schema_inspection_table($table)];
    if (schema_inspection_is_available($requirements[0])) {
        foreach ($columns as $column) {
            $requirements[] = schema_inspection_column($table, $column);
        }
    }
    return schema_inspection_feature($feature, $requirements);
}

/**
 * Combine several table/column requirement groups into one capability result.
 *
 * @param string $feature Stable capability identifier.
 * @param array<string,array<int,string>> $tables Required columns keyed by table.
 * @return array{state:string,feature:string,requirements:array}
 */
function mutation_schema_tables_status(string $feature, array $tables): array
{
    $requirements = [];
    foreach ($tables as $table => $columns) {
        $tableStatus = schema_inspection_table((string) $table);
        $requirements[] = $tableStatus;
        if (!schema_inspection_is_available($tableStatus)) {
            continue;
        }
        foreach ($columns as $column) {
            $requirements[] = schema_inspection_column((string) $table, (string) $column);
        }
    }
    return schema_inspection_feature($feature, $requirements);
}

/**
 * Return only validated affected object names from a schema capability result.
 *
 * @param array $status Aggregate schema status.
 * @return array<int,string> Bounded table/column identities.
 */
function mutation_schema_affected_objects(array $status): array
{
    $objects = [];
    foreach ((array) ($status['requirements'] ?? []) as $requirement) {
        if (!is_array($requirement) || schema_inspection_is_available($requirement)) {
            continue;
        }
        $table = (string) ($requirement['table'] ?? '');
        $object = (string) ($requirement['object'] ?? '');
        $type = (string) ($requirement['object_type'] ?? '');
        if (
            preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $object) !== 1
        ) {
            continue;
        }
        $objects[] = $type === 'table' || $table === $object ? $table : $table . '.' . $object;
    }
    return array_values(array_unique($objects));
}

/**
 * Record one bounded schema-policy refusal for an administrator-facing mutation.
 *
 * @param array $status Aggregate schema status.
 * @param string $operation Stable operation identifier.
 */
function mutation_schema_log_refusal(array $status, string $operation): void
{
    if (!function_exists('Gallery\\Services\\admin_log_event')) {
        return;
    }
    $feature = (string) ($status['feature'] ?? 'mutation_schema');
    $state = (string) ($status['state'] ?? 'unknown');
    if (preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $feature) !== 1) {
        $feature = 'mutation_schema';
    }
    if (preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $operation) !== 1) {
        $operation = 'mutation';
    }
    if (!in_array($state, ['missing', 'unknown'], true)) {
        $state = 'unknown';
    }
    admin_log_event('warning', 'database.mutation_schema_refused', 'Database mutation was refused because required schema was not safely available.', [
        'feature' => $feature,
        'state' => $state,
        'operation' => $operation,
        'affected_objects' => mutation_schema_affected_objects($status),
    ], ['category' => 'database', 'severity' => 'warning']);
}

/**
 * Require a fully available schema capability before mutation begins.
 *
 * @param array $status Aggregate schema status.
 * @param string $operation Stable operation identifier.
 * @param string $missingMessage Message for confirmed migration absence.
 * @param string $unknownMessage Message for metadata inspection failure.
 */
function mutation_schema_assert_available(
    array $status,
    string $operation,
    string $missingMessage = 'Required database migration has not been applied.',
    string $unknownMessage = 'Required database schema could not be verified. The mutation was not started.'
): void {
    if (schema_inspection_is_available($status)) {
        return;
    }
    mutation_schema_log_refusal($status, $operation);
    $state = schema_inspection_is_missing($status) ? 'missing' : 'unknown';
    throw new MutationSchemaUnavailableException(
        (string) ($status['feature'] ?? 'mutation_schema'),
        $state,
        $operation,
        $state === 'missing' ? $missingMessage : $unknownMessage
    );
}

/**
 * Require metadata inspection to be conclusive while allowing confirmed absence.
 *
 * This boundary is used by workflows that are explicitly responsible for
 * creating missing schema, for example migration/repair/update activation.
 * Missing is safe because information_schema answered reliably. Unknown is not.
 *
 * @param array $status Aggregate schema status.
 * @param string $operation Stable operation identifier.
 * @param string $unknownMessage Message for metadata inspection failure.
 */
function mutation_schema_assert_known(array $status, string $operation, string $unknownMessage): void
{
    if (!schema_inspection_is_unknown($status)) {
        return;
    }
    mutation_schema_log_refusal($status, $operation);
    throw new MutationSchemaUnavailableException(
        (string) ($status['feature'] ?? 'mutation_schema'),
        'unknown',
        $operation,
        $unknownMessage
    );
}

/**
 * Inspect one optional table/column used by a destructive compatibility cleanup.
 *
 * Confirmed absence returns false and is the documented compatibility path.
 * Unknown state throws so the caller cannot silently skip a dependency that may
 * actually exist and then commit an incomplete destructive mutation.
 *
 * @param string $feature Stable feature identifier.
 * @param string $table Optional table.
 * @param string $column Optional column.
 * @param string $operation Stable operation identifier.
 * @return bool True only when both table and column are verified available.
 */
function mutation_schema_optional_table_column_available(string $feature, string $table, string $column, string $operation): bool
{
    $tableStatus = schema_inspection_table($table);
    if (schema_inspection_is_missing($tableStatus)) {
        return false;
    }
    if (schema_inspection_is_unknown($tableStatus)) {
        $status = schema_inspection_feature($feature, [$tableStatus]);
        mutation_schema_assert_known($status, $operation, 'Optional database dependency could not be inspected. The mutation was not started.');
    }

    $columnStatus = schema_inspection_column($table, $column);
    if (schema_inspection_is_missing($columnStatus)) {
        return false;
    }
    if (schema_inspection_is_unknown($columnStatus)) {
        $status = schema_inspection_feature($feature, [$columnStatus]);
        mutation_schema_assert_known($status, $operation, 'Optional database dependency could not be inspected. The mutation was not started.');
    }
    return true;
}

/**
 * Inspect one optional table and all columns used by a compatibility mutation.
 *
 * Confirmed absence of either the table or any requested column returns false.
 * An inspection failure throws before mutation begins.
 *
 * @param string $feature Stable feature identifier.
 * @param string $table Optional table.
 * @param array<int,string> $columns Optional columns used together.
 * @param string $operation Stable operation identifier.
 * @return bool True only when the table and every requested column are verified.
 */
function mutation_schema_optional_table_columns_available(string $feature, string $table, array $columns, string $operation): bool
{
    $tableStatus = schema_inspection_table($table);
    if (schema_inspection_is_missing($tableStatus)) {
        return false;
    }
    if (schema_inspection_is_unknown($tableStatus)) {
        mutation_schema_assert_known(
            schema_inspection_feature($feature, [$tableStatus]),
            $operation,
            'Optional database dependency could not be inspected. The mutation was not started.'
        );
    }

    foreach ($columns as $column) {
        $columnStatus = schema_inspection_column($table, (string) $column);
        if (schema_inspection_is_missing($columnStatus)) {
            return false;
        }
        if (schema_inspection_is_unknown($columnStatus)) {
            mutation_schema_assert_known(
                schema_inspection_feature($feature, [$columnStatus]),
                $operation,
                'Optional database dependency could not be inspected. The mutation was not started.'
            );
        }
    }
    return true;
}

/**
 * Inspect one optional column on a verified core table.
 *
 * Confirmed absence allows the caller to omit optional imported metadata.
 * Unknown state aborts the mutation because guessing could create a partial or
 * internally inconsistent import.
 */
function mutation_schema_optional_column_available(string $feature, string $table, string $column, string $operation): bool
{
    $columnStatus = schema_inspection_column($table, $column);
    if (schema_inspection_is_missing($columnStatus)) {
        return false;
    }
    if (schema_inspection_is_unknown($columnStatus)) {
        mutation_schema_assert_known(
            schema_inspection_feature($feature, [$columnStatus]),
            $operation,
            'Optional database column could not be inspected. The mutation was not started.'
        );
    }
    return true;
}

/** @return array{state:string,feature:string,requirements:array} */
function gallery_deletion_schema_status(): array
{
    return mutation_schema_tables_status('mutation.gallery_delete', [
        'galleries' => ['id', 'folder_path', 'cover_image_id', 'parent_id', 'updated_at'],
        'images' => ['id', 'gallery_id'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function gallery_move_schema_status(): array
{
    return mutation_schema_tables_status('mutation.gallery_move', [
        'galleries' => ['id', 'folder_path', 'folder_path_hash', 'parent_id', 'cover_image_id', 'updated_at'],
        'images' => ['id', 'gallery_id', 'relative_path', 'relative_path_hash', 'sort_order', 'updated_at'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function duplicate_photo_ledger_schema_status(): array
{
    return mutation_schema_tables_status('mutation.duplicate_photo_ledger', [
        'duplicate_photo_ledger_pairs' => ['user_id', 'image_id_low', 'image_id_high', 'created_at'],
        'duplicate_photo_ledger_galleries' => ['user_id', 'gallery_id', 'created_at'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function upload_ingestion_schema_status(): array
{
    return mutation_schema_tables_status('mutation.upload_ingestion', [
        'galleries' => ['id', 'folder_path'],
        'images' => ['id', 'gallery_id', 'relative_path', 'relative_path_hash', 'filename', 'sort_order', 'created_at', 'updated_at'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function upload_automation_schema_status(): array
{
    return mutation_schema_table_columns_status('mutation.upload_automation', 'gallery_upload_tokens', [
        'id', 'gallery_id', 'token_hash', 'label', 'active', 'created_by_user_id', 'created_at', 'last_used_at', 'revoked_at',
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function upload_automation_revocation_schema_status(): array
{
    return mutation_schema_table_columns_status('mutation.upload_automation_revoke', 'gallery_upload_tokens', [
        'id', 'gallery_id', 'active', 'revoked_at',
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function mobile_webdav_schema_status(): array
{
    return mutation_schema_table_columns_status('mutation.mobile_webdav', 'mobile_webdav_upload_tokens', [
        'id', 'user_id', 'gallery_id', 'label', 'username', 'password_hash', 'path_token', 'enabled', 'created_at', 'updated_at', 'last_used_at',
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function mobile_webdav_revocation_schema_status(): array
{
    return mutation_schema_table_columns_status('mutation.mobile_webdav_revoke', 'mobile_webdav_upload_tokens', [
        'id',
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function gallery_migration_schema_status(): array
{
    return mutation_schema_tables_status('mutation.gallery_migration', [
        'galleries' => ['id', 'folder_path', 'updated_at'],
        'images' => ['id', 'gallery_id', 'relative_path', 'relative_path_hash', 'created_at', 'updated_at'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function thumbnail_metadata_mutation_schema_status(): array
{
    return mutation_schema_table_columns_status('mutation.thumbnail_metadata', 'image_thumbnail_variants', [
        'image_id', 'size_px', 'format', 'width', 'height', 'file_size', 'modified_at', 'status', 'status_reason', 'checked_at', 'created_at', 'updated_at',
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function database_maintenance_mutation_schema_status(): array
{
    return schema_inspection_feature('mutation.database_maintenance', [schema_inspection_table('schema_migrations')]);
}

/** @return array{state:string,feature:string,requirements:array} */
function application_update_activation_schema_status(): array
{
    return schema_inspection_feature('mutation.application_update', [schema_inspection_table('schema_migrations')]);
}
