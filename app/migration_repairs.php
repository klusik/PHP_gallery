<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/migration_repairs.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides deterministic PHP data repairs used by database migrations.
 *
 * Responsibilities:
 *   - Keep migration repair callbacks reusable by the normal updater and installer
 *   - Preserve compatibility with the former SQL-only migration runner
 *   - Run gallery public-path repairs transactionally
 *   - Verify that nested galleries retain complete hierarchical public URLs
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
 *   2026-07-25
 */

declare(strict_types=1);

namespace Gallery\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Build a migration definition for the gallery public-path repair.
 *
 * The optional legacy PDO argument is intentional. Migration files released in
 * July 2026 may be loaded by either the current definition-aware runner or the
 * former SQL-only runner. The former runner includes migration files directly
 * from a scope containing `$pdo`. In that case the repair is executed while the
 * file is loaded and an empty SQL statement list is returned, preventing arrays
 * from being passed to apply_migration_statement().
 *
 * @param PDO|null $legacyPdo Database connection exposed by the former runner.
 * @param bool $verifyHierarchy Whether nested physical galleries must have nested public paths.
 * @return array{statements: array<int,string>, after: callable|null}|array<int,string> Migration definition.
 */
function gallery_public_path_repair_migration_definition(?PDO $legacyPdo, bool $verifyHierarchy): array
{
    $repair = static function (PDO $pdo) use ($verifyHierarchy): void {
        run_gallery_public_path_repair($pdo, $verifyHierarchy);
    };

    if ($legacyPdo !== null) {
        $repair($legacyPdo);
        return [];
    }

    return [
        'statements' => [],
        'after' => $repair,
    ];
}

/**
 * Regenerate gallery public paths and optionally verify their hierarchy.
 *
 * @param PDO $pdo Database connection.
 * @param bool $verifyHierarchy Whether nested physical galleries must have nested public paths.
 */
function run_gallery_public_path_repair(PDO $pdo, bool $verifyHierarchy): void
{
    $galleryCount = (int) $pdo->query('SELECT COUNT(*) FROM galleries')->fetchColumn();
    if ($galleryCount === 0) {
        return;
    }

    if (!function_exists('Gallery\\Services\\regenerate_gallery_public_paths')) {
        $projectRoot = dirname(__DIR__);
        require_once $projectRoot . '/app/helpers.php';
        require_once $projectRoot . '/app/services/public_paths.php';
    }
    if (!function_exists('Gallery\\Services\\regenerate_gallery_public_paths')) {
        throw new RuntimeException('Gallery public path repair is unavailable. Deploy the complete migration compatibility patch before running migrations.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        \Gallery\Services\regenerate_gallery_public_paths($pdo);
        if ($verifyHierarchy) {
            verify_hierarchical_gallery_public_paths($pdo);
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
}

/**
 * Verify that every nested filesystem gallery also has a nested public path.
 *
 * @param PDO $pdo Database connection.
 */
function verify_hierarchical_gallery_public_paths(PDO $pdo): void
{
    $nestedRows = $pdo->query("SELECT id, folder_path, url_path FROM galleries WHERE folder_path LIKE '%/%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nestedRows as $row) {
        $urlPath = trim((string) ($row['url_path'] ?? ''), '/');
        if ($urlPath !== '' && str_contains($urlPath, '/')) {
            continue;
        }

        throw new RuntimeException(
            'Hierarchical public path repair failed for gallery #' . (int) ($row['id'] ?? 0)
            . ' (' . (string) ($row['folder_path'] ?? '') . ').'
        );
    }
}

/**
 * Build the conditional database-maintenance schema repair migration.
 *
 * The optional PDO argument preserves compatibility with the former SQL-only
 * migration runner. Current runners receive a callback definition, while an old
 * runner executes the repair during file loading and receives an empty SQL list.
 *
 * @param PDO|null $legacyPdo Database connection exposed by the former runner.
 * @return array{statements: array<int,string>, after: callable|null}|array<int,string> Migration definition.
 */
function database_maintenance_schema_repair_migration_definition(?PDO $legacyPdo): array
{
    $repair = static function (PDO $pdo): void {
        run_database_maintenance_schema_repair($pdo);
    };

    if ($legacyPdo !== null) {
        $repair($legacyPdo);
        return [];
    }

    return [
        'statements' => [],
        'after' => $repair,
    ];
}

/**
 * Repair partially applied thumbnail metadata compaction safely.
 *
 * Every object is inspected before alteration. Source geometry is copied to the
 * images table before duplicated legacy columns are dropped. The function is
 * idempotent and validates the compact result after all conditional changes.
 *
 * @param PDO $pdo Database connection.
 */
function run_database_maintenance_schema_repair(PDO $pdo): void
{
    $auditColumns = [
        'id', 'operation_id', 'rule_key', 'table_name', 'category', 'reason',
        'identifier_columns_json', 'removed_identifiers_json', 'deleted_count', 'created_at',
    ];
    if (!migration_repair_table_exists($pdo, 'database_maintenance_audit_log')) {
        $pdo->exec(
            "CREATE TABLE database_maintenance_audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                operation_id CHAR(16) NOT NULL,
                rule_key VARCHAR(120) NOT NULL,
                table_name VARCHAR(128) NOT NULL,
                category VARCHAR(80) NOT NULL,
                reason VARCHAR(500) NOT NULL,
                identifier_columns_json TEXT NOT NULL,
                removed_identifiers_json LONGTEXT NOT NULL,
                deleted_count INT UNSIGNED NOT NULL,
                created_at DATETIME NOT NULL,
                KEY database_maintenance_audit_operation_index (operation_id, id),
                KEY database_maintenance_audit_table_created_index (table_name, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
    if (!migration_repair_table_exists($pdo, 'database_maintenance_audit_log')) {
        throw new RuntimeException('Database maintenance schema repair failed to create the transactional cleanup audit table.');
    }
    foreach ($auditColumns as $auditColumn) {
        if (!migration_repair_column_exists($pdo, 'database_maintenance_audit_log', $auditColumn)) {
            throw new RuntimeException('Database maintenance audit table is missing required column ' . $auditColumn . '.');
        }
    }

    if (!migration_repair_table_exists($pdo, 'image_thumbnail_variants')) {
        return;
    }

    if (migration_repair_table_exists($pdo, 'images')) {
        $imageColumns = [
            'display_width' => 'INT UNSIGNED NULL AFTER `height`',
            'display_height' => 'INT UNSIGNED NULL AFTER `display_width`',
            'exif_orientation' => 'TINYINT UNSIGNED NULL AFTER `display_height`',
            'thumbnail_derivative_version' => 'INT UNSIGNED NOT NULL DEFAULT 1 AFTER `exif_orientation`',
            'thumbnail_metadata_refreshed_at' => 'DATETIME NULL AFTER `thumbnail_derivative_version`',
        ];
        foreach ($imageColumns as $columnName => $definition) {
            if (!migration_repair_column_exists($pdo, 'images', $columnName)) {
                $pdo->exec('ALTER TABLE `images` ADD COLUMN `' . $columnName . '` ' . $definition);
            }
        }
    }

    if (!migration_repair_column_exists($pdo, 'image_thumbnail_variants', 'derivative_version')) {
        $pdo->exec('ALTER TABLE `image_thumbnail_variants` ADD COLUMN `derivative_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `format`');
    }

    if (
        migration_repair_table_exists($pdo, 'images')
        && migration_repair_column_exists($pdo, 'image_thumbnail_variants', 'source_width')
        && migration_repair_column_exists($pdo, 'image_thumbnail_variants', 'source_height')
    ) {
        $orientationSelect = migration_repair_column_exists($pdo, 'image_thumbnail_variants', 'source_exif_orientation')
            ? 'MAX(source_exif_orientation) AS source_exif_orientation'
            : 'NULL AS source_exif_orientation';
        $pdo->exec(
            'UPDATE images i
             JOIN (
                 SELECT image_id, MAX(source_width) AS source_width, MAX(source_height) AS source_height, ' . $orientationSelect . '
                   FROM image_thumbnail_variants
                  WHERE source_width IS NOT NULL
                    AND source_height IS NOT NULL
                    AND source_width > 0
                    AND source_height > 0
                  GROUP BY image_id
             ) v ON v.image_id = i.id
                SET i.display_width = COALESCE(i.display_width, v.source_width),
                    i.display_height = COALESCE(i.display_height, v.source_height),
                    i.exif_orientation = COALESCE(i.exif_orientation, v.source_exif_orientation, 1),
                    i.thumbnail_derivative_version = GREATEST(1, i.thumbnail_derivative_version),
                    i.thumbnail_metadata_refreshed_at = COALESCE(i.thumbnail_metadata_refreshed_at, NOW())'
        );
    }

    if (migration_repair_table_exists($pdo, 'images')) {
        $pdo->exec(
            'UPDATE image_thumbnail_variants v
             JOIN images i ON i.id = v.image_id
                SET v.derivative_version = GREATEST(1, i.thumbnail_derivative_version)
              WHERE v.derivative_version < 1 OR v.derivative_version <> i.thumbnail_derivative_version'
        );
    }

    if (migration_repair_foreign_key_exists($pdo, 'image_thumbnail_variants', 'image_thumbnail_variants_gallery_id_foreign')) {
        $pdo->exec('ALTER TABLE `image_thumbnail_variants` DROP FOREIGN KEY `image_thumbnail_variants_gallery_id_foreign`');
    }
    if (migration_repair_index_exists($pdo, 'image_thumbnail_variants', 'image_thumbnail_variants_gallery_index')) {
        $pdo->exec('ALTER TABLE `image_thumbnail_variants` DROP INDEX `image_thumbnail_variants_gallery_index`');
    }

    $legacyColumns = [
        'gallery_id',
        'thumbnail_rel_path',
        'source_width',
        'source_height',
        'source_mime_type',
        'source_file_size',
        'source_modified_at',
        'source_checksum_sha256',
        'source_exif_orientation',
        'source_exif_json',
    ];
    foreach ($legacyColumns as $columnName) {
        if (migration_repair_column_exists($pdo, 'image_thumbnail_variants', $columnName)) {
            $pdo->exec('ALTER TABLE `image_thumbnail_variants` DROP COLUMN `' . $columnName . '`');
        }
    }

    if (!migration_repair_column_exists($pdo, 'image_thumbnail_variants', 'derivative_version')) {
        throw new RuntimeException('Thumbnail metadata schema repair failed to create derivative_version.');
    }
    foreach ($legacyColumns as $columnName) {
        if (migration_repair_column_exists($pdo, 'image_thumbnail_variants', $columnName)) {
            throw new RuntimeException('Thumbnail metadata schema repair failed to remove legacy column ' . $columnName . '.');
        }
    }
}

/**
 * Return whether one table exists in the active database.
 */
function migration_repair_table_exists(PDO $pdo, string $tableName): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $statement->execute([$tableName]);
    return (bool) $statement->fetchColumn();
}

/**
 * Return whether one column exists in the active database.
 */
function migration_repair_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $statement->execute([$tableName, $columnName]);
    return (bool) $statement->fetchColumn();
}

/**
 * Return whether one index exists in the active database.
 */
function migration_repair_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
    $statement->execute([$tableName, $indexName]);
    return (bool) $statement->fetchColumn();
}

/**
 * Return whether one foreign key exists in the active database.
 */
function migration_repair_foreign_key_exists(PDO $pdo, string $tableName, string $constraintName): bool
{
    $statement = $pdo->prepare('SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ? LIMIT 1');
    $statement->execute([$tableName, $constraintName, 'FOREIGN KEY']);
    return (bool) $statement->fetchColumn();
}
