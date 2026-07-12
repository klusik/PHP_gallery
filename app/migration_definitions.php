<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/migration_definitions.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Defines and validates the shared database migration file contract.
 *
 * Responsibilities:
 *   - Keep the normal application runner and first-run installer consistent
 *   - Accept historical list-of-SQL migrations without modification
 *   - Support current migrations with SQL statements and an optional PHP repair callback
 *   - Reject malformed migration files before recording their versions
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
 *   2026-07-12
 */

declare(strict_types=1);

namespace Gallery\Core;

use RuntimeException;


/**
 * Discover migration files in deterministic filename order.
 *
 * @param string $migrationPath Migration directory path.
 * @return array<int,string> Sorted absolute migration file paths.
 */
function discover_migration_files(string $migrationPath): array
{
    $files = glob(rtrim($migrationPath, '/\\') . '/*.php') ?: [];
    sort($files, SORT_STRING);
    return array_values($files);
}

/**
 * Return migration files whose versions are not present in schema_migrations.
 *
 * Applied versions without a corresponding current file are intentionally
 * ignored. This preserves upgrade consistency after obsolete implementation
 * migrations are removed from the release while their audit rows remain in the
 * database.
 *
 * @param array<int,string> $files Current migration file paths.
 * @param array<int,string> $appliedVersions Applied migration version names.
 * @return array<int,string> Pending migration file paths.
 */
function pending_migration_files(array $files, array $appliedVersions): array
{
    $applied = array_fill_keys(array_map('strval', $appliedVersions), true);
    return array_values(array_filter(
        $files,
        static fn (string $file): bool => !isset($applied[basename($file, '.php')])
    ));
}

/**
 * Load and validate one migration definition.
 *
 * Historical migrations return a simple list of SQL strings. Current migrations
 * may additionally return an associative definition with `statements` and an
 * optional `after` callable for deterministic PHP data repairs. The callable is
 * executed before schema_migrations is updated, so a failure remains retryable.
 *
 * @param string $file Migration file path.
 * @return array{statements: array<int,string>, after: callable|null} Validated definition.
 */
function load_migration_definition(string $file): array
{
    $definition = require $file;
    if (!is_array($definition)) {
        throw new RuntimeException('Migration ' . basename($file) . ' must return an array.');
    }

    if (array_is_list($definition)) {
        $statements = $definition;
        $after = null;
    } else {
        $unknownKeys = array_diff(array_keys($definition), ['statements', 'after']);
        if ($unknownKeys !== []) {
            throw new RuntimeException('Migration ' . basename($file) . ' contains unsupported keys: ' . implode(', ', $unknownKeys) . '.');
        }
        $statements = $definition['statements'] ?? [];
        $after = $definition['after'] ?? null;
    }

    if (!is_array($statements) || !array_is_list($statements)) {
        throw new RuntimeException('Migration ' . basename($file) . ' statements must be a list.');
    }
    foreach ($statements as $index => $statement) {
        if (!is_string($statement) || trim($statement) === '') {
            throw new RuntimeException('Migration ' . basename($file) . ' statement ' . ((int) $index + 1) . ' must be a non-empty SQL string.');
        }
    }
    if ($after !== null && !is_callable($after)) {
        throw new RuntimeException('Migration ' . basename($file) . ' after value must be callable.');
    }

    return [
        'statements' => array_values($statements),
        'after' => $after,
    ];
}
